import { useState } from 'react'
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query'
import { api, type Channel, type Paginated, type Product } from '../../services/api'
import { rupiah, imageUrl } from '../../components/ShopHeader'

export default function Products() {
  const qc = useQueryClient()
  const [showForm, setShowForm] = useState(false)
  const [aiResult, setAiResult] = useState<string>('')
  const [busyAi, setBusyAi] = useState(false)
  const [_toast, _setToast] = useState('')

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

  // ---- Fase 11: editor varian ----
  const [variantFor, setVariantFor] = useState<Product | null>(null)
  const [vrows, setVrows] = useState<{ name: string; price: string; stock: string; sku: string }[]>([])

  async function openVariants(p: Product) {
    try {
      const res = await api.get<{ data: Product }>(`/products/${p.id}`)
      const vs = res.data.variants ?? []
      setVrows(
        vs.length
          ? vs.map((v) => ({
              name: v.name,
              price: v.price === null ? '' : String(v.price),
              stock: String(v.stock),
              sku: v.sku ?? '',
            }))
          : [{ name: '', price: '', stock: '0', sku: '' }],
      )
      setVariantFor(p)
    } catch (e) {
      setToast(e instanceof Error ? e.message : 'Gagal memuat varian')
    }
  }

  const saveVariants = useMutation({
    mutationFn: (payload: { id: number; variants: { name: string; price: number | null; stock: number; sku: string | null }[] }) =>
      api.put(`/products/${payload.id}/variants`, { variants: payload.variants }),
    onSuccess: () => {
      qc.invalidateQueries({ queryKey: ['products'] })
      setToast('Varian tersimpan.')
      setVariantFor(null)
    },
    onError: (e) => setToast(e instanceof Error ? e.message : 'Gagal simpan varian'),
  })

  function submitVariants() {
    if (!variantFor) return
    const variants = vrows
      .filter((r) => r.name.trim() !== '')
      .map((r) => ({
        name: r.name.trim(),
        sku: r.sku.trim() || null,
        price: r.price.trim() === '' ? null : Number(r.price),
        stock: Math.max(0, Number(r.stock) || 0),
      }))
    if (variants.length === 0) {
      setToast('Isi minimal satu varian (atau biarkan kosong untuk hapus semua).')
      return
    }
    saveVariants.mutate({ id: variantFor.id, variants })
  }

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

      {variantFor && (
        <div className="bg-white rounded-xl border border-amber-200 p-5 space-y-3">
          <div className="flex items-center justify-between">
            <div>
              <h2 className="font-semibold">Varian — {variantFor.title}</h2>
              <p className="text-xs text-slate-500 mt-0.5">
                Kosongkan harga = ikut harga produk. Stok produk otomatis = jumlah stok varian.
              </p>
            </div>
            <button onClick={() => setVariantFor(null)} className="text-slate-400 hover:text-slate-600">
              ✕
            </button>
          </div>

          <div className="space-y-2">
            {vrows.map((r, i) => (
              <div key={i} className="grid grid-cols-12 gap-2 items-center">
                <input
                  value={r.name}
                  onChange={(e) => setVrows(vrows.map((x, j) => (j === i ? { ...x, name: e.target.value } : x)))}
                  placeholder="Nama (M, L, Merah / XL)"
                  className="col-span-4 rounded-lg border border-slate-300 px-3 py-1.5 text-sm"
                />
                <input
                  value={r.sku}
                  onChange={(e) => setVrows(vrows.map((x, j) => (j === i ? { ...x, sku: e.target.value } : x)))}
                  placeholder="SKU (opsional)"
                  className="col-span-3 rounded-lg border border-slate-300 px-3 py-1.5 text-sm"
                />
                <input
                  type="number"
                  min={0}
                  value={r.price}
                  onChange={(e) => setVrows(vrows.map((x, j) => (j === i ? { ...x, price: e.target.value } : x)))}
                  placeholder="Harga (ikut produk)"
                  className="col-span-2 rounded-lg border border-slate-300 px-3 py-1.5 text-sm"
                />
                <input
                  type="number"
                  min={0}
                  value={r.stock}
                  onChange={(e) => setVrows(vrows.map((x, j) => (j === i ? { ...x, stock: e.target.value } : x)))}
                  placeholder="Stok"
                  className="col-span-2 rounded-lg border border-slate-300 px-3 py-1.5 text-sm"
                />
                <button
                  onClick={() => setVrows(vrows.filter((_, j) => j !== i))}
                  className="col-span-1 text-rose-500 text-sm"
                  title="Hapus baris"
                >
                  ✕
                </button>
              </div>
            ))}
          </div>

          <div className="flex items-center gap-2">
            <button
              onClick={() => setVrows([...vrows, { name: '', price: '', stock: '0', sku: '' }])}
              className="text-xs px-3 py-1.5 rounded-lg border border-slate-300 hover:bg-slate-50"
            >
              + Tambah varian
            </button>
            <button
              onClick={submitVariants}
              disabled={saveVariants.isPending}
              className="text-xs px-3 py-1.5 rounded-lg bg-slate-900 text-white disabled:opacity-50"
            >
              {saveVariants.isPending ? 'Menyimpan…' : 'Simpan Varian'}
            </button>
            <button
              onClick={() => {
                setVrows([])
                saveVariants.mutate({ id: variantFor.id, variants: [] })
              }}
              className="text-xs px-3 py-1.5 rounded-lg border border-rose-200 text-rose-600"
            >
              Hapus semua varian
            </button>
          </div>
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
              <span className="text-sm font-medium text-slate-700">Batas Stok Menipis</span>
              <input
                type="number"
                name="low_stock_at"
                min={0}
                defaultValue={5}
                className="mt-1 w-full rounded-lg border border-slate-300 px-3 py-2"
              />
              <span className="text-xs text-slate-400">Notif saat stok ≤ angka ini. Stok 0 = listing auto-arsip.</span>
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
                  <td className="px-4 py-3 text-right">
                    {p.stock}
                    {(p.low_stock_at ?? 5) >= p.stock && (
                      <span className={`ml-1 text-[10px] px-1.5 py-0.5 rounded-full ${p.stock <= 0 ? 'bg-rose-100 text-rose-700' : 'bg-amber-100 text-amber-700'}`}>
                        {p.stock <= 0 ? 'habis' : 'menipis'}
                      </span>
                    )}
                  </td>
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
                        onClick={() => openVariants(p)}
                        className="text-xs px-2.5 py-1.5 rounded-lg border border-slate-300 hover:bg-slate-50"
                      >
                        Varian
                      </button>
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
