<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Permintaan penarikan saldo seller.
 * Alur: pending (saldo ditahan) -> approved (sudah dibayar admin) /
 *      rejected (saldo dikembalikan).
 */
class Withdrawal extends Model
{
    protected $fillable = [
        'merchant_id', 'amount', 'bank_name', 'bank_account_no',
        'bank_account_holder', 'status', 'admin_note', 'processed_by',
        'processed_at',
    ];

    protected $casts = [
        'amount' => 'integer',
        'processed_at' => 'datetime',
    ];

    public function merchant(): BelongsTo
    {
        return $this->belongsTo(Merchant::class);
    }

    public function processor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'processed_by');
    }
}