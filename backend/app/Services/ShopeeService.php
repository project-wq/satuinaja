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

    public function publish(Product $product, Channel $channel): array
    {
        $creds = (array) $channel->credentials;
        $missing = $this->missing($creds);
        if ($missing) {
            return ['ok' => false, 'error' => "Kredensial Shopee kurang: {$missing}."];
        }

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
                'image_id_list' => [], // Shopee minta upload gambar dulu via /media_space/upload_image
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

            return [
                'ok' => true,
                'external_id' => (string) $itemId,
                'external_url' => "https://shopee.co.id/product/{$creds['shop_id']}/{$itemId}",
            ];
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
