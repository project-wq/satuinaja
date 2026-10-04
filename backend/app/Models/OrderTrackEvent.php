<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class OrderTrackEvent extends Model
{
    protected $fillable = ['order_id', 'status', 'message', 'occurred_at', 'provider'];

    protected function casts(): array
    {
        return ['occurred_at' => 'datetime'];
    }
}
