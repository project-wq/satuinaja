<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\Channel;
use App\Services\MarketplaceService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

/**
 * Webhook order dari marketplace (Fase 7).
 *
 * Verifikasi: HMAC-SHA256(raw body, secret) dengan secret = kredensial channel:
 *   Shopee    → partner_key
 *   Tokopedia → client_secret
 * Signature dibandingkan terhadap header `X-Marketplace-Signature` (atau
 * `Authorization`), hex lowercase. URL dipanggil sebagai:
 *   POST /api/v1/webhooks/shopee/{channel}   (header signature)
 *
 * Idempotent di MarketplaceService: (channel_id, external_order_id) unik.
 */
class MarketplaceWebhookController extends Controller
{
    public function __construct(
        private MarketplaceService $marketplace,
    ) {
    }

    public function shopee(Request $request, Channel $channel): JsonResponse
    {
        return $this->handle($request, $channel, 'shopee', 'partner_key');
    }

    public function tokopedia(Request $request, Channel $channel): JsonResponse
    {
        return $this->handle($request, $channel, 'tokopedia', 'client_secret');
    }

    private function handle(Request $request, Channel $channel, string $platform, string $secretKey): JsonResponse
    {
        if ($channel->platform !== $platform) {
            return response()->json(['message' => 'Channel tidak cocok platform.'], 422);
        }

        $creds = (array) $channel->credentials;
        $secret = (string) ($creds[$secretKey] ?? '');

        if ($secret === '' || ! $this->validSignature($request, $secret)) {
            Log::warning("marketplace.webhook.invalid_signature", [
                'platform' => $platform,
                'channel_id' => $channel->id,
            ]);

            return response()->json(['message' => 'Invalid signature.'], 403);
        }

        $payload = $request->all();
        $data = $this->normalize($platform, $payload);

        try {
            $result = $this->marketplace->ingest($channel, $data);
        } catch (\InvalidArgumentException|\RuntimeException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }

        return response()->json([
            'message' => $result['created'] ? 'Order dibuat.' : 'Order sudah ada (idempotent).',
            'order_no' => $result['order']->order_no,
            'unmatched' => $result['unmatched'],
        ], $result['created'] ? 201 : 200);
    }

    /** Verifikasi HMAC-SHA256(raw body, secret) terhadap header signature. */
    private function validSignature(Request $request, string $secret): bool
    {
        $provided = (string) ($request->header('X-Marketplace-Signature')
            ?? $request->header('Authorization')
            ?? '');
        $provided = trim(preg_replace('/^Bearer\s+/i', '', $provided));
        if ($provided === '') {
            return false;
        }

        $expected = hash_hmac('sha256', $request->getContent(), $secret);

        return hash_equals($expected, strtolower($provided));
    }

    /**
     * Normalisasi payload platform ke bentuk internal MarketplaceService.
     * Dokumentasi platform berbeda; di sini kami petakan field umum.
     */
    private function normalize(string $platform, array $payload): array
    {
        if ($platform === 'shopee') {
            // Shopee push: { ordersn / order_sn, buyer_username, item_list: [{item_id, model_id, model_sku, quantity, ...}] }
            $items = array_map(fn ($i) => [
                'sku' => (string) ($i['item_sku'] ?? $i['sku'] ?? $i['model_sku'] ?? ''),
                'model_sku' => (string) ($i['model_sku'] ?? ''),
                'model_id' => isset($i['model_id']) ? (string) $i['model_id'] : null,
                'external_item_id' => $i['item_id'] ?? null,
                'qty' => (int) ($i['quantity'] ?? $i['model_quantity_purchased'] ?? 1),
            ], (array) data_get($payload, 'item_list', data_get($payload, 'items', [])));

            return [
                'external_order_id' => (string) ($payload['ordersn'] ?? $payload['order_sn'] ?? ''),
                'buyer_name' => (string) ($payload['buyer_username'] ?? $payload['recipient_address.name'] ?? 'Pembeli Shopee'),
                'buyer_phone' => (string) ($payload['recipient_address.phone'] ?? ''),
                'shipping_address' => (string) ($payload['recipient_address.full_address'] ?? ''),
                'items' => $items,
            ];
        }

        // Tokopedia: { order_id, buyer_name, products: [{ product_id|sku, quantity }] }
        $items = array_map(fn ($i) => [
            'sku' => (string) ($i['sku'] ?? $i['product_sku'] ?? ''),
            'product_id' => $i['product_id'] ?? null,
            'qty' => (int) ($i['quantity'] ?? $i['qty'] ?? 1),
        ], (array) data_get($payload, 'products', data_get($payload, 'items', [])));

        return [
            'external_order_id' => (string) ($payload['order_id'] ?? $payload['invoice_ref_num'] ?? ''),
            'buyer_name' => (string) ($payload['buyer_name'] ?? 'Pembeli Tokopedia'),
            'buyer_phone' => (string) ($payload['buyer_phone'] ?? ''),
            'shipping_address' => (string) ($payload['shipping_address'] ?? ''),
            'items' => $items,
        ];
    }
}
