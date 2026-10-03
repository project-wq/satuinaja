<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable([
    'order_id', 'product_id', 'variant_id', 'title', 'price', 'discount_price',
    'qty', 'line_total', 'buyer_fee', 'buyer_admin_fee', 'seller_net',
])]
class OrderItem extends Model
{
    /** @use HasFactory<\Database\Factories\OrderItemFactory> */
    use HasFactory;

    protected $casts = [
        'price' => 'integer',
        'discount_price' => 'integer',
        'qty' => 'integer',
        'line_total' => 'integer',
        'buyer_fee' => 'integer',
        'buyer_admin_fee' => 'integer',
        'seller_net' => 'integer',
    ];

    public function order(): BelongsTo
    {
        return $this->belongsTo(Order::class);
    }

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }

    public function variant(): BelongsTo
    {
        return $this->belongsTo(ProductVariant::class, 'variant_id');
    }
}
