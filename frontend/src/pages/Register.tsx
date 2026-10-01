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
  })
  const [error, setError] = useState('')
  const [busy, setBusy] = useState(false)

  function set<K extends keyof typeof form>(k: K, v: string) {
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

  return (
    <div className="min-h-screen grid place-items-center bg-slate-100 px-4 py-10">
      <form onSubmit={submit} className="w-full max-w-sm bg-white rounded-2xl shadow-sm p-6 space-y-4">
        <div>
          <h1 className="text-2xl font-bold">Buka Toko</h1>
          <p className="text-sm text-slate-500 mt-1">Gratis. Upload sekali, publish ke semua channel.</p>
        </div>

        {error && <div className="text-sm text-rose-600 bg-rose-50 rounded-lg px-3 py-2">{error}</div>}

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
          <input type="email" className={input} value={form.email} onChange={(e) => set('email', e.target.value)} required />
        </label>

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
