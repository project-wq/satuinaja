<?php

namespace App\Services;

use App\Models\Channel;
use App\Models\Product;
use App\Models\PublishLog;
use Illuminate\Support\Facades\Log;

/**
 * Sinkronkan perubahan produk ke channel yang sudah aktif:
 *   - stok berkurang (checkout)   -> update stok di Facebook Page/Shopee
 *   - judul/harga/deskripsi berubah -> update produk di channel (Fase 10:
 *     deteksi perubahan via hash + anti-flap, republish kalau platform
 *     tidak punya endpoint update (IG/TikTok/FB tanpa catalog)).
 *
 * Hash disimpan di channels.product_hashes sebagai JSON:
 *   { "<product_id>": {"hash": "...", "updated_at": ..., "flap_at": ...} }
 * (awal: string md5; dibaca kompatibel dengan cast array — lihat
 *  SyncStock::changed()).
 */
class StockSyncService
{
    public function __construct(
        private ShopeeService $shopee,
    ) {
    }

    /**
     * Push perubahan ke channel.
     *
     * @param  bool  $includeContent  kirim juga judul/harga/deskripsi
     *                                (Shopee update_item menerima field ini).
     *                                Dipakai command untuk anti-flap: konten
     *                                ditahan bila masih dalam jendela edit.
     */
    public function sync(Product $product, bool $includeContent = true): array
    {
        $channels = Channel::where('merchant_id', $product->merchant_id)
            ->where('active', true)
            ->get();

        $results = [];
        foreach ($channels as $channel) {
            $results[$channel->platform] = match ($channel->platform) {
                'facebook' => $this->facebook($product, $channel),
                'shopee' => $this->shopee($product, $channel, $includeContent),
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

    private function shopee(Product $product, Channel $channel, bool $includeContent = true): array
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
            $itemId = $this->externalIdOf($product, $channel);
            if (! $itemId) {
                // Fallback untuk produk yang belum pernah dipublikasikan: Shopee menerima update_item
                // dengan item_id=0 (null → 0). Block per-model diabaikan saat belum ada pemetaan.
                $itemId = 0;
            }

            // Fase 12: produk bervarian yang sudah terpetakan → update_stock +
            // update_price per-model; bukan update_item agregat.
            $mapped = $product->variants()
                ->whereNotNull('shopee_model_id')
                ->orderBy('position')
                ->get();
            if ($itemId > 0 && $product->variants()->exists() && $mapped->isNotEmpty()) {
                $rows = $mapped->map(fn ($v) => [
                    'model_id' => $v->shopee_model_id,
                    'stock' => $v->stock,
                    'price' => $v->effectivePrice($product),
                ])->all();

                $stock = $this->shopee->pushVariantStock($channel, $itemId, $rows);
                if (! $stock['ok']) {
                    return $stock;
                }
                if (! $includeContent) {
                    return $stock;
                }

                return $this->shopee->pushVariantPrice($channel, $itemId, $rows);
            }

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
                // item_id asli dari log publish sukses (bukan product_hashes —
                // kolom itu menyimpan md5 perubahan, BUKAN external_id).
                'item_id' => $itemId,
                'stock' => (int) $product->stock,
            ] + ($includeContent ? [
                // Konten: judul/harga/deskripsi ikut terkirim (update_item
                // menerima field ini tanpa publish ulang).
                'original_price' => (float) $product->price,
                'item_name' => mb_substr($product->title, 0, 120),
                'description' => (string) ($product->description ?? $product->title),
            ] : []),
            );

            if (! $res->successful()) {
                return ['ok' => false, 'error' => data_get($res->json(), 'error', 'HTTP '.$res->status())];
            }

            return ['ok' => true, 'data' => $res->json()];
        } catch (\Throwable $e) {
            Log::warning('stocksync.shopee.failed', ['error' => $e->getMessage()]);

            return ['ok' => false, 'error' => $e->getMessage()];
        }
    }

    /** external_id item marketplace dari log publish sukses; 0 bila belum pernah publish. */
    private function externalIdOf(Product $product, Channel $channel): int
    {
        return (int) (PublishLog::where('product_id', $product->id)
            ->where('channel_id', $channel->id)
            ->where('status', 'success')
            ->value('external_id') ?? 0);
    }

    /**
     * Two-way sync (Fase 7): tarik stok asli dari marketplace → produk lokal.
     * Sumber stok: PublishLog.status=success (punya external_id saat publish).
     * Saat ini didukung Shopee (get_item_base_info).
     * Fase 12: produk bervarian → get_model_list, petakan per model_id/sku,
     * update stok tiap varian, lalu re-agregat products.stock.
     *
     * @return array<int, array>  hasil per produk
     */
    public function pull(Product $product): array
    {
        $channels = Channel::where('merchant_id', $product->merchant_id)
            ->where('active', true)
            ->get();

        $results = [];

        foreach ($channels as $channel) {
            if ($channel->platform !== 'shopee') {
                $results[$channel->platform] = [
                    'ok' => false, 'skipped' => true,
                    'error' => 'Platform ini belum mendukung tarik stok (pull).',
                ];
                continue;
            }

            // external_id dari log publish sukses (publish → channel).
            $externalId = PublishLog::where('product_id', $product->id)
                ->where('channel_id', $channel->id)
                ->where('status', 'success')
                ->value('external_id');

            if (! $externalId) {
                $results[$channel->platform] = [
                    'ok' => false,
                    'error' => 'Produk belum pernah dipublish ke channel ini (tak ada external_id).',
                ];
                continue;
            }

            // Fase 12: varian terpetakan → pull per-model.
            $mapped = $product->variants()
                ->whereNotNull('shopee_model_id')
                ->get();
            if ($product->variants()->exists() && $mapped->isNotEmpty()) {
                $results[$channel->platform] = $this->pullVariants($product, $channel, (int) $externalId, $mapped);
                continue;
            }

            $r = $this->shopee->pullStock($channel, (string) $externalId);
            if ($r['ok']) {
                $product->update(['stock' => (int) $r['stock']]);
            }
            $results[$channel->platform] = $r;
        }

        return $results;
    }

    /**
     * Fase 12: pull stok per-varian dari get_model_list.
     * Pemetaan: shopee_model_id dulu, fallback model_sku == varian.sku.
     *
     * @param  \Illuminate\Support\Collection<int, \App\Models\ProductVariant>  $mapped
     */
    private function pullVariants(Product $product, Channel $channel, int $itemId, $mapped): array
    {
        $r = $this->shopee->fetchModels($channel, $itemId);
        if (! $r['ok']) {
            return $r;
        }

        $byId = [];
        $bySku = [];
        foreach ((array) ($r['models'] ?? []) as $m) {
            $mid = (string) ($m['model_id'] ?? '');
            if ($mid !== '') {
                $byId[$mid] = $m;
            }
            $sku = (string) ($m['model_sku'] ?? '');
            if ($sku !== '') {
                $bySku[$sku] = $m;
            }
        }

        $updated = 0;
        foreach ($mapped as $v) {
            $m = $byId[(string) $v->shopee_model_id]
                ?? ($v->sku ? ($bySku[$v->sku] ?? null) : null);
            if (! $m) {
                continue;
            }
            $seller = data_get($m, 'stock_info_v2.seller_stock');
            $stock = is_array($seller) && $seller !== []
                ? array_sum(array_map(fn ($s) => (int) ($s['stock'] ?? 0), $seller))
                : (int) data_get($m, 'stock_info_v2.seller_stock.0.stock', $v->stock);
            $v->update(['stock' => max(0, $stock)]);
            $updated++;
        }

        $product->update(['stock' => (int) $product->variants()->sum('stock')]);

        return ['ok' => true, 'variants_updated' => $updated, 'total' => $mapped->count()];
    }
}