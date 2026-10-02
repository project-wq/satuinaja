<?php

namespace App\Services;

use App\Models\Merchant;
use App\Models\Notification;

/**
 * Notifikasi in-app merchant (fase 6).
 * Dipakai dari webhook, checkout, withdraw, refund, billing.
 */
class NotificationService
{
    /** Buat satu notifikasi untuk merchant. */
    public function push(Merchant|int $merchant, string $type, string $title, ?string $body = null, ?string $link = null): Notification
    {
        $merchantId = $merchant instanceof Merchant ? $merchant->id : $merchant;

        return Notification::create([
            'merchant_id' => $merchantId,
            'type' => $type,
            'title' => $title,
            'body' => $body,
            'link' => $link,
        ]);
    }

    /** Jumlah belum dibaca (untuk badge lonceng). */
    public function unreadCount(Merchant|int $merchant): int
    {
        $merchantId = $merchant instanceof Merchant ? $merchant->id : $merchant;

        return Notification::where('merchant_id', $merchantId)->whereNull('read_at')->count();
    }

    /** Tandai semua (atau satu) notifikasi sudah dibaca. */
    public function markRead(Merchant|int $merchant, ?int $id = null): int
    {
        $merchantId = $merchant instanceof Merchant ? $merchant->id : $merchant;

        $q = Notification::where('merchant_id', $merchantId)->whereNull('read_at');
        if ($id !== null) {
            $q->where('id', $id);
        }

        return $q->update(['read_at' => now()]);
    }
}
