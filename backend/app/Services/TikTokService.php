<?php

namespace App\Services;

use App\Models\Channel;
use App\Models\Product;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * TikTok Content Posting API (v2).
 *
 * Alur:
 *   1. POST /v2/post/publish/inbox/video/init/  (upload ke inbox, user selesaikan di app)
 *      atau /v2/post/publish/video/init/        (direct post, butuh scope lebih tinggi)
 *   2. PUT  {upload_url}  → kirim byte video
 *
 * Kredensial seller:
 *   access_token     — OAuth user token (scope video.upload / video.publish)
 *   video_url        — (opsional) URL publik video; default kosong = masuk inbox draft
 *   privacy_level    — PUBLIC_TO_EVERYONE | SELF_ONLY (default SELF_ONLY)
 *
 * Jika tidak ada video_url, kami kirim post mode inbox supaya seller bisa
 * menambahkan video/foto di app TikTok dan memakai caption yang disiapkan.
 */
class TikTokService
{
    private const API = 'https://open.tiktokapis.com/v2';

    public function publish(Product $product, Channel $channel): array
    {
        $creds = (array) $channel->credentials;
        $token = $creds['access_token'] ?? null;

        if (! $token) {
            return ['ok' => false, 'error' => 'access_token TikTok belum diisi.'];
        }

        $videoUrl = $creds['video_url'] ?? null;

        // Tanpa video: TikTok menolak publish. Kembalikan caption siap pakai
        // supaya seller tetap bisa posting manual dari app.
        if (! $videoUrl) {
            return [
                'ok' => false,
                'error' => 'TikTok wajib video. Isi "video_url" (link video HTTPS) di kredensial channel, '
                    .'atau salin caption berikut untuk posting manual.',
                'caption' => $this->buildCaption($product),
            ];
        }

        try {
            $privacy = $creds['privacy_level'] ?? 'SELF_ONLY';
            $caption = $this->buildCaption($product);

            // Langkah 1: inisialisasi upload.
            $init = Http::withToken($token)
                ->timeout(45)
                ->acceptJson()
                ->post(self::API.'/post/publish/video/init/', [
                    'post_info' => [
                        'title' => mb_substr($caption, 0, 2200),
                        'privacy_level' => $privacy,
                        'disable_duet' => false,
                        'disable_comment' => false,
                        'disable_stitch' => false,
                    ],
                    'source_info' => [
                        'source' => 'PULL_FROM_URL',
                        'video_url' => $videoUrl,
                    ],
                ]);

            if (! $init->successful()) {
                return ['ok' => false, 'error' => $this->errorOf($init->json(), $init->status())];
            }

            $errorCode = data_get($init->json(), 'error.code');
            if ($errorCode && $errorCode !== 'ok') {
                return ['ok' => false, 'error' => $this->errorOf($init->json(), $init->status())];
            }

            $publishId = (string) data_get($init->json(), 'data.publish_id');

            return [
                'ok' => true,
                'external_id' => $publishId,
                'external_url' => null, // TikTok tidak memberi URL sebelum video live
            ];
        } catch (\Throwable $e) {
            Log::warning('tiktok.publish.failed', ['error' => $e->getMessage()]);

            return ['ok' => false, 'error' => $e->getMessage()];
        }
    }

    /**
     * Cek token: GET /v2/user/info/
     */
    public function verify(Channel $channel): array
    {
        $creds = (array) $channel->credentials;
        $token = $creds['access_token'] ?? null;

        if (! $token) {
            return ['ok' => false, 'error' => 'access_token TikTok belum diisi.'];
        }

        $res = Http::withToken($token)
            ->timeout(20)
            ->acceptJson()
            ->get(self::API.'/user/info/', ['fields' => 'open_id,display_name,follower_count']);

        if (! $res->successful()) {
            return ['ok' => false, 'error' => $this->errorOf($res->json(), $res->status())];
        }

        $code = data_get($res->json(), 'error.code');
        if ($code && $code !== 'ok') {
            return ['ok' => false, 'error' => $this->errorOf($res->json(), $res->status())];
        }

        return ['ok' => true, 'data' => data_get($res->json(), 'data.user', [])];
    }

    /**
     * Query status publish (dipakai untuk job follow-up bila diperlukan).
     */
    public function status(string $publishId, Channel $channel): array
    {
        $token = (array) $channel->credentials['access_token'] ?? null;
        if (! $token) {
            return ['ok' => false, 'error' => 'access_token TikTok belum diisi.'];
        }

        $res = Http::withToken($token)
            ->timeout(20)
            ->acceptJson()
            ->post(self::API.'/post/publish/status/fetch/', ['publish_id' => $publishId]);

        if (! $res->successful()) {
            return ['ok' => false, 'error' => $this->errorOf($res->json(), $res->status())];
        }

        return ['ok' => true, 'data' => data_get($res->json(), 'data', [])];
    }

    private function buildCaption(Product $product): string
    {
        $price = 'Rp'.number_format((float) $product->price, 0, ',', '.');

        return "{$product->title} — {$price} 🔥 "
            .($product->description ? mb_substr((string) $product->description, 0, 120).' ' : '')
            .'#racunTikTok #fyp #onlineshop #'.str_replace('-', '', (string) $product->slug);
    }

    private function errorOf(mixed $json, int $status): string
    {
        $msg = data_get($json, 'error.message')
            ?? data_get($json, 'message')
            ?? 'HTTP '.$status;
        $code = data_get($json, 'error.code');

        return trim($msg.($code ? " ({$code})" : ''));
    }
}
