<?php

namespace App\Services;

use App\Models\Channel;
use App\Models\Product;
use App\Models\PublishLog;

/**
 * Dispatcher publish: pilih service sesuai platform channel.
 * Berjalan di queue (job) supaya request cepat.
 */
class PublisherService
{
    public function __construct(
        private FacebookService $facebook,
    ) {
    }

    public function publish(Product $product, Channel $channel): PublishLog
    {
        $log = PublishLog::firstOrCreate(
            ['product_id' => $product->id, 'channel_id' => $channel->id],
            ['status' => 'pending'],
        );

        if (! $channel->active) {
            $log->update(['status' => 'failed', 'error' => 'Channel tidak aktif.']);

            return $log;
        }

        $result = match ($channel->platform) {
            'facebook', 'instagram' => $this->facebook->publish($product, $channel),
            default => ['ok' => false, 'error' => "Integrasi {$channel->platform} belum tersedia."],
        };

        if ($result['ok']) {
            $log->update([
                'status' => 'success',
                'external_id' => $result['external_id'] ?? null,
                'external_url' => $result['external_url'] ?? null,
                'error' => null,
                'published_at' => now(),
            ]);
        } else {
            $log->update([
                'status' => 'failed',
                'error' => mb_substr((string) ($result['error'] ?? 'Unknown error'), 0, 1000),
            ]);
        }

        $channel->update(['last_sync_at' => now(), 'last_error' => $result['ok'] ? null : $log->error]);

        return $log->fresh();
    }
}