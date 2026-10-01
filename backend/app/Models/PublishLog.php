<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable([
    'product_id', 'channel_id', 'status', 'external_id',
    'external_url', 'error', 'meta', 'published_at',
])]
class PublishLog extends Model
{
    /** @use HasFactory<\Database\Factories\PublishLogFactory> */
    use HasFactory;

    protected $casts = [
        'published_at' => 'datetime',
        'meta' => 'array',
    ];

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }

    public function channel(): BelongsTo
    {
        return $this->belongsTo(Channel::class);
    }
}
