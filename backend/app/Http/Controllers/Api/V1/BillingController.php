<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\Channel;
use App\Models\Merchant;
use App\Models\Plan;
use App\Models\Subscription;
use App\Services\MidtransService;
use App\Support\Audit;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Langganan & batas plan. Seller melihat plan saat ini, upgrade → redirect
 * Midtrans Snap (order langganan = 1 transaksi, webhook payment bekotak di
 * WebhookController lama, lalu status sub dipanggil lewat task scheduler).
 */
class BillingController extends Controller
{
    public function __construct(private MidtransService $midtrans)
    {
    }

    /** Daftar plan + plan saat ini + batas yang berlaku. */
    public function index(Request $request): JsonResponse
    {
        $merchant = $request->user()->merchant;

        return response()->json([
            'data' => [
                'current' => [
                    'code' => $merchant->plan_code,
                    'publishes_this_month' => $merchant->publishes_this_month,
                ],
                'plans' => Plan::where('active', true)->orderBy('price_monthly')->get()->map(fn ($p) => [
                    'code' => $p->code,
                    'name' => $p->name,
                    'price_monthly' => $p->price_monthly,
                    'max_products' => $p->max_products,
                    'max_channels' => $p->max_channels,
                    'max_publishes_monthly' => $p->max_publishes_monthly,
                    'ai_caption' => $p->ai_caption,
                    'auto_publish' => $p->auto_publish,
                ]),
            ],
        ]);
    }

    /** Upgrade ke plan (buat order langganan → redirect Midtrans). */
    public function subscribe(Request $request): JsonResponse
    {
        $data = $request->validate([
            'plan_code' => ['required', 'string'],
        ]);

        $plan = Plan::where('code', $data['plan_code'])->first();
        abort_unless($plan && $plan->active, 422, 'Plan tak ada.');
        abort_unless($plan->price_monthly > 0, 422, 'Plan ini gratis — sudah aktif.');

        $merchant = $request->user()->merchant;

        // Kalau sudah punya langganan aktif untuk plan ini, tak perlu bayar 2 kali.
        $existing = Subscription::where('merchant_id', $merchant->id)
            ->where('status', 'active')
            ->where('plan_code', $plan->code)
            ->first();
        if ($existing && $existing->isActive()) {
            return response()->json(['data' => ['already_active' => true]]);
        }

        $orderNo = $this->midtrans->generateOrderNo();
        $subscription = Subscription::create([
            'merchant_id' => $merchant->id,
            'plan_code' => $plan->code,
            'payment_ref' => $orderNo,
            'status' => 'pending',
            'starts_at' => now(),
        ]);

        $snap = $this->midtrans->createSnapGeneric([
            'order_no' => $orderNo,
            'total' => $plan->price_monthly,
            'buyer_name' => $request->user()->name,
            'buyer_email' => $request->user()->email,
            'buyer_phone' => '',
            'items' => [[
                'product_id' => 'plan',
                'title' => 'Plan '.$plan->name.' (1 bulan)',
                'price' => $plan->price_monthly,
                'qty' => 1,
            ]],
        ]);

        if (! $snap['ok']) {
            $subscription->update(['status' => 'failed']);
            return response()->json(['error' => $snap['error']], 422);
        }

        Audit::record('billing.subscribe', $subscription, [
            'plan' => $plan->code, 'amount' => $plan->price_monthly,
        ]);

        return response()->json([
            'data' => [
                'subscription_id' => $subscription->id,
                'plan_code' => $plan->code,
                'redirect_url' => $snap['data']['redirect_url'],
            ],
        ]);
    }

    /**
     * Batas seller — dilewat LOKAL (app kernel, bukan request). Fungsi dipakai
     * ProductController/ChannelController/PublisherService sebelum aksi.
     */
    public static function limits(Merchant $merchant): array
    {
        $plan = Plan::where('code', $merchant->plan_code)->first();

        return array_merge(
            Plan::FREE_LIMITS,
            $plan ? [
                'max_products' => $plan->max_products,
                'max_channels' => $plan->max_channels,
                'max_publishes_monthly' => $plan->max_publishes_monthly,
                'ai_caption' => $plan->ai_caption,
                'auto_publish' => $plan->auto_publish,
            ] : [],
        );
    }
}