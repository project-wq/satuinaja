import { useState } from 'react'
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query'
import { api, type Channel, type Paginated, type Product } from '../../services/api'
import { rupiah, imageUrl } from '../../components/ShopHeader'

export default function Products() {
  const qc = useQueryClient()
  const [showForm, setShowForm] = useState(false)
  const [aiResult, setAiResult] = useState<string>('')
  const [busyAi, setBusyAi] = useState(false)
  const [toast, setToast] = useState('')

  const { data, isLoading } = useQuery({
    queryKey: ['products'],
    queryFn: () => api.get<Paginated<Product>>('/products?per_page=100'),
  })

  const channels = useQuery({
    queryKey: ['channels'],
    queryFn: () => api.get<{ data: Channel[] }>('/channels'),
  })

  const create = useMutation({
    mutationFn: (fd: FormData) => api.post<Product>('/products', fd),
    onSuccess: () => {
      qc.invalidateQueries({ queryKey: ['products'] })
      setShowForm(false)
      setToast('Produk ditambahkan.')
    },
    onError: (e) => setToast(e instanceof Error ? e.message : 'Gagal simpan'),
  })

  const publish = useMutation({
    mutationFn: (id: number) => api.post<{ message: string }>(`/products/${id}/publish`),
    onSuccess: (r) => {
      qc.invalidateQueries({ queryKey: ['products'] })
      setToast(r.message)
    },
    onError: (e) => setToast(e instanceof Error ? e.message : 'Gagal publish'),
  })

  const remove = useMutation({
    mutationFn: (id: number) => api.delete(`/products/${id}`),
    onSuccess: () => {
      qc.invalidateQueries({ queryKey: ['products'] })
      setToast('Produk dihapus.')
    },
  })

  async function generateCaption(product: Product) {
    setBusyAi(true)
    setAiResult('')
    try {
      const res = await api.post<{ ok: boolean; data?: { caption: string; hashtags: string[]; cta: string }; error?: string }>(
        '/ai/caption',
        { product_id: product.id, tone: 'promo' },
      )
      if (res.ok && res.data) {
        setAiResult(
          `${res.data.caption}\n\n${res.data.cta}\n\n${res.data.hashtags.map((h) => '#' + h).join(' ')}`,
        )
      } else {
        setAiResult(res.error ?? 'Gagal generate.')
      }
    } catch (e) {
      setAiResult(e instanceof Error ? e.message : 'Gagal generate.')
    } finally {
      setBusyAi(false)
    }
  }

  function submit(e: React.FormEvent<HTMLFormElement>) {
    e.preventDefault()
    const fd = new FormData(e.currentTarget)
    if (!fd.get('status')) fd.set('status', 'draft')
    create.mutate(fd)
  }

  const activeChannels = channels.data?.data.filter((c) => c.active).length ?? 0

  return (
    <div className="space-y-5">
      <div className="flex items-center justify-between">
        <div>
          <h1 className="text-2xl font-bold">Produk</h1>
          <p className="text-sm text-slate-500 mt-1">
            Upload sekali → publish ke {activeChannels} channel aktif.
          </p>
        </div>
        <button
          onClick={() => setShowForm((v) => !v)}
          className="px-4 py-2 rounded-lg bg-slate-900 text-white text-sm font-medium"
        >
          {showForm ? 'Tutup' : '+ Produk'}
        </button>
      </div>

      {toast && (
        <div className="text-sm bg-slate-900 text-white rounded-lg px-4 py-2 flex items-center justify-between">
          <span>{toast}</span>
          <button onClick={() => setToast('')} className="opacity-70">
            ✕
          </button>
        </div>
      )}

      {showForm && (
        <form onSubmit={submit} className="bg-white rounded-xl border border-slate-200 p-5 space-y-4">
          <div className="grid md:grid-cols-2 gap-4">
            <label className="block md:col-span-2">
              <span className="text-sm font-medium text-slate-700">Nama produk</span>
              <input
                name="title"
                required
                maxLength={160}
                className="mt-1 w-full rounded-lg border border-slate-300 px-3 py-2"
              />
            </label>

            <label className="block md:col-span-2">
              <span className="text-sm font-medium text-slate-700">Deskripsi</span>
              <textarea
                name="description"
                rows={3}
                maxLength={5000}
                className="mt-1 w-full rounded-lg border border-slate-300 px-3 py-2"
              />
            </label>

            <label className="block">
              <span className="text-sm font-medium text-slate-700">Harga (Rp)</span>
              <input
                type="number"
                name="price"
                min={0}
                required
                className="mt-1 w-full rounded-lg border border-slate-300 px-3 py-2"
              />
            </label>

            <label className="block">
              <span className="text-sm font-medium text-slate-700">Stok</span>
              <input
                type="number"
                name="stock"
                min={0}
                required
                className="mt-1 w-full rounded-lg border border-slate-300 px-3 py-2"
              />
            </label>

            <label className="block">
              <span className="text-sm font-medium text-slate-700">Berat (gram)</span>
              <input
                type="number"
                name="weight"
                min={1}
                defaultValue={500}
                required
                className="mt-1 w-full rounded-lg border border-slate-300 px-3 py-2"
              />
            </label>

            <label className="block">
              <span className="text-sm font-medium text-slate-700">Status</span>
              <select name="status" className="mt-1 w-full rounded-lg border border-slate-300 px-3 py-2">
                <option value="draft">Draft</option>
                <option value="active">Aktif</option>
              </select>
            </label>

            <label className="block md:col-span-2">
              <span className="text-sm font-medium text-slate-700">Foto (maks 8, jpg/png/webp)</span>
              <input
                type="file"
                name="images[]"
                accept="image/jpeg,image/png,image/webp"
                multiple
                className="mt-1 w-full text-sm"
              />
            </label>
          </div>

          <button
            disabled={create.isPending}
            className="px-4 py-2 rounded-lg bg-slate-900 text-white text-sm font-medium disabled:opacity-50"
          >
            {create.isPending ? 'Menyimpan…' : 'Simpan Produk'}
          </button>
        </form>
      )}

      {aiResult && (
        <div className="bg-indigo-50 border border-indigo-200 rounded-xl p-4">
          <div className="flex items-center justify-between mb-2">
            <h3 className="text-sm font-semibold text-indigo-900">Caption AI</h3>
            <button onClick={() => setAiResult('')} className="text-indigo-700 text-sm">
              ✕
            </button>
          </div>
          <pre className="whitespace-pre-wrap text-sm text-indigo-900 font-sans">{aiResult}</pre>
          <button
            onClick={() => navigator.clipboard.writeText(aiResult)}
            className="mt-3 text-xs px-3 py-1.5 rounded-lg bg-indigo-600 text-white"
          >
            Copy
          </button>
        </div>
      )}

      <div className="bg-white rounded-xl border border-slate-200 overflow-hidden">
        {isLoading ? (
          <p className="p-6 text-sm text-slate-500">Memuat…</p>
        ) : (data?.data.length ?? 0) === 0 ? (
          <p className="p-6 text-sm text-slate-500">Belum ada produk. Klik “+ Produk”.</p>
        ) : (
          <table className="w-full text-sm">
            <thead className="bg-slate-50 text-slate-500 text-xs uppercase">
              <tr>
                <th className="text-left px-4 py-3">Produk</th>
                <th className="text-right px-4 py-3">Harga</th>
                <th className="text-right px-4 py-3">Stok</th>
                <th className="text-center px-4 py-3">Status</th>
                <th className="text-right px-4 py-3">Aksi</th>
              </tr>
            </thead>
            <tbody className="divide-y divide-slate-100">
              {data?.data.map((p) => (
                <tr key={p.id}>
                  <td className="px-4 py-3">
                    <div className="flex items-center gap-3">
                      <div className="w-10 h-10 rounded-lg bg-slate-100 grid place-items-center overflow-hidden shrink-0">
                        {imageUrl(p.images?.[0]) ? (
                          <img src={imageUrl(p.images?.[0])!} alt="" className="w-full h-full object-cover" />
                        ) : (
                          <span className="text-slate-400 text-xs">—</span>
                        )}
                      </div>
                      <span className="font-medium">{p.title}</span>
                    </div>
                  </td>
                  <td className="px-4 py-3 text-right">{rupiah(p.price)}</td>
                  <td className="px-4 py-3 text-right">{p.stock}</td>
                  <td className="px-4 py-3 text-center">
                    <span
                      className={`text-xs px-2 py-0.5 rounded-full ${
                        p.status === 'active' ? 'bg-emerald-50 text-emerald-700' : 'bg-slate-100 text-slate-500'
                      }`}
                    >
                      {p.status}
                    </span>
                  </td>
                  <td className="px-4 py-3">
                    <div className="flex items-center justify-end gap-2">
                      <button
                        onClick={() => generateCaption(p)}
                        disabled={busyAi}
                        className="text-xs px-2.5 py-1.5 rounded-lg border border-slate-300 hover:bg-slate-50 disabled:opacity-50"
                      >
                        AI caption
                      </button>
                      <button
                        onClick={() => publish.mutate(p.id)}
                        disabled={publish.isPending || activeChannels === 0}
                        title={activeChannels === 0 ? 'Aktifkan channel dulu' : ''}
                        className="text-xs px-2.5 py-1.5 rounded-lg bg-slate-900 text-white disabled:opacity-40"
                      >
                        Publish
                      </button>
                      <button
                        onClick={() => confirm(`Hapus ${p.title}?`) && remove.mutate(p.id)}
                        className="text-xs px-2.5 py-1.5 rounded-lg border border-rose-200 text-rose-600 hover:bg-rose-50"
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
    </div>
  )
}
