<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\Merchant;
use App\Models\Order;
use App\Models\Product;
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
    ) {
    }

    /**
     * Preview rincian biaya (halaman checkout sebelum bayar):
     * harga - diskon + fee 11% + admin 1000/unit + ongkir = total.
     */
    public function preview(Request $request): JsonResponse
    {
        $data = $request->validate([
            'merchant_slug' => ['required', 'string', 'exists:merchants,slug'],
            'items' => ['required', 'array', 'min:1', 'max:50'],
            'items.*.product_id' => ['required', 'integer', 'exists:products,id'],
            'items.*.qty' => ['required', 'integer', 'min:1', 'max:1000'],
            'shipping_cost' => ['nullable', 'integer', 'min:0'],
        ]);

        $merchant = Merchant::where('slug', $data['merchant_slug'])->where('active', true)->firstOrFail();
        $rows = $this->buildLines($merchant, $data['items']);

        return response()->json(['data' => $this->summarize($rows, $data['shipping_cost'] ?? 0)]);
    }

    /**
     * Checkout publik: buat order (dengan rincian fee) + transaksi Midtrans.
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
            'items.*.qty' => ['required', 'integer', 'min:1', 'max:1000'],
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

                if ($product->stock < $item['qty']) {
                    abort(422, "Stok {$product->title} tidak cukup (tersisa {$product->stock}).");
                }

                $f = $this->fee->line($product->price, $product->discount_price, $item['qty']);

                $subtotal += $product->price * $item['qty'];
                $discountTotal += $f['discount'];
                $subtotalSale += $f['base_sale'];
                $buyerFeeTotal += $f['buyer_fee'];
                $buyerAdminTotal += $f['buyer_admin_fee'];
                $sellerNetTotal += $f['seller_net'];

                $lines[] = [
                    'product_id' => $product->id,
                    'title' => $product->title,
                    'price' => $product->price,
                    'discount_price' => $product->discount_price,
                    'qty' => $item['qty'],
                    'line_total' => $f['base_sale'],
                    'buyer_fee' => $f['buyer_fee'],
                    'buyer_admin_fee' => $f['buyer_admin_fee'],
                    'seller_net' => $f['seller_net'],
                ];

                $product->decrement('stock', $item['qty']);
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
                'shipping_cost' => $data['shipping_cost'],
                'subtotal' => $subtotal,
                'discount_total' => $discountTotal,
                'subtotal_sale' => $subtotalSale,
                'buyer_fee' => $buyerFeeTotal,
                'buyer_admin_fee' => $buyerAdminTotal,
                'seller_net' => $sellerNetTotal,
                'total' => $subtotalSale + $buyerFeeTotal + $buyerAdminTotal + $data['shipping_cost'],
                'payment_status' => 'unpaid',
                'fulfillment_status' => 'pending',
            ]);

            $order->items()->createMany($lines);

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

            $f = $this->fee->line($product->price, $product->discount_price, $item['qty']);

            $rows[] = [
                'product_id' => $product->id,
                'title' => $product->title,
                'price' => $product->price,
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
    private function summarize(array $rows, int $shipping): array
    {
        $subtotal = array_sum(array_map(fn ($r) => $r['price'] * $r['qty'], $rows));
        $sale = array_sum(array_map(fn ($r) => $r['line_total'], $rows));
        $fee = array_sum(array_map(fn ($r) => $r['buyer_fee'], $rows));
        $admin = array_sum(array_map(fn ($r) => $r['buyer_admin_fee'], $rows));

        return [
            'items' => $rows,
            'subtotal' => $subtotal,
            'discount_total' => $subtotal - $sale,
            'subtotal_sale' => $sale,
            'buyer_fee' => $fee,
            'buyer_admin_fee' => $admin,
            'shipping_cost' => $shipping,
            'total' => $sale + $fee + $admin + $shipping,
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
            'total' => $order->total,
        ];
    }
}