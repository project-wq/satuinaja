<?php

namespace App\Services;

use App\Models\Channel;
use App\Models\Product;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * Shopee Open Platform API v2.
 *
 * Signature resmi Shopee:
 *   base_string = partner_id + api_path + timestamp + access_token + shop_id
 *   sign        = HMAC-SHA256(base_string, partner_key)   (hex lowercase)
 *
 * Header wajib:
 *   Authorization: <sign>            ← hanya nilai sign, TANPA "Bearer"
 *   Content-Type:  application/json
 *
 * Kredensial seller:
 *   partner_id    — Partner ID dari Shopee Open Platform
 *   partner_key   — Partner Key (rahasia)
 *   shop_id       — Shop ID toko
 *   access_token  — access token hasil OAuth per toko
 *   sandbox       — "1" untuk test mode (partner.test-stable.shopeemobile.com)
 *
 * Endpoint produk: POST /api/v2/product/add_item
 */
class ShopeeService
{
    private const PATH_ADD_ITEM = '/api/v2/product/add_item';
    private const PATH_SHOP_INFO = '/api/v2/shop/get_shop_info';
    private const PATH_UPLOAD_IMAGE = '/api/v2/media_space/upload_image';
    private const PATH_INIT_TIER = '/api/v2/product/init_tier_variation';
    private const PATH_UPDATE_STOCK = '/api/v2/product/update_stock';
    private const PATH_UPDATE_PRICE = '/api/v2/product/update_price';
    private const PATH_GET_MODEL_LIST = '/api/v2/product/get_model_list';

    public function publish(Product $product, Channel $channel): array
    {
        $creds = (array) $channel->credentials;
        $missing = $this->missing($creds);
        if ($missing) {
            return ['ok' => false, 'error' => "Kredensial Shopee kurang: {$missing}."];
        }

        $imageIdList = $this->uploadImages($product, $channel);

        $body = [
            'original_price' => (float) $product->price,
            'description' => (string) ($product->description ?? $product->title),
            'item_name' => mb_substr("{$product->title} — {$product->slug}", 0, 120),
            'weight' => (float) $product->weight / 1000, // Shopee pakai kg
            'item_status' => 'NORMAL',
            'dimension' => [
                'package_length' => 10,
                'package_width' => 10,
                'package_height' => 10,
            ],
            'logistic_info' => [],
            'image' => [
                'image_id_list' => $imageIdList,
            ],
        ];

        try {
            $path = self::PATH_ADD_ITEM;
            $timestamp = time();
            $sign = $this->sign($creds, $path, $timestamp);

            $res = Http::withHeaders([
                'Authorization' => $sign,
                'Content-Type' => 'application/json',
            ])
                ->timeout(45)
                ->post($this->baseUrl($creds).$path.'?'.http_build_query([
                    'partner_id' => (int) $creds['partner_id'],
                    'timestamp' => $timestamp,
                    'access_token' => $creds['access_token'],
                    'shop_id' => (int) $creds['shop_id'],
                ]), $body);

            if (! $res->successful()) {
                return ['ok' => false, 'error' => $this->errorOf($res->json(), $res->status())];
            }

            $itemId = data_get($res->json(), 'response.item_id');
            if (! $itemId) {
                return ['ok' => false, 'error' => $this->errorOf($res->json(), $res->status() ?: 200)];
            }

            // Fase 12: produk bervarian -> inisialisasi tier Shopee + petakan model_id.
            $variantInfo = $this->pushVariants($product, $channel, (int) $itemId);

            return [
                'ok' => true,
                'external_id' => (string) $itemId,
                'external_url' => "https://shopee.co.id/product/{$creds['shop_id']}/{$itemId}",
            ] + ($variantInfo ? ['variant' => $variantInfo] : []);
        } catch (\Throwable $e) {
            Log::warning('shopee.publish.failed', ['error' => $e->getMessage()]);

            return ['ok' => false, 'error' => $e->getMessage()];
        }
    }

    public function verify(Channel $channel): array
    {
        $creds = (array) $channel->credentials;
        $missing = $this->missing($creds);
        if ($missing) {
            return ['ok' => false, 'error' => "Kredensial Shopee kurang: {$missing}."];
        }

        try {
            $path = self::PATH_SHOP_INFO;
            $timestamp = time();
            $sign = $this->sign($creds, $path, $timestamp);

            $res = Http::withHeaders(['Authorization' => $sign])
                ->timeout(20)
                ->get($this->baseUrl($creds).$path.'?'.http_build_query([
                    'partner_id' => (int) $creds['partner_id'],
                    'timestamp' => $timestamp,
                    'access_token' => $creds['access_token'],
                    'shop_id' => (int) $creds['shop_id'],
                ]));

            if (! $res->successful()) {
                return ['ok' => false, 'error' => $this->errorOf($res->json(), $res->status())];
            }

            return ['ok' => true, 'data' => data_get($res->json(), 'response', [])];
        } catch (\Throwable $e) {
            return ['ok' => false, 'error' => $e->getMessage()];
        }
    }

    /**
     * HMAC-SHA256 sesuai dokumentasi Shopee Open Platform v2.
     */
    private function sign(array $creds, string $path, int $timestamp): string
    {
        $baseString = $creds['partner_id'].$path.$timestamp.$creds['access_token'].$creds['shop_id'];

        return hash_hmac('sha256', $baseString, (string) $creds['partner_key']);
    }

    /**
     * Two-way sync (Fase 7): tarik stok asli dari Shopee untuk 1 item.
     * Shopee: POST /api/v2/product/get_item_base_info → response.stock_info_v2
     * (atau response.stock_info.stock untuk item single-stock).
     */
    public function pullStock(Channel $channel, string $externalId): array
    {
        $creds = (array) $channel->credentials;
        $missing = $this->missing($creds);
        if ($missing) {
            return ['ok' => false, 'error' => "Kredensial Shopee kurang: {$missing}."];
        }

        try {
            $path = '/api/v2/product/get_item_base_info';
            $timestamp = time();
            $sign = $this->sign($creds, $path, $timestamp);

            $res = Http::withHeaders([
                'Authorization' => $sign,
                'Content-Type' => 'application/json',
            ])->timeout(30)->post($this->baseUrl($creds).$path.'?'.http_build_query([
                'partner_id' => (int) $creds['partner_id'],
                'timestamp' => $timestamp,
                'access_token' => $creds['access_token'],
                'shop_id' => (int) $creds['shop_id'],
            ]), [
                'item_id_list' => [(int) $externalId],
            ]);

            if (! $res->successful()) {
                return ['ok' => false, 'error' => $this->errorOf($res->json(), $res->status())];
            }

            $item = data_get($res->json(), 'response.item_list.0');
            if (! $item) {
                return ['ok' => false, 'error' => $this->errorOf($res->json(), 200)];
            }

            // Item multi-varian: stok ada di stock_info_v2.seller_stock[]; item tunggal: stock_info.stock.
            $stock = null;
            $v2 = data_get($item, 'stock_info_v2.seller_stock');
            if (is_array($v2) && $v2 !== []) {
                $stock = array_sum(array_map(fn ($s) => (int) ($s['stock'] ?? 0), $v2));
            } elseif (data_get($item, 'stock_info_v2.seller_stock.0.stock') !== null) {
                $stock = (int) data_get($item, 'stock_info_v2.seller_stock.0.stock');
            } elseif (data_get($item, 'stock_info.stock') !== null) {
                $stock = (int) data_get($item, 'stock_info.stock');
            }

            if ($stock === null) {
                return ['ok' => false, 'error' => 'Shopee tidak mengembalikan stok untuk item tsb.'];
            }

            return [
                'ok' => true,
                'stock' => max(0, $stock),
                'status' => data_get($item, 'item_status'),
            ];
        } catch (\Throwable $e) {
            Log::warning('shopee.pull_stock.failed', ['error' => $e->getMessage()]);

            return ['ok' => false, 'error' => $e->getMessage()];
        }
    }

    /**
     * Upload gambar produk ke Shopee media space → daftar image_id.
     * Shopee /media_space/upload_image menerima URL publik via query `url`;
     * response: response.image_info.image_id. Gagal upload 1 gambar tidak
     * menggagalkan publish (item tayang tanpa foto tsb).
     */
    private function uploadImages(Product $product, Channel $channel): array
    {
        $images = array_values(array_filter((array) $product->images, fn ($u) => is_string($u) && $u !== ''));
        if ($images === []) {
            return [];
        }

        $creds = (array) $channel->credentials;
        $ids = [];

        foreach (array_slice($images, 0, 9) as $url) {
            try {
                $path = self::PATH_UPLOAD_IMAGE;
                $timestamp = time();
                $sign = $this->sign($creds, $path, $timestamp);

                $res = Http::withHeaders([
                    'Authorization' => $sign,
                ])->timeout(45)->get($this->baseUrl($creds).$path.'?'.http_build_query([
                    'partner_id' => (int) $creds['partner_id'],
                    'timestamp' => $timestamp,
                    'access_token' => $creds['access_token'],
                    'shop_id' => (int) $creds['shop_id'],
                    'url' => $url,
                ]));

                if (! $res->successful()) {
                    continue;
                }

                $imageId = data_get($res->json(), 'response.image_info.image_id');
                if ($imageId) {
                    $ids[] = (string) $imageId;
                }
            } catch (\Throwable $e) {
                Log::warning('shopee.upload_image.failed', ['url' => mb_substr($url, 0, 120)]);
            }
        }

        return $ids;
    }

    /**
     * Fase 12: inisialisasi tier Shopee setelah add_item (produk bervarian).
     *
     * Alur resmi Shopee: item dulu (add_item) → tunggu ≥5 detik → init model.
     * Struktur 1 tier "Varian" (opsi = nama varian lokal); tiap model bawa
     * tier_index [i], original_price efektif (float), seller_stock, model_sku.
     * respons.response.model[] berisi {model_id, tier_index} → disimpan ke
     * product_variants.shopee_model_id untuk update_stock/update_price/pull.
     *
     * @return array|null  ['mapped' => n] bila sukses; warning di Log bila gagal.
     */
    private function pushVariants(Product $product, Channel $channel, int $itemId): ?array
    {
        $variants = $product->variants()->orderBy('position')->get();
        if ($variants->isEmpty()) {
            return null;
        }

        $creds = (array) $channel->credentials;
        $models = [];
        foreach ($variants->values() as $i => $v) {
            $models[] = [
                'tier_index' => [$i],
                'original_price' => (float) $v->effectivePrice($product),
                'seller_stock' => [['stock' => (int) $v->stock]],
            ] + ($v->sku ? ['model_sku' => mb_substr($v->sku, 0, 100)] : []);
        }

        try {
            $path = self::PATH_INIT_TIER;
            $timestamp = time();
            $sign = $this->sign($creds, $path, $timestamp);

            $res = Http::withHeaders([
                'Authorization' => $sign,
                'Content-Type' => 'application/json',
            ])->timeout(45)->post($this->baseUrl($creds).$path.'?'.http_build_query([
                'partner_id' => (int) $creds['partner_id'],
                'timestamp' => $timestamp,
                'access_token' => $creds['access_token'],
                'shop_id' => (int) $creds['shop_id'],
            ]), [
                'item_id' => $itemId,
                'tier_variation' => [[
                    'name' => 'Varian',
                    'option_list' => $variants->values()->map(fn ($v) => ['option' => mb_substr($v->name, 0, 20)])->all(),
                ]],
                'model' => $models,
            ]);

            if (! $res->successful()) {
                Log::warning('shopee.init_tier.failed', ['item_id' => $itemId, 'error' => $this->errorOf($res->json(), $res->status())]);

                return null;
            }

            $mapped = 0;
            foreach ((array) data_get($res->json(), 'response.model', []) as $m) {
                $idx = data_get($m, 'tier_index.0');
                $mid = data_get($m, 'model_id');
                if ($idx === null || ! $mid) {
                    continue;
                }
                $local = $variants->values()->get((int) $idx);
                if ($local) {
                    $local->update(['shopee_model_id' => (string) $mid]);
                    $mapped++;
                }
            }

            return ['mapped' => $mapped, 'total' => $variants->count()];
        } catch (\Throwable $e) {
            Log::warning('shopee.init_tier.failed', ['item_id' => $itemId, 'error' => $e->getMessage()]);

            return null;
        }
    }

    /**
     * Fase 12: update stok per-model (update_stock: stock_list [{model_id, seller_stock[]}])
     * + harga per-model (update_price: price_list [{model_id, original_price}]).
     * Dipakai StockSyncService untuk produk bervarian yang sudah terpetakan.
     */
    public function pushVariantStock(Channel $channel, int $itemId, array $rows): array
    {
        $creds = (array) $channel->credentials;

        try {
            $stockList = [];
            foreach ($rows as $r) {
                if (empty($r['model_id'])) {
                    continue;
                }
                $stockList[] = [
                    'model_id' => (int) $r['model_id'],
                    'seller_stock' => [['stock' => max(0, (int) $r['stock'])]],
                ];
            }
            if ($stockList === []) {
                return ['ok' => false, 'error' => 'Tak ada model Shopee terpetakan untuk produk ini.'];
            }

            $path = self::PATH_UPDATE_STOCK;
            $timestamp = time();
            $sign = $this->sign($creds, $path, $timestamp);

            $res = Http::withHeaders([
                'Authorization' => $sign,
                'Content-Type' => 'application/json',
            ])->timeout(45)->post($this->baseUrl($creds).$path.'?'.http_build_query([
                'partner_id' => (int) $creds['partner_id'],
                'timestamp' => $timestamp,
                'access_token' => $creds['access_token'],
                'shop_id' => (int) $creds['shop_id'],
            ]), [
                'item_id' => $itemId,
                'stock_list' => $stockList,
            ]);

            if (! $res->successful()) {
                return ['ok' => false, 'error' => $this->errorOf($res->json(), $res->status())];
            }

            $failed = (array) data_get($res->json(), 'response.failure_list', []);
            if ($failed !== []) {
                return ['ok' => false, 'error' => 'Shopee menolak '.count($failed).' model: '.mb_substr(json_encode($failed), 0, 300)];
            }

            return ['ok' => true, 'data' => $res->json()];
        } catch (\Throwable $e) {
            Log::warning('shopee.update_stock.failed', ['item_id' => $itemId, 'error' => $e->getMessage()]);

            return ['ok' => false, 'error' => $e->getMessage()];
        }
    }

    public function pushVariantPrice(Channel $channel, int $itemId, array $rows): array
    {
        $creds = (array) $channel->credentials;

        try {
            $priceList = [];
            foreach ($rows as $r) {
                if (empty($r['model_id'])) {
                    continue;
                }
                $priceList[] = [
                    'model_id' => (int) $r['model_id'],
                    'original_price' => (float) $r['price'],
                ];
            }
            if ($priceList === []) {
                return ['ok' => false, 'error' => 'Tak ada model Shopee terpetakan untuk produk ini.'];
            }

            $path = self::PATH_UPDATE_PRICE;
            $timestamp = time();
            $sign = $this->sign($creds, $path, $timestamp);

            $res = Http::withHeaders([
                'Authorization' => $sign,
                'Content-Type' => 'application/json',
            ])->timeout(45)->post($this->baseUrl($creds).$path.'?'.http_build_query([
                'partner_id' => (int) $creds['partner_id'],
                'timestamp' => $timestamp,
                'access_token' => $creds['access_token'],
                'shop_id' => (int) $creds['shop_id'],
            ]), [
                'item_id' => $itemId,
                'price_list' => $priceList,
            ]);

            if (! $res->successful()) {
                return ['ok' => false, 'error' => $this->errorOf($res->json(), $res->status())];
            }

            $failed = (array) data_get($res->json(), 'response.failure_list', []);
            if ($failed !== []) {
                return ['ok' => false, 'error' => 'Shopee menolak '.count($failed).' model: '.mb_substr(json_encode($failed), 0, 300)];
            }

            return ['ok' => true, 'data' => $res->json()];
        } catch (\Throwable $e) {
            Log::warning('shopee.update_price.failed', ['item_id' => $itemId, 'error' => $e->getMessage()]);

            return ['ok' => false, 'error' => $e->getMessage()];
        }
    }

    /**
     * Fase 12: daftar model Shopee (get_model_list): {model_id, tier_index,
     * model_sku, stock_info_v2.seller_stock[], price_info[].original_price}.
     * Pemetaan balik dilakukan pemanggil via model_id/model_sku.
     */
    public function fetchModels(Channel $channel, int $itemId): array
    {
        $creds = (array) $channel->credentials;
        $missing = $this->missing($creds);
        if ($missing) {
            return ['ok' => false, 'error' => "Kredensial Shopee kurang: {$missing}."];
        }

        try {
            $path = self::PATH_GET_MODEL_LIST;
            $timestamp = time();
            $sign = $this->sign($creds, $path, $timestamp);

            $res = Http::withHeaders(['Authorization' => $sign])
                ->timeout(30)->get($this->baseUrl($creds).$path.'?'.http_build_query([
                    'partner_id' => (int) $creds['partner_id'],
                    'timestamp' => $timestamp,
                    'access_token' => $creds['access_token'],
                    'shop_id' => (int) $creds['shop_id'],
                    'item_id' => $itemId,
                ]));

            if (! $res->successful()) {
                return ['ok' => false, 'error' => $this->errorOf($res->json(), $res->status())];
            }

            return [
                'ok' => true,
                'tier_variation' => data_get($res->json(), 'response.tier_variation', []),
                'models' => data_get($res->json(), 'response.model', []),
            ];
        } catch (\Throwable $e) {
            Log::warning('shopee.get_model_list.failed', ['item_id' => $itemId, 'error' => $e->getMessage()]);

            return ['ok' => false, 'error' => $e->getMessage()];
        }
    }

    private function baseUrl(array $creds): string
    {
        $sandbox = in_array((string) ($creds['sandbox'] ?? ''), ['1', 'true', 'yes'], true);

        return $sandbox
            ? 'https://partner.test-stable.shopeemobile.com'
            : 'https://partner.shopeemobile.com';
    }

    private function missing(array $creds): ?string
    {
        $need = ['partner_id', 'partner_key', 'shop_id', 'access_token'];
        $missing = array_values(array_filter($need, fn ($k) => empty($creds[$k])));

        return $missing ? implode(', ', $missing) : null;
    }

    private function errorOf(mixed $json, int $status): string
    {
        $msg = data_get($json, 'error')
            ?? data_get($json, 'message')
            ?? data_get($json, 'error_msg')
            ?? 'HTTP '.$status;
        $code = data_get($json, 'code') ?? data_get($json, 'error_code');

        return trim((string) $msg.($code ? " (code {$code})" : ''));
    }
}
