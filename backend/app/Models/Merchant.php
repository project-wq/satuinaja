<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

#[Fillable([
    'user_id', 'name', 'slug', 'logo_path', 'description',
    'phone', 'address', 'city_id', 'active',
    'province', 'city_name', 'district', 'postal_code', 'area_id',
    'kyc_status', 'kyc_nik', 'kyc_ktp_path',
    'kyc_submitted_at', 'kyc_reviewed_at', 'kyc_reject_reason',
    'plan_code', 'publishes_this_month', 'last_publish_reset_at', 'rating_avg', 'rating_count',
])]
class Merchant extends Model
{
    /** @use HasFactory<\Database\Factories\MerchantFactory> */
    use HasFactory, SoftDeletes;

    protected $casts = [
        'active' => 'boolean',
        'kyc_submitted_at' => 'datetime',
        'kyc_reviewed_at' => 'datetime',
    ];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function products(): HasMany
    {
        return $this->hasMany(Product::class);
    }

    public function channels(): HasMany
    {
        return $this->hasMany(Channel::class);
    }

    public function orders(): HasMany
    {
        return $this->hasMany(Order::class);
    }
}
