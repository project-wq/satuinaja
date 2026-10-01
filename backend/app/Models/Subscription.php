<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Langganan aktif seller. 1 merchant bisa punya banyak baris (riwayat),
 * tapi hanya satu yang status=active.
 */
class Subscription extends Model
{
    use HasFactory;

    protected $fillable = [
        'merchant_id', 'plan_code', 'starts_at', 'ends_at',
        'payment_ref', 'status',
    ];

    protected $casts = [
        'starts_at' => 'datetime',
        'ends_at' => 'datetime',
    ];

    public function merchant(): BelongsTo
    {
        return $this->belongsTo(Merchant::class);
    }

    public function isActive(): bool
    {
        return in_array($this->status, ['active', 'trialing'], true)
            && ($this->ends_at === null || $this->ends_at->isFuture());
    }
}