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

interface WithdrawRow {
  id: number
  merchant: string
  amount: number
  bank_name: string
  bank_account_no: string
  bank_account_holder: string
  status: string
  admin_note: string | null
  created_at: string
  processed_at: string | null
}

interface FeeSettings {
  fee_buyer_percent: number
  admin_fee_per_item: number
  seller_fee_per_item: number
}

const fmt = (n: number) => 'Rp' + n.toLocaleString('id-ID')

export default function Admin() {
  const { user } = useAuth()
  const [tab, setTab] = useState<'merchants' | 'withdrawals' | 'settings'>('merchants')
  const [stats, setStats] = useState<Stats | null>(null)
  const [rows, setRows] = useState<MerchantRow[]>([])
  const [wds, setWds] = useState<WithdrawRow[]>([])
  const [settings, setSettings] = useState<FeeSettings | null>(null)
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

  const loadWithdrawals = async () => {
    try {
      const r = await api.get<{ data: WithdrawRow[] }>('/admin/withdrawals')
      setWds(r.data)
    } catch (e) {
      setErr((e as ApiError).message)
    }
  }

  const loadSettings = async () => {
    try {
      const r = await api.get<{ data: FeeSettings }>('/admin/settings')
      setSettings(r.data)
    } catch (e) {
      setErr((e as ApiError).message)
    }
  }

  const switchTab = (t: typeof tab) => {
    setTab(t)
    setErr('')
    if (t === 'withdrawals') loadWithdrawals()
    if (t === 'settings') loadSettings()
  }

  const processWithdrawal = async (w: WithdrawRow, decision: 'approved' | 'rejected') => {
    if (decision === 'rejected' && !confirm('Tolak penarikan ini? Saldo dikembalikan ke seller.')) return
    if (decision === 'approved' && !confirm('Setujui penarikan ini? Uang dianggap sudah keluar.')) return
    setBusy(w.id)
    setErr('')
    try {
      await api.put(`/admin/withdrawals/${w.id}`, { decision })
      await loadWithdrawals()
    } catch (e) {
      setErr((e as ApiError).message)
    } finally {
      setBusy(null)
    }
  }

  const saveSettings = async (e: React.FormEvent) => {
    e.preventDefault()
    if (!settings) return
    setErr('')
    setBusy(-1)
    try {
      const r = await api.put<{ data: FeeSettings }>('/admin/settings', settings)
      setSettings(r.data)
    } catch (ex) {
      setErr((ex as ApiError).message)
    } finally {
      setBusy(null)
    }
  }

  if (user?.role !== 'admin') {
    return <div className="text-slate-500">Halaman ini khusus admin.</div>
  }

  return (
    <div className="space-y-6">
      <div className="flex items-center justify-between">
        <h1 className="text-2xl font-bold">Admin</h1>
        <div className="flex gap-1 bg-white border border-slate-200 rounded-lg p-1">
          {(
            [
              ['merchants', 'Merchant'],
              ['withdrawals', 'Penarikan'],
              ['settings', 'Pengaturan'],
            ] as const
          ).map(([key, label]) => (
            <button
              key={key}
              onClick={() => switchTab(key)}
              className={`px-3 py-1.5 rounded-md text-sm font-medium ${
                tab === key ? 'bg-slate-900 text-white' : 'text-slate-600 hover:bg-slate-100'
              }`}
            >
              {label}
            </button>
          ))}
        </div>
      </div>

      {err && (
        <div className="bg-red-50 border border-red-200 text-red-700 text-sm rounded-lg px-4 py-3">
          {err}
        </div>
      )}

      {tab === 'merchants' && (
        <>
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
        </>
      )}

      {tab === 'withdrawals' && (
        <div className="bg-white rounded-xl border border-slate-200 overflow-hidden">
          <div className="px-4 py-3 border-b border-slate-100 text-sm font-semibold text-slate-600">
            Permintaan Penarikan ({wds.length})
          </div>
          <div className="overflow-x-auto">
            <table className="w-full text-sm">
              <thead className="text-left text-xs text-slate-400 border-b border-slate-100">
                <tr>
                  <th className="px-4 py-2">Merchant</th>
                  <th className="px-4 py-2">Jumlah</th>
                  <th className="px-4 py-2">Rekening</th>
                  <th className="px-4 py-2">Tgl</th>
                  <th className="px-4 py-2">Status</th>
                  <th className="px-4 py-2">Aksi</th>
                </tr>
              </thead>
              <tbody>
                {wds.map((w) => (
                  <tr key={w.id} className="border-b border-slate-50">
                    <td className="px-4 py-2 font-medium">{w.merchant}</td>
                    <td className="px-4 py-2 font-semibold">{fmt(w.amount)}</td>
                    <td className="px-4 py-2 text-slate-500">
                      {w.bank_name} {w.bank_account_no}
                      <div className="text-xs text-slate-400">{w.bank_account_holder}</div>
                    </td>
                    <td className="px-4 py-2 text-slate-500">
                      {new Date(w.created_at).toLocaleDateString('id-ID')}
                    </td>
                    <td className="px-4 py-2">
                      <span
                        className={`text-xs px-2 py-0.5 rounded-full font-medium ${
                          w.status === 'approved'
                            ? 'bg-emerald-100 text-emerald-700'
                            : w.status === 'rejected'
                              ? 'bg-red-100 text-red-700'
                              : 'bg-amber-100 text-amber-700'
                        }`}
                      >
                        {w.status === 'approved' ? 'Disetujui' : w.status === 'rejected' ? 'Ditolak' : 'Pending'}
                      </span>
                      {w.admin_note && <div className="text-xs text-slate-400 mt-0.5">{w.admin_note}</div>}
                    </td>
                    <td className="px-4 py-2">
                      {w.status === 'pending' && (
                        <div className="flex gap-1.5">
                          <button
                            disabled={busy === w.id}
                            onClick={() => processWithdrawal(w, 'approved')}
                            className="text-xs px-2 py-1 rounded border border-emerald-200 text-emerald-700 hover:bg-emerald-50"
                          >
                            Setujui
                          </button>
                          <button
                            disabled={busy === w.id}
                            onClick={() => processWithdrawal(w, 'rejected')}
                            className="text-xs px-2 py-1 rounded border border-red-200 text-red-600 hover:bg-red-50"
                          >
                            Tolak
                          </button>
                        </div>
                      )}
                    </td>
                  </tr>
                ))}
                {wds.length === 0 && (
                  <tr>
                    <td colSpan={6} className="px-4 py-6 text-center text-slate-400">
                      Belum ada permintaan penarikan.
                    </td>
                  </tr>
                )}
              </tbody>
            </table>
          </div>
        </div>
      )}

      {tab === 'settings' && settings && (
        <form onSubmit={saveSettings} className="bg-white rounded-xl border border-slate-200 p-5 max-w-md space-y-4">
          <h2 className="font-semibold">Konfigurasi Fee</h2>
          <p className="text-xs text-slate-500">
            Buyer bayar: (harga − diskon) + fee % + admin/unit + ongkir. Seller terima: (harga − diskon) − potongan
            seller/unit.
          </p>
          <label className="block text-sm">
            <span className="text-slate-600">Fee pembeli (%)</span>
            <input
              type="number"
              min={0}
              max={100}
              value={settings.fee_buyer_percent}
              onChange={(e) => setSettings({ ...settings, fee_buyer_percent: Number(e.target.value) })}
              className="mt-1 w-full border border-slate-300 rounded-lg px-3 py-2"
            />
          </label>
          <label className="block text-sm">
            <span className="text-slate-600">Admin fee per unit (Rp)</span>
            <input
              type="number"
              min={0}
              value={settings.admin_fee_per_item}
              onChange={(e) => setSettings({ ...settings, admin_fee_per_item: Number(e.target.value) })}
              className="mt-1 w-full border border-slate-300 rounded-lg px-3 py-2"
            />
          </label>
          <label className="block text-sm">
            <span className="text-slate-600">Potongan seller per unit (Rp)</span>
            <input
              type="number"
              min={0}
              value={settings.seller_fee_per_item}
              onChange={(e) => setSettings({ ...settings, seller_fee_per_item: Number(e.target.value) })}
              className="mt-1 w-full border border-slate-300 rounded-lg px-3 py-2"
            />
          </label>
          <button
            type="submit"
            disabled={busy === -1}
            className="bg-slate-900 text-white rounded-lg px-4 py-2 text-sm font-medium hover:bg-slate-700 disabled:opacity-50"
          >
            {busy === -1 ? 'Menyimpan…' : 'Simpan Pengaturan'}
          </button>
        </form>
      )}
    </div>
  )
}