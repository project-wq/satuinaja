<?php

namespace App\Services;

use App\Models\Channel;
use App\Models\Product;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * Publish produk ke Facebook Page via Graph API.
 * Credentials yang dibutuhkan seller:
 *   page_id     — ID Halaman FB
 *   page_token  — Page Access Token (long-lived)
 *   ig_user_id  — (opsional) IG Business Account ID, untuk cross-post IG
 */
class FacebookService
{
    private const GRAPH = 'https://graph.facebook.com/v21.0';

    public function publish(Product $product, Channel $channel): array
    {
        $creds = (array) $channel->credentials;
        $pageId = $creds['page_id'] ?? null;
        $token = $creds['page_token'] ?? null;

        if (! $pageId || ! $token) {
            return ['ok' => false, 'error' => 'page_id / page_token belum diisi.'];
        }

        $message = $this->buildMessage($product);
        $imageUrl = $this->firstImageUrl($product);

        try {
            if ($imageUrl) {
                $res = Http::timeout(45)->asForm()->post(
                    self::GRAPH."/{$pageId}/photos",
                    ['url' => $imageUrl, 'caption' => $message, 'access_token' => $token]
                );
            } else {
                $res = Http::timeout(45)->asForm()->post(
                    self::GRAPH."/{$pageId}/feed",
                    ['message' => $message, 'access_token' => $token]
                );
            }

            if (! $res->successful()) {
                return ['ok' => false, 'error' => $this->errorOf($res->json(), $res->status())];
            }

            $body = $res->json();

            return [
                'ok' => true,
                'external_id' => (string) (data_get($body, 'post_id') ?? data_get($body, 'id')),
                'external_url' => $this->postUrl($pageId, data_get($body, 'post_id') ?? data_get($body, 'id')),
            ];
        } catch (\Throwable $e) {
            Log::warning('facebook.publish.failed', ['error' => $e->getMessage()]);

            return ['ok' => false, 'error' => $e->getMessage()];
        }
    }

    public function verify(Channel $channel): array
    {
        $creds = (array) $channel->credentials;
        $pageId = $creds['page_id'] ?? null;
        $token = $creds['page_token'] ?? null;

        if (! $pageId || ! $token) {
            return ['ok' => false, 'error' => 'page_id / page_token belum diisi.'];
        }

        $res = Http::timeout(20)->get(self::GRAPH."/{$pageId}", [
            'fields' => 'id,name,category',
            'access_token' => $token,
        ]);

        if (! $res->successful()) {
            return ['ok' => false, 'error' => $this->errorOf($res->json(), $res->status())];
        }

        return ['ok' => true, 'data' => $res->json()];
    }

    private function buildMessage(Product $product): string
    {
        $price = 'Rp'.number_format((float) $product->price, 0, ',', '.');

        return "{$product->title}\n\n"
            .($product->description ? "{$product->description}\n\n" : '')
            ."Harga: {$price}\n"
            ."Stok: {$product->stock}\n\n"
            ."Pesan sekarang sebelum kehabisan!";
    }

    private function firstImageUrl(Product $product): ?string
    {
        $images = (array) $product->images;
        $first = $images[0] ?? null;

        if (! $first) {
            return null;
        }

        return str_starts_with((string) $first, 'http')
            ? $first
            : rtrim((string) config('app.url'), '/').'/storage/'.ltrim((string) $first, '/');
    }

    private function postUrl(string $pageId, mixed $postId): ?string
    {
        if (! $postId) {
            return null;
        }

        $parts = explode('_', (string) $postId);

        return count($parts) === 2
            ? "https://facebook.com/{$parts[0]}/posts/{$parts[1]}"
            : "https://facebook.com/{$pageId}";
    }

    private function errorOf(mixed $json, int $status): string
    {
        $msg = data_get($json, 'error.message') ?? 'HTTP '.$status;
        $code = data_get($json, 'error.code');
        $sub = data_get($json, 'error.error_subcode');

        return trim($msg.($code ? " (code {$code}".($sub ? "/{$sub}" : '').')' : ''));
    }
}
