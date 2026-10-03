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
     * Fase 13: item boleh membawa model_sku/model_id Shopee — dicocokkan ke
     * varian lokal (model_sku == variant.sku, atau shopee_model_id). Harga,
     * fee, dan potong stok mengikuti varian; order_items.variant_id diisi.
     *
     * @param  array  $data  {external_order_id, buyer_name, buyer_phone?, shipping_address?, items:[{sku|product_id|model_sku|model_id, qty, price?}]}
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
                    $unmatched[] = (string) ($item['sku'] ?? $item['product_id'] ?? $item['model_sku'] ?? $item['model_id'] ?? '?');
                    continue;
                }

                /** @var Product $product */
                $product = Product::withoutGlobalScope('merchant')
                    ->where('id', $product->id)
                    ->lockForUpdate()
                    ->firstOrFail();

                // Fase 13: cocokkan varian via model_sku/model_id Shopee.
                $variant = $this->resolveVariant($product, $item);
                $unitPrice = $variant ? $variant->effectivePrice($product) : $product->price;
                $unitDiscount = $variant ? null : $product->discount_price;

                $f = $this->fee->line($unitPrice, $unitDiscount, $qty);

                $subtotal += $unitPrice * $qty;
                $discountTotal += $f['discount'];
                $subtotalSale += $f['base_sale'];
                $buyerFeeTotal += $f['buyer_fee'];
                $buyerAdminTotal += $f['buyer_admin_fee'];
                $sellerNetTotal += $f['seller_net'];

                $lines[] = [
                    'product_id' => $product->id,
                    'variant_id' => $variant?->id,
                    'title' => $product->title.($variant ? ' — '.$variant->name : ''),
                    'price' => $unitPrice,
                    'discount_price' => $unitDiscount,
                    'qty' => $qty,
                    'line_total' => $f['base_sale'],
                    'buyer_fee' => $f['buyer_fee'],
                    'buyer_admin_fee' => $f['buyer_admin_fee'],
                    'seller_net' => $f['seller_net'],
                ];

                // Potong stok lokal (tidak negatif): varian bila ada, lalu agregat.
                if ($variant) {
                    $variant->update(['stock' => max(0, $variant->stock - $qty)]);
                    $product->update(['stock' => max(0, (int) $product->variants()->sum('stock'))]);
                } else {
                    $product->update(['stock' => max(0, $product->stock - $qty)]);
                }
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

    /** Cocokkan item payload ke produk lokal: product_id dulu, lalu slug (SKU), lalu model_sku/model_id ke varian. */
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
            $p = (clone $q)->where('slug', (string) $item['sku'])->first();
            if ($p) {
                return $p;
            }
        }

        // Fase 13: Shopee kirim model_sku/item_sku atau model_id — cocokkan
        // lewat varian (sku / shopee_model_id), kembalikan produk induknya.
        $modelSku = (string) ($item['model_sku'] ?? $item['item_sku'] ?? '');
        $modelId = (string) ($item['model_id'] ?? '');
        if ($modelSku !== '' || $modelId !== '') {
            $variant = \App\Models\ProductVariant::query()
                ->whereHas('product', fn ($pq) => $pq->withoutGlobalScope('merchant')->where('merchant_id', $channel->merchant_id))
                ->when($modelId !== '', fn ($vq) => $vq->where('shopee_model_id', $modelId))
                ->when($modelId === '' && $modelSku !== '', fn ($vq) => $vq->where('sku', $modelSku))
                ->first();
            if ($variant) {
                return $variant->product;
            }
            // model_sku Shopee = sku varian lokal, tapi item-level sku bisa
            // juga sama dengan slug produk (produk tanpa varian).
            if ($modelSku !== '') {
                $p = (clone $q)->where('slug', $modelSku)->first();
                if ($p) {
                    return $p;
                }
            }
        }

        return null;
    }

    /**
     * Fase 13: cocokkan varian dalam produk yang sudah ter-resolve.
     * Urutan: shopee_model_id (model_id) → sku varian (model_sku/item_sku/sku).
     */
    private function resolveVariant(Product $product, array $item): ?\App\Models\ProductVariant
    {
        $modelId = (string) ($item['model_id'] ?? '');
        $modelSku = (string) ($item['model_sku'] ?? $item['item_sku'] ?? $item['sku'] ?? '');

        $q = $product->variants();
        if ($modelId !== '') {
            $v = (clone $q)->where('shopee_model_id', $modelId)->first();
            if ($v) {
                return $v;
            }
        }
        if ($modelSku !== '') {
            $v = (clone $q)->where('sku', $modelSku)->first();
            if ($v) {
                return $v;
            }
        }

        return null;
    }
}
