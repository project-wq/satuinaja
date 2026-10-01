import { useEffect, useState } from 'react'
import { api, ApiError } from '../../services/api'
import { useAuth } from '../../store'

interface Stats {
  users: { total: number; merchants: number }
  products: number
  channels: number
  orders: { total: number; revenue: number; this_month: number }
  publishes: number
}

interface MerchantRow {
  id: number
  name: string
  slug: string
  email: string | null
  plan_code: string
  publishes_this_month: number
  active: boolean
  products_count: number
  orders_count: number
  created_at: string
}

export default function Admin() {
  const { user } = useAuth()
  const [stats, setStats] = useState<Stats | null>(null)
  const [rows, setRows] = useState<MerchantRow[]>([])
  const [err, setErr] = useState('')
  const [busy, setBusy] = useState<number | null>(null)

  useEffect(() => {
    if (user?.role !== 'admin') return
    api
      .get<{ data: Stats }>('/admin/stats')
      .then((r) => setStats(r.data))
      .catch((e) => setErr((e as ApiError).message))
    loadMerchants()
  }, [user?.role])

  const loadMerchants = async () => {
    try {
      const r = await api.get<{ data: MerchantRow[] }>('/admin/merchants')
      setRows(r.data)
    } catch (e) {
      setErr((e as ApiError).message)
    }
  }

  const toggleBan = async (m: MerchantRow) => {
    setBusy(m.id)
    setErr('')
    try {
      await api.put(`/admin/merchants/${m.id}/status`, { active: !m.active })
      await loadMerchants()
    } catch (e) {
      setErr((e as ApiError).message)
    } finally {
      setBusy(null)
    }
  }

  const setPlan = async (m: MerchantRow, plan: string) => {
    setBusy(m.id)
    setErr('')
    try {
      await api.put(`/admin/merchants/${m.id}/plan`, { plan_code: plan })
      await loadMerchants()
    } catch (e) {
      setErr((e as ApiError).message)
    } finally {
      setBusy(null)
    }
  }

  if (user?.role !== 'admin') {
    return <div className="text-slate-500">Halaman ini khusus admin.</div>
  }

  return (
    <div className="space-y-6">
      <h1 className="text-2xl font-bold">Admin</h1>

      {err && (
        <div className="bg-red-50 border border-red-200 text-red-700 text-sm rounded-lg px-4 py-3">
          {err}
        </div>
      )}

      {stats && (
        <div className="grid grid-cols-2 md:grid-cols-5 gap-3">
          {(
            [
              ['Pengguna', stats.users.total],
              ['Merchant', stats.users.merchants],
              ['Produk', stats.products],
              ['Channel', stats.channels],
              ['Order', stats.orders.total],
            ] as [string, number][]
          ).map(([label, v]) => (
            <div key={label} className="bg-white rounded-xl border border-slate-200 p-4">
              <div className="text-xs text-slate-400">{label}</div>
              <div className="text-xl font-bold">{v}</div>
            </div>
          ))}
        </div>
      )}

      <div className="bg-white rounded-xl border border-slate-200 overflow-hidden">
        <div className="px-4 py-3 border-b border-slate-100 text-sm font-semibold text-slate-600">
          Merchant ({rows.length})
        </div>
        <div className="overflow-x-auto">
          <table className="w-full text-sm">
            <thead className="text-left text-xs text-slate-400 border-b border-slate-100">
              <tr>
                <th className="px-4 py-2">Nama</th>
                <th className="px-4 py-2">Email</th>
                <th className="px-4 py-2">Plan</th>
                <th className="px-4 py-2">Produk</th>
                <th className="px-4 py-2">Publish</th>
                <th className="px-4 py-2">Status</th>
                <th className="px-4 py-2">Aksi</th>
              </tr>
            </thead>
            <tbody>
              {rows.map((m) => (
                <tr key={m.id} className="border-b border-slate-50">
                  <td className="px-4 py-2 font-medium">{m.name}</td>
                  <td className="px-4 py-2 text-slate-500">{m.email}</td>
                  <td className="px-4 py-2">
                    <select
                      value={m.plan_code}
                      disabled={busy === m.id}
                      onChange={(e) => setPlan(m, e.target.value)}
                      className="text-xs border border-slate-200 rounded px-1.5 py-1"
                    >
                      <option value="free">free</option>
                      <option value="pro">pro</option>
                      <option value="bisnis">bisnis</option>
                    </select>
                  </td>
                  <td className="px-4 py-2">{m.products_count}</td>
                  <td className="px-4 py-2">{m.publishes_this_month}</td>
                  <td className="px-4 py-2">
                    <span
                      className={`text-xs px-2 py-0.5 rounded-full ${
                        m.active ? 'bg-green-100 text-green-700' : 'bg-red-100 text-red-700'
                      }`}
                    >
                      {m.active ? 'Aktif' : 'Banned'}
                    </span>
                  </td>
                  <td className="px-4 py-2">
                    <button
                      disabled={busy === m.id}
                      onClick={() => toggleBan(m)}
                      className={`text-xs px-2 py-1 rounded border ${
                        m.active
                          ? 'border-red-200 text-red-600 hover:bg-red-50'
                          : 'border-green-200 text-green-600 hover:bg-green-50'
                      }`}
                    >
                      {m.active ? 'Ban' : 'Aktifkan'}
                    </button>
                  </td>
                </tr>
              ))}
              {rows.length === 0 && (
                <tr>
                  <td colSpan={7} className="px-4 py-6 text-center text-slate-400">
                    Belum ada merchant.
                  </td>
                </tr>
              )}
            </tbody>
          </table>
        </div>
      </div>
    </div>
  )
}