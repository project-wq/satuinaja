<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Cache;

/**
 * Pengaturan global (key/value). Dipakai untuk konfigurasi fee.
 * Kunci: fee_buyer_percent, admin_fee_per_item, seller_fee_per_item.
 */
class Setting extends Model
{
    protected $fillable = ['key', 'value'];

    /** Baca nilai setting sebagai string (fallback ke default). */
    public static function get(string $key, string $default = ''): string
    {
        return Cache::remember("setting.{$key}", 60, function () use ($key, $default) {
            return static::where('key', $key)->value('value') ?? $default;
        });
    }

    /** Tulis/ubah nilai setting + bersihkan cache. */
    public static function set(string $key, string $value): void
    {
        static::updateOrCreate(['key' => $key], ['value' => $value]);
        Cache::forget("setting.{$key}");
    }
}