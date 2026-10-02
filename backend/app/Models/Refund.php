<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Facades\DB;

/**
 * Refund / pengembalian dana order (fase 6).
 * status: pending | approved | rejected
 */
class Refund extends Model
{
    protected $fillable = [
        'order_id', 'merchant_id', 'amount', 'reason', 'status',
        'processed_at', 'metadata',
    ];

    protected $casts = [
        'amount' => 'integer',
        'processed_at' => 'datetime',
        'metadata' => 'array',
    ];

    public function order(): BelongsTo
    {
        return $this->belongsTo(Order::class);
    }

    public function merchant(): BelongsTo
    {
        return $this->belongsTo(Merchant::class);
    }

    /**
     * Approve refund: kembalikan seller_net ke saldo seller.
     * Hanya bisa dipanggil sekali (status pending -> processed).
     */
    public function approve(?int $adminId = null, ?string $note = null): self
    {
        if ($this->status !== 'pending') {
            throw new \RuntimeException('Refund sudah diproses.');
        }

        $this->load('order', 'merchant');

        return DB::transaction(function () use ($adminId, $note) {
            $order = $this->order;

            // Kembalikan seller_net ke saldo seller
            $balance = \App\Models\SellerBalance::firstOrCreate(
                ['merchant_id' => $this->merchant_id],
                ['balance' => 0, 'held' => 0]
            );

            $balance->balance += $order->seller_net;
            $balance->save();

            // Catat transaksi refund
            \App\Models\BalanceTransaction::create([
                'merchant_id' => $this->merchant_id,
                'type' => 'refund_paid',
                'amount' => $order->seller_net,
                'balance_after' => $balance->balance,
                'order_id' => $this->order_id,
                'withdrawal_id' => null,
                'note' => "Pengembalian dana order {$order->order_no}",
            ]);

            $this->status = 'approved';
            $this->processed_at = now();
            $this->metadata = array_merge($this->metadata ?? [], [
                'processed_by' => $adminId,
                'admin_note' => $note,
            ]);
            $this->save();

            return $this;
        });
    }

    public function reject(?int $adminId = null, ?string $note = null): self
    {
        if ($this->status !== 'pending') {
            throw new \RuntimeException('Refund sudah diproses.');
        }

        $this->status = 'rejected';
        $this->processed_at = now();
        $this->metadata = array_merge($this->metadata ?? [], [
            'processed_by' => $adminId,
            'admin_note' => $note,
        ]);
        $this->save();

        return $this;
    }
}