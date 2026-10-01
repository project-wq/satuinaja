<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

/**
 * Paket langganan seller (free/pro/bisnis).
 */
class Plan extends Model
{
    use HasFactory;

    protected $fillable = [
        'code', 'name', 'price_monthly', 'max_products', 'max_channels',
        'max_publishes_monthly', 'ai_caption', 'auto_publish', 'active',
    ];

    protected $casts = [
        'price_monthly' => 'integer',
        'max_products' => 'integer',
        'max_channels' => 'integer',
        'max_publishes_monthly' => 'integer',
        'ai_caption' => 'boolean',
        'auto_publish' => 'boolean',
        'active' => 'boolean',
    ];

    /** Konstanta batas plan Free (tanpa perlu baris DB). */
    public const FREE_LIMITS = [
        'max_products' => 10,
        'max_channels' => 2,
        'max_publishes_monthly' => 30,
        'ai_caption' => false,
        'auto_publish' => false,
    ];
}