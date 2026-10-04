import { useState } from 'react'
import { useQuery, useMutation, useQueryClient } from '@tanstack/react-query'
import { api, type Voucher, type Paginated, type Product } from '../../services/api'
import { rupiah } from '../../components/ShopHeader'
import { useAuth } from '../../store'

interface FormState {
  id?: number
  scope: 'product' | 'shop' | 'platform'
  product_id: string
  code: string
  name: string
  type: 'percent' | 'fixed'
  value: string
  min_spend: string
  max_discount: string
  quota: string
  max_per_buyer: string
  free_shipping: boolean
  active: boolean
  start_at: string
  end_at: string
}

const empty: FormState = {
  scope: 'shop',
  product_id: '',
  code: '',
  name: '',
  type: 'fixed',
  value: '',
  min_spend: '0',
  max_discount: '',
  quota: '',
  max_per_buyer: '1',
  free_shipping: false,
  active: true,
  start_at: '',
  end_at: '',
}

export default function Vouchers() {
  const { user } = useAuth()
  const qc = useQueryClient()
  const [form, setForm] = useState<FormState | null>(null)
  const [toast, setToast] = useState('')

  const { data, isLoading } = useQuery({
    queryKey: ['vouchers'],
    queryFn: () => api.get<Paginated<Voucher>>('/vouchers'),
  })

  const products = useQuery({
    queryKey: ['products-voucher-opt'],
    queryFn: () => api.get<Paginated<Product>>('/products'),
  })

  const save = useMutation({
    mutationFn: (f: FormState) => {
      const payload = {
        scope: f.scope,
        product_id: f.scope === 'product' && f.product_id ? Number(f.product_id) : null,
        code: f.code,
        name: f.name,
        type: f.type,
        value: Number(f.value || 0),
        min_spend: Number(f.min_spend || 0),
        max_discount: f.max_discount ? Number(f.max_discount) : null,
        quota: f.quota ? Number(f.quota) : null,
        max_per_buyer: Number(f.max_per_buyer || 1),
        free_shipping: f.free_shipping,
        active: f.active,
        start_at: f.start_at || null,
        end_at: f.end_at || null,
      }
      return f.id ? api.put(`/vouchers/${f.id}`, payload) : api.post('/vouchers', payload)
    },
    onSuccess: () => {
      qc.invalidateQueries({ queryKey: ['vouchers'] })
      setForm(null)
      setToast('Voucher disimpan.')
    },
    onError: (e) => setToast(e instanceof Error ? e.message : 'Gagal simpan'),
  })

  const toggle = useMutation({
    mutationFn: (v: Voucher) => api.put(`/vouchers/${v.id}`, { active: !v.active }),
    onSuccess: () => qc.invalidateQueries({ queryKey: ['vouchers'] }),
  })

  const remove = useMutation({
    mutationFn: (id: number) => api.delete(`/vouchers/${id}`),
    onSuccess: () => {
      qc.invalidateQueries({ queryKey: ['vouchers'] })
      setToast('Voucher dihapus.')
    },
  })

  const edit = (v: Voucher) => {
    setForm({
      id: v.id,
      scope: v.scope,
      product_id: v.product_id ? String(v.product_id) : '',
      code: v.code,
      name: v.name,
      type: v.type,
      value: String(v.value),
      min_spend: String(v.min_spend ?? 0),
      max_discount: v.max_discount ? String(v.max_discount) : '',
      quota: v.quota ? String(v.quota) : '',
      max_per_buyer: String(v.max_per_buyer ?? 1),
      free_shipping: v.free_shipping,
      active: v.active,
      start_at: v.start_at ? v.start_at.slice(0, 16) : '',
      end_at: v.end_at ? v.end_at.slice(0, 16) : '',
    })
  }

  const scopeLabel: Record<string, string> = {
    product: 'Produk',
    shop: 'Toko',
    platform: 'Platform',
  }

  return (
    <div className="space-y-5">
      <div className="flex items-center justify-between gap-4">
        <div>
          <h1 className="text-2xl font-bold">Voucher & Promo</h1>
          <p className="text-sm text-slate-500 mt-1">
            Kode diskon produk/toko + gratis ongkir. Berlaku saat checkout storefront.
          </p>
        </div>
        <button
          onClick={() => setForm({ ...empty })}
          className="bg-slate-900 text-white rounded-lg px-4 py-2 text-sm font-medium hover:bg-slate-700"
        >
          + Buat voucher
        </button>
      </div>

      {toast && (
        <div className="text-sm bg-slate-900 text-white rounded-lg px-4 py-2 flex items-center justify-between">
          <span>{toast}</span>
          <button onClick={() => setToast('')}>✕</button>
        </div>
      )}

      {form && (
        <form
          onSubmit={(e) => {
            e.preventDefault()
            save.mutate(form)
          }}
          className="bg-white rounded-xl border border-slate-200 p-5 space-y-4"
        >
          <h2 className="font-semibold">{form.id ? 'Edit voucher' : 'Voucher baru'}</h2>

          <div className="grid md:grid-cols-2 gap-4">
            <label className="block text-sm">
              <span className="text-slate-600">Cakupan</span>
              <select
                value={form.scope}
                onChange={(e) => setForm({ ...form, scope: e.target.value as FormState['scope'] })}
                className="mt-1 w-full border border-slate-300 rounded-lg px-3 py-2"
              >
                <option value="shop">Toko (semua produk)</option>
                <option value="product">Produk tertentu</option>
              </select>
            </label>

            {form.scope === 'product' && (
              <label className="block text-sm">
                <span className="text-slate-600">Produk</span>
                <select
                  value={form.product_id}
                  onChange={(e) => setForm({ ...form, product_id: e.target.value })}
                  required
                  className="mt-1 w-full border border-slate-300 rounded-lg px-3 py-2"
                >
                  <option value="">— pilih produk —</option>
                  {products.data?.data.map((p) => (
                    <option key={p.id} value={p.id}>
                      {p.title}
                    </option>
                  ))}
                </select>
              </label>
            )}

            <label className="block text-sm">
              <span className="text-slate-600">Kode</span>
              <input
                value={form.code}
                onChange={(e) => setForm({ ...form, code: e.target.value.toUpperCase() })}
                required
                placeholder="HEMAT10"
                className="mt-1 w-full border border-slate-300 rounded-lg px-3 py-2 font-mono"
              />
            </label>

            <label className="block text-sm">
              <span className="text-slate-600">Nama promo</span>
              <input
                value={form.name}
                onChange={(e) => setForm({ ...form, name: e.target.value })}
                required
                placeholder="Diskon 10%"
                className="mt-1 w-full border border-slate-300 rounded-lg px-3 py-2"
              />
            </label>

            <label className="block text-sm">
              <span className="text-slate-600">Tipe</span>
              <select
                value={form.type}
                onChange={(e) => setForm({ ...form, type: e.target.value as FormState['type'] })}
                className="mt-1 w-full border border-slate-300 rounded-lg px-3 py-2"
              >
                <option value="fixed">Potongan tetap (Rp)</option>
                <option value="percent">Persen (%)</option>
              </select>
            </label>

            <label className="block text-sm">
              <span className="text-slate-600">
                Nilai {form.type === 'percent' ? '(%)' : '(Rp)'}
              </span>
              <input
                type="number"
                min={1}
                value={form.value}
                onChange={(e) => setForm({ ...form, value: e.target.value })}
                required
                className="mt-1 w-full border border-slate-300 rounded-lg px-3 py-2"
              />
            </label>

            <label className="block text-sm">
              <span className="text-slate-600">Min. belanja (Rp)</span>
              <input
                type="number"
                min={0}
                value={form.min_spend}
                onChange={(e) => setForm({ ...form, min_spend: e.target.value })}
                className="mt-1 w-full border border-slate-300 rounded-lg px-3 py-2"
              />
            </label>

            {form.type === 'percent' && (
              <label className="block text-sm">
                <span className="text-slate-600">Maks. potongan (Rp)</span>
                <input
                  type="number"
                  min={1}
                  value={form.max_discount}
                  onChange={(e) => setForm({ ...form, max_discount: e.target.value })}
                  className="mt-1 w-full border border-slate-300 rounded-lg px-3 py-2"
                />
              </label>
            )}

            <label className="block text-sm">
              <span className="text-slate-600">Kuota (kosong = tanpa batas)</span>
              <input
                type="number"
                min={1}
                value={form.quota}
                onChange={(e) => setForm({ ...form, quota: e.target.value })}
                className="mt-1 w-full border border-slate-300 rounded-lg px-3 py-2"
              />
            </label>

            <label className="block text-sm">
              <span className="text-slate-600">Maks. per pembeli</span>
              <input
                type="number"
                min={1}
                value={form.max_per_buyer}
                onChange={(e) => setForm({ ...form, max_per_buyer: e.target.value })}
                className="mt-1 w-full border border-slate-300 rounded-lg px-3 py-2"
              />
            </label>

            <label className="block text-sm">
              <span className="text-slate-600">Mulai (opsional)</span>
              <input
                type="datetime-local"
                value={form.start_at}
                onChange={(e) => setForm({ ...form, start_at: e.target.value })}
                className="mt-1 w-full border border-slate-300 rounded-lg px-3 py-2"
              />
            </label>

            <label className="block text-sm">
              <span className="text-slate-600">Berakhir (opsional)</span>
              <input
                type="datetime-local"
                value={form.end_at}
                onChange={(e) => setForm({ ...form, end_at: e.target.value })}
                className="mt-1 w-full border border-slate-300 rounded-lg px-3 py-2"
              />
            </label>
          </div>

          <div className="flex items-center gap-5">
            <label className="flex items-center gap-2 text-sm">
              <input
                type="checkbox"
                checked={form.free_shipping}
                onChange={(e) => setForm({ ...form, free_shipping: e.target.checked })}
              />
              Gratis ongkir
            </label>
            <label className="flex items-center gap-2 text-sm">
              <input
                type="checkbox"
                checked={form.active}
                onChange={(e) => setForm({ ...form, active: e.target.checked })}
              />
              Aktif
            </label>
          </div>

          <div className="flex gap-2">
            <button
              type="submit"
              disabled={save.isPending}
              className="bg-slate-900 text-white rounded-lg px-4 py-2 text-sm font-medium hover:bg-slate-700 disabled:opacity-50"
            >
              {save.isPending ? 'Menyimpan…' : 'Simpan'}
            </button>
            <button
              type="button"
              onClick={() => setForm(null)}
              className="border border-slate-300 rounded-lg px-4 py-2 text-sm hover:bg-slate-100"
            >
              Batal
            </button>
          </div>
        </form>
      )}

      <div className="bg-white rounded-xl border border-slate-200 overflow-hidden">
        {isLoading ? (
          <p className="p-6 text-sm text-slate-500">Memuat…</p>
        ) : (data?.data.length ?? 0) === 0 ? (
          <p className="p-6 text-sm text-slate-500">Belum ada voucher.</p>
        ) : (
          <table className="w-full text-sm">
            <thead className="bg-slate-50 text-slate-500 text-xs uppercase">
              <tr>
                <th className="text-left px-4 py-3">Kode</th>
                <th className="text-left px-4 py-3">Nama</th>
                <th className="text-left px-4 py-3">Cakupan</th>
                <th className="text-left px-4 py-3">Nilai</th>
                <th className="text-right px-4 py-3">Min</th>
                <th className="text-center px-4 py-3">Terpakai</th>
                <th className="text-center px-4 py-3">Status</th>
                <th className="text-right px-4 py-3">Aksi</th>
              </tr>
            </thead>
            <tbody className="divide-y divide-slate-100">
              {data?.data.map((v) => (
                <tr key={v.id}>
                  <td className="px-4 py-3 font-mono text-xs">{v.code}</td>
                  <td className="px-4 py-3">
                    <div className="font-medium">{v.name}</div>
                    {v.free_shipping && (
                      <span className="text-xs text-emerald-600">+ gratis ongkir</span>
                    )}
                  </td>
                  <td className="px-4 py-3 text-slate-500">
                    {scopeLabel[v.scope]}
                    {v.product && <span className="text-xs"> · {v.product.title}</span>}
                  </td>
                  <td className="px-4 py-3">
                    {v.type === 'percent' ? `${v.value}%` : rupiah(v.value)}
                    {v.type === 'percent' && v.max_discount ? (
                      <span className="text-xs text-slate-400"> maks {rupiah(v.max_discount)}</span>
                    ) : null}
                  </td>
                  <td className="px-4 py-3 text-right">{rupiah(v.min_spend)}</td>
                  <td className="px-4 py-3 text-center">
                    {v.used}
                    {v.quota ? ` / ${v.quota}` : ''}
                  </td>
                  <td className="px-4 py-3 text-center">
                    <span
                      className={`text-xs px-2 py-0.5 rounded-full ${
                        v.active ? 'bg-emerald-50 text-emerald-700' : 'bg-slate-100 text-slate-500'
                      }`}
                    >
                      {v.active ? 'Aktif' : 'Nonaktif'}
                    </span>
                  </td>
                  <td className="px-4 py-3 text-right">
                    <div className="flex gap-1.5 justify-end">
                      <button
                        onClick={() => edit(v)}
                        className="text-xs px-2 py-1 rounded border border-slate-200 hover:bg-slate-50"
                      >
                        Edit
                      </button>
                      <button
                        onClick={() => toggle.mutate(v)}
                        className="text-xs px-2 py-1 rounded border border-slate-200 hover:bg-slate-50"
                      >
                        {v.active ? 'Matikan' : 'Aktifkan'}
                      </button>
                      <button
                        onClick={() => confirm('Hapus voucher ini?') && remove.mutate(v.id)}
                        className="text-xs px-2 py-1 rounded border border-red-200 text-red-600 hover:bg-red-50"
                      >
                        Hapus
                      </button>
                    </div>
                  </td>
                </tr>
              ))}
            </tbody>
          </table>
        )}
      </div>

      {user?.merchant && (
        <p className="text-xs text-slate-500">
          Voucher tampil otomatis di storefront tokomu:{' '}
          <span className="font-mono">/{user.merchant.slug}</span>
        </p>
      )}
    </div>
  )
}
