<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

#[Fillable([
    'merchant_id', 'title', 'slug', 'description', 'price',
    'discount_price', 'stock', 'weight', 'images', 'status',
    'free_shipping', 'voucher_enabled', 'sold_count', 'rating_avg', 'rating_count',
])]
class Product extends Model
{
    /** @use HasFactory<\Database\Factories\ProductFactory> */
    use HasFactory, SoftDeletes;

    protected $casts = [
        'images' => 'array',
        'price' => 'integer',
        'discount_price' => 'integer',
        'stock' => 'integer',
        'weight' => 'integer',
        'free_shipping' => 'boolean',
        'voucher_enabled' => 'boolean',
        'sold_count' => 'integer',
        'rating_avg' => 'integer',
        'rating_count' => 'integer',
    ];

    protected static function booted(): void
    {
        // Tenant isolation: setiap query produk otomatis dibatasi merchant login.
        static::addGlobalScope('merchant', function (Builder $builder) {
            $merchantId = auth()->user()?->merchant?->id;
            if ($merchantId !== null) {
                $builder->where('products.merchant_id', $merchantId);
            }
        });
    }

    public function merchant(): BelongsTo
    {
        return $this->belongsTo(Merchant::class);
    }

    public function publishLogs(): HasMany
    {
        return $this->hasMany(PublishLog::class);
    }

    public function variants(): HasMany
    {
        return $this->hasMany(ProductVariant::class)->orderBy('position');
    }

    public function vouchers(): HasMany
    {
        return $this->hasMany(Voucher::class)->where('scope', 'product');
    }

    public function scopePublic(Builder $builder): Builder
    {
        return $builder->where('status', 'active');
    }

    /** Harga setelah diskon langsung (bukan voucher). */
    public function effectivePrice(): int
    {
        return $this->discount_price ?? $this->price;
    }
}
