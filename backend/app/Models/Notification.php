<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Notifikasi in-app untuk merchant (fase 6).
 */
class Notification extends Model
{
    use HasFactory;

    protected $fillable = ['merchant_id', 'type', 'title', 'body', 'link', 'read_at'];

    protected $casts = ['read_at' => 'datetime'];

    public function merchant(): BelongsTo
    {
        return $this->belongsTo(Merchant::class);
    }
}
