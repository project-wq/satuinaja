<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\Order;
use App\Models\Subscription;
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

        $orderNo = (string) ($payload['order_id'] ?? '');
        $transactionStatus = (string) ($payload['transaction_status'] ?? '');

        // 1) Langganan plan: order id diawali ORD- tapi mungkin sub. Backup check:
        $subscription = Subscription::where('payment_ref', $orderNo)->first();
        if ($subscription) {
            return $this->handleSubscriptionWebhook($subscription, $payload);
        }

        // 2) Order produk biasa.
        $order = Order::withoutGlobalScope('merchant')
            ->where('order_no', $orderNo)
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

    /**
     * Webhook langganan: settlement → aktifkan plan seller; expire/deny → batal.
     */
    private function handleSubscriptionWebhook(Subscription $subscription, array $payload): JsonResponse
    {
        $status = (string) ($payload['transaction_status'] ?? '');
        $fraud = (string) ($payload['fraud_status'] ?? '');

        $paid = ($status === 'settlement') || ($status === 'capture' && $fraud === 'accept');
        $failed = in_array($status, ['deny', 'cancel', 'expire'], true);

        if ($paid) {
            $endsAt = now()->addMonth();
            $subscription->update([
                'status' => 'active',
                'starts_at' => $subscription->starts_at ?? now(),
                'ends_at' => $endsAt,
            ]);

            $subscription->merchant()->update([
                'plan_code' => $subscription->plan_code,
                'last_publish_reset_at' => now(),
            ]);

            Audit::record('billing.subscription.activated', $subscription, [
                'plan' => $subscription->plan_code,
                'ends_at' => $endsAt->toDateTimeString(),
            ]);

            return response()->json(['message' => 'Plan aktif.']);
        }

        if ($failed) {
            $subscription->update(['status' => 'canceled']);
            Audit::record('billing.subscription.canceled', $subscription, ['status' => $status]);

            return response()->json(['message' => 'Status diterima.']);
        }

        return response()->json(['message' => 'OK']);
    }
}
