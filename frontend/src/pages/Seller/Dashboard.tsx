import { useQuery } from '@tanstack/react-query'
import { Link } from 'react-router-dom'
import { api, type Channel, type Order, type Paginated, type Product } from '../../services/api'
import { rupiah } from '../../components/ShopHeader'
import { useAuth } from '../../store'

export default function Dashboard() {
  const { user } = useAuth()

  const products = useQuery({
    queryKey: ['products'],
    queryFn: () => api.get<Paginated<Product>>('/products'),
  })

  const orders = useQuery({
    queryKey: ['orders'],
    queryFn: () => api.get<Paginated<Order>>('/orders'),
  })

  const channels = useQuery({
    queryKey: ['channels'],
    queryFn: () => api.get<{ data: Channel[] }>('/channels'),
  })

  const report = useQuery({
    queryKey: ['reports-sales-7d'],
    queryFn: () => api.get<{ data: { daily: { d: string; revenue: number; orders: number }[]; summary: { revenue: number } } }>('/reports/sales?period=7d'),
  })

  const totalProducts = products.data?.total ?? 0
  const activeChannels = channels.data?.data.filter((c) => c.active).length ?? 0
  const paidOrders = orders.data?.data.filter((o) => o.payment_status === 'paid').length ?? 0
  const revenue = report.data?.data.summary.revenue ??
    (orders.data?.data
      .filter((o) => o.payment_status === 'paid')
      .reduce((a, o) => a + o.total, 0) ?? 0)

  const cards = [
    { label: 'Produk', value: totalProducts, hint: `${products.data?.data.filter((p) => p.status === 'active').length ?? 0} aktif` },
    { label: 'Channel Aktif', value: activeChannels, hint: `dari ${channels.data?.data.length ?? 0} terhubung` },
    { label: 'Pesanan Dibayar', value: paidOrders, hint: `${orders.data?.total ?? 0} total pesanan` },
    { label: 'Pendapatan', value: rupiah(revenue), hint: 'dari pesanan paid' },
  ]

  return (
    <div className="space-y-6">
      <div>
        <h1 className="text-2xl font-bold">Halo, {user?.name}</h1>
        <p className="text-slate-500 text-sm mt-1">
          Toko: <span className="font-medium text-slate-700">{user?.merchant?.name}</span> ·{' '}
          {user?.merchant && (
            <Link to={`/${user.merchant.slug}`} className="underline">
              /{user.merchant.slug}
            </Link>
          )}
        </p>
      </div>

      <div className="grid grid-cols-2 lg:grid-cols-4 gap-4">
        {cards.map((c) => (
          <div key={c.label} className="bg-white rounded-xl border border-slate-200 p-4">
            <p className="text-xs uppercase tracking-wide text-slate-500">{c.label}</p>
            <p className="text-2xl font-bold mt-1">{c.value}</p>
            <p className="text-xs text-slate-400 mt-1">{c.hint}</p>
          </div>
        ))}
      </div>

      <div className="grid lg:grid-cols-2 gap-4">
        <section className="bg-white rounded-xl border border-slate-200 p-5">
          <div className="flex items-center justify-between">
            <h2 className="font-semibold">Penjualan 7 Hari Terakhir</h2>
            <Link to="/seller/balance" className="text-sm underline text-slate-500">
              Detail
            </Link>
          </div>
          <div className="mt-4 flex items-end gap-1.5 h-32">
            {(report.data?.data.daily ?? []).map((d) => {
              const max = Math.max(...(report.data?.data.daily ?? []).map((x) => x.revenue), 1)
              const h = d.revenue > 0 ? Math.max(6, Math.round((d.revenue / max) * 100)) : 2
              return (
                <div key={d.d} className="flex-1 flex flex-col items-center gap-1" title={`${d.d}: ${rupiah(d.revenue)}`}>
                  <div
                    className="w-full rounded-t-md bg-slate-900"
                    style={{ height: `${h}%` }}
                  />
                  <span className="text-[10px] text-slate-400">{d.d.slice(8)}</span>
                </div>
              )
            })}
            {!report.data && <p className="text-sm text-slate-400">Memuat…</p>}
          </div>
        </section>

        <section className="bg-white rounded-xl border border-slate-200 p-5">
          <div className="flex items-center justify-between">
            <h2 className="font-semibold">Channel</h2>
            <Link to="/seller/channels" className="text-sm underline text-slate-500">
              Atur
            </Link>
          </div>
          <ul className="mt-3 divide-y divide-slate-100">
            {channels.data?.data.map((c) => (
              <li key={c.id} className="py-2 flex items-center justify-between text-sm">
                <span className="capitalize">{c.platform}</span>
                <span
                  className={`text-xs px-2 py-0.5 rounded-full ${
                    c.active ? 'bg-emerald-50 text-emerald-700' : 'bg-slate-100 text-slate-500'
                  }`}
                >
                  {c.active ? 'aktif' : 'nonaktif'}
                </span>
              </li>
            ))}
          </ul>
        </section>
      </div>
    </div>
  )
}
