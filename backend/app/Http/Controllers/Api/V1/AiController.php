<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\Channel;
use App\Models\Product;
use App\Services\AiCaptionService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class AiController extends Controller
{
    public function __construct(private AiCaptionService $ai)
    {
    }

    /**
     * Generate caption + hashtag untuk produk (draft maupun tersimpan).
     */
    public function caption(Request $request): JsonResponse
    {
        $data = $request->validate([
            'product_id' => ['nullable', 'integer', 'exists:products,id'],
            'title' => ['required_without:product_id', 'nullable', 'string', 'max:160'],
            'description' => ['nullable', 'string', 'max:5000'],
            'price' => ['nullable', 'integer', 'min:0'],
            'tone' => ['nullable', 'in:promo,informatif,lucu'],
        ]);

        $product = $data['product_id'] ?? null
            ? Product::find($data['product_id'])
            : [
                'title' => $data['title'] ?? '',
                'description' => $data['description'] ?? '',
                'price' => $data['price'] ?? 0,
            ];

        if ($product instanceof Product && $product->merchant_id !== $request->user()->merchant->id) {
            abort(403);
        }

        $payload = $product instanceof Product
            ? ['title' => $product->title, 'description' => $product->description, 'price' => $product->price]
            : $product;

        $result = $this->ai->generate($payload, $data['tone'] ?? 'promo');

        return response()->json($result, $result['ok'] ? 200 : 422);
    }

    /**
     * Preview pesan yang akan diposting ke channel tertentu.
     */
    public function preview(Request $request): JsonResponse
    {
        $data = $request->validate([
            'product_id' => ['required', 'integer', 'exists:products,id'],
            'channel_id' => ['nullable', 'integer', 'exists:channels,id'],
            'caption' => ['nullable', 'string', 'max:5000'],
        ]);

        $product = Product::findOrFail($data['product_id']);
        abort_unless($product->merchant_id === $request->user()->merchant->id, 403);

        $channel = isset($data['channel_id'])
            ? Channel::find($data['channel_id'])
            : null;

        abort_if($channel && $channel->merchant_id !== $request->user()->merchant->id, 403);

        $price = 'Rp'.number_format((float) $product->price, 0, ',', '.');

        return response()->json(['data' => [
            'platform' => $channel?->platform,
            'message' => $data['caption']
                ?? "{$product->title}\n\n{$product->description}\n\nHarga: {$price}\nStok: {$product->stock}",
            'images' => $product->images ?? [],
        ]]);
    }
}
