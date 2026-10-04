import { Link, NavLink, Outlet, useNavigate } from 'react-router-dom'
import { useAuth } from '../store'

export default function SellerLayout() {
  const { user, logout } = useAuth()
  const navigate = useNavigate()

  const link = ({ isActive }: { isActive: boolean }) =>
    `px-3 py-2 rounded-lg text-sm font-medium transition ${
      isActive ? 'bg-slate-900 text-white' : 'text-slate-600 hover:bg-slate-100'
    }`

  const kyc = user?.merchant?.kyc_status

  return (
    <div className="min-h-screen bg-slate-50">
      {kyc && kyc !== 'approved' && (
        <div
          className={`text-sm px-4 py-2 text-center ${
            kyc === 'pending' ? 'bg-amber-50 text-amber-800 border-b border-amber-200' : 'bg-rose-50 text-rose-700 border-b border-rose-200'
          }`}
        >
          {kyc === 'pending'
            ? 'Pendaftaran sedang diverifikasi admin (maks 1×24 jam kerja). Toko belum tayang.'
            : 'Pendaftaran ditolak admin. Hubungi support untuk pengajuan ulang.'}
        </div>
      )}
      <header className="bg-white border-b border-slate-200 sticky top-0 z-20">
        <div className="max-w-6xl mx-auto px-4 h-14 flex items-center gap-4">
          <Link to="/seller" className="font-bold text-lg tracking-tight">
            Satuinaja
          </Link>

          <nav className="flex items-center gap-1 ml-4">
            <NavLink to="/seller" end className={link}>
              Dashboard
            </NavLink>
            <NavLink to="/seller/products" className={link}>
              Produk
            </NavLink>
            <NavLink to="/seller/channels" className={link}>
              Channel
            </NavLink>
            <NavLink to="/seller/orders" className={link}>
              Pesanan
            </NavLink>
            <NavLink to="/seller/vouchers" className={link}>
              Voucher
            </NavLink>
            <NavLink to="/seller/billing" className={link}>
              Langganan
            </NavLink>
            <NavLink to="/seller/balance" className={link}>
              Saldo
            </NavLink>
            {user?.role === 'admin' && (
              <NavLink to="/seller/admin" className={link}>
                Admin
              </NavLink>
            )}
          </nav>

          <div className="ml-auto flex items-center gap-3">
            {user?.merchant && (
              <Link
                to={`/${user.merchant.slug}`}
                className="text-xs text-slate-500 hover:text-slate-900 underline"
              >
                Lihat toko publik
              </Link>
            )}
            <span className="text-sm text-slate-600 hidden sm:inline">{user?.name}</span>
            <button
              onClick={async () => {
                await logout()
                navigate('/login')
              }}
              className="text-sm px-3 py-1.5 rounded-lg border border-slate-300 hover:bg-slate-100"
            >
              Keluar
            </button>
          </div>
        </div>
      </header>

      <main className="max-w-6xl mx-auto px-4 py-6">
        <Outlet />
      </main>
    </div>
  )
}
