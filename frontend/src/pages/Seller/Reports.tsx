import { useState } from 'react'
import { useToast } from '../../hooks/useToast'
import { useQuery } from '@tanstack/react-query'
import { api } from '../../services/api'
import { rupiah } from '../../components/ShopHeader'

interface Summary {
  orders_paid: number
  orders_all: number
  orders_pending: number
  revenue: number
  seller_net: number
  items_sold: number
  avg_order: number
  conversion_rate: number
}

interface DayRow {
  date: string
  orders: number
  revenue: number
  seller_net: number
}

interface TopProduct {
  product_id: number
  title: string
  qty: number
  gross: number
  seller_net: number
}

interface ReportData {
  range: { from: string; to: string }
  summary: Summary
  daily: DayRow[]
  top_products: TopProduct[]
  previous?: Summary
  delta?: { revenue: number; revenue_pct: number; orders_paid: number; seller_net: number }
}

const PERIODS = [
  { key: 'today', label: 'Hari ini' },
  { key: '7d', label: '7 hari' },
  { key: '30d', label: '30 hari' },
  { key: 'month', label: 'Bulan ini' },
] as const

export default function Reports() {
  const [period, setPeriod] = useState<string>('30d')
  const [compare, setCompare] = useState(false)

  const report = useQuery({
    queryKey: ['reports', period, compare],
    queryFn: () =>
      api.get<ReportData>(`/reports/sales?period=${period}${compare ? '&compare=prev' : ''}`),
  })

  const d = report.data
  const s = d?.summary
  const max = Math.max(...(d?.daily ?? []).map((x) => x.revenue), 1)
  const deltaUp = (d?.delta?.revenue ?? 0) >= 0

  return (
    <div className="space-y-6">
      <div className="flex flex-wrap items-center justify-between gap-3">
        <h1 className="text-2xl font-bold">Laporan</h1>
        <div className="flex items-center gap-2">
          {PERIODS.map((p) => (
            <button
              key={p.key}
              onClick={() => setPeriod(p.key)}
              className={`px-3 py-1.5 rounded-lg text-sm font-medium ${
                period === p.key ? 'bg-slate-900 text-white' : 'bg-white border border-slate-200 text-slate-600'
              }`}
            >
              {p.label}
            </button>
          ))}
          <label className="flex items-center gap-1.5 text-sm text-slate-600 ml-2">
            <input type="checkbox" checked={compare} onChange={(e) => setCompare(e.target.checked)} />
            Bandingkan periode lalu
          </label>
        </div>
      </div>

      {report.isLoading && <p className="text-sm text-slate-400">Memuat…</p>}
      {report.isError && <p className="text-sm text-rose-600">Gagal memuat laporan.</p>}

      {s && (
        <>
          <div className="grid grid-cols-2 lg:grid-cols-4 gap-4">
            {[
              { label: 'Omzet', value: rupiah(s.revenue), hint: `${s.orders_paid} pesanan dibayar` },
              { label: 'Pendapatan Bersih', value: rupiah(s.seller_net), hint: `${s.items_sold} item terjual` },
              { label: 'Rata-rata Order', value: rupiah(s.avg_order), hint: `konversi ${s.conversion_rate}%` },
              { label: 'Menunggu Bayar', value: s.orders_pending, hint: `dari ${s.orders_all} total order` },
            ].map((c) => (
              <div key={c.label} className="bg-white rounded-xl border border-slate-200 p-4">
                <p className="text-xs uppercase tracking-wide text-slate-500">{c.label}</p>
                <p className="text-2xl font-bold mt-1">{c.value}</p>
                <p className="text-xs text-slate-400 mt-1">{c.hint}</p>
              </div>
            ))}
          </div>

          {d?.delta && (
            <div className={`rounded-xl border p-4 text-sm ${deltaUp ? 'bg-emerald-50 border-emerald-200 text-emerald-800' : 'bg-rose-50 border-rose-200 text-rose-700'}`}>
              vs periode lalu: {deltaUp ? '+' : ''}
              {rupiah(d.delta.revenue)} ({d.delta.revenue_pct}%) ·{' '}
              {d.delta.orders_paid >= 0 ? '+' : ''}{d.delta.orders_paid} pesanan
            </div>
          )}

          <section className="bg-white rounded-xl border border-slate-200 p-5">
            <div className="flex items-center justify-between">
              <h2 className="font-semibold">Grafik Harian</h2>
              <a
                href={`/api/v1/reports/sales/export?period=${period}`}
                className="text-sm underline text-slate-500"
              >
                Unduh CSV
              </a>
            </div>
            <div className="mt-4 flex items-end gap-1.5 h-40">
              {(d?.daily ?? []).map((row) => {
                const h = row.revenue > 0 ? Math.max(6, Math.round((row.revenue / max) * 100)) : 2
                return (
                  <div key={row.date} className="flex-1 flex flex-col items-center gap-1" title={`${row.date}: ${rupiah(row.revenue)} (${row.orders} order)`}>
                    <div className="w-full rounded-t-md bg-slate-900" style={{ height: `${h}%` }} />
                    <span className="text-[10px] text-slate-400">{row.date.slice(8)}</span>
                  </div>
                )
              })}
            </div>
          </section>

          <section className="bg-white rounded-xl border border-slate-200 p-5">
            <h2 className="font-semibold">Produk Terlaris</h2>
            <ul className="mt-3 divide-y divide-slate-100">
              {(d?.top_products ?? []).map((p, i) => (
                <li key={p.product_id} className="py-2 flex items-center justify-between text-sm">
                  <span className="truncate pr-3">
                    <span className="text-slate-400 mr-2">#{i + 1}</span>
                    {p.title}
                  </span>
                  <span className="text-slate-500 shrink-0">
                    {p.qty} terjual · {rupiah(p.gross)}
                  </span>
                </li>
              ))}
              {(d?.top_products ?? []).length === 0 && (
                <li className="py-2 text-sm text-slate-400">Belum ada penjualan.</li>
              )}
            </ul>
          </section>
        </>
      )}
    </div>
  )
}
