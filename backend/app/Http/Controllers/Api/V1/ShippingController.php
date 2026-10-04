<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\Merchant;
use App\Models\Order;
use App\Services\BiteshipService;
use App\Services\ResiService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class ShippingController extends Controller
{
    public function __construct(
        private BiteshipService $biteship,
        private ResiService $resi,
    ) {
    }

    /**
     * Cek ongkir via Biteship (kode pos). Origin = alamat toko saat seller
     * registrasi (kewajiban untuk pickup kurir), tujuan = kode pos pembeli.
     */
    public function cost(Request $request): JsonResponse
    {
        $data = $request->validate([
            'merchant_slug' => ['required', 'string', 'exists:merchants,slug'],
            'destination_postal_code' => ['required', 'string', 'regex:/^\d{5}$/'],
            'weight' => ['required', 'integer', 'min:1', 'max:1500000'],
            'value' => ['nullable', 'integer', 'min:0', 'max:999999999'],
            'couriers' => ['nullable', 'string', 'max:300'],
        ]);

        $merchant = Merchant::where('slug', $data['merchant_slug'])
            ->where('active', true)
            ->firstOrFail();

        if (! $merchant->postal_code) {
            return response()->json([
                'ok' => false,
                'error' => 'Toko belum punya kode pos. Lengkapi alamat toko di Pengaturan (verifikasi seller).',
            ], 422);
        }

        $result = $this->biteship->rates(
            (int) $merchant->postal_code,
            (int) $data['destination_postal_code'],
            $data['weight'],
            (string) ($data['couriers'] ?? ''),
            (int) ($data['value'] ?? 0),
        );

        return response()->json($result, $result['ok'] ? 200 : 422);
    }

    /**
     * Lacak resi:优先 pakai order id Biteship (data internal order),
     * fallback ke BinderByte untuk resi manual.
     */
    public function track(Request $request): JsonResponse
    {
        $data = $request->validate([
            'awb' => ['required', 'string', 'max:64', 'regex:/^[A-Za-z0-9\-\_]+$/'],
        ]);

        $order = Order::withoutGlobalScope('merchant')
            ->where('tracking_no', $data['awb'])
            ->first();

        if ($order && $order->biteship_order_id) {
            $result = $this->biteship->track($order->biteship_order_id);
            if ($result['ok']) {
                return response()->json($result);
            }
        }

        $result = $this->resi->track($order?->courier ?? 'jne', $data['awb']);

        return response()->json($result, $result['ok'] ? 200 : 422);
    }

    /** Info kesiapan integrasi (dipakai frontend untuk hint UI). */
    public function status(): JsonResponse
    {
        return response()->json([
            'ok' => true,
            'data' => [
                'provider' => 'biteship',
                'configured' => $this->biteship->enabled(),
            ],
        ]);
    }
}
