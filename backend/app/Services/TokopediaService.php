<?php

namespace App\Services;

use App\Models\Channel;
use App\Models\Product;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * Tokopedia Publisher.
 *
 * PENTING — keterbatasan nyata platform (bukan bug aplikasi ini):
 * Tokopedia **tidak menyediakan API produk self-serve untuk seller umum**.
 * Akses otomatis hanya lewat kemitraan resmi (Tokopedia Partner / "Tokopedia
 * for Business" / program mitra logistik). Karena itu, service ini:
 *
 *   1. Jika seller mengisi `api_base` + `endpoint` + `client_secret` (dari
 *      kemitraan resmi mereka) → kami kirim request ke endpoint tsb.
 *   2. Jika tidak → kami kembalikan pesan jelas bahwa akses partner diperlukan,
 *      plus payload produk yang siap ditempel di dashboard Tokopedia seller.
 *
 * Jadi: tidak ada URL/endpoint karangan. Kalau kredensial kemitraan tidak ada,
 * sistem bilang jujur, bukan pura-pura sukses.
 *
 * Kredensial seller:
 *   api_base        — base URL endpoint mitra Tokopedia (dari kontrak)
 *   endpoint        — path tambah produk (mis. /v1/products)
 *   client_id       — Client ID mitra
 *   client_secret   — Client Secret mitra
 *   fs_id           — FS ID (ID toko seller)
 */
class TokopediaService
{
    public function publish(Product $product, Channel $channel): array
    {
        $creds = (array) $channel->credentials;
        $payload = $this->payload($product, $creds);

        // Mode 1: kemitraan resmi — kirim ke endpoint yang seller konfigurasi.
        if (! empty($creds['api_base']) && ! empty($creds['endpoint'])) {
            try {
                $res = Http::withHeaders(array_filter([
                    'Client-Id' => $creds['client_id'] ?? null,
                    'Authorization' => isset($creds['client_secret'])
                        ? 'Bearer '.$creds['client_secret']
                        : null,
                ]))
                    ->timeout(45)
                    ->acceptJson()
                    ->post(rtrim((string) $creds['api_base'], '/').'/'.ltrim((string) $creds['endpoint'], '/'), $payload);

                if (! $res->successful()) {
                    return ['ok' => false, 'error' => 'Tokopedia HTTP '.$res->status().': '.mb_substr($res->body(), 0, 200)];
                }

                $body = $res->json();
                $id = data_get($body, 'data.id') ?? data_get($body, 'id');

                return [
                    'ok' => true,
                    'external_id' => $id ? (string) $id : null,
                    'external_url' => data_get($body, 'data.url'),
                ];
            } catch (\Throwable $e) {
                Log::warning('tokopedia.publish.failed', ['error' => $e->getMessage()]);

                return ['ok' => false, 'error' => $e->getMessage()];
            }
        }

        // Mode 2: belum ada akses kemitraan → beri payload siap-tempel + alasan jelas.
        return [
            'ok' => false,
            'error' => 'Akses API Tokopedia memerlukan kemitraan resmi (Tokopedia Partner). '
                .'Isi "api_base" + "endpoint" + "client_id" + "client_secret" bila sudah punya. '
                .'Sementara itu pakai payload siap-tempel di bawah untuk upload manual.',
            'payload' => $payload,
        ];
    }

    public function verify(Channel $channel): array
    {
        $creds = (array) $channel->credentials;

        if (empty($creds['api_base'])) {
            return [
                'ok' => false,
                'error' => 'Belum ada api_base mitra Tokopedia. Akses API hanya via kemitraan resmi.',
            ];
        }

        try {
            $res = Http::withHeaders(array_filter([
                'Client-Id' => $creds['client_id'] ?? null,
                'Authorization' => isset($creds['client_secret']) ? 'Bearer '.$creds['client_secret'] : null,
            ]))
                ->timeout(20)
                ->get(rtrim((string) $creds['api_base'], '/'));

            return $res->successful()
                ? ['ok' => true, 'data' => ['status' => $res->status()]]
                : ['ok' => false, 'error' => 'HTTP '.$res->status()];
        } catch (\Throwable $e) {
            return ['ok' => false, 'error' => $e->getMessage()];
        }
    }

    /**
     * Payload produk dalam bentuk yang bisa langsung ditempel / dikirim.
     */
    private function payload(Product $product, array $creds): array
    {
        return [
            'fs_id' => $creds['fs_id'] ?? null,
            'name' => mb_substr($product->title, 0, 70),
            'description' => (string) ($product->description ?? $product->title),
            'price' => [
                'value' => (int) $product->price,
                'currency' => 'IDR',
            ],
            'stock' => [
                'value' => (int) $product->stock,
                'use_stock' => true,
            ],
            'weight' => (int) $product->weight,
            'weight_unit' => 'GR',
            'condition' => 'NEW',
            'status' => 'ACTIVE',
            'images' => array_values((array) $product->images),
            'sku' => (string) $product->slug,
        ];
    }
}
