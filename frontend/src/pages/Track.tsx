import { useState } from 'react'
import { Link, useParams, useSearchParams } from 'react-router-dom'
import Header from '../components/ShopHeader'
import { api } from '../services/api'

type TrackEvent = {
  status: string
  message: string
  occurred_at: string | null
}

type OrderTrack = {
  order_no: string
  payment_status: string
  fulfillment_status: string
  tracking_no: string | null
  courier: string | null
  total: number
  timeline: TrackEvent[]
}

interface TrackResponse {
  data?: OrderTrack
  message?: string
}

interface ResiHistory {
  time: string
  date: string
  desc: string
}

interface ResiResponse {
  ok: boolean
  data?: {
    status: string
    message: string
    receiver: string | null
    history: ResiHistory[]
  }
  error?: string
}

export default function Track() {
  const { shopSlug = '' } = useParams()
  const [params] = useSearchParams()
  const [orderNo, setOrderNo] = useState(params.get('order') ?? '')
  const [order, setOrder] = useState<OrderTrack | null>(null)
  const [orderErr, setOrderErr] = useState('')

  const [courier, setCourier] = useState('jne')
  const [awb, setAwb] = useState('')
  const [resi, setResi] = useState<ResiResponse['data'] | null>(null)
  const [resiErr, setResiErr] = useState('')
  const [busy, setBusy] = useState(false)

  async function findOrder(e: React.FormEvent) {
    e.preventDefault()
    setOrderErr('')
    setOrder(null)
    try {
      const r = await api.get<TrackResponse>(`/orders/track/${encodeURIComponent(orderNo.trim())}`)
      setOrder(r.data ?? null)
    } catch (err) {
      setOrderErr(err instanceof Error ? err.message : 'Pesanan tidak ditemukan.')
    }
  }

  async function findResi(e: React.FormEvent) {
    e.preventDefault()
    setBusy(true)
    setResiErr('')
    setResi(null)
    try {
      const r = await api.post<ResiResponse>('/shipping/track', { courier, awb: awb.trim() })
      if (r.ok) setResi(r.data)
      else setResiErr(r.error ?? 'Resi tidak ditemukan.')
    } catch (err) {
      setResiErr(err instanceof Error ? err.message : 'Gagal lacak.')
    } finally {
      setBusy(false)
    }
  }

  const input = 'mt-1 w-full rounded-lg border border-slate-300 px-3 py-2 text-sm'

  return (
    <div className="min-h-screen bg-slate-50">
      <Header shopSlug={shopSlug} shopName="Lacak Pesanan" />

      <main className="max-w-2xl mx-auto px-4 py-8 space-y-6">
        <section className="bg-white rounded-xl border border-slate-200 p-5">
          <h2 className="font-semibold">Cari dengan Nomor Pesanan</h2>
          <form onSubmit={findOrder} className="mt-3 flex gap-2">
            <input
              className="flex-1 rounded-lg border border-slate-300 px-3 py-2 text-sm"
              placeholder="ORD-261001-XXXXXX"
              value={orderNo}
              onChange={(e) => setOrderNo(e.target.value)}
              required
            />
            <button className="px-4 py-2 rounded-lg bg-slate-900 text-white text-sm">Cari</button>
          </form>

          {orderErr && <p className="mt-3 text-sm text-rose-600">{orderErr}</p>}

          {order && (
            <div className="mt-4 rounded-lg bg-slate-50 p-4 text-sm space-y-1">
              <p className="font-mono font-semibold">{order.order_no}</p>
              <p>
                Status bayar: <span className="font-medium">{order.payment_status}</span> · Status kirim:{' '}
                <span className="font-medium">{order.fulfillment_status}</span>
              </p>
              {order.tracking_no && (
                <p>
                  Resi {order.courier?.toUpperCase()}: <span className="font-mono">{order.tracking_no}</span>
                </p>
              )}
              {order.timeline && order.timeline.length > 0 && (
                <div className="pt-2">
                  <p className="text-xs font-semibold uppercase tracking-wide text-slate-400">
                    Perjalanan Paket
                  </p>
                  <ol className="mt-2 border-l-2 border-slate-300 pl-4 space-y-3 text-sm bg-white rounded-lg p-3">
                    {order.timeline.map((t, i) => (
                      <li key={i} className="relative">
                        <span className="absolute -left-[25px] top-1.5 w-2.5 h-2.5 rounded-full bg-slate-900" />
                        <p className="font-medium">{t.message}</p>
                        {t.occurred_at && (
                          <p className="text-xs text-slate-500">
                            {new Date(t.occurred_at).toLocaleString('id-ID')}
                          </p>
                        )}
                      </li>
                    ))}
                  </ol>
                </div>
              )}
              {order.fulfillment_status === 'delivered' && (
                <p className="pt-1">
                  <Link
                    to={`/${shopSlug}/review/${encodeURIComponent(order.order_no)}`}
                    className="text-xs font-medium text-slate-900 underline"
                  >
                    Paket diterima? Beri ulasan ★
                  </Link>
                </p>
              )}
            </div>
          )}
        </section>

        <section className="bg-white rounded-xl border border-slate-200 p-5">
          <h2 className="font-semibold">Lacak No. Resi</h2>
          <form onSubmit={findResi} className="mt-3 grid grid-cols-3 gap-2">
            <select className={input.replace('mt-1 w-full', 'mt-1')} value={courier} onChange={(e) => setCourier(e.target.value)}>
              {['jne', 'pos', 'tiki', 'sicepat', 'jnt', 'anteraja', 'ninja', 'ide'].map((c) => (
                <option key={c} value={c}>
                  {c.toUpperCase()}
                </option>
              ))}
            </select>
            <input
              className={input.replace('mt-1 w-full', 'mt-1 col-span-2')}
              placeholder="No. resi"
              value={awb}
              onChange={(e) => setAwb(e.target.value)}
              required
            />
            <button className="col-span-3 px-4 py-2 rounded-lg bg-slate-900 text-white text-sm disabled:opacity-50" disabled={busy}>
              {busy ? 'Melacak…' : 'Lacak'}
            </button>
          </form>

          {resiErr && (
            <p className="mt-3 text-sm text-amber-700 bg-amber-50 rounded-lg px-3 py-2">
              {resiErr} <span className="text-xs">(butuh API key Biteship di Admin → Pengaturan)</span>
            </p>
          )}

          {resi && (
            <div className="mt-4 space-y-3">
              <p className="text-sm font-medium">
                {resi.message} {resi.receiver && <span className="text-slate-500">· diterima {resi.receiver}</span>}
              </p>
              <ol className="border-l-2 border-slate-200 pl-4 space-y-3 text-sm">
                {resi.history.map((h, i) => (
                  <li key={i} className="relative">
                    <span className="absolute -left-[21px] top-1.5 w-2.5 h-2.5 rounded-full bg-slate-400" />
                    <p className="font-medium">{h.desc}</p>
                    <p className="text-xs text-slate-500">
                      {h.date} {h.time}
                    </p>
                  </li>
                ))}
              </ol>
            </div>
          )}
        </section>

        <p className="text-center text-sm">
          <Link to={`/${shopSlug}`} className="underline">
            ← Kembali ke toko
          </Link>
        </p>
      </main>
    </div>
  )
}
