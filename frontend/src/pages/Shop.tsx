import { useQuery } from '@tanstack/react-query'
import { Link, useParams } from 'react-router-dom'
import { api, type Paginated, type Product } from '../services/api'
import Header, { imageUrl, rupiah } from '../components/ShopHeader'

interface ShopResponse {
  data: {
    merchant: { id: number; name: string; slug: string; description: string | null; city_id: number | null }
    products: Paginated<Product>
  }
}

export default function Shop() {
  const { shopSlug = '' } = useParams()

  const { data, isLoading, error } = useQuery({
    queryKey: ['shop', shopSlug],
    queryFn: () => api.get<ShopResponse>(`/shops/${shopSlug}`),
  })

  if (isLoading) return <p className="p-10 text-center text-slate-500">Memuat toko…</p>
  if (error) return <p className="p-10 text-center text-rose-600">Toko tidak ditemukan.</p>

  const shop = data!.data.merchant
  const products = data!.data.products.data

  return (
    <div className="min-h-screen bg-slate-50">
      <Header shopSlug={shop.slug} shopName={shop.name} />

      <section className="bg-white border-b border-slate-200">
        <div className="max-w-6xl mx-auto px-4 py-8">
          <h1 className="text-3xl font-bold">{shop.name}</h1>
          {shop.description && <p className="text-slate-500 mt-2 max-w-2xl">{shop.description}</p>}
          <p className="text-xs text-slate-400 mt-2">{data!.data.products.total} produk</p>
        </div>
      </section>

      <main className="max-w-6xl mx-auto px-4 py-8">
        {products.length === 0 ? (
          <p className="text-slate-500">Belum ada produk aktif di toko ini.</p>
        ) : (
          <div className="grid grid-cols-2 md:grid-cols-3 lg:grid-cols-4 gap-4">
            {products.map((p) => (
              <Link
                key={p.id}
                to={`/${shop.slug}/produk/${p.slug}`}
                className="group bg-white rounded-xl border border-slate-200 overflow-hidden hover:shadow-md transition"
              >
                <div className="aspect-square bg-slate-100 overflow-hidden">
                  {imageUrl(p.images?.[0]) ? (
                    <img
                      src={imageUrl(p.images?.[0])!}
                      alt={p.title}
                      className="w-full h-full object-cover group-hover:scale-105 transition"
                    />
                  ) : (
                    <div className="w-full h-full grid place-items-center text-slate-300 text-sm">Tanpa foto</div>
                  )}
                </div>
                <div className="p-3">
                  <h3 className="text-sm font-medium line-clamp-2 min-h-[2.5rem]">{p.title}</h3>
                  <p className="mt-1 font-bold">{rupiah(p.price)}</p>
                  <p className="text-xs text-slate-400 mt-0.5">
                    {p.stock > 0 ? `Stok ${p.stock}` : 'Stok habis'}
                  </p>
                </div>
              </Link>
            ))}
          </div>
        )}
      </main>
    </div>
  )
}
