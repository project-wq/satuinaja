import { useEffect, useState } from 'react'
import { api, ApiError } from '../../services/api'

interface Plan {
  code: string
  name: string
  price_monthly: number
  max_products: number | null
  max_channels: number | null
  max_publishes_monthly: number | null
  ai_caption: boolean
  auto_publish: boolean
}

interface BillingData {
  current: { code: string; publishes_this_month: number }
  plans: Plan[]
}

const fmt = (n: number) => 'Rp ' + n.toLocaleString('id-ID')

export default function Billing() {
  const [data, setData] = useState<BillingData | null>(null)
  const [err, setErr] = useState('')
  const [busy, setBusy] = useState('')

  useEffect(() => {
    api
      .get<{ data: BillingData }>('/billing')
      .then((r) => setData(r.data))
      .catch((e) => setErr((e as ApiError).message))
  }, [])

  const subscribe = async (code: string) => {
    setBusy(code)
    setErr('')
    try {
      const r = await api.post<{
        data: { redirect_url?: string; already_active?: boolean }
      }>('/billing/subscribe', { plan_code: code })
      if (r.data.redirect_url) {
        window.location.href = r.data.redirect_url
      } else if (r.data.already_active) {
        setErr('Plan ini sudah aktif.')
      }
    } catch (e) {
      setErr((e as ApiError).message)
    } finally {
      setBusy('')
    }
  }

  if (!data) {
    return <div className="text-slate-500">{err || 'Memuat langganan…'}</div>
  }

  const maxProd = (p: Plan) => (p.max_products ?? '∞')
  const maxCh = (p: Plan) => (p.max_channels ?? '∞')
  const maxPub = (p: Plan) => (p.max_publishes_monthly ?? '∞')

  return (
    <div className="space-y-6">
      <div>
        <h1 className="text-2xl font-bold">Langganan</h1>
        <p className="text-sm text-slate-500 mt-1">
          Plan saat ini: <b>{data.current.code}</b> · Publish bulan ini:{' '}
          {data.current.publishes_this_month}
        </p>
      </div>

      {err && (
        <div className="bg-red-50 border border-red-200 text-red-700 text-sm rounded-lg px-4 py-3">
          {err}
        </div>
      )}

      <div className="grid md:grid-cols-3 gap-4">
        {data.plans.map((p) => (
          <div
            key={p.code}
            className={`bg-white rounded-xl border p-5 flex flex-col gap-3 ${
              p.code === data.current.code ? 'border-indigo-400 ring-2 ring-indigo-100' : 'border-slate-200'
            }`}
          >
            <div className="flex items-center justify-between">
              <h3 className="font-bold text-lg">{p.name}</h3>
              {p.code === data.current.code && (
                <span className="text-xs bg-indigo-100 text-indigo-700 px-2 py-0.5 rounded-full">
                  Aktif
                </span>
              )}
            </div>
            <div className="text-2xl font-extrabold">
              {p.price_monthly === 0 ? 'Gratis' : fmt(p.price_monthly)}
              {p.price_monthly > 0 && <span className="text-sm font-normal text-slate-500">/bulan</span>}
            </div>
            <ul className="text-sm text-slate-600 space-y-1 flex-1">
              <li>Produk: {maxProd(p)}</li>
              <li>Channel aktif: {maxCh(p)}</li>
              <li>Publish/bln: {maxPub(p)}</li>
              <li>Caption AI: {p.ai_caption ? '✓' : '—'}</li>
              <li>Auto-publish: {p.auto_publish ? '✓' : '—'}</li>
            </ul>
            <button
              disabled={busy === p.code || p.code === data.current.code}
              onClick={() => subscribe(p.code)}
              className={`mt-2 px-4 py-2 rounded-lg text-sm font-semibold ${
                p.code === data.current.code
                  ? 'bg-slate-100 text-slate-400 cursor-default'
                  : 'bg-slate-900 text-white hover:bg-slate-700'
              }`}
            >
              {busy === p.code ? 'Memproses…' : p.code === data.current.code ? 'Plan aktif' : 'Pilih plan'}
            </button>
          </div>
        ))}
      </div>

      <p className="text-xs text-slate-400">
        Pembayaran via Midtrans (Snap). Setelah bayar, plan aktif otomatis via webhook.
      </p>
    </div>
  )
}