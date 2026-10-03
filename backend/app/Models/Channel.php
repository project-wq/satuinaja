<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

#[Fillable([
    'merchant_id', 'platform', 'label', 'credentials', 'active',
    'last_sync_at', 'last_error', 'product_hashes',
])]
#[Hidden(['credentials'])]
class Channel extends Model
{
    /** @use HasFactory<\Database\Factories\ChannelFactory> */
    use HasFactory;

    public const PLATFORMS = ['facebook', 'instagram', 'tiktok', 'shopee', 'tokopedia'];

    protected $casts = [
        // Token/API key seller: terenkripsi AES-256 di DB, tidak pernah ke response.
        'credentials' => 'encrypted:array',
        'active' => 'boolean',
        'last_sync_at' => 'datetime',
        'product_hashes' => 'array',
    ];

    protected static function booted(): void
    {
        static::addGlobalScope('merchant', function (Builder $builder) {
            $merchantId = auth()->user()?->merchant?->id;
            if ($merchantId !== null) {
                $builder->where('channels.merchant_id', $merchantId);
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
}
