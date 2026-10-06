import { useState } from 'react'
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query'
import { api, type Channel, type PlatformSpec, type PublishRecord } from '../../services/api'

export default function Channels() {
  const qc = useQueryClient()
  const [open, setOpen] = useState<string | null>(null)
  const [history, setHistory] = useState<number | null>(null)
  const [_toast, _setToast] = useState('')

  // Spesifikasi platform (field, label, catatan) diambil dari backend
  // supaya form selalu sinkron dengan service yang benar-benar ada.
  const { data: specs } = useQuery({
    queryKey: ['platforms'],
    queryFn: () => api.get<{ data: PlatformSpec[] }>('/platforms'),
    staleTime: 5 * 60_000,
  })

  const { data } = useQuery({
    queryKey: ['channels'],
    queryFn: () => api.get<{ data: Channel[] }>('/channels'),
  })

  const { data: logs } = useQuery({
    queryKey: ['channel-logs', history],
    queryFn: () => api.get<{ data: PublishRecord[] }>(`/channels/${history}/logs`),
    enabled: history !== null,
  })

  const save = useMutation({
    mutationFn: (body: { platform: string; label: string; credentials: Record<string, string> }) =>
      api.post<{ data: Channel }>('/channels', body),
    onSuccess: () => {
      qc.invalidateQueries({ queryKey: ['channels'] })
      setOpen(null)
      setToast('Kredensial tersimpan (terenkripsi AES-256).')
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
    mutationFn: (id: number) => api.post<{ ok: boolean; error?: string; data?: unknown }>(`/channels/${id}/verify`),
    onSuccess: (r) => setToast(r.ok ? `Koneksi OK: ${JSON.stringify(r.data)}` : `Gagal: ${r.error}`),
    onError: (e) => setToast(e instanceof Error ? e.message : 'Verifikasi gagal'),
  })

  const byPlatform = new Map((data?.data ?? []).map((c) => [c.platform, c]))

  return (
    <div className="space-y-5">
      <div>
        <h1 className="text-2xl font-bold">Channel</h1>
        <p className="text-sm text-slate-500 mt-1">
          Masukkan API key / login tiap platform. Kredensial dienkripsi AES-256 dan tidak pernah dikirim
          balik ke browser.
        </p>
      </div>

      {toast && (
        <div className="text-sm bg-slate-900 text-white rounded-lg px-4 py-2 flex items-center justify-between">
          <span>{toast}</span>
          <button onClick={() => setToast('')}>✕</button>
        </div>
      )}

      <div className="grid md:grid-cols-2 gap-4">
        {(specs?.data ?? []).map((p) => {
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
                <p className="text-xs text-slate-400">Kunci tersimpan: {ch.credential_keys.join(', ')}</p>
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
                    <button
                      onClick={() => setHistory(history === ch.id ? null : ch.id)}
                      className="text-xs px-3 py-1.5 rounded-lg border border-slate-300 hover:bg-slate-50"
                    >
                      {history === ch.id ? 'Tutup riwayat' : 'Riwayat publish'}
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
                        type={f.secret ? 'password' : 'text'}
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

              {history === ch?.id && logs && (
                <div className="pt-3 border-t border-slate-100 space-y-2">
                  <h3 className="text-xs font-semibold text-slate-600">Riwayat publish</h3>
                  {logs.data.length === 0 && <p className="text-xs text-slate-400">Belum ada.</p>}
                  {logs.data.map((r) => (
                    <div key={r.id} className="text-xs rounded-lg border border-slate-100 p-3 space-y-1">
                      <div className="flex items-center justify-between gap-2">
                        <span className="font-medium truncate">{r.product?.title ?? '—'}</span>
                        <span
                          className={`px-1.5 py-0.5 rounded ${
                            r.status === 'success'
                              ? 'bg-emerald-50 text-emerald-700'
                              : r.status === 'failed'
                                ? 'bg-rose-50 text-rose-700'
                                : 'bg-slate-100 text-slate-500'
                          }`}
                        >
                          {r.status}
                        </span>
                      </div>

                      {r.external_url && (
                        <a
                          href={r.external_url}
                          target="_blank"
                          rel="noreferrer"
                          className="text-sky-600 underline break-all"
                        >
                          {r.external_url}
                        </a>
                      )}

                      {r.error && <p className="text-rose-600">{r.error}</p>}

                      {/* Sistem menyiapkan caption / payload ketika publish butuh
                          aksi tambahan (TikTok video, Tokopedia kemitraan). */}
                      {r.meta?.caption && (
                        <div className="bg-slate-50 rounded p-2">
                          <p className="text-[10px] uppercase text-slate-400 mb-1">Caption siap pakai</p>
                          <p className="whitespace-pre-wrap text-slate-700">{r.meta.caption}</p>
                        </div>
                      )}
                      {r.meta?.payload && (
                        <div className="bg-slate-50 rounded p-2">
                          <p className="text-[10px] uppercase text-slate-400 mb-1">Payload siap-tempel</p>
                          <pre className="overflow-x-auto text-[10px] text-slate-700">
                            {JSON.stringify(r.meta.payload, null, 2)}
                          </pre>
                          <button
                            onClick={() => navigator.clipboard.writeText(JSON.stringify(r.meta!.payload, null, 2))}
                            className="mt-1 text-[10px] px-2 py-0.5 rounded border border-slate-300 hover:bg-white"
                          >
                            Salin JSON
                          </button>
                        </div>
                      )}
                    </div>
                  ))}
                </div>
              )}
            </div>
          )
        })}
      </div>
    </div>
  )
}
