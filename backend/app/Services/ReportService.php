<?php

namespace App\Services;

use App\Models\Merchant;
use App\Models\Order;
use App\Models\OrderItem;
use Illuminate\Support\Carbon;

/**
 * Laporan penjualan (fase 6). Dihitung real-time dari tabel orders —
 * tidak butuh tabel tersendiri. Semua query di-scope ke merchant login.
 */
class ReportService
{
    /** Ringkasan periode: omzet, jumlah order, item terjual, fee, net seller. */
    public function summary(Merchant $merchant, Carbon $from, Carbon $to): array
    {
        $base = Order::withoutGlobalScope('merchant')
            ->where('orders.merchant_id', $merchant->id)
            ->whereBetween('orders.created_at', [$from, $to]);

        $paid = (clone $base)->where('payment_status', 'paid');

        $agg = (clone $paid)->selectRaw(
            'COUNT(*) as orders,
             COALESCE(SUM(total),0) as revenue,
             COALESCE(SUM(subtotal_sale),0) as gross_sale,
             COALESCE(SUM(discount_total),0) as discount,
             COALESCE(SUM(buyer_fee),0) as buyer_fee,
             COALESCE(SUM(buyer_admin_fee),0) as buyer_admin_fee,
             COALESCE(SUM(seller_net),0) as seller_net,
             COALESCE(SUM(shipping_cost),0) as shipping'
        )->first();

        $itemsSold = (int) OrderItem::whereIn('order_id', (clone $paid)->select('orders.id'))
            ->sum('qty');

        return [
            'from' => $from->toDateString(),
            'to' => $to->toDateString(),
            'orders_paid' => (int) $agg->orders,
            'orders_all' => (int) (clone $base)->count(),
            'orders_pending' => (int) (clone $base)->where('payment_status', 'unpaid')->count(),
            'revenue' => (int) $agg->revenue,           // total dibayar buyer
            'gross_sale' => (int) $agg->gross_sale,     // harga jual setelah diskon
            'discount' => (int) $agg->discount,
            'buyer_fee' => (int) $agg->buyer_fee,
            'buyer_admin_fee' => (int) $agg->buyer_admin_fee,
            'shipping' => (int) $agg->shipping,
            'seller_net' => (int) $agg->seller_net,     // pendapatan bersih seller
            'items_sold' => $itemsSold,
            'avg_order' => $agg->orders > 0 ? (int) round($agg->revenue / $agg->orders) : 0,
        ];
    }

    /** Deret harian untuk grafik (isi 0 pada hari tanpa order). */
    public function daily(Merchant $merchant, Carbon $from, Carbon $to): array
    {
        $rows = Order::withoutGlobalScope('merchant')
            ->where('orders.merchant_id', $merchant->id)
            ->where('payment_status', 'paid')
            ->whereBetween('orders.created_at', [$from, $to])
            ->selectRaw('DATE(orders.created_at) as d,
                COUNT(*) as orders,
                COALESCE(SUM(total),0) as revenue,
                COALESCE(SUM(seller_net),0) as seller_net')
            ->groupBy('d')
            ->get()
            ->keyBy('d');

        $out = [];
        $cursor = $from->copy()->startOfDay();
        $end = $to->copy()->startOfDay();
        while ($cursor->lte($end)) {
            $key = $cursor->toDateString();
            $row = $rows[$key] ?? null;
            $out[] = [
                'date' => $key,
                'orders' => (int) ($row->orders ?? 0),
                'revenue' => (int) ($row->revenue ?? 0),
                'seller_net' => (int) ($row->seller_net ?? 0),
            ];
            $cursor->addDay();
        }

        return $out;
    }

    /** Produk terlaris pada periode. */
    public function topProducts(Merchant $merchant, Carbon $from, Carbon $to, int $limit = 10): array
    {
        return OrderItem::query()
            ->join('orders', 'orders.id', '=', 'order_items.order_id')
            ->where('orders.merchant_id', $merchant->id)
            ->where('orders.payment_status', 'paid')
            ->whereBetween('orders.created_at', [$from, $to])
            ->groupBy('order_items.product_id', 'order_items.title')
            ->selectRaw('order_items.product_id,
                order_items.title,
                SUM(order_items.qty) as qty,
                COALESCE(SUM(order_items.line_total),0) as gross,
                COALESCE(SUM(order_items.seller_net),0) as seller_net')
            ->orderByDesc('qty')
            ->limit($limit)
            ->get()
            ->map(fn ($r) => [
                'product_id' => (int) $r->product_id,
                'title' => $r->title,
                'qty' => (int) $r->qty,
                'gross' => (int) $r->gross,
                'seller_net' => (int) $r->seller_net,
            ])
            ->all();
    }

    /** Export CSV laporan harian. */
    public function dailyCsv(Merchant $merchant, Carbon $from, Carbon $to): string
    {
        $rows = $this->daily($merchant, $from, $to);
        $fh = fopen('php://temp', 'r+');
        fputcsv($fh, ['tanggal', 'order', 'omzet', 'pendapatan_bersih']);
        foreach ($rows as $r) {
            fputcsv($fh, [$r['date'], $r['orders'], $r['revenue'], $r['seller_net']]);
        }
        rewind($fh);
        $csv = stream_get_contents($fh);
        fclose($fh);

        return $csv;
    }
}
