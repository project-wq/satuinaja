<?php

namespace App\Services;

use App\Models\Channel;
use App\Models\Order;
use App\Models\Product;
use App\Support\Audit;
use Illuminate\Support\Facades\DB;

/**
 * Ingest order dari marketplace (Shopee/Tokopedia) via webhook (Fase 7).
 *
 * Alur: marketplace push order → verifikasi signature → cocokkan item ke
 * produk lokal (lewat SKU = product.slug) → buat Order + OrderItem + potong
 * stok, lalu credit saldo seller (order sudah dibayar di marketplace).
 *
 * Idempotent: kalau (channel_id, external_order_id) sudah ada, request
 * diulang tidak membuat order baru.
 */
class MarketplaceService
{
    public function __construct(
        private FeeService $fee,
        private BalanceService $balance,
        private NotificationService $notif,
        private MidtransService $midtrans,
    ) {
    }

    /**
     * Buat/ambil order dari payload marketplace.
     *
     * @param  array  $data  {external_order_id, buyer_name, buyer_phone?, shipping_address?, items:[{sku|product_id, qty, price?}]}
     * @return array {order: Order, created: bool, unmatched: string[]}
     */
    public function ingest(Channel $channel, array $data): array
    {
        $externalId = (string) ($data['external_order_id'] ?? '');
        if ($externalId === '') {
            throw new \InvalidArgumentException('external_order_id wajib.');
        }

        $existing = Order::withoutGlobalScope('merchant')
            ->where('channel_id', $channel->id)
            ->where('external_order_id', $externalId)
            ->first();
        if ($existing) {
            return ['order' => $existing, 'created' => false, 'unmatched' => []];
        }

        $items = array_values((array) ($data['items'] ?? []));
        if ($items === []) {
            throw new \InvalidArgumentException('items kosong.');
        }

        $unmatched = [];

        $order = DB::transaction(function () use ($channel, $data, $externalId, $items, &$unmatched) {
            $subtotal = 0;
            $discountTotal = 0;
            $subtotalSale = 0;
            $buyerFeeTotal = 0;
            $buyerAdminTotal = 0;
            $sellerNetTotal = 0;
            $lines = [];

            foreach ($items as $item) {
                $qty = (int) ($item['qty'] ?? 0);
                if ($qty < 1) {
                    continue;
                }

                $product = $this->resolveProduct($channel, $item);
                if (! $product) {
                    $unmatched[] = (string) ($item['sku'] ?? $item['product_id'] ?? '?');
                    continue;
                }

                /** @var Product $product */
                $product = Product::withoutGlobalScope('merchant')
                    ->where('id', $product->id)
                    ->lockForUpdate()
                    ->firstOrFail();

                $f = $this->fee->line($product->price, $product->discount_price, $qty);

                $subtotal += $product->price * $qty;
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
                    'qty' => $qty,
                    'line_total' => $f['base_sale'],
                    'buyer_fee' => $f['buyer_fee'],
                    'buyer_admin_fee' => $f['buyer_admin_fee'],
                    'seller_net' => $f['seller_net'],
                ];

                // Potong stok lokal (tidak negatif).
                $product->update(['stock' => max(0, $product->stock - $qty)]);
            }

            if ($lines === []) {
                throw new \RuntimeException('Tak ada item yang cocok dengan produk lokal.');
            }

            $order = Order::create([
                'merchant_id' => $channel->merchant_id,
                'channel_id' => $channel->id,
                'source' => $channel->platform,
                'external_order_id' => $externalId,
                'order_no' => $this->midtrans->generateOrderNo(),
                'buyer_name' => (string) ($data['buyer_name'] ?? 'Pembeli '.$channel->platform),
                'buyer_phone' => (string) ($data['buyer_phone'] ?? '-'),
                'buyer_email' => $data['buyer_email'] ?? null,
                'shipping_address' => (string) ($data['shipping_address'] ?? '-'),
                'destination_city_id' => $data['destination_city_id'] ?? null,
                'courier' => $data['courier'] ?? null,
                'service' => $data['service'] ?? null,
                'shipping_cost' => (int) ($data['shipping_cost'] ?? 0),
                'subtotal' => $subtotal,
                'discount_total' => $discountTotal,
                'subtotal_sale' => $subtotalSale,
                'buyer_fee' => $buyerFeeTotal,
                'buyer_admin_fee' => $buyerAdminTotal,
                'seller_net' => $sellerNetTotal,
                'total' => $subtotalSale + $buyerFeeTotal + $buyerAdminTotal + (int) ($data['shipping_cost'] ?? 0),
                // Marketplace sudah menerima pembayaran saat order masuk.
                'payment_status' => 'paid',
                'fulfillment_status' => 'pending',
            ]);

            $order->items()->createMany($lines);

            return $order;
        });

        // Credit saldo seller (idempotent lewat ledger).
        $this->balance->creditOrder($order);

        Audit::record('order.imported.'.$channel->platform, $order, [
            'external_order_id' => $externalId,
            'unmatched' => $unmatched,
        ]);

        $this->notif->push(
            $order->merchant_id,
            'order.new',
            'Pesanan marketplace masuk',
            "Order {$order->order_no} dari {$channel->platform} ({$order->buyer_name}). Total Rp"
                .number_format($order->total, 0, ',', '.').'.',
            '/seller/orders',
        );

        return ['order' => $order, 'created' => true, 'unmatched' => $unmatched];
    }

    /** Cocokkan item payload ke produk lokal: product_id dulu, lalu slug (SKU). */
    private function resolveProduct(Channel $channel, array $item): ?Product
    {
        $q = Product::withoutGlobalScope('merchant')->where('merchant_id', $channel->merchant_id);

        if (! empty($item['product_id'])) {
            $p = (clone $q)->where('id', (int) $item['product_id'])->first();
            if ($p) {
                return $p;
            }
        }

        if (! empty($item['sku'])) {
            return (clone $q)->where('slug', (string) $item['sku'])->first();
        }

        return null;
    }
}
