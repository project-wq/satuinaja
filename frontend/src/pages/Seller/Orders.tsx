import { useState } from 'react'
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query'
import { api, type Order, type Paginated } from '../../services/api'
import { rupiah } from '../../components/ShopHeader'

/** Langkah alur berikutnya yang tersedia untuk seller, per status. */
function nextActions(o: Order): { key: string; label: string; danger?: boolean }[] {
  const a: { key: string; label: string; danger?: boolean }[] = []
  if (o.fulfillment_status === 'pending' && o.payment_status === 'paid') a.push({ key: 'pack', label: 'Kemas' })
  if (o.fulfillment_status === 'packed') a.push({ key: 'ship', label: 'Buat Resi Otomatis (Biteship)' })
  if (o.fulfillment_status === 'shipped') a.push({ key: 'deliver', label: 'Diterima' })
  if (o.fulfillment_status === 'delivered') a.push({ key: 'complete', label: 'Selesai' })
  if (['pending', 'packed'].includes(o.fulfillment_status)) a.push({ key: 'cancel', label: 'Batal', danger: true })
  if (['shipped', 'delivered', 'completed'].includes(o.fulfillment_status) && !o.return_status)
    a.push({ key: 'return', label: 'Retur', danger: true })
  if (o.return_status === 'requested') a.push({ key: 'resolve-return', label: 'Putuskan Retur' })
  return a
}

export default function Orders() {
  const qc = useQueryClient()
  const [filter, setFilter] = useState('')
  const [toast, setToast] = useState('')
  const [detail, setDetail] = useState<Order | null>(null)

  const { data, isLoading } = useQuery({
    queryKey: ['orders', filter],
    queryFn: () => api.get<Paginated<Order>>(`/orders${filter ? `?fulfillment_status=${filter}` : ''}`),
  })

  const invalidate = () => qc.invalidateQueries({ queryKey: ['orders'] })

  interface ShipResult {
    ok: boolean
    data: { tracking_no: string; fulfillment_status: string; biteship_order_id?: string | null }
    error?: string
  }

  const ship = useMutation({
    mutationFn: ({ id, tracking }: { id: number; tracking?: string }) =>
      api.put<ShipResult>(`/orders/${id}/ship`, tracking ? { tracking_no: tracking } : {}),
    onSuccess: (r) => {
      invalidate()
      setToast(
        r.data.biteship_order_id
          ? `Resi Biteship terbit: ${r.data.tracking_no}. Cetak label di detail.`
          : `Resi disimpan: ${r.data.tracking_no}.`,
      )
    },
    onError: (e) => setToast(e instanceof Error ? e.message : 'Gagal buat resi'),
  })

  const act = useMutation({
    mutationFn: ({ id, key }: { id: number; key: string }) => api.put(`/orders/${id}/${key}`),
    onSuccess: (_r, v) => {
      invalidate()
      setDetail(null)
      setToast(`Aksi "${v.key}" berhasil.`)
    },
    onError: (e) => setToast(e instanceof Error ? e.message : 'Gagal'),
  })

  const cancelOrder = useMutation({
    mutationFn: ({ id, reason }: { id: number; reason: string }) =>
      api.put(`/orders/${id}/cancel`, { reason }),
    onSuccess: () => {
      invalidate()
      setDetail(null)
      setToast('Order dibatalkan; stok dikembalikan.')
    },
    onError: (e) => setToast(e instanceof Error ? e.message : 'Gagal batal'),
  })

  const requestReturn = useMutation({
    mutationFn: ({ id, reason }: { id: number; reason: string }) =>
      api.post(`/orders/${id}/return`, { reason }),
    onSuccess: () => {
      invalidate()
      setDetail(null)
      setToast('Retur diajukan.')
    },
    onError: (e) => setToast(e instanceof Error ? e.message : 'Gagal retur'),
  })

  const resolveReturn = useMutation({
    mutationFn: ({ id, decision, note }: { id: number; decision: string; note?: string }) =>
      api.put(`/orders/${id}/return`, { decision, note }),
    onSuccess: () => {
      invalidate()
      setDetail(null)
      setToast('Retur diputuskan.')
    },
    onError: (e) => setToast(e instanceof Error ? e.message : 'Gagal'),
  })

  const badges: Record<string, string> = {
    paid: 'bg-emerald-50 text-emerald-700',
    unpaid: 'bg-amber-50 text-amber-700',
    expired: 'bg-slate-100 text-slate-500',
    refunded: 'bg-rose-50 text-rose-700',
  }

  const fulfill: Record<string, string> = {
    pending: 'bg-amber-50 text-amber-700',
    packed: 'bg-indigo-50 text-indigo-700',
    shipped: 'bg-blue-50 text-blue-700',
    delivered: 'bg-emerald-50 text-emerald-700',
    completed: 'bg-emerald-100 text-emerald-800',
    cancelled: 'bg-slate-100 text-slate-500',
    returned: 'bg-rose-50 text-rose-700',
  }

  const onAction = (o: Order, key: string) => {
    if (key === 'cancel') {
      const reason = prompt('Alasan batal?')
      if (reason === null) return
      cancelOrder.mutate({ id: o.id, reason })
      return
    }
    if (key === 'return') {
      const reason = prompt('Alasan retur?')
      if (reason === null) return
      requestReturn.mutate({ id: o.id, reason })
      return
    }
    if (key === 'resolve-return') {
      const note = prompt('Catatan (opsional)?') ?? ''
      const decision = confirm('Setujui retur? OK = setujui, Batal = tolak.') ? 'approved' : 'rejected'
      resolveReturn.mutate({ id: o.id, decision, note })
      return
    }
    act.mutate({ id: o.id, key })
  }

  return (
    <div className="space-y-5">
      <div className="flex items-center justify-between gap-4">
        <div>
          <h1 className="text-2xl font-bold">Pesanan</h1>
          <p className="text-sm text-slate-500 mt-1">
            Alur: bayar → kemas → kirim → diterima → selesai. Bisa batal &amp; retur.
          </p>
        </div>
        <select
          value={filter}
          onChange={(e) => setFilter(e.target.value)}
          className="text-sm rounded-lg border border-slate-300 px-3 py-2"
        >
          <option value="">Semua status</option>
          <option value="pending">Pending</option>
          <option value="packed">Dikemas</option>
          <option value="shipped">Dikirim</option>
          <option value="delivered">Diterima</option>
          <option value="completed">Selesai</option>
          <option value="cancelled">Dibatal</option>
        </select>
      </div>

      {toast && (
        <div className="text-sm bg-slate-900 text-white rounded-lg px-4 py-2 flex items-center justify-between">
          <span>{toast}</span>
          <button onClick={() => setToast('')}>✕</button>
        </div>
      )}

      <div className="bg-white rounded-xl border border-slate-200 overflow-hidden">
        {isLoading ? (
          <p className="p-6 text-sm text-slate-500">Memuat…</p>
        ) : (data?.data.length ?? 0) === 0 ? (
          <p className="p-6 text-sm text-slate-500">Belum ada pesanan.</p>
        ) : (
          <div className="overflow-x-auto">
            <table className="w-full text-sm">
              <thead className="bg-slate-50 text-slate-500 text-xs uppercase">
                <tr>
                  <th className="text-left px-4 py-3">Order</th>
                  <th className="text-left px-4 py-3">Pembeli</th>
                  <th className="text-right px-4 py-3">Total</th>
                  <th className="text-center px-4 py-3">Bayar</th>
                  <th className="text-center px-4 py-3">Status</th>
                  <th className="text-left px-4 py-3">Resi</th>
                  <th className="text-right px-4 py-3">Aksi</th>
                </tr>
              </thead>
              <tbody className="divide-y divide-slate-100">
                {data?.data.map((o) => (
                  <tr key={o.id}>
                    <td className="px-4 py-3 font-mono text-xs">
                      <button onClick={() => setDetail(o)} className="hover:underline">
                        {o.order_no}
                      </button>
                      {o.voucher_code && (
                        <div className="text-xs text-emerald-600">🏷 {o.voucher_code}</div>
                      )}
                    </td>
                    <td className="px-4 py-3">
                      <div className="font-medium">{o.buyer_name}</div>
                      <div className="text-xs text-slate-500">{o.buyer_phone}</div>
                    </td>
                    <td className="px-4 py-3 text-right">
                      {rupiah(o.total)}
                      {o.voucher_discount > 0 && (
                        <div className="text-xs text-emerald-600">−{rupiah(o.voucher_discount)}</div>
                      )}
                    </td>
                    <td className="px-4 py-3 text-center">
                      <span className={`text-xs px-2 py-0.5 rounded-full ${badges[o.payment_status] ?? 'bg-slate-100'}`}>
                        {o.payment_status}
                      </span>
                    </td>
                    <td className="px-4 py-3 text-center">
                      <span className={`text-xs px-2 py-0.5 rounded-full ${fulfill[o.fulfillment_status] ?? 'bg-slate-100'}`}>
                        {o.fulfillment_status}
                      </span>
                      {o.return_status && (
                        <div className="text-xs text-rose-600 mt-0.5">retur: {o.return_status}</div>
                      )}
                    </td>
                    <td className="px-4 py-3">
                      {o.tracking_no ? (
                        <span className="font-mono text-xs">{o.tracking_no}</span>
                      ) : o.fulfillment_status === 'packed' ? (
                        <div className="space-y-1.5">
                          <button
                            onClick={() => ship.mutate({ id: o.id })}
                            disabled={ship.isPending}
                            className="text-xs px-2 py-1 rounded-lg bg-slate-900 text-white disabled:opacity-50"
                            title="Buat resi otomatis via Biteship dari alamat toko kamu"
                          >
                            {ship.isPending ? 'Memproses…' : 'Buat Resi Otomatis'}
                          </button>
                          <form
                            onSubmit={(e) => {
                              e.preventDefault()
                              const fd = new FormData(e.currentTarget)
                              ship.mutate({ id: o.id, tracking: String(fd.get('tracking') ?? '') })
                            }}
                            className="flex gap-2"
                          >
                            <input
                              name="tracking"
                              required
                              placeholder="No. resi manual"
                              className="w-28 text-xs rounded-lg border border-slate-300 px-2 py-1"
                            />
                            <button className="text-xs px-2 py-1 rounded-lg border border-slate-300">
                              Kirim
                            </button>
                          </form>
                        </div>
                      ) : (
                        <span className="text-xs text-slate-400">—</span>
                      )}
                    </td>
                    <td className="px-4 py-3">
                      <div className="flex flex-wrap gap-1.5 justify-end">
                        {nextActions(o).map((a) => (
                          <button
                            key={a.key}
                            onClick={() => onAction(o, a.key)}
                            className={`text-xs px-2 py-1 rounded border ${
                              a.danger
                                ? 'border-red-200 text-red-600 hover:bg-red-50'
                                : 'border-slate-200 hover:bg-slate-50'
                            }`}
                          >
                            {a.label}
                          </button>
                        ))}
                      </div>
                    </td>
                  </tr>
                ))}
              </tbody>
            </table>
          </div>
        )}
      </div>

      {detail && (
        <div
          className="fixed inset-0 bg-black/40 flex items-center justify-center p-4 z-30"
          onClick={() => setDetail(null)}
        >
          <div
            className="bg-white rounded-xl max-w-lg w-full p-5 space-y-3 max-h-[85vh] overflow-y-auto"
            onClick={(e) => e.stopPropagation()}
          >
            <div className="flex items-center justify-between">
              <h2 className="font-semibold font-mono text-sm">{detail.order_no}</h2>
              <button onClick={() => setDetail(null)} className="text-slate-400">✕</button>
            </div>
            <div className="text-sm space-y-1">
              <div>
                <span className="text-slate-500">Pembeli:</span> {detail.buyer_name} · {detail.buyer_phone}
              </div>
              <div className="text-slate-500">{/* alamat tak dikirim di list; lihat via API show */}</div>
              <div>
                <span className="text-slate-500">Total:</span> <b>{rupiah(detail.total)}</b>
              </div>
              {detail.voucher_code && (
                <div className="text-emerald-600">
                  Voucher {detail.voucher_code}: −{rupiah(detail.voucher_discount)}
                  {detail.shipping_discount > 0 && ` (gratis ongkir ${rupiah(detail.shipping_discount)})`}
                </div>
              )}
              {detail.cancel_reason && (
                <div className="text-rose-600">Dibatalkan ({detail.cancelled_by}): {detail.cancel_reason}</div>
              )}
              {detail.return_reason && (
                <div className="text-rose-600">
                  Retur ({detail.return_status}): {detail.return_reason}
                  {detail.seller_note && ` — ${detail.seller_note}`}
                </div>
              )}
            </div>
            {detail.items && detail.items.length > 0 && (
              <div className="border-t pt-2 text-sm">
                {detail.items.map((it) => (
                  <div key={it.id} className="flex justify-between py-1">
                    <span>
                      {it.title} × {it.qty}
                    </span>
                    <span>{rupiah(it.line_total)}</span>
                  </div>
                ))}
              </div>
            )}
            <div className="flex flex-wrap gap-2 pt-1">
              {nextActions(detail).map((a) => (
                <button
                  key={a.key}
                  onClick={() => onAction(detail, a.key)}
                  className={`text-xs px-3 py-1.5 rounded-lg border ${
                    a.danger ? 'border-red-200 text-red-600' : 'border-slate-300'
                  }`}
                >
                  {a.label}
                </button>
              ))}
            </div>
          </div>
        </div>
      )}
    </div>
  )
}
