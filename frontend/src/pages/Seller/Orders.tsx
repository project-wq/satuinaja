import { useState } from 'react'
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query'
import { api, type Order, type Paginated } from '../../services/api'
import { rupiah } from '../../components/ShopHeader'

export default function Orders() {
  const qc = useQueryClient()
  const [filter, setFilter] = useState('')
  const [toast, setToast] = useState('')

  const { data, isLoading } = useQuery({
    queryKey: ['orders', filter],
    queryFn: () => api.get<Paginated<Order>>(`/orders${filter ? `?fulfillment_status=${filter}` : ''}`),
  })

  const ship = useMutation({
    mutationFn: ({ id, tracking }: { id: number; tracking: string }) =>
      api.put(`/orders/${id}/ship`, { tracking_no: tracking }),
    onSuccess: () => {
      qc.invalidateQueries({ queryKey: ['orders'] })
      setToast('Resi disimpan.')
    },
    onError: (e) => setToast(e instanceof Error ? e.message : 'Gagal simpan resi'),
  })

  const badges: Record<string, string> = {
    paid: 'bg-emerald-50 text-emerald-700',
    unpaid: 'bg-amber-50 text-amber-700',
    expired: 'bg-slate-100 text-slate-500',
    refunded: 'bg-rose-50 text-rose-700',
  }

  const fulfill: Record<string, string> = {
    pending: 'bg-amber-50 text-amber-700',
    shipped: 'bg-blue-50 text-blue-700',
    delivered: 'bg-emerald-50 text-emerald-700',
    cancelled: 'bg-slate-100 text-slate-500',
  }

  return (
    <div className="space-y-5">
      <div className="flex items-center justify-between gap-4">
        <div>
          <h1 className="text-2xl font-bold">Pesanan</h1>
          <p className="text-sm text-slate-500 mt-1">Dari checkout storefront & webhook payment gateway.</p>
        </div>
        <select
          value={filter}
          onChange={(e) => setFilter(e.target.value)}
          className="text-sm rounded-lg border border-slate-300 px-3 py-2"
        >
          <option value="">Semua status</option>
          <option value="pending">Pending</option>
          <option value="shipped">Dikirim</option>
          <option value="delivered">Diterima</option>
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
          <table className="w-full text-sm">
            <thead className="bg-slate-50 text-slate-500 text-xs uppercase">
              <tr>
                <th className="text-left px-4 py-3">Order</th>
                <th className="text-left px-4 py-3">Pembeli</th>
                <th className="text-right px-4 py-3">Total</th>
                <th className="text-center px-4 py-3">Bayar</th>
                <th className="text-center px-4 py-3">Kirim</th>
                <th className="text-left px-4 py-3">Resi</th>
              </tr>
            </thead>
            <tbody className="divide-y divide-slate-100">
              {data?.data.map((o) => (
                <tr key={o.id}>
                  <td className="px-4 py-3 font-mono text-xs">{o.order_no}</td>
                  <td className="px-4 py-3">
                    <div className="font-medium">{o.buyer_name}</div>
                    <div className="text-xs text-slate-500">{o.buyer_phone}</div>
                  </td>
                  <td className="px-4 py-3 text-right">{rupiah(o.total)}</td>
                  <td className="px-4 py-3 text-center">
                    <span className={`text-xs px-2 py-0.5 rounded-full ${badges[o.payment_status] ?? 'bg-slate-100'}`}>
                      {o.payment_status}
                    </span>
                  </td>
                  <td className="px-4 py-3 text-center">
                    <span className={`text-xs px-2 py-0.5 rounded-full ${fulfill[o.fulfillment_status] ?? 'bg-slate-100'}`}>
                      {o.fulfillment_status}
                    </span>
                  </td>
                  <td className="px-4 py-3">
                    {o.tracking_no ? (
                      <span className="font-mono text-xs">{o.tracking_no}</span>
                    ) : (
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
                          placeholder="No. resi"
                          className="w-28 text-xs rounded-lg border border-slate-300 px-2 py-1"
                        />
                        <button className="text-xs px-2 py-1 rounded-lg bg-slate-900 text-white">Kirim</button>
                      </form>
                    )}
                  </td>
                </tr>
              ))}
            </tbody>
          </table>
        )}
      </div>
    </div>
  )
}
