<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\Order;
use App\Services\MidtransService;
use App\Support\Audit;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

/**
 * Webhook pembayaran. Selalu validasi signature server-side.
 */
class WebhookController extends Controller
{
    public function __construct(private MidtransService $midtrans)
    {
    }

    public function midtrans(Request $request): JsonResponse
    {
        $payload = $request->all();

        if (! $this->midtrans->isValidSignature($payload)) {
            Log::warning('midtrans.webhook.invalid_signature', ['order_id' => $payload['order_id'] ?? null]);

            return response()->json(['message' => 'Invalid signature.'], 403);
        }

        $order = Order::withoutGlobalScope('merchant')
            ->where('order_no', $payload['order_id'] ?? '')
            ->first();

        if (! $order) {
            return response()->json(['message' => 'Order tidak ditemukan.'], 404);
        }

        $status = (string) ($payload['transaction_status'] ?? '');
        $fraud = (string) ($payload['fraud_status'] ?? '');

        $paymentStatus = match (true) {
            $status === 'capture' && $fraud === 'accept', $status === 'settlement' => 'paid',
            $status === 'pending' => 'unpaid',
            in_array($status, ['deny', 'cancel', 'expire'], true) => 'expired',
            $status === 'refund', $status === 'partial_refund' => 'refunded',
            default => $order->payment_status,
        };

        $order->update([
            'payment_status' => $paymentStatus,
            'payment_ref' => $payload['transaction_id'] ?? $order->payment_ref,
        ]);

        Audit::record('order.payment.'.$paymentStatus, $order, [
            'order_no' => $order->order_no,
            'transaction_status' => $status,
        ]);

        return response()->json(['message' => 'OK']);
    }
}
