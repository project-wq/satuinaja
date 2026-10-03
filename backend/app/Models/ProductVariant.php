<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable(['product_id', 'name', 'sku', 'price', 'stock', 'position'])]
class ProductVariant extends Model
{
    protected $casts = [
        'price' => 'integer',
        'stock' => 'integer',
        'position' => 'integer',
    ];

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }

    /** Harga efektif: varian punya harga sendiri → pakai; tidak → harga produk. */
    public function effectivePrice(Product $product): int
    {
        return $this->price ?? $product->price;
    }
}
