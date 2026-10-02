<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Saldo terkini seller. Source of truth = balance_transactions (ledger);
 * kolom balance/held hanya denormalisasi agar cepat dibaca.
 * balance = saldo siap cair; held = ditahan saat withdraw pending.
 */
class SellerBalance extends Model
{
    protected $fillable = ['merchant_id', 'balance', 'held'];

    protected $casts = [
        'balance' => 'integer',
        'held' => 'integer',
    ];

    public function merchant(): BelongsTo
    {
        return $this->belongsTo(Merchant::class);
    }

    public function transactions(): HasMany
    {
        return $this->hasMany(BalanceTransaction::class, 'merchant_id');
    }
}