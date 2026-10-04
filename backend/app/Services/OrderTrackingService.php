<?php

namespace App\Services;

use App\Models\Order;
use App\Models\OrderTrackEvent;
use Illuminate\Support\Facades\Log;

/**
 * Fase 17: tracking otomatis.
 *
 * Tarik riwayat kiriman dari Biteship untuk order yang punya
 * biteship_order_id, simpan jadi event (timeline), dan otomatis
 * majukan status fulfillment (shipped → delivered) saat kurir
 * menandai paket diterima.
 */
class OrderTrackingService
{
    public function __construct(
        private BiteshipService $biteship,
        private NotificationService $notif,
    ) {
    }

    /**
     * Sinkronkan 1 order. Return true bila ada perubahan baru.
     */
    public function sync(Order $order): bool
    {
        if (! $order->biteship_order_id || ! $this->biteship->enabled()) {
            return false;
        }

        $res = $this->biteship->track($order->biteship_order_id);
        $order->update(['track_synced_at' => now()]);

        if (! $res['ok']) {
            Log::warning('biteship.track.failed', ['order' => $order->order_no, 'error' => $res['error']]);

            return false;
        }

        $changed = false;
        $status = (string) data_get($res, 'data.status', '');

        // Majukan status fulfillment otomatis bila kurir sudah konfirmasi.
        if ($status === 'delivered'
            && in_array($order->fulfillment_status, ['shipped', 'packed'], true)) {
            $order->update(['fulfillment_status' => 'delivered', 'delivered_at' => now()]);
            $this->notif->push(
                $order->merchant_id,
                'order.delivered',
                'Paket diterima pembeli',
                "Order {$order->order_no} sudah diterima (resi {$order->tracking_no}).",
                '/seller/orders',
            );
            $changed = true;
        }

        // Simpan riwayat event unik.
        foreach ((array) data_get($res, 'data.history', []) as $h) {
            $message = mb_substr((string) ($h['desc'] ?? ''), 0, 255);
            if ($message === '') {
                continue;
            }
            $exists = OrderTrackEvent::where('order_id', $order->id)
                ->where('status', (string) ($h['status'] ?? 'unknown'))
                ->where('message', $message)
                ->exists();
            if ($exists) {
                continue;
            }
            OrderTrackEvent::create([
                'order_id' => $order->id,
                'status' => (string) ($h['status'] ?? 'unknown'),
                'message' => $message,
                'occurred_at' => $h['date'] ?? null,
                'provider' => 'biteship',
            ]);
            $changed = true;
        }

        return $changed;
    }

    /**
     * Poll banyak order (dipakai command biteship:track).
     * Return jumlah order yang berubah.
     */
    public function syncPending(int $limit = 50): int
    {
        $changed = 0;
        Order::withoutGlobalScope('merchant')
            ->whereNotNull('biteship_order_id')
            ->whereIn('fulfillment_status', ['shipped', 'delivered'])
            ->where(fn ($q) => $q->whereNull('track_synced_at')->orWhere('track_synced_at', '<', now()->subMinutes(10)))
            ->orderBy('track_synced_at')
            ->limit($limit)
            ->get()
            .each(function (Order $o) use (&$changed) {
                if ($this->sync($o)) {
                    $changed++;
                }
            });

        return $changed;
    }

    /** Timeline event terurut lama → baru. */
    public function history(Order $order): array
    {
        return OrderTrackEvent::where('order_id', $order->id)
            ->orderByDesc('occurred_at')
            ->orderByDesc('id')
            ->get()
            ->map(fn ($e) => [
                'status' => $e->status,
                'message' => $e->message,
                'occurred_at' => $e->occurred_at?->toIso8601String(),
            ])
            ->all();
    }
}
