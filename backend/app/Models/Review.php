<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Review extends Model
{
    protected $fillable = [
        'merchant_id', 'order_id', 'product_id',
        'rating', 'comment', 'reviewer_name',
        'seller_reply', 'replied_at', 'visible',
    ];

    protected function casts(): array
    {
        return ['rating' => 'integer', 'visible' => 'boolean', 'replied_at' => 'datetime'];
    }

    public function merchant()
    {
        return $this->belongsTo(Merchant::class);
    }

    public function order()
    {
        return $this->belongsTo(Order::class);
    }

    public function product()
    {
        return $this->belongsTo(Product::class);
    }
}
