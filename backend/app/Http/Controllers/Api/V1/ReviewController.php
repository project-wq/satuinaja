<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\Merchant;
use App\Models\Order;
use App\Models\Review;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Fase 19: ulasan & rating.
 * Buyer tanpa login kirim ulasan berdasar nomor order (status delivered/completed).
 * Seller balas tanpa login via toko: cukup punyai toko (auth merchant).
 */
class ReviewController extends Controller
{
    /** Kirim ulasan — publik, validasi berdasar order. */
    public function store(Request $request): JsonResponse
    {
        $data = $request->validate([
            'order_no' => ['required', 'string', 'max:32'],
            'product_id' => ['nullable', 'integer', 'exists:products,id'],
            'rating' => ['required', 'integer', 'min:1', 'max:5'],
            'comment' => ['nullable', 'string', 'max:2000'],
        ]);

        $order = Order::withoutGlobalScope('merchant')->where('order_no', $data['order_no'])->firstOrFail();

        abort_unless(
            in_array($order->fulfillment_status, ['delivered', 'completed'], true),
            422, 'Ulasan hanya bisa dikirim setelah paket diterima.'
        );

        $reviewer = $order->buyer_name ?: 'Pembeli';

        $review = Review::updateOrCreate(
            ['order_id' => $order->id, 'product_id' => $data['product_id'] ?? null],
            [
                'merchant_id' => $order->merchant_id,
                'rating' => $data['rating'],
                'comment' => $data['comment'] ?? null,
                'reviewer_name' => $reviewer,
            ],
        );

        $this->refreshAggregate($order->merchant_id);

        return response()->json(['data' => $review], 201);
    }

    /** Daftar ulasan toko — publik (storefront). */
    public function forShop(string $slug): JsonResponse
    {
        $merchant = Merchant::where('slug', $slug)->where('active', true)->firstOrFail();

        $page = Review::where('merchant_id', $merchant->id)
            ->where('visible', true)
            ->with('product:id,title')
            ->latest()
            ->paginate(10);

        return response()->json([
            'data' => $page,
            'aggregate' => [
                'avg' => (float) $merchant->rating_avg,
                'count' => (int) $merchant->rating_count,
            ],
        ]);
    }

    /** Daftar ulasan panel seller. */
    public function index(Request $request): JsonResponse
    {
        $merchant = $request->user()->merchant;
        abort_unless($merchant, 403);

        $page = Review::where('merchant_id', $merchant->id)
            ->with(['product:id,title', 'order:id,order_no'])
            ->latest()
            ->paginate(20);

        return response()->json(['data' => $page]);
    }

    /** Balas ulasan — seller pemilik toko. */
    public function reply(Request $request, Review $review): JsonResponse
    {
        $merchant = $request->user()->merchant;
        abort_unless($merchant && $review->merchant_id === $merchant->id, 403);

        $data = $request->validate([
            'reply' => ['required', 'string', 'max:2000'],
        ]);

        $review->update(['seller_reply' => $data['reply'], 'replied_at' => now()]);

        return response()->json(['data' => $review]);
    }

    private function refreshAggregate(int $merchantId): void
    {
        $row = Review::where('merchant_id', $merchantId)
            ->where('visible', true)
            ->selectRaw('COUNT(*) c, AVG(rating) a')
            ->first();

        Merchant::where('id', $merchantId)->update([
            'rating_count' => (int) ($row?->c ?? 0),
            'rating_avg' => round((float) ($row?->a ?? 0), 2),
        ]);
    }
}
