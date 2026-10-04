<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\Merchant;
use App\Models\Order;
use App\Models\Product;
use App\Models\Voucher;
use App\Services\FeeService;
use App\Services\MidtransService;
use App\Support\Audit;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class CheckoutController extends Controller
{
    public function __construct(
        private MidtransService $midtrans,
        private FeeService $fee,
        private \App\Services\NotificationService $notif,
        private \App\Services\PromotionService $promo,
    ) {
    }

    /**
     * Preview rincian biaya (halaman checkout sebelum bayar):
     * harga - diskon + fee 11% + admin 1000/unit + ongkir = total.
     * Fase 14: voucher_code optional → hitung diskon voucher + gratis ongkir.
     */
    public function preview(Request $request): JsonResponse
    {
        $data = $request->validate([
            'merchant_slug' => ['required', 'string', 'exists:merchants,slug'],
            'items' => ['required', 'array', 'min:1', 'max:50'],
            'items.*.product_id' => ['required', 'integer', 'exists:products,id'],
            'items.*.variant_id' => ['nullable', 'integer', 'exists:product_variants,id'],
            'items.*.qty' => ['required', 'integer', 'min:1', 'max:1000'],
            'shipping_cost' => ['nullable', 'integer', 'min:0'],
            'voucher_code' => ['nullable', 'string', 'max:40'],
        ]);

        $merchant = Merchant::where('slug', $data['merchant_slug'])->where('active', true)->firstOrFail();
        $rows = $this->buildLines($merchant, $data['items']);
        $shippingCost = $data['shipping_cost'] ?? 0;

        $voucher = null;
        $voucherResult = null;
        if (! empty($data['voucher_code'])) {
            $voucher = $this->promo->findUsable((string) $data['voucher_code'], $merchant, array_column($rows, 'product_id'));
            if ($voucher) {
                $voucherResult = $this->promo->apply($voucher, $rows, $shippingCost);
            }
        }

        return response()->json(['data' => $this->summarize($rows, $shippingCost, $voucher, $voucherResult)]);
    }

    /**
     * Checkout publik: buat order (dengan rincian fee) + transaksi Midtrans.
     * Fase 14: voucher_code optional → apply + redeem (dalam transaksi).
     */
    public function store(Request $request): JsonResponse
    {
        $data = $request->validate([
            'merchant_slug' => ['required', 'string', 'exists:merchants,slug'],
            'buyer_name' => ['required', 'string', 'max:120'],
            'buyer_phone' => ['required', 'string', 'max:32'],
            'buyer_email' => ['nullable', 'email', 'max:120'],
            'shipping_address' => ['required', 'string', 'max:1000'],
            'destination_city_id' => ['required', 'string', 'max:16'],
            'courier' => ['required', 'string', 'max:32'],
            'service' => ['required', 'string', 'max:64'],
            'shipping_cost' => ['required', 'integer', 'min:0', 'max:10000000'],
            'items' => ['required', 'array', 'min:1', 'max:50'],
            'items.*.product_id' => ['required', 'integer', 'exists:products,id'],
            'items.*.variant_id' => ['nullable', 'integer', 'exists:product_variants,id'],
            'items.*.qty' => ['required', 'integer', 'min:1', 'max:1000'],
            'voucher_code' => ['nullable', 'string', 'max:40'],
        ]);

        $merchant = Merchant::where('slug', $data['merchant_slug'])->where('active', true)->firstOrFail();

        $order = DB::transaction(function () use ($data, $merchant) {
            $subtotal = 0;
            $discountTotal = 0;
            $subtotalSale = 0;
            $buyerFeeTotal = 0;
            $buyerAdminTotal = 0;
            $sellerNetTotal = 0;
            $lines = [];

            foreach ($data['items'] as $item) {
                $product = Product::withoutGlobalScope('merchant')
                    ->where('merchant_id', $merchant->id)
                    ->where('id', $item['product_id'])
                    ->where('status', 'active')
                    ->lockForUpdate()
                    ->firstOrFail();

                // Fase 11: varian dipilih → harga & stok dari varian.
                $variant = null;
                $basePrice = $product->price;
                $nameSuffix = '';
                if (! empty($item['variant_id'])) {
                    $variant = $product->variants()
                        ->whereKey($item['variant_id'])
                        ->lockForUpdate()
                        ->firstOrFail();
                    $basePrice = $variant->effectivePrice($product);
                    $nameSuffix = ' — '.$variant->name;
                }

                $stockLeft = $variant ? $variant->stock : $product->stock;
                if ($stockLeft < $item['qty']) {
                    $label = $product->title.($variant ? " ({$variant->name})" : '');
                    abort(422, "Stok {$label} tidak cukup (tersisa {$stockLeft}).");
                }

                $f = $this->fee->line($basePrice, $product->discount_price, $item['qty']);

                $subtotal += $basePrice * $item['qty'];
                $discountTotal += $f['discount'];
                $subtotalSale += $f['base_sale'];
                $buyerFeeTotal += $f['buyer_fee'];
                $buyerAdminTotal += $f['buyer_admin_fee'];
                $sellerNetTotal += $f['seller_net'];

                $lines[] = [
                    'product_id' => $product->id,
                    'title' => $product->title.$nameSuffix,
                    'price' => $basePrice,
                    'discount_price' => $product->discount_price,
                    'qty' => $item['qty'],
                    'line_total' => $f['base_sale'],
                    'buyer_fee' => $f['buyer_fee'],
                    'buyer_admin_fee' => $f['buyer_admin_fee'],
                    'seller_net' => $f['seller_net'],
                ];

                if ($variant) {
                    $variant->decrement('stock', $item['qty']);
                }
                $product->decrement('stock', $item['qty']);
            }

            // Fase 14: voucher (dalam transaksi; redeem + kurangi kuota).
            $voucher = null;
            $voucherDiscount = 0;
            $shippingDiscount = 0;
            $shippingCost = $data['shipping_cost'];

            if (! empty($data['voucher_code'])) {
                $voucher = $this->promo->findUsable(
                    (string) $data['voucher_code'],
                    $merchant,
                    array_column($lines, 'product_id'),
                );
                if ($voucher) {
                    $vr = $this->promo->apply($voucher, $lines, $shippingCost);
                    if (! $vr['error']) {
                        $voucherDiscount = (int) $vr['discount'];
                        $shippingDiscount = (int) $vr['shipping_discount'];
                        if ($vr['free_shipping']) {
                            $shippingCost = 0;
                        }
                    }
                }
            }

            $order = Order::create([
                'merchant_id' => $merchant->id,
                'order_no' => $this->midtrans->generateOrderNo(),
                'buyer_name' => $data['buyer_name'],
                'buyer_phone' => $data['buyer_phone'],
                'buyer_email' => $data['buyer_email'] ?? null,
                'shipping_address' => $data['shipping_address'],
                'destination_city_id' => $data['destination_city_id'],
                'courier' => $data['courier'],
                'service' => $data['service'],
                'shipping_cost' => $shippingCost,
                'subtotal' => $subtotal,
                'discount_total' => $discountTotal,
                'subtotal_sale' => $subtotalSale,
                'buyer_fee' => $buyerFeeTotal,
                'buyer_admin_fee' => $buyerAdminTotal,
                'seller_net' => $sellerNetTotal,
                'total' => $subtotalSale + $buyerFeeTotal + $buyerAdminTotal + $shippingCost - $voucherDiscount,
                'payment_status' => 'unpaid',
                'fulfillment_status' => 'pending',
                'voucher_id' => $voucher?->id,
                'voucher_discount' => $voucherDiscount,
                'voucher_code' => $voucher?->code,
                'shipping_discount' => $shippingDiscount,
            ]);

            $order->items()->createMany($lines);

            // Fase 14: catat pemakaian voucher (kuota + limit per pembeli).
            if ($voucher && $voucherDiscount > 0) {
                $this->promo->redeem($voucher, $order->id, $voucherDiscount, $data['buyer_phone']);
            }

            return $order;
        });

        $snap = $this->midtrans->createSnap($order->load('items'));
        Audit::record('order.created', $order, ['order_no' => $order->order_no]);

        $this->notif->push(
            $merchant->id,
            'order.new',
            'Pesanan baru masuk',
            "Order {$order->order_no} dari {$order->buyer_name}. Total Rp".number_format($order->total, 0, ',', '.').'.',
            '/seller/orders',
        );

        return response()->json([
            'data' => [
                'order_no' => $order->order_no,
                'total' => $order->total,
                'rincian' => $this->rincian($order),
                'payment_url' => $snap['ok'] ? $snap['data']['redirect_url'] : null,
                'payment_note' => $snap['ok'] ? null : 'Payment gateway belum aktif; order tersimpan sebagai unpaid.',
            ],
        ], 201);
    }

    /** Daftar order milik merchant login. */
    public function index(Request $request): JsonResponse
    {
        $orders = Order::query()
            ->with('items')
            ->when($request->string('payment_status')->toString(), fn ($q, $s) => $q->where('payment_status', $s))
            ->when($request->string('fulfillment_status')->toString(), fn ($q, $s) => $q->where('fulfillment_status', $s))
            ->latest()
            ->paginate(20);

        return response()->json($orders);
    }

    public function show(Request $request, Order $order): JsonResponse
    {
        abort_unless($order->merchant_id === $request->user()->merchant->id, 403);

        return response()->json(['data' => $order->load('items')]);
    }

    /** Input nomor resi + tandai sudah dikirim. */
    public function ship(Request $request, Order $order): JsonResponse
    {
        abort_unless($order->merchant_id === $request->user()->merchant->id, 403);
        $data = $request->validate([
            'tracking_no' => ['required', 'string', 'max:64', 'regex:/^[A-Za-z0-9\-]+$/'],
        ]);
        $order->update([
            'tracking_no' => Str::upper($data['tracking_no']),
            'fulfillment_status' => 'shipped',
        ]);
        Audit::record('order.shipped', $order, ['tracking_no' => $order->tracking_no]);

        $this->notif->push(
            $order->merchant_id,
            'order.shipped',
            'Order ditandai terkirim',
            "Order {$order->order_no} dikirim dengan resi {$order->tracking_no}.",
            '/seller/orders',
        );

        return response()->json(['data' => $order->fresh()]);
    }

    /** Lacak status order publik via nomor order (storefront). */
    public function track(string $orderNo): JsonResponse

        return response()->json(['data' => [
            'order_no' => $order->order_no,
            'payment_status' => $order->payment_status,
            'fulfillment_status' => $order->fulfillment_status,
            'tracking_no' => $order->tracking_no,
            'courier' => $order->courier,
            'total' => $order->total,
            'rincian' => $this->rincian($order),
        ]]);
    }

    // ---- helper ----

    /** Bangun baris item dari request (preview; tanpa mutasi stok). */
    private function buildLines(Merchant $merchant, array $items): array
    {
        $rows = [];
        foreach ($items as $item) {
            $product = Product::withoutGlobalScope('merchant')
                ->where('merchant_id', $merchant->id)
                ->where('id', $item['product_id'])
                ->where('status', 'active')
                ->firstOrFail();

            $basePrice = $product->price;
            $title = $product->title;
            if (! empty($item['variant_id'])) {
                $variant = $product->variants()->whereKey($item['variant_id'])->firstOrFail();
                $basePrice = $variant->effectivePrice($product);
                $title .= ' — '.$variant->name;
            }

            $f = $this->fee->line($basePrice, $product->discount_price, $item['qty']);

            $rows[] = [
                'product_id' => $product->id,
                'title' => $title,
                'price' => $basePrice,
                'discount_price' => $product->discount_price,
                'qty' => $item['qty'],
                'unit_sale' => $f['unit_sale'],
                'line_total' => $f['base_sale'],
                'buyer_fee' => $f['buyer_fee'],
                'buyer_admin_fee' => $f['buyer_admin_fee'],
                'seller_net' => $f['seller_net'],
            ];
        }

        return $rows;
    }

    /** Ringkas rincian biaya dari daftar baris preview. */
    private function summarize(array $rows, int $shipping, ?Voucher $voucher = null, ?array $voucherResult = null): array
    {
        $subtotal = array_sum(array_map(fn ($r) => $r['price'] * $r['qty'], $rows));
        $sale = array_sum(array_map(fn ($r) => $r['line_total'], $rows));
        $fee = array_sum(array_map(fn ($r) => $r['buyer_fee'], $rows));
        $admin = array_sum(array_map(fn ($r) => $r['buyer_admin_fee'], $rows));
        $shippingCost = $shipping;
        $voucherDiscount = 0;
        $freeShipping = false;

        if ($voucher && $voucherResult && ! $voucherResult['error']) {
            $voucherDiscount = (int) $voucherResult['discount'];
            $freeShipping = (bool) $voucherResult['free_shipping'];
            if ($freeShipping) {
                $shippingCost = 0;
            }
        }

        $voucherDisallowed = $voucher && $voucherResult && $voucherResult['error'] ? $voucherResult['error'] : null;

        return [
            'items' => $rows,
            'subtotal' => $subtotal,
            'discount_total' => $subtotal - $sale,
            'subtotal_sale' => $sale,
            'buyer_fee' => $fee,
            'buyer_admin_fee' => $admin,
            'shipping_cost' => $shippingCost,
            'total' => $sale + $fee + $admin + $shippingCost - $voucherDiscount,
            'voucher' => $voucher ? [
                'code' => $voucher->code,
                'name' => $voucher->name,
                'discount' => $voucherDiscount,
                'free_shipping' => $freeShipping,
            ] : null,
            'voucher_disallowed' => $voucherDisallowed,
        ];
    }

    /** Rincian dari order tersimpan. */
    private function rincian(Order $order): array
    {
        return [
            'subtotal' => $order->subtotal,
            'discount_total' => $order->discount_total,
            'subtotal_sale' => $order->subtotal_sale,
            'buyer_fee' => $order->buyer_fee,
            'buyer_admin_fee' => $order->buyer_admin_fee,
            'shipping_cost' => $order->shipping_cost,
            'shipping_discount' => $order->shipping_discount,
            'voucher_code' => $order->voucher_code,
            'voucher_discount' => $order->voucher_discount,
            'total' => $order->total,
        ];
    }
}