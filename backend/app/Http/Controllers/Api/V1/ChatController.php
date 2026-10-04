<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\Order;
use App\Models\OrderMessage;
use App\Services\NotificationService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Fase 22: chat buyer↔seller per order (Shopee-style).
 * Buyer = publik (verifikasi no. HP sesuai order). Seller = auth + authorizeOrder.
 */
class ChatController extends Controller
{
    public function __construct(private NotificationService $notif)
    {
    }

    /** Cari order publik + cocokkan no. HP buyer. */
    private function publicOrder(string $orderNo, ?string $phone): Order
    {
        $order = Order::withoutGlobalScope('merchant')->where('order_no', $orderNo)->firstOrFail();
        abort_unless($phone && hash_equals((string) $order->buyer_phone, (string) $phone), 403, 'Nomor HP tidak cocok.');

        return $order;
    }

    private function shape(OrderMessage $m): array
    {
        return [
            'id' => $m->id,
            'sender' => $m->sender,
            'sender_name' => $m->sender_name,
            'body' => $m->body,
            'read_at' => $m->read_at?->toDateTimeString(),
            'created_at' => $m->created_at?->toDateTimeString(),
        ];
    }

    // ---------- Buyer (publik) ----------

    /** GET /orders/track/{orderNo}/messages?phone= — daftar pesan + tandai pesan seller dibaca. */
    public function buyerIndex(string $orderNo, Request $request): JsonResponse
    {
        $order = $this->publicOrder($orderNo, $request->query('phone'));
        $order->messages()->where('sender', 'seller')->whereNull('read_at')->update(['read_at' => now()]);

        return response()->json(['data' => $order->messages()->get()->map(fn ($m) => $this->shape($m))->all()]);
    }

    /** POST /orders/track/{orderNo}/messages {buyer_phone, body} — buyer kirim pesan. */
    public function buyerStore(string $orderNo, Request $request): JsonResponse
    {
        $data = $request->validate([
            'buyer_phone' => ['required', 'string', 'max:30'],
            'body' => ['required', 'string', 'max:2000'],
        ]);
        $order = $this->publicOrder($orderNo, $data['buyer_phone']);
        abort_if($order->fulfillment_status === 'cancelled', 422, 'Order dibatalkan, chat ditutup.');

        $msg = $order->messages()->create([
            'merchant_id' => $order->merchant_id,
            'sender' => 'buyer',
            'sender_name' => $order->buyer_name,
            'body' => $data['body'],
        ]);
        $this->notif->push($order->merchant_id, 'chat', 'Pesan baru dari '.$order->buyer_name, mb_substr($data['body'], 0, 100), '/seller/orders');

        return response()->json(['data' => $this->shape($msg)], 201);
    }

    // ---------- Seller (auth) ----------

    private function authorizeOrder(Request $request, Order $order): void
    {
        if ($request->user()->role === 'admin') {
            return;
        }
        abort_unless($order->merchant_id === $request->user()->effectiveMerchant()?->id, 403);
    }

    /** GET /orders/{order}/messages — daftar + tandai pesan buyer dibaca + unread count. */
    public function sellerIndex(Request $request, Order $order): JsonResponse
    {
        $this->authorizeOrder($request, $order);
        $unread = $order->messages()->where('sender', 'buyer')->whereNull('read_at')->count();
        $order->messages()->where('sender', 'buyer')->whereNull('read_at')->update(['read_at' => now()]);

        return response()->json([
            'data' => $order->messages()->get()->map(fn ($m) => $this->shape($m))->all(),
            'unread_buyer' => $unread,
        ]);
    }

    /** POST /orders/{order}/messages {body} — seller balas. */
    public function sellerStore(Request $request, Order $order): JsonResponse
    {
        $this->authorizeOrder($request, $order);
        $data = $request->validate(['body' => ['required', 'string', 'max:2000']]);
        abort_if($order->fulfillment_status === 'cancelled', 422, 'Order dibatalkan, chat ditutup.');

        $msg = $order->messages()->create([
            'merchant_id' => $order->merchant_id,
            'sender' => 'seller',
            'sender_name' => $request->user()->name,
            'body' => $data['body'],
        ]);

        return response()->json(['data' => $this->shape($msg)], 201);
    }

    /** GET /messages/unread — total pesan buyer belum dibaca (badge). */
    public function unread(Request $request): JsonResponse
    {
        $merchantId = $request->user()->role === 'admin'
            ? null
            : $request->user()->effectiveMerchant()?->id;

        $q = OrderMessage::where('sender', 'buyer')->whereNull('read_at');
        if ($merchantId !== null) {
            $q->where('merchant_id', $merchantId);
        }

        return response()->json(['data' => ['unread' => $q->count()]]);
    }
}
