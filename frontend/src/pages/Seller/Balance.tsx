import { useCallback, useEffect, useState } from 'react'
import { api, ApiError } from '../../services/api'

interface BalanceInfo {
  balance: number
  held: number
  pending_withdrawals: number
}

interface Txn {
  id: number
  type: string
  amount: number
  balance_after: number
  note: string | null
  created_at: string
  order?: { order_no: string } | null
  withdrawal?: { amount: number; status: string } | null
}

interface WithdrawRow {
  id: number
  amount: number
  bank_name: string
  bank_account_no: string
  status: string
  admin_note: string | null
  created_at: string
  processed_at: string | null
}

const fmt = (n: number) => 'Rp' + n.toLocaleString('id-ID')
const typeLabel: Record<string, string> = {
  sale: 'Penjualan',
  withdraw_hold: 'Penarikan (ditahan)',
  withdraw_paid: 'Penarikan diproses',
  withdraw_refund: 'Pengembalian penarikan',
  adjustment: 'Penyesuaian',
}

export default function Balance() {
  const [bal, setBal] = useState<BalanceInfo | null>(null)
  const [txns, setTxns] = useState<Txn[]>([])
  const [wds, setWds] = useState<WithdrawRow[]>([])
  const [err, setErr] = useState('')
  const [ok, setOk] = useState('')
  const [busy, setBusy] = useState(false)
  const [form, setForm] = useState({
    amount: '',
    bank_name: '',
    bank_account_no: '',
    bank_account_holder: '',
  })

  const load = useCallback(async () => {
    try {
      const [b, t, w] = await Promise.all([
        api.get<{ data: BalanceInfo }>('/balance'),
        api.get<{ data: Txn[] }>('/balance/transactions'),
        api.get<{ data: WithdrawRow[] }>('/balance/withdrawals'),
      ])
      setBal(b.data)
      setTxns(t.data)
      setWds(w.data)
    } catch (e) {
      setErr((e as ApiError).message)
    }
  }, [])

  useEffect(() => {
    load()
  }, [load])

  const submit = async (e: React.FormEvent) => {
    e.preventDefault()
    setErr('')
    setOk('')
    setBusy(true)
    try {
      await api.post('/balance/withdraw', {
        amount: Number(form.amount),
        bank_name: form.bank_name,
        bank_account_no: form.bank_account_no,
        bank_account_holder: form.bank_account_holder,
      })
      setOk('Permintaan penarikan terkirim. Menunggu persetujuan admin.')
      setForm({ amount: '', bank_name: '', bank_account_no: '', bank_account_holder: '' })
      await load()
    } catch (ex) {
      setErr((ex as ApiError).message)
    } finally {
      setBusy(false)
    }
  }

  if (!bal) {
    return <div className="text-slate-500">Memuat…</div>
  }

  return (
    <div className="space-y-6">
      <h1 className="text-2xl font-bold">Saldo</h1>

      {err && (
        <div className="bg-red-50 border border-red-200 text-red-700 text-sm rounded-lg px-4 py-3">{err}</div>
      )}
      {ok && (
        <div className="bg-emerald-50 border border-emerald-200 text-emerald-700 text-sm rounded-lg px-4 py-3">{ok}</div>
      )}

      {/* Ringkasan saldo */}
      <div className="grid grid-cols-1 sm:grid-cols-3 gap-4">
        <div className="bg-white border border-slate-200 rounded-xl p-5">
          <div className="text-xs text-slate-500 uppercase tracking-wide">Saldo tersedia</div>
          <div className="text-2xl font-bold mt-1">{fmt(bal.balance)}</div>
        </div>
        <div className="bg-white border border-slate-200 rounded-xl p-5">
          <div className="text-xs text-slate-500 uppercase tracking-wide">Sedang diproses</div>
          <div className="text-2xl font-bold mt-1">{fmt(bal.held)}</div>
        </div>
        <div className="bg-white border border-slate-200 rounded-xl p-5">
          <div className="text-xs text-slate-500 uppercase tracking-wide">Penarikan pending</div>
          <div className="text-2xl font-bold mt-1">{bal.pending_withdrawals}</div>
        </div>
      </div>

      {/* Form tarik saldo */}
      <div className="bg-white border border-slate-200 rounded-xl p-5">
        <h2 className="font-semibold mb-3">Tarik Saldo</h2>
        <p className="text-xs text-slate-500 mb-4">
          Minimal penarikan Rp10.000. Uang ditransfer ke rekening setelah disetujui admin.
        </p>
        <form onSubmit={submit} className="grid grid-cols-1 sm:grid-cols-2 gap-3">
          <input
            type="number"
            placeholder="Jumlah (Rp)"
            value={form.amount}
            onChange={(e) => setForm({ ...form, amount: e.target.value })}
            required min={10000}
            className="border border-slate-300 rounded-lg px-3 py-2 text-sm"
          />
          <input
            type="text"
            placeholder="Nama bank (BCA, BRI, …)"
            value={form.bank_name}
            onChange={(e) => setForm({ ...form, bank_name: e.target.value })}
            required
            className="border border-slate-300 rounded-lg px-3 py-2 text-sm"
          />
          <input
            type="text"
            inputMode="numeric"
            placeholder="No. rekening"
            value={form.bank_account_no}
            onChange={(e) => setForm({ ...form, bank_account_no: e.target.value.replace(/\D/g, '') })}
            required
            className="border border-slate-300 rounded-lg px-3 py-2 text-sm"
          />
          <input
            type="text"
            placeholder="Nama pemilik rekening"
            value={form.bank_account_holder}
            onChange={(e) => setForm({ ...form, bank_account_holder: e.target.value })}
            required
            className="border border-slate-300 rounded-lg px-3 py-2 text-sm"
          />
          <button
            type="submit"
            disabled={busy || Number(form.amount) > bal.balance}
            className="sm:col-span-2 bg-slate-900 text-white rounded-lg px-4 py-2.5 text-sm font-medium hover:bg-slate-700 disabled:opacity-50 disabled:cursor-not-allowed"
          >
            {busy ? 'Mengirim…' : 'Ajukan Penarikan'}
          </button>
          {Number(form.amount) > bal.balance && (
            <div className="sm:col-span-2 text-xs text-red-600">Jumlah melebihi saldo tersedia.</div>
          )}
        </form>
      </div>

      {/* Riwayat mutasi */}
      <div className="bg-white border border-slate-200 rounded-xl p-5">
        <h2 className="font-semibold mb-3">Riwayat Mutasi</h2>
        {txns.length === 0 ? (
          <div className="text-sm text-slate-500">Belum ada mutasi.</div>
        ) : (
          <div className="divide-y divide-slate-100">
            {txns.map((t) => (
              <div key={t.id} className="py-2.5 flex items-center justify-between gap-3">
                <div>
                  <div className="text-sm font-medium">
                    {typeLabel[t.type] ?? t.type}
                    {t.order && <span className="text-slate-400 font-normal"> — {t.order.order_no}</span>}
                  </div>
                  <div className="text-xs text-slate-400">
                    {new Date(t.created_at).toLocaleString('id-ID')}
                    {t.note && ` · ${t.note}`}
                  </div>
                </div>
                <div className="text-right">
                  <div className={`text-sm font-semibold ${t.amount >= 0 ? 'text-emerald-600' : 'text-red-500'}`}>
                    {t.amount >= 0 ? '+' : '−'}{fmt(Math.abs(t.amount))}
                  </div>
                  <div className="text-xs text-slate-400">Saldo {fmt(t.balance_after)}</div>
                </div>
              </div>
            ))}
          </div>
        )}
      </div>

      {/* Riwayat penarikan */}
      <div className="bg-white border border-slate-200 rounded-xl p-5">
        <h2 className="font-semibold mb-3">Riwayat Penarikan</h2>
        {wds.length === 0 ? (
          <div className="text-sm text-slate-500">Belum ada penarikan.</div>
        ) : (
          <div className="divide-y divide-slate-100">
            {wds.map((w) => (
              <div key={w.id} className="py-2.5 flex items-center justify-between gap-3">
                <div>
                  <div className="text-sm font-medium">
                    {fmt(w.amount)} · {w.bank_name} {w.bank_account_no}
                  </div>
                  <div className="text-xs text-slate-400">
                    {new Date(w.created_at).toLocaleString('id-ID')}
                    {w.admin_note && ` · Catatan admin: ${w.admin_note}`}
                  </div>
                </div>
                <span
                  className={`text-xs px-2 py-1 rounded-full font-medium ${
                    w.status === 'approved'
                      ? 'bg-emerald-100 text-emerald-700'
                      : w.status === 'rejected'
                        ? 'bg-red-100 text-red-700'
                        : 'bg-amber-100 text-amber-700'
                  }`}
                >
                  {w.status === 'approved' ? 'Disetujui' : w.status === 'rejected' ? 'Ditolak' : 'Pending'}
                </span>
              </div>
            ))}
          </div>
        )}
      </div>
    </div>
  )
}