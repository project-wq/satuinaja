import { Link } from 'react-router-dom'
import { useCart } from '../store'

export function rupiah(n: number | string | null | undefined): string {
  return 'Rp' + Number(n ?? 0).toLocaleString('id-ID')
}

export function imageUrl(path: string | undefined): string | null {
  if (!path) return null
  return path.startsWith('http') ? path : `/storage/${path}`
}

export default function Header({ shopSlug, shopName }: { shopSlug: string; shopName: string }) {
  const count = useCart((s) => s.items.reduce((a, i) => a + i.qty, 0))

  return (
    <header className="bg-white border-b border-slate-200 sticky top-0 z-20">
      <div className="max-w-6xl mx-auto px-4 h-16 flex items-center gap-4">
        <Link to={`/${shopSlug}`} className="font-bold text-lg">
          {shopName}
        </Link>
        <nav className="ml-6 hidden sm:flex items-center gap-4 text-sm text-slate-600">
          <Link to={`/${shopSlug}`} className="hover:text-slate-900">
            Produk
          </Link>
          <Link to={`/${shopSlug}/lacak`} className="hover:text-slate-900">
            Lacak Pesanan
          </Link>
        </nav>
        <Link
          to={`/${shopSlug}/keranjang`}
          className="ml-auto relative px-4 py-2 rounded-lg bg-slate-900 text-white text-sm font-medium"
        >
          Keranjang
          {count > 0 && (
            <span className="absolute -top-1.5 -right-1.5 bg-rose-500 text-white text-[10px] font-bold w-5 h-5 rounded-full grid place-items-center">
              {count}
            </span>
          )}
        </Link>
      </div>
    </header>
  )
}
