<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\BalanceTransaction;
use App\Models\Withdrawal;
use App\Services\BalanceService;
use App\Support\Audit;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Saldo seller + withdraw (penarikan hasil penjualan).
 * Semua mutasi lewat BalanceService (transaksi DB + lock).
 */
class BalanceController extends Controller
{
    public function __construct(private BalanceService $balance)
    {
    }

    /** Saldo terkini + ringkasan. */
    public function index(Request $request): JsonResponse
    {
        $merchant = $request->user()->merchant;
        $bal = $this->balance->for($merchant);

        return response()->json(['data' => [
            'balance' => $bal->balance,
            'held' => $bal->held,
            'pending_withdrawals' => Withdrawal::where('merchant_id', $merchant->id)
                ->where('status', 'pending')->count(),
        ]]);
    }

    /** Riwayat mutasi saldo. */
    public function transactions(Request $request): JsonResponse
    {
        $merchant = $request->user()->merchant;

        return response()->json(
            BalanceTransaction::where('merchant_id', $merchant->id)
                ->with('order:id,order_no', 'withdrawal:id,amount,status')
                ->latest()->paginate(20),
        );
    }

    /** Ajukan penarikan saldo. */
    public function withdraw(Request $request): JsonResponse
    {
        $data = $request->validate([
            'amount' => ['required', 'integer', 'min:10000', 'max:1000000000'],
            'bank_name' => ['required', 'string', 'max:64'],
            'bank_account_no' => ['required', 'string', 'max:64', 'regex:/^[0-9]+$/'],
            'bank_account_holder' => ['required', 'string', 'max:128'],
        ]);

        $merchant = $request->user()->merchant;

        try {
            $wd = $this->balance->requestWithdraw($merchant, $data);
        } catch (\RuntimeException $e) {
            return response()->json(['error' => $e->getMessage()], 422);
        }

        Audit::record('withdraw.requested', $wd, [
            'amount' => $wd->amount, 'bank' => $wd->bank_name,
        ]);

        return response()->json(['data' => [
            'id' => $wd->id,
            'amount' => $wd->amount,
            'status' => $wd->status,
        ]], 201);
    }

    /** Riwayat penarikan milik seller ini. */
    public function withdrawals(Request $request): JsonResponse
    {
        $merchant = $request->user()->merchant;

        return response()->json([
            'data' => Withdrawal::where('merchant_id', $merchant->id)
                ->latest()->paginate(20),
        ]);
    }
}