import { useEffect } from 'react'
import { Navigate, Route, Routes, useLocation } from 'react-router-dom'
import SellerLayout from './components/SellerLayout'
import { useAuth } from './store'
import Login from './pages/Login'
import Register from './pages/Register'
import Dashboard from './pages/Seller/Dashboard'
import Products from './pages/Seller/Products'
import Channels from './pages/Seller/Channels'
import Orders from './pages/Seller/Orders'
import Billing from './pages/Seller/Billing'
import Balance from './pages/Seller/Balance'
import Admin from './pages/Seller/Admin'
import Shop from './pages/Shop'
import ProductDetail from './pages/ProductDetail'
import Checkout from './pages/Checkout'
import Track from './pages/Track'

function RequireAuth({ children }: { children: React.ReactNode }) {
  const { user, loading } = useAuth()
  const location = useLocation()

  if (loading) {
    return <div className="p-10 text-center text-slate-500">Memuat…</div>
  }

  if (!user) {
    return <Navigate to="/login" replace state={{ from: location.pathname }} />
  }

  return <>{children}</>
}

export default function App() {
  const { fetchMe, loading } = useAuth()
  const location = useLocation()

  // Ambil sesi saat startup (cookie HttpOnly).
  useEffect(() => {
    fetchMe()
  }, [fetchMe])

  // Scroll-to-top setiap ganti halaman.
  useEffect(() => {
    window.scrollTo(0, 0)
  }, [location.pathname])

  if (loading) {
    return <div className="p-10 text-center text-slate-500">Memuat…</div>
  }

  return (
    <Routes>
      {/* Auth */}
      <Route path="/login" element={<Login />} />
      <Route path="/register" element={<Register />} />

      {/* Dashboard seller */}
      <Route
        path="/seller"
        element={
          <RequireAuth>
            <SellerLayout />
          </RequireAuth>
        }
      >
        <Route index element={<Dashboard />} />
        <Route path="products" element={<Products />} />
        <Route path="channels" element={<Channels />} />
        <Route path="orders" element={<Orders />} />
        <Route path="billing" element={<Billing />} />
        <Route path="balance" element={<Balance />} />
        <Route path="admin" element={<Admin />} />
      </Route>

      {/* Storefront publik */}
      <Route path="/:shopSlug" element={<Shop />} />
      <Route path="/:shopSlug/produk/:productSlug" element={<ProductDetail />} />
      <Route path="/:shopSlug/keranjang" element={<Checkout />} />
      <Route path="/:shopSlug/lacak" element={<Track />} />

      <Route path="/" element={<Navigate to="/seller" replace />} />
      <Route path="*" element={<div className="p-10 text-center text-slate-500">404 — Halaman tidak ada.</div>} />
    </Routes>
  )
}
