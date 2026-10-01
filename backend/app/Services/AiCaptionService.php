<?php

namespace App\Services;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * Generator caption iklan memakai gateway AI (9Router / OpenAI-compatible).
 * Tidak pernah menerima kredensial seller — hanya teks produk.
 */
class AiCaptionService
{
    public function generate(array $product, string $tone = 'promo'): array
    {
        $baseUrl = rtrim((string) config('services.ai.base_url'), '/');
        $apiKey = (string) config('services.ai.key');
        $model = (string) config('services.ai.model', 'Ai');

        if ($baseUrl === '' || $apiKey === '') {
            return [
                'ok' => false,
                'error' => 'AI gateway belum dikonfigurasi (AI_BASE_URL / AI_API_KEY).',
            ];
        }

        $tones = [
            'promo' => 'Gaya promo jualan: singkat, persuasif, ada CTA dan harga.',
            'informatif' => 'Gaya informatif: jelaskan bahan, ukuran, keunggulan.',
            'lucu' => 'Gaya santai/lucu tapi tetap menjual.',
        ];
        $style = $tones[$tone] ?? $tones['promo'];

        $prompt = "Buat caption jualan untuk produk berikut.\n"
            ."Nama: {$product['title']}\n"
            ."Harga: Rp".number_format((float) ($product['price'] ?? 0), 0, ',', '.')."\n"
            ."Deskripsi: ".($product['description'] ?? '-')."\n\n"
            ."{$style}\n"
            ."Output JSON dengan kunci: caption (string), hashtags (array string, tanpa #), "
            ."cta (string). Hanya JSON, tanpa penjelasan.";

        try {
            $res = Http::withToken($apiKey)
                ->timeout(60)
                ->acceptJson()
                ->post("{$baseUrl}/chat/completions", [
                    'model' => $model,
                    'messages' => [
                        ['role' => 'system', 'content' => 'Kamu copywriter e-commerce Indonesia.'],
                        ['role' => 'user', 'content' => $prompt],
                    ],
                    'max_tokens' => 900,
                    'temperature' => 0.8,
                ]);

            if (! $res->successful()) {
                return ['ok' => false, 'error' => 'AI HTTP '.$res->status()];
            }

            $content = (string) data_get($res->json(), 'choices.0.message.content', '');
            $content = trim(preg_replace('/^```(?:json)?|```$/m', '', $content));

            $decoded = json_decode($content, true);
            if (! is_array($decoded)) {
                return ['ok' => true, 'data' => [
                    'caption' => $content,
                    'hashtags' => [],
                    'cta' => '',
                ]];
            }

            return ['ok' => true, 'data' => [
                'caption' => (string) ($decoded['caption'] ?? ''),
                'hashtags' => array_values((array) ($decoded['hashtags'] ?? [])),
                'cta' => (string) ($decoded['cta'] ?? ''),
            ]];
        } catch (\Throwable $e) {
            Log::warning('ai.caption.failed', ['error' => $e->getMessage()]);

            return ['ok' => false, 'error' => $e->getMessage()];
        }
    }
}
