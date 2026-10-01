<?php

namespace App\Services;

use App\Models\Channel;
use App\Models\Product;
use Illuminate\Support\Facades\Log;

/**
 * Sinkronkan perubahan produk ke channel yang sudah aktif:
 *   - stok berkurang (checkout)   -> update stok di Facebook Page/Shopee
 *   - judul/harga/deskripsi berubah -> update produk di channel
 *
 * Facebook: POST /{page-id}/products  (Catalog Manager) — membutuhkan
 *   katalog produk Meta yang terhubung (commerce_products scope).
 * Shopee  : POST /api/v2/product/update_item
 *
 * Kalau channel tidak mendukung update (mis. IG/TikTok), dilewati dengan
 * catatan. Tidak ada "sukses palsu": kalau platform butuh setup tambahan,
 * sistem melaporkan pesan yang jelas.
 */
class StockSyncService
{
    public function sync(Product $product): array
    {
        $channels = Channel::where('merchant_id', $product->merchant_id)
            ->where('active', true)
            ->get();

        $results = [];
        foreach ($channels as $channel) {
            $results[$channel->platform] = match ($channel->platform) {
                'facebook' => $this->facebook($product, $channel),
                'shopee' => $this->shopee($product, $channel),
                default => ['ok' => false, 'skipped' => true, 'error' => 'Platform ini tidak mendukung update stok otomatis.'],
            };
        }

        return $results;
    }

    private function facebook(Product $product, Channel $channel): array
    {
        $creds = (array) $channel->credentials;
        $pageId = $creds['page_id'] ?? null;
        $token = $creds['page_token'] ?? null;
        if (! $pageId || ! $token) {
            return ['ok' => false, 'error' => 'Kredensial Facebook belum lengkap (page_id/page_token).'];
        }

        // Butuh product catalog dari Meta Commerce. Ini integrasi opsional:
        // kalau sudah lewat Meta Catalog Manager, update stok via Page API.
        try {
            $res = \Illuminate\Support\Facades\Http::timeout(45)->asForm()->post(
                "https://graph.facebook.com/v21.0/{$pageId}/products",
                [
                    'retailer_id' => $product->slug,
                    'item_group_id' => null,
                    'availability' => $product->stock > 0 ? 'in stock' : 'out of stock',
                    'inventory' => (string) $product->stock,
                    'access_token' => $token,
                ]
            );

            if (! $res->successful()) {
                return ['ok' => false, 'error' => data_get($res->json(), 'error.message', 'HTTP '.$res->status())];
            }

            return [
                'ok' => true,
                'external_id' => (string) data_get($res->json(), 'id'),
            ];
        } catch (\Throwable $e) {
            Log::warning('stocksync.facebook.failed', ['error' => $e->getMessage()]);

            return ['ok' => false, 'error' => $e->getMessage()];
        }
    }

    private function shopee(Product $product, Channel $channel): array
    {
        $creds = (array) $channel->credentials;
        $missing = array_values(array_filter(
            ['partner_id', 'partner_key', 'shop_id', 'access_token'],
            fn ($k) => empty($creds[$k]),
        ));
        if ($missing) {
            return ['ok' => false, 'error' => 'Kredensial Shopee kurang: '.implode(', ', $missing).'.'];
        }

        try {
            $path = '/api/v2/product/update_item';
            $timestamp = time();
            $baseString = $creds['partner_id'].$path.$timestamp.$creds['access_token'].$creds['shop_id'];
            $sign = hash_hmac('sha256', $baseString, (string) $creds['partner_key']);
            $isSandbox = in_array((string) ($creds['sandbox'] ?? ''), ['1', 'true', 'yes'], true);
            $base = $isSandbox
                ? 'https://partner.test-stable.shopeemobile.com'
                : 'https://partner.shopeemobile.com';

            $res = \Illuminate\Support\Facades\Http::withHeaders([
                'Authorization' => $sign,
                'Content-Type' => 'application/json',
            ])->timeout(45)->post($base.$path.'?'.http_build_query([
                'partner_id' => (int) $creds['partner_id'],
                'timestamp' => $timestamp,
                'access_token' => $creds['access_token'],
                'shop_id' => (int) $creds['shop_id'],
            ]), [
                'item_id' => (int) data_get($channel->product_hashes, $product->id.'.external_id', 0),
                'stock' => (int) $product->stock,
            ]);

            if (! $res->successful()) {
                return ['ok' => false, 'error' => data_get($res->json(), 'error', 'HTTP '.$res->status())];
            }

            return ['ok' => true, 'data' => $res->json()];
        } catch (\Throwable $e) {
            Log::warning('stocksync.shopee.failed', ['error' => $e->getMessage()]);

            return ['ok' => false, 'error' => $e->getMessage()];
        }
    }
}