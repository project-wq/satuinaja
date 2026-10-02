<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Ledger mutasi saldo seller (append-only, jangan diedit).
 * type: sale | withdraw_hold | withdraw_paid | withdraw_refund | refund_paid | adjustment
 */
class BalanceTransaction extends Model
{
    protected $fillable = [
        'merchant_id', 'type', 'amount', 'balance_after',
        'order_id', 'withdrawal_id', 'note',
    ];

    protected $casts = [
        'amount' => 'integer',
        'balance_after' => 'integer',
    ];

    public function merchant(): BelongsTo
    {
        return $this->belongsTo(Merchant::class);
    }

    public function order(): BelongsTo
    {
        return $this->belongsTo(Order::class);
    }

    public function withdrawal(): BelongsTo
    {
        return $this->belongsTo(Withdrawal::class);
    }
}