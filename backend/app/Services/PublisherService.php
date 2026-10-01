<?php

namespace App\Services;

use App\Http\Controllers\Api\V1\BillingController;
use App\Models\Channel;
use App\Models\Merchant;
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
        private InstagramService $instagram,
        private TikTokService $tiktok,
        private ShopeeService $shopee,
        private TokopediaService $tokopedia,
    ) {
    }

    /** Platform yang sudah punya implementasi. */
    public const SUPPORTED = ['facebook', 'instagram', 'tiktok', 'shopee', 'tokopedia'];

    public function publish(Product $product, Channel $channel): PublishLog
    {
        $log = PublishLog::firstOrCreate(
            ['product_id' => $product->id, 'channel_id' => $channel->id],
            ['status' => 'pending'],
        );

        if (! $channel->active) {
            $log->update(['status' => 'failed', 'error' => 'Channel tidak aktif.', 'meta' => null]);

            return $log;
        }

        // Batas plan: publish/bulan (referensi per merchant).
        $merchant = $channel->merchant()->first();
        if ($merchant) {
            $limits = BillingController::limits($merchant);
            if ($limits['max_publishes_monthly'] !== null
                && $merchant->publishes_this_month >= $limits['max_publishes_monthly']) {
                $log->update([
                    'status' => 'failed',
                    'error' => "Batas publish plan {$merchant->plan_code}: maksimal "
                        ."{$limits['max_publishes_monthly']} publish/bulan. Upgrade di halaman Langganan.",
                    'meta' => null,
                ]);

                return $log;
            }
        }

        $result = match ($channel->platform) {
            'facebook' => $this->facebook->publish($product, $channel),
            'instagram' => $this->instagram->publish($product, $channel),
            'tiktok' => $this->tiktok->publish($product, $channel),
            'shopee' => $this->shopee->publish($product, $channel),
            'tokopedia' => $this->tokopedia->publish($product, $channel),
            default => ['ok' => false, 'error' => "Integrasi {$channel->platform} belum tersedia."],
        };

        // Beberapa platform mengembalikan payload/caption siap-pakai saat gagal
        // (mis. TikTok butuh video, Tokopedia butuh kemitraan). Simpan itu.
        $meta = [];
        if (! empty($result['payload'])) {
            $meta['payload'] = $result['payload'];
        }
        if (! empty($result['caption'])) {
            $meta['caption'] = $result['caption'];
        }

        if ($result['ok']) {
            if ($merchant) {
                $merchant->increment('publishes_this_month');
            }
            $log->update([
                'status' => 'success',
                'external_id' => $result['external_id'] ?? null,
                'external_url' => $result['external_url'] ?? null,
                'error' => null,
                'meta' => $meta ?: null,
                'published_at' => now(),
            ]);
        } else {
            $log->update([
                'status' => 'failed',
                'error' => mb_substr((string) ($result['error'] ?? 'Unknown error'), 0, 1000),
                'meta' => $meta ?: null,
            ]);
        }

        $channel->update([
            'last_sync_at' => now(),
            'last_error' => $result['ok'] ? null : mb_substr((string) $log->error, 0, 250),
        ]);

        return $log->fresh();
    }

    /**
     * Uji koneksi kredensial channel tanpa publish produk.
     */
    public function verify(Channel $channel): array
    {
        return match ($channel->platform) {
            'facebook' => $this->facebook->verify($channel),
            'instagram' => $this->instagram->verify($channel),
            'tiktok' => $this->tiktok->verify($channel),
            'shopee' => $this->shopee->verify($channel),
            'tokopedia' => $this->tokopedia->verify($channel),
            default => ['ok' => false, 'error' => "Verifikasi {$channel->platform} belum tersedia."],
        };
    }
}
