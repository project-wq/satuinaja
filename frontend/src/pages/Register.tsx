import { useState } from 'react'
import { Link, useNavigate } from 'react-router-dom'
import { useAuth } from '../store'

export default function Register() {
  const { register } = useAuth()
  const navigate = useNavigate()
  const [form, setForm] = useState({
    name: '',
    email: '',
    password: '',
    password_confirmation: '',
    store_name: '',
    // Alamat pengambilan (Biteship origin) — wajib untuk cek ongkir otomatis.
    phone: '',
    address: '',
    province: '',
    city_name: '',
    district: '',
    postal_code: '',
    // KYC
    kyc_nik: '',
    kyc_ktp: null as File | null,
  })
  const [error, setError] = useState('')
  const [busy, setBusy] = useState(false)

  function set<K extends keyof typeof form>(k: K, v: string | File | null) {
    setForm((f) => ({ ...f, [k]: v }))
  }

  async function submit(e: React.FormEvent) {
    e.preventDefault()
    setError('')
    setBusy(true)
    try {
      await register(form)
      navigate('/seller')
    } catch (err) {
      setError(err instanceof Error ? err.message : 'Gagal daftar')
    } finally {
      setBusy(false)
    }
  }

  const input =
    'mt-1 w-full rounded-lg border border-slate-300 px-3 py-2 focus:outline-none focus:ring-2 focus:ring-slate-900'
  const legend = 'text-xs font-semibold uppercase tracking-wide text-slate-400'

  return (
    <div className="min-h-screen grid place-items-center bg-slate-100 px-4 py-10">
      <form onSubmit={submit} className="w-full max-w-md bg-white rounded-2xl shadow-sm p-6 space-y-4">
        <div>
          <h1 className="text-2xl font-bold">Buka Toko</h1>
          <p className="text-sm text-slate-500 mt-1">
            Gratis. Upload sekali, publish ke semua channel. Toko aktif setelah verifikasi admin.
          </p>
        </div>

        {error && <div className="text-sm text-rose-600 bg-rose-50 rounded-lg px-3 py-2">{error}</div>}

        <div className="space-y-3">
          <p className={legend}>Akun</p>
          <label className="block">
            <span className="text-sm font-medium text-slate-700">Nama kamu</span>
            <input className={input} value={form.name} onChange={(e) => set('name', e.target.value)} required />
          </label>
          <label className="block">
            <span className="text-sm font-medium text-slate-700">Nama toko</span>
            <input
              className={input}
              value={form.store_name}
              onChange={(e) => set('store_name', e.target.value)}
              required
              placeholder="mis. Toko Baju Keren"
            />
          </label>
          <label className="block">
            <span className="text-sm font-medium text-slate-700">Email</span>
            <input
              type="email"
              className={input}
              value={form.email}
              onChange={(e) => set('email', e.target.value)}
              required
            />
          </label>
          <div className="grid grid-cols-2 gap-3">
            <label className="block">
              <span className="text-sm font-medium text-slate-700">Password</span>
              <input
                type="password"
                minLength={8}
                className={input}
                value={form.password}
                onChange={(e) => set('password', e.target.value)}
                required
              />
            </label>
            <label className="block">
              <span className="text-sm font-medium text-slate-700">Ulangi password</span>
              <input
                type="password"
                minLength={8}
                className={input}
                value={form.password_confirmation}
                onChange={(e) => set('password_confirmation', e.target.value)}
                required
              />
            </label>
          </div>
        </div>

        <div className="space-y-3 border-t pt-4">
          <p className={legend}>Alamat toko (penjemputan kurir)</p>
          <label className="block">
            <span className="text-sm font-medium text-slate-700">No. HP kurir hubungi</span>
            <input
              className={input}
              value={form.phone}
              onChange={(e) => set('phone', e.target.value)}
              placeholder="0812xxxxxxx"
            />
          </label>
          <label className="block">
            <span className="text-sm font-medium text-slate-700">Alamat lengkap</span>
            <textarea
              className={input}
              rows={2}
              required
              value={form.address}
              onChange={(e) => set('address', e.target.value)}
              placeholder="Jl. ..., RT/RW, patokan"
            />
          </label>
          <div className="grid grid-cols-2 gap-3">
            <label className="block">
              <span className="text-sm font-medium text-slate-700">Provinsi</span>
              <input
                className={input}
                required
                value={form.province}
                onChange={(e) => set('province', e.target.value)}
              />
            </label>
            <label className="block">
              <span className="text-sm font-medium text-slate-700">Kota/Kabupaten</span>
              <input
                className={input}
                required
                value={form.city_name}
                onChange={(e) => set('city_name', e.target.value)}
              />
            </label>
            <label className="block">
              <span className="text-sm font-medium text-slate-700">Kecamatan</span>
              <input
                className={input}
                required
                value={form.district}
                onChange={(e) => set('district', e.target.value)}
              />
            </label>
            <label className="block">
              <span className="text-sm font-medium text-slate-700">Kode pos</span>
              <input
                className={input}
                required
                inputMode="numeric"
                maxLength={5}
                placeholder="12345"
                value={form.postal_code}
                onChange={(e) => set('postal_code', e.target.value)}
              />
            </label>
          </div>
        </div>

        <div className="space-y-3 border-t pt-4">
          <p className={legend}>Verifikasi identitas (KYC)</p>
          <label className="block">
            <span className="text-sm font-medium text-slate-700">NIK (16 digit)</span>
            <input
              className={input}
              required
              inputMode="numeric"
              maxLength={16}
              minLength={16}
              value={form.kyc_nik}
              onChange={(e) => set('kyc_nik', e.target.value)}
            />
          </label>
          <label className="block">
            <span className="text-sm font-medium text-slate-700">Foto KTP (JPG/PNG, maks 4MB)</span>
            <input
              type="file"
              accept="image/jpg,image/jpeg,image/png,image/webp"
              className="mt-1 block w-full text-sm text-slate-600"
              required
              onChange={(e) => set('kyc_ktp', e.target.files?.[0] ?? null)}
            />
          </label>
          <p className="text-xs text-slate-500 bg-slate-50 rounded-lg px-3 py-2">
            Pendaftaran diverifikasi admin (1×24 jam kerja). Toko tayang setelah disetujui.
          </p>
        </div>

        <button
          disabled={busy}
          className="w-full rounded-lg bg-slate-900 text-white py-2.5 font-medium hover:bg-slate-800 disabled:opacity-50"
        >
          {busy ? 'Mendaftar…' : 'Buat Toko'}
        </button>

        <p className="text-sm text-slate-500 text-center">
          Sudah punya akun?{' '}
          <Link to="/login" className="text-slate-900 font-medium underline">
            Masuk
          </Link>
        </p>
      </form>
    </div>
  )
}
