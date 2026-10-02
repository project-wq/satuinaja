<?php

namespace App\Services;

use App\Models\BalanceTransaction;
use App\Models\Merchant;
use App\Models\Order;
use App\Models\SellerBalance;
use App\Models\Withdrawal;
use Illuminate\Support\Facades\DB;

/**
 * Saldo seller + ledger + withdraw. Semua mutasi lewat class ini
 * (dalam transaksi DB, dengan lock baris) supaya tidak ada double-credit.
 */
class BalanceService
{
    /** Ambil/buat baris saldo seller. */
    public function for(Merchant $merchant): SellerBalance
    {
        return SellerBalance::firstOrCreate(['merchant_id' => $merchant->id]);
    }

    /**
     * Credit pendapatan order ke saldo seller (panggil sekali saat order PAID).
     * Idempotent: ditandai lewat ledger `sale` untuk order tsb.
     */
    public function creditOrder(Order $order): bool
    {
        if ($order->seller_net <= 0) {
            return false;
        }

        return DB::transaction(function () use ($order) {
            $exists = BalanceTransaction::where('type', 'sale')
                ->where('order_id', $order->id)
                ->lockForUpdate()
                ->exists();
            if ($exists) {
                return false;
            }

            $bal = SellerBalance::where('merchant_id', $order->merchant_id)
                ->lockForUpdate()
                ->first() ?? SellerBalance::create(['merchant_id' => $order->merchant_id]);

            $bal->balance += $order->seller_net;
            $bal->save();

            BalanceTransaction::create([
                'merchant_id' => $order->merchant_id,
                'type' => 'sale',
                'amount' => $order->seller_net,
                'balance_after' => $bal->balance,
                'order_id' => $order->id,
                'note' => "Penjualan {$order->order_no}",
            ]);

            return true;
        });
    }

    /**
     * Tarik kembali pendapatan order dari saldo seller saat refund disetujui.
     * Idempotent: ditandai lewat ledger `refund_paid` untuk order tsb.
     * Saldo boleh negatif bila seller sudah menarik dananya (jadi utang).
     */
    public function refundOrder(Order $order, ?string $note = null): bool
    {
        if ($order->seller_net <= 0) {
            return false;
        }

        return DB::transaction(function () use ($order, $note) {
            $exists = BalanceTransaction::where('type', 'refund_paid')
                ->where('order_id', $order->id)
                ->lockForUpdate()
                ->exists();
            if ($exists) {
                return false;
            }

            $bal = SellerBalance::where('merchant_id', $order->merchant_id)
                ->lockForUpdate()
                ->first() ?? SellerBalance::create(['merchant_id' => $order->merchant_id]);

            $bal->balance -= $order->seller_net;
            $bal->save();

            BalanceTransaction::create([
                'merchant_id' => $order->merchant_id,
                'type' => 'refund_paid',
                'amount' => -$order->seller_net,
                'balance_after' => $bal->balance,
                'order_id' => $order->id,
                'note' => $note ?? "Pengembalian dana order {$order->order_no}",
            ]);

            return true;
        });
    }

    /**
     * Ajukan withdraw: pindahkan balance -> held. Gagal kalau kurang.
     * @throws \RuntimeException
     */
    public function requestWithdraw(Merchant $merchant, array $data): Withdrawal
    {
        return DB::transaction(function () use ($merchant, $data) {
            $bal = SellerBalance::where('merchant_id', $merchant->id)
                ->lockForUpdate()
                ->first() ?? SellerBalance::create(['merchant_id' => $merchant->id]);

            if ($data['amount'] > $bal->balance) {
                throw new \RuntimeException("Saldo tidak cukup (tersedia {$bal->balance}).");
            }

            $bal->balance -= $data['amount'];
            $bal->held += $data['amount'];
            $bal->save();

            $wd = Withdrawal::create([
                'merchant_id' => $merchant->id,
                'amount' => $data['amount'],
                'bank_name' => $data['bank_name'],
                'bank_account_no' => $data['bank_account_no'],
                'bank_account_holder' => $data['bank_account_holder'],
                'status' => 'pending',
            ]);

            BalanceTransaction::create([
                'merchant_id' => $merchant->id,
                'type' => 'withdraw_hold',
                'amount' => -$data['amount'],
                'balance_after' => $bal->balance,
                'withdrawal_id' => $wd->id,
                'note' => 'Penarikan menunggu persetujuan admin',
            ]);

            return $wd;
        });
    }

    /**
     * Admin putuskan withdraw:
     *   approved -> held dilepas (uang keluar, saldo hilang permanen)
     *   rejected -> held dikembalikan ke balance
     */
    public function processWithdraw(Withdrawal $wd, string $decision, ?int $adminId, ?string $note): Withdrawal
    {
        if ($wd->status !== 'pending') {
            throw new \RuntimeException('Penarikan sudah diproses.');
        }
        if (! in_array($decision, ['approved', 'rejected'], true)) {
            throw new \RuntimeException('Keputusan tidak valid.');
        }

        return DB::transaction(function () use ($wd, $decision, $adminId, $note) {
            $bal = SellerBalance::where('merchant_id', $wd->merchant_id)
                ->lockForUpdate()
                ->firstOrFail();

            $bal->held -= $wd->amount;

            if ($decision === 'rejected') {
                $bal->balance += $wd->amount;
            }

            $bal->save();

            $wd->update([
                'status' => $decision,
                'admin_note' => $note,
                'processed_by' => $adminId,
                'processed_at' => now(),
            ]);

            BalanceTransaction::create([
                'merchant_id' => $wd->merchant_id,
                'type' => $decision === 'approved' ? 'withdraw_paid' : 'withdraw_refund',
                'amount' => $decision === 'rejected' ? $wd->amount : 0,
                'balance_after' => $bal->balance,
                'withdrawal_id' => $wd->id,
                'note' => $note,
            ]);

            return $wd;
        });
    }
}