import { useState } from 'react'
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query'
import { api, type Channel } from '../../services/api'

const PLATFORMS = [
  {
    key: 'facebook',
    name: 'Facebook Page',
    fields: [
      { key: 'page_id', label: 'Page ID', placeholder: '1234567890' },
      { key: 'page_token', label: 'Page Access Token', placeholder: 'EAAG...', secret: true },
    ],
    note: 'Butuh Meta App + Page Access Token long-lived.',
  },
  {
    key: 'instagram',
    name: 'Instagram Business',
    fields: [
      { key: 'ig_user_id', label: 'IG Business Account ID', placeholder: '178414...' },
      { key: 'page_token', label: 'Access Token (FB App)', placeholder: 'EAAG...', secret: true },
    ],
    note: 'IG wajib terhubung ke Facebook Page.',
  },
  {
    key: 'tiktok',
    name: 'TikTok / TikTok Shop',
    fields: [
      { key: 'app_key', label: 'App Key', placeholder: '' },
      { key: 'app_secret', label: 'App Secret', placeholder: '', secret: true },
      { key: 'access_token', label: 'Access Token', placeholder: '', secret: true },
    ],
    note: 'Perlu approval TikTok for Developers.',
  },
  {
    key: 'shopee',
    name: 'Shopee',
    fields: [
      { key: 'partner_id', label: 'Partner ID', placeholder: '' },
      { key: 'partner_key', label: 'Partner Key', placeholder: '', secret: true },
      { key: 'shop_id', label: 'Shop ID', placeholder: '' },
      { key: 'access_token', label: 'Access Token', placeholder: '', secret: true },
    ],
    note: 'Wajib daftar Shopee Open Platform.',
  },
  {
    key: 'tokopedia',
    name: 'Tokopedia',
    fields: [
      { key: 'client_id', label: 'Client ID', placeholder: '' },
      { key: 'client_secret', label: 'Client Secret', placeholder: '', secret: true },
      { key: 'fs_id', label: 'FS ID (toko)', placeholder: '' },
    ],
    note: 'Akses via Tokopedia Partner.',
  },
] as const

export default function Channels() {
  const qc = useQueryClient()
  const [open, setOpen] = useState<string | null>(null)
  const [toast, setToast] = useState('')

  const { data } = useQuery({
    queryKey: ['channels'],
    queryFn: () => api.get<{ data: Channel[] }>('/channels'),
  })

  const save = useMutation({
    mutationFn: (body: { platform: string; label: string; credentials: Record<string, string> }) =>
      api.post<{ data: Channel }>('/channels', body),
    onSuccess: () => {
      qc.invalidateQueries({ queryKey: ['channels'] })
      setOpen(null)
      setToast('Kredensial tersimpan (terenkripsi).')
    },
    onError: (e) => setToast(e instanceof Error ? e.message : 'Gagal simpan'),
  })

  const toggle = useMutation({
    mutationFn: ({ id, active }: { id: number; active: boolean }) =>
      api.put<{ data: Channel }>(`/channels/${id}`, { active }),
    onSuccess: () => qc.invalidateQueries({ queryKey: ['channels'] }),
    onError: (e) => setToast(e instanceof Error ? e.message : 'Gagal update'),
  })

  const verify = useMutation({
    mutationFn: (id: number) => api.post<{ ok: boolean; error?: string }>(`/channels/${id}/verify`),
    onSuccess: (r) => setToast(r.ok ? 'Koneksi OK.' : `Gagal: ${r.error}`),
    onError: (e) => setToast(e instanceof Error ? e.message : 'Verifikasi gagal'),
  })

  const byPlatform = new Map((data?.data ?? []).map((c) => [c.platform, c]))

  return (
    <div className="space-y-5">
      <div>
        <h1 className="text-2xl font-bold">Channel</h1>
        <p className="text-sm text-slate-500 mt-1">
          Masukkan API key / login tiap platform. Kredensial dienkripsi AES-256 dan tidak pernah dikirim balik
          ke browser.
        </p>
      </div>

      {toast && (
        <div className="text-sm bg-slate-900 text-white rounded-lg px-4 py-2 flex items-center justify-between">
          <span>{toast}</span>
          <button onClick={() => setToast('')}>✕</button>
        </div>
      )}

      <div className="grid md:grid-cols-2 gap-4">
        {PLATFORMS.map((p) => {
          const ch = byPlatform.get(p.key)
          return (
            <div key={p.key} className="bg-white rounded-xl border border-slate-200 p-5 space-y-3">
              <div className="flex items-start justify-between">
                <div>
                  <h2 className="font-semibold">{p.name}</h2>
                  <p className="text-xs text-slate-500 mt-0.5">{p.note}</p>
                </div>
                <span
                  className={`text-xs px-2 py-0.5 rounded-full shrink-0 ${
                    ch?.active ? 'bg-emerald-50 text-emerald-700' : 'bg-slate-100 text-slate-500'
                  }`}
                >
                  {ch ? (ch.active ? 'aktif' : 'nonaktif') : 'belum diatur'}
                </span>
              </div>

              {ch && ch.credential_keys.length > 0 && (
                <p className="text-xs text-slate-400">
                  Kunci tersimpan: {ch.credential_keys.join(', ')}
                </p>
              )}

              {ch?.last_error && (
                <p className="text-xs text-rose-600 bg-rose-50 rounded-lg px-3 py-2">{ch.last_error}</p>
              )}

              <div className="flex flex-wrap gap-2 pt-1">
                <button
                  onClick={() => setOpen(open === p.key ? null : p.key)}
                  className="text-xs px-3 py-1.5 rounded-lg border border-slate-300 hover:bg-slate-50"
                >
                  {ch ? 'Ubah kredensial' : 'Hubungkan'}
                </button>

                {ch && (
                  <>
                    <button
                      onClick={() => toggle.mutate({ id: ch.id, active: !ch.active })}
                      className="text-xs px-3 py-1.5 rounded-lg bg-slate-900 text-white"
                    >
                      {ch.active ? 'Nonaktifkan' : 'Aktifkan'}
                    </button>
                    <button
                      onClick={() => verify.mutate(ch.id)}
                      disabled={verify.isPending}
                      className="text-xs px-3 py-1.5 rounded-lg border border-slate-300 hover:bg-slate-50 disabled:opacity-50"
                    >
                      {verify.isPending ? 'Menguji…' : 'Uji koneksi'}
                    </button>
                  </>
                )}
              </div>

              {open === p.key && (
                <form
                  onSubmit={(e) => {
                    e.preventDefault()
                    const fd = new FormData(e.currentTarget)
                    const credentials: Record<string, string> = {}
                    p.fields.forEach((f) => {
                      const v = String(fd.get(f.key) ?? '').trim()
                      if (v) credentials[f.key] = v
                    })
                    save.mutate({ platform: p.key, label: p.name, credentials })
                  }}
                  className="space-y-3 pt-3 border-t border-slate-100"
                >
                  {p.fields.map((f) => (
                    <label key={f.key} className="block">
                      <span className="text-xs font-medium text-slate-700">{f.label}</span>
                      <input
                        name={f.key}
                        type={'secret' in f && f.secret ? 'password' : 'text'}
                        placeholder={f.placeholder}
                        autoComplete="off"
                        className="mt-1 w-full rounded-lg border border-slate-300 px-3 py-2 text-sm"
                      />
                    </label>
                  ))}
                  <button
                    disabled={save.isPending}
                    className="text-xs px-3 py-1.5 rounded-lg bg-slate-900 text-white disabled:opacity-50"
                  >
                    {save.isPending ? 'Menyimpan…' : 'Simpan'}
                  </button>
                </form>
              )}
            </div>
          )
        })}
      </div>
    </div>
  )
}
