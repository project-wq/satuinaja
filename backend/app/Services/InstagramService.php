<?php

namespace App\Services;

use App\Models\Channel;
use App\Models\Product;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * Publish ke Instagram Business via Instagram Graph API.
 *
 * Alur (wajib 2 langkah):
 *   1. POST /{ig-user-id}/media          → buat container (dapat creation_id)
 *   2. POST /{ig-user-id}/media_publish  → publish container
 *
 * Kredensial seller:
 *   ig_user_id      — IG Business Account ID (numeric)
 *   access_token    — Page/User token dengan scope instagram_content_publish
 *   image_url       — (opsional) override gambar publik; default dari produk
 *
 * Catatan: IG **wajib** punya gambar URL publik (HTTPS). Posting teks saja ditolak.
 */
class InstagramService
{
    private const GRAPH = 'https://graph.facebook.com/v21.0';

    /** IG butuh waktu proses container; beri jeda sebelum publish. */
    private const CONTAINER_WAIT_SECONDS = 3;

    public function publish(Product $product, Channel $channel): array
    {
        $creds = (array) $channel->credentials;
        $igUserId = $creds['ig_user_id'] ?? null;
        $token = $creds['access_token'] ?? null;

        if (! $igUserId || ! $token) {
            return ['ok' => false, 'error' => 'ig_user_id / access_token belum diisi.'];
        }

        $imageUrl = $creds['image_url'] ?? $this->firstImageUrl($product);

        if (! $imageUrl) {
            return ['ok' => false, 'error' => 'Instagram wajib punya gambar publik (HTTPS). Upload foto produk dulu.'];
        }

        if (! str_starts_with((string) $imageUrl, 'https://')) {
            return ['ok' => false, 'error' => 'Gambar Instagram harus URL HTTPS publik, bukan path lokal.'];
        }

        try {
            // Langkah 1: buat container.
            $container = Http::timeout(45)->asForm()->post(
                self::GRAPH."/{$igUserId}/media",
                [
                    'image_url' => $imageUrl,
                    'caption' => $this->buildCaption($product),
                    'access_token' => $token,
                ]
            );

            if (! $container->successful()) {
                return ['ok' => false, 'error' => $this->errorOf($container->json(), $container->status())];
            }

            $creationId = data_get($container->json(), 'id');
            if (! $creationId) {
                return ['ok' => false, 'error' => 'Container IG tidak mengembalikan id.'];
            }

            // IG butuh jeda singkat sebelum container siap dipublish.
            sleep(self::CONTAINER_WAIT_SECONDS);

            // Langkah 2: publish.
            $publish = Http::timeout(45)->asForm()->post(
                self::GRAPH."/{$igUserId}/media_publish",
                ['creation_id' => $creationId, 'access_token' => $token]
            );

            if (! $publish->successful()) {
                return ['ok' => false, 'error' => $this->errorOf($publish->json(), $publish->status())];
            }

            $mediaId = (string) data_get($publish->json(), 'id');

            return [
                'ok' => true,
                'external_id' => $mediaId,
                'external_url' => $mediaId ? "https://www.instagram.com/p/{$mediaId}/" : null,
            ];
        } catch (\Throwable $e) {
            Log::warning('instagram.publish.failed', ['error' => $e->getMessage()]);

            return ['ok' => false, 'error' => $e->getMessage()];
        }
    }

    /**
     * Cek kredensial: GET /{ig-user-id}?fields=id,username
     */
    public function verify(Channel $channel): array
    {
        $creds = (array) $channel->credentials;
        $igUserId = $creds['ig_user_id'] ?? null;
        $token = $creds['access_token'] ?? null;

        if (! $igUserId || ! $token) {
            return ['ok' => false, 'error' => 'ig_user_id / access_token belum diisi.'];
        }

        $res = Http::timeout(20)->get(self::GRAPH."/{$igUserId}", [
            'fields' => 'id,username,media_count',
            'access_token' => $token,
        ]);

        if (! $res->successful()) {
            return ['ok' => false, 'error' => $this->errorOf($res->json(), $res->status())];
        }

        return ['ok' => true, 'data' => $res->json()];
    }

    private function buildCaption(Product $product): string
    {
        $price = 'Rp'.number_format((float) $product->price, 0, ',', '.');
        $tags = '#jualan #onlineshop #'.str_replace('-', '', (string) $product->slug).' #belanjaonline';

        return "{$product->title}\n\n"
            .($product->description ? "{$product->description}\n\n" : '')
            ."Harga: {$price}\nStok: {$product->stock}\n\n"
            ."Order lewat link di bio.\n\n{$tags}";
    }

    private function firstImageUrl(Product $product): ?string
    {
        $images = (array) $product->images;
        $first = $images[0] ?? null;

        if (! $first) {
            return null;
        }

        return str_starts_with((string) $first, 'http')
            ? (string) $first
            : rtrim((string) config('app.public_url', config('app.url')), '/').'/storage/'.ltrim((string) $first, '/');
    }

    private function errorOf(mixed $json, int $status): string
    {
        $msg = data_get($json, 'error.message') ?? 'HTTP '.$status;
        $code = data_get($json, 'error.code');

        return trim($msg.($code ? " (code {$code})" : ''));
    }
}
