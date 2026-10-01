import { useQuery } from '@tanstack/react-query'
import { useState } from 'react'
import { Link, useNavigate, useParams } from 'react-router-dom'
import { api, type Product } from '../services/api'
import Header, { imageUrl, rupiah } from '../components/ShopHeader'
import { useCart } from '../store'

interface DetailResponse {
  data: {
    merchant: { name: string; slug: string; city_id: number | null }
    product: Product
  }
}

export default function ProductDetail() {
  const { shopSlug = '', productSlug = '' } = useParams()
  const navigate = useNavigate()
  const add = useCart((s) => s.add)
  const [qty, setQty] = useState(1)
  const [imgIdx, setImgIdx] = useState(0)

  const { data, isLoading, error } = useQuery({
    queryKey: ['product', shopSlug, productSlug],
    queryFn: () => api.get<DetailResponse>(`/shops/${shopSlug}/products/${productSlug}`),
  })

  if (isLoading) return <p className="p-10 text-center text-slate-500">Memuat…</p>
  if (error) return <p className="p-10 text-center text-rose-600">Produk tidak ditemukan.</p>

  const { merchant, product } = data!.data
  const images = (product.images ?? []).map((i) => imageUrl(i)!).filter(Boolean)

  return (
    <div className="min-h-screen bg-slate-50">
      <Header shopSlug={merchant.slug} shopName={merchant.name} />

      <main className="max-w-6xl mx-auto px-4 py-8">
        <nav className="text-sm text-slate-500 mb-4">
          <Link to={`/${merchant.slug}`} className="hover:underline">
            {merchant.name}
          </Link>{' '}
          / <span className="text-slate-700">{product.title}</span>
        </nav>

        <div className="grid md:grid-cols-2 gap-8">
          <div className="space-y-3">
            <div className="aspect-square bg-white rounded-xl border border-slate-200 overflow-hidden">
              {images[imgIdx] ? (
                <img src={images[imgIdx]} alt={product.title} className="w-full h-full object-cover" />
              ) : (
                <div className="w-full h-full grid place-items-center text-slate-300">Tanpa foto</div>
              )}
            </div>
            {images.length > 1 && (
              <div className="flex gap-2">
                {images.map((src, i) => (
                  <button
                    key={i}
                    onClick={() => setImgIdx(i)}
                    className={`w-16 h-16 rounded-lg overflow-hidden border-2 ${
                      i === imgIdx ? 'border-slate-900' : 'border-transparent'
                    }`}
                  >
                    <img src={src} alt="" className="w-full h-full object-cover" />
                  </button>
                ))}
              </div>
            )}
          </div>

          <div>
            <h1 className="text-2xl font-bold">{product.title}</h1>
            <p className="text-3xl font-bold mt-3">{rupiah(product.price)}</p>
            <p className="text-sm text-slate-500 mt-2">
              Berat {product.weight} g · {product.stock > 0 ? `Stok ${product.stock}` : 'Stok habis'}
            </p>

            {product.description && (
              <p className="mt-5 text-slate-700 whitespace-pre-wrap leading-relaxed">{product.description}</p>
            )}

            <div className="mt-6 flex items-center gap-3">
              <div className="flex items-center rounded-lg border border-slate-300">
                <button
                  onClick={() => setQty((q) => Math.max(1, q - 1))}
                  className="px-3 py-2 text-lg leading-none"
                >
                  −
                </button>
                <span className="px-4 tabular-nums">{qty}</span>
                <button
                  onClick={() => setQty((q) => Math.min(product.stock, q + 1))}
                  className="px-3 py-2 text-lg leading-none"
                >
                  +
                </button>
              </div>

              <button
                disabled={product.stock === 0}
                onClick={() => {
                  add({
                    productId: product.id,
                    slug: product.slug,
                    title: product.title,
                    price: product.price,
                    weight: product.weight,
                    image: images[0],
                    qty,
                  })
                  navigate(`/${merchant.slug}/keranjang`)
                }}
                className="flex-1 rounded-lg bg-slate-900 text-white py-3 font-medium disabled:opacity-40"
              >
                {product.stock === 0 ? 'Stok habis' : 'Tambah ke Keranjang'}
              </button>
            </div>
          </div>
        </div>
      </main>
    </div>
  )
}
