<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

#[Fillable([
    'scope', 'merchant_id', 'product_id', 'code', 'name', 'type', 'value',
    'min_spend', 'max_discount', 'quota', 'used', 'max_per_buyer',
    'free_shipping', 'active', 'start_at', 'end_at',
])]
class Voucher extends Model
{
    protected $casts = [
        'min_spend' => 'integer',
        'max_discount' => 'integer',
        'quota' => 'integer',
        'used' => 'integer',
        'max_per_buyer' => 'integer',
        'free_shipping' => 'boolean',
        'active' => 'boolean',
        'start_at' => 'datetime',
        'end_at' => 'datetime',
    ];

    protected static function booted(): void
    {
        // Tenant isolation: seller melihat voucher miliknya saja.
        static::addGlobalScope('merchant', function (Builder $builder) {
            $merchantId = auth()->user()?->merchant?->id;
            $isAdmin = auth()->user()?->role === 'admin';
            if ($merchantId !== null && ! $isAdmin) {
                $builder->where('vouchers.merchant_id', $merchantId);
            }
        });
    }

    public function merchant(): BelongsTo
    {
        return $this->belongsTo(Merchant::class);
    }

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }

    public function redemptions(): HasMany
    {
        return $this->hasMany(VoucherRedemption::class);
    }

    /** Voucher berlaku sekarang (aktif + jendela waktu + kuota sisa). */
    public function isLive(): bool
    {
        if (! $this->active) {
            return false;
        }
        $now = now();
        if ($this->start_at && $now->lt($this->start_at)) {
            return false;
        }
        if ($this->end_at && $now->gt($this->end_at)) {
            return false;
        }

        return $this->quota === null || $this->used < $this->quota;
    }

    /**
     * Hitung diskon untuk subtotal tsb.
     *
     * @return array{discount:int, free_shipping:bool}
     */
    public function discountFor(int $subtotal): array
    {
        if ($subtotal < $this->min_spend) {
            return ['discount' => 0, 'free_shipping' => false];
        }

        $discount = $this->type === 'percent'
            ? (int) floor($subtotal * $this->value / 100)
            : $this->value;

        if ($this->max_discount !== null) {
            $discount = min($discount, $this->max_discount);
        }

        return [
            'discount' => max(0, min($discount, $subtotal)),
            'free_shipping' => $this->free_shipping,
        ];
    }
}
