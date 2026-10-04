<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\Merchant;
use App\Models\Order;
use App\Models\Product;
use App\Models\Voucher;
use App\Models\VoucherRedemption;
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
                    'unit_sale' => $f['unit_sale'],
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
                // Batas pemakaian per pembeli ditegakkan di sini juga.
                if ($voucher && $this->promo->buyerLimitReached($voucher, $data['buyer_phone'] ?? null)) {
                    $voucher = null;
                }
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
    {
        $order = Order::withoutGlobalScope('merchant')
            ->where('order_no', $orderNo)
            ->firstOrFail();

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

    // ================= FASE 15: alur pemenuhan order =================

    /** Seller: tandai pesanan dikemas (pending → packed). */
    public function pack(Request $request, Order $order): JsonResponse
    {
        $this->authorizeOrder($request, $order);
        abort_unless($order->fulfillment_status === 'pending', 422, 'Hanya order pending yang bisa dikemas.');
        abort_unless($order->payment_status === 'paid', 422, 'Order belum dibayar.');

        $order->update(['fulfillment_status' => 'packed', 'packed_at' => now()]);
        Audit::record('order.packed', $order);
        $this->notif->push($order->merchant_id, 'order.packed', 'Order dikemas', "Order {$order->order_no} siap dikirim.", '/seller/orders');

        return response()->json(['data' => $order->fresh()]);
    }

    /** Seller: tandai pesanan tiba (shipped → delivered) — atau buyer konfirmasi via track token. */
    public function deliver(Request $request, Order $order): JsonResponse
    {
        $this->authorizeOrder($request, $order);
        abort_unless(in_array($order->fulfillment_status, ['shipped', 'packed'], true), 422, 'Order belum dalam pengiriman.');
        abort_unless($order->payment_status === 'paid', 422, 'Order belum dibayar.');

        $order->update(['fulfillment_status' => 'delivered', 'delivered_at' => now()]);
        Audit::record('order.delivered', $order);

        return response()->json(['data' => $order->fresh()]);
    }

    /** Seller: tandai pesanan selesai (delivered → completed) → cairkan saldo. */
    public function complete(Request $request, Order $order): JsonResponse
    {
        $this->authorizeOrder($request, $order);
        abort_unless($order->fulfillment_status === 'delivered', 422, 'Order belum diterima pembeli.');
        abort_unless($order->completed_at === null, 422, 'Order sudah selesai.');

        DB::transaction(function () use ($order) {
            $order->update(['fulfillment_status' => 'completed', 'completed_at' => now()]);
            // Saldo seller cair setelah order selesai (net = seller_net).
            app(\App\Services\BalanceService::class)->creditOrder($order);
        });
        Audit::record('order.completed', $order);

        return response()->json(['data' => $order->fresh()]);
    }

    /** Seller/buyer/admin: batalkan order (belum dikirim). Otomatis kembalikan stok. */
    public function cancel(Request $request, Order $order): JsonResponse
    {
        $user = $request->user();
        $isAdmin = $user->role === 'admin';
        if (! $isAdmin) {
            $this->authorizeOrder($request, $order);
        }

        abort_unless(in_array($order->fulfillment_status, ['pending', 'packed'], true), 422, 'Order sudah dikirim, gunakan retur.');

        $data = $request->validate([
            'reason' => ['nullable', 'string', 'max:255'],
            'by' => ['nullable', 'in:buyer,seller,admin'],
        ]);

        DB::transaction(function () use ($order, $data, $isAdmin, $user) {
            // Kembalikan stok item (produk + varian).
            foreach ($order->items as $line) {
                if ($line->variant_id) {
                    \App\Models\ProductVariant::withoutGlobalScope('merchant')
                        ->whereKey($line->variant_id)->increment('stock', $line->qty);
                }
                Product::withoutGlobalScope('merchant')
                    ->whereKey($line->product_id)->increment('stock', $line->qty);
            }

            // Kembalikan kuota voucher bila ada.
            if ($order->voucher_id && $order->voucher_discount > 0) {
                $v = Voucher::withoutGlobalScope('merchant')->whereKey($order->voucher_id)->first();
                if ($v) {
                    $v->decrement('used');
                    VoucherRedemption::where('voucher_id', $v->id)->where('order_id', $order->id)->delete();
                }
            }

            $order->update([
                'fulfillment_status' => 'cancelled',
                'cancelled_at' => now(),
                'cancelled_by' => $data['by'] ?? ($isAdmin ? 'admin' : 'seller'),
                'cancel_reason' => $data['reason'] ?? null,
            ]);
        });
        Audit::record('order.cancelled', $order, ['by' => $order->cancelled_by]);

        return response()->json(['data' => $order->fresh()]);
    }

    /** Buyer/seller: ajukan retur (setelah dikirim/diterima). */
    public function requestReturn(Request $request, Order $order): JsonResponse
    {
        $this->authorizeOrder($request, $order);
        abort_unless(in_array($order->fulfillment_status, ['shipped', 'delivered', 'completed'], true), 422, 'Order belum dikirim.');
        abort_unless(in_array($order->return_status, [null, 'rejected'], true), 422, 'Retur sudah diajukan.');

        $data = $request->validate(['reason' => ['required', 'string', 'max:500']]);

        $order->update([
            'return_status' => 'requested',
            'return_requested_at' => now(),
            'return_reason' => $data['reason'],
        ]);
        Audit::record('order.return_requested', $order);
        $this->notif->push($order->merchant_id, 'order.return', 'Pengajuan retur', "Order {$order->order_no} mengajukan retur.", '/seller/orders');

        return response()->json(['data' => $order->fresh()]);
    }

    /** Seller/admin: putuskan retur (approve/reject). Approve → kembalikan stok + potong saldo. */
    public function processReturn(Request $request, Order $order): JsonResponse
    {
        $user = $request->user();
        if ($user->role !== 'admin') {
            $this->authorizeOrder($request, $order);
        }
        abort_unless($order->return_status === 'requested', 422, 'Tidak ada pengajuan retur aktif.');

        $data = $request->validate([
            'decision' => ['required', 'in:approved,rejected'],
            'note' => ['nullable', 'string', 'max:500'],
        ]);

        DB::transaction(function () use ($order, $data) {
            if ($data['decision'] === 'approved') {
                foreach ($order->items as $line) {
                    if ($line->variant_id) {
                        \App\Models\ProductVariant::withoutGlobalScope('merchant')
                            ->whereKey($line->variant_id)->increment('stock', $line->qty);
                    }
                    Product::withoutGlobalScope('merchant')
                        ->whereKey($line->product_id)->increment('stock', $line->qty);
                }
                $order->update([
                    'return_status' => 'approved',
                    'seller_note' => $data['note'] ?? null,
                    'fulfillment_status' => 'returned',
                ]);
            } else {
                $order->update(['return_status' => 'rejected', 'seller_note' => $data['note'] ?? null]);
            }
        });
        Audit::record('order.return_'.$data['decision'], $order);

        return response()->json(['data' => $order->fresh()]);
    }

    /** Admin: daftar semua order lintas merchant dengan filter. */
    public function adminIndex(Request $request): JsonResponse
    {
        abort_unless($request->user()->role === 'admin', 403);

        $orders = Order::withoutGlobalScope('merchant')
            ->with('items')
            ->when($request->string('payment_status')->toString(), fn ($q, $s) => $q->where('payment_status', $s))
            ->when($request->string('fulfillment_status')->toString(), fn ($q, $s) => $q->where('fulfillment_status', $s))
            ->when($request->string('return_status')->toString(), fn ($q, $s) => $q->where('return_status', $s))
            ->when($request->integer('merchant_id'), fn ($q, $m) => $q->where('merchant_id', $m))
            ->latest()
            ->paginate(30);

        return response()->json($orders);
    }

    /** Guard kepemilikan order oleh merchant login (atau admin untuk semua order). */
    private function authorizeOrder(Request $request, Order $order): void
    {
        if ($request->user()->role === 'admin') {
            return;
        }
        abort_unless($order->merchant_id === $request->user()->merchant?->id, 403);
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