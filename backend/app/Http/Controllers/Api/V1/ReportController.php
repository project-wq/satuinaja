<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Services\ReportService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;

/**
 * Laporan penjualan seller (fase 6).
 * Semua data dihitung real-time dari orders (tanpa tabel laporan).
 */
class ReportController extends Controller
{
    public function __construct(private ReportService $report)
    {
    }

    /**
     * GET /reports/sales?from=&to=&period=7d|30d|month|today
     * Mengembalikan ringkasan + deret harian + produk terlaris.
     */
    public function sales(Request $request): JsonResponse
    {
        $merchant = $request->user()->effectiveMerchant();
        [$from, $to] = $this->range($request);

        $data = [
            'range' => ['from' => $from->toDateString(), 'to' => $to->toDateString()],
            'summary' => $this->report->summary($merchant, $from, $to),
            'daily' => $this->report->daily($merchant, $from, $to),
            'top_products' => $this->report->topProducts($merchant, $from, $to),
        ];

        // ?compare=prev — bandingkan dengan periode sebelumnya (panjang sama).
        if ($request->string('compare')->toString() === 'prev') {
            $days = $from->diffInDays($to) + 1;
            $prevTo = $from->copy()->subSecond();
            $prevFrom = $prevTo->copy()->subDays($days - 1)->startOfDay();
            $prev = $this->report->summary($merchant, $prevFrom, $prevTo);
            $cur = $data['summary'];
            $data['previous'] = $prev;
            $data['delta'] = [
                'revenue' => $cur['revenue'] - $prev['revenue'],
                'revenue_pct' => $prev['revenue'] > 0
                    ? round((($cur['revenue'] - $prev['revenue']) / $prev['revenue']) * 100, 1)
                    : ($cur['revenue'] > 0 ? 100.0 : 0.0),
                'orders_paid' => $cur['orders_paid'] - $prev['orders_paid'],
                'seller_net' => $cur['seller_net'] - $prev['seller_net'],
            ];
        }

        return response()->json(['data' => $data]);
    }

    /** GET /reports/sales/export — unduh CSV laporan harian. */
    public function export(Request $request)
    {
        $merchant = $request->user()->effectiveMerchant();
        [$from, $to] = $this->range($request);

        $csv = $this->report->dailyCsv($merchant, $from, $to);
        $name = "laporan-penjualan-{$from->toDateString()}-{$to->toDateString()}.csv";

        return response($csv, 200, [
            'Content-Type' => 'text/csv; charset=UTF-8',
            'Content-Disposition' => "attachment; filename=\"{$name}\"",
        ]);
    }

    /** Rentang tanggal dari query (default 30 hari terakhir). */
    private function range(Request $request): array
    {
        $period = $request->string('period')->toString();

        if ($request->filled('from') && $request->filled('to')) {
            return [
                Carbon::parse($request->string('from')->toString())->startOfDay(),
                Carbon::parse($request->string('to')->toString())->endOfDay(),
            ];
        }

        $to = now()->endOfDay();

        $from = match ($period) {
            'today' => now()->startOfDay(),
            '7d' => now()->subDays(6)->startOfDay(),
            'month' => now()->startOfMonth(),
            default => now()->subDays(29)->startOfDay(),
        };

        return [$from, $to];
    }
}
