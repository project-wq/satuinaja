<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

#[Fillable([
    'merchant_id', 'channel_id', 'source', 'external_order_id', 'order_no',
    'buyer_name', 'buyer_phone', 'buyer_email',
    'shipping_address', 'destination_city_id', 'courier', 'service',
    'shipping_cost', 'subtotal', 'discount_total', 'subtotal_sale',
    'buyer_fee', 'buyer_admin_fee', 'seller_net', 'total', 'tracking_no',
    'payment_status', 'payment_ref', 'payment_url', 'fulfillment_status',
])]
class Order extends Model
{
    /** @use HasFactory<\Database\Factories\OrderFactory> */
    use HasFactory;

    protected $casts = [
        'shipping_cost' => 'integer',
        'subtotal' => 'integer',
        'discount_total' => 'integer',
        'subtotal_sale' => 'integer',
        'buyer_fee' => 'integer',
        'buyer_admin_fee' => 'integer',
        'seller_net' => 'integer',
        'total' => 'integer',
    ];

    protected static function booted(): void
    {
        static::addGlobalScope('merchant', function (Builder $builder) {
            $merchantId = auth()->user()?->merchant?->id;
            if ($merchantId !== null) {
                $builder->where('orders.merchant_id', $merchantId);
            }
        });
    }

    public function merchant(): BelongsTo
    {
        return $this->belongsTo(Merchant::class);
    }

    public function channel(): BelongsTo
    {
        return $this->belongsTo(Channel::class);
    }

    public function items(): HasMany
    {
        return $this->hasMany(OrderItem::class);
    }
}
