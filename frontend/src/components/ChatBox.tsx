import { useState } from 'react'
import { useQuery } from '@tanstack/react-query'
import { api } from '../services/api'

interface Msg {
  id: number
  sender: string
  sender_name: string | null
  body: string
  created_at: string | null
}

/** Kotak chat: mode buyer (publik, perlu no. HP) atau seller (auth). */
export default function ChatBox({
  orderNo,
  orderId,
  mode,
}: {
  orderNo: string
  orderId?: number
  mode: 'buyer' | 'seller'
}) {
  const [phone, setPhone] = useState('')
  const [unlocked, setUnlocked] = useState(mode === 'seller')
  const [body, setBody] = useState('')
  const [err, setErr] = useState('')

  const list = useQuery({
    queryKey: ['chat', mode, orderNo, orderId, phone],
    enabled: unlocked,
    refetchInterval: 10000,
    queryFn: () =>
      mode === 'seller'
        ? api.get<{ data: Msg[] }>(`/orders/${orderId}/messages`)
        : api.get<{ data: Msg[] }>(
            `/orders/track/${encodeURIComponent(orderNo)}/messages?phone=${encodeURIComponent(phone)}`,
          ),
  })

  async function send(e: React.FormEvent) {
    e.preventDefault()
    if (!body.trim()) return
    setErr('')
    try {
      if (mode === 'seller') {
        await api.post(`/orders/${orderId}/messages`, { body: body.trim() })
      } else {
        await api.post(`/orders/track/${encodeURIComponent(orderNo)}/messages`, {
          buyer_phone: phone,
          body: body.trim(),
        })
      }
      setBody('')
      list.refetch()
    } catch (e) {
      setErr(e instanceof Error ? e.message : 'Gagal kirim')
    }
  }

  if (!unlocked) {
    return (
      <div className="pt-3 border-t mt-3">
        <p className="text-xs font-semibold uppercase tracking-wide text-slate-400">
          Chat dengan Penjual
        </p>
        <form
          className="mt-2 flex gap-2"
          onSubmit={(e) => {
            e.preventDefault()
            if (phone.trim()) setUnlocked(true)
          }}
        >
          <input
            className="flex-1 border border-slate-300 rounded-lg px-3 py-2 text-sm"
            placeholder="No. HP saat checkout (verifikasi)"
            value={phone}
            onChange={(e) => setPhone(e.target.value)}
            required
          />
          <button className="px-4 py-2 rounded-lg bg-slate-900 text-white text-sm">Buka</button>
        </form>
      </div>
    )
  }

  const msgs = list.data?.data ?? []

  return (
    <div className="pt-3 border-t mt-3">
      <p className="text-xs font-semibold uppercase tracking-wide text-slate-400">
        Chat {mode === 'seller' ? 'dengan Pembeli' : 'dengan Penjual'}
      </p>
      <div className="mt-2 space-y-2 max-h-64 overflow-y-auto bg-slate-50 rounded-lg p-3">
        {msgs.length === 0 && (
          <p className="text-xs text-slate-400">Belum ada pesan. Mulai percakapan…</p>
        )}
        {msgs.map((m) => {
          const mine = (mode === 'seller' && m.sender === 'seller') || (mode === 'buyer' && m.sender === 'buyer')
          return (
            <div key={m.id} className={`flex ${mine ? 'justify-end' : 'justify-start'}`}>
              <div
                className={`max-w-[80%] rounded-lg px-3 py-2 text-sm ${
                  mine ? 'bg-slate-900 text-white' : 'bg-white border border-slate-200'
                }`}
              >
                <p>{m.body}</p>
                {m.created_at && (
                  <p className={`text-[10px] mt-0.5 ${mine ? 'text-slate-300' : 'text-slate-400'}`}>
                    {new Date(m.created_at).toLocaleString('id-ID')}
                  </p>
                )}
              </div>
            </div>
          )
        })}
      </div>
      <form onSubmit={send} className="mt-2 flex gap-2">
        <input
          className="flex-1 border border-slate-300 rounded-lg px-3 py-2 text-sm"
          placeholder="Tulis pesan…"
          value={body}
          onChange={(e) => setBody(e.target.value)}
          maxLength={2000}
          required
        />
        <button className="px-4 py-2 rounded-lg bg-slate-900 text-white text-sm">Kirim</button>
      </form>
      {err && <p className="mt-1 text-xs text-rose-600">{err}</p>}
    </div>
  )
}
