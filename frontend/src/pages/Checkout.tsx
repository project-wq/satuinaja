import { useEffect, useState } from 'react'
import { Link, useNavigate, useParams } from 'react-router-dom'
import Header, { rupiah } from '../components/ShopHeader'
import { useCart } from '../store'
import { api } from '../services/api'

interface ShippingOption {
  courier: string
  service: string
  name: string
  description: string
  cost: number
  etd: string
}

interface CostResult {
  ok: boolean
  data?: { services: ShippingOption[] }
  error?: string
}

interface FeePreview {
  subtotal: number
  discount_total: number
  subtotal_sale: number
  buyer_fee: number
  buyer_admin_fee: number
  shipping_cost: number
  total: number
  voucher: { code: string; name: string; discount: number; free_shipping: boolean } | null
  voucher_disallowed: string | null
}

interface PublicVoucher {
  code: string
  name: string
  type: 'percent' | 'fixed'
  value: number
  min_spend: number
  max_discount: number | null
  free_shipping: boolean
  scope: string
  end_at: string | null
  sisa: number | null
}

export default function Checkout() {
  const { shopSlug = '' } = useParams()
  const navigate = useNavigate()
  const { items, setQty, remove, subtotal, clear } = useCart()
  const [costs, setCosts] = useState<CostResult['data'] | null>(null)
  const [ongkirErr, setOngkirErr] = useState('')
  const [selected, setSelected] = useState<ShippingOption | null>(null)
  const [form, setForm] = useState({
    buyer_name: '',
    buyer_phone: '',
    buyer_email: '',
    shipping_address: '',
    destination_postal_code: '',
  })
  const [loadingCost, setLoadingCost] = useState(false)
  const [submitting, setSubmitting] = useState(false)
  const [error, setError] = useState('')
  const [fee, setFee] = useState<FeePreview | null>(null)
  const [voucherCode, setVoucherCode] = useState('')
  const [vouchers, setVouchers] = useState<PublicVoucher[]>([])

  const totalWeight = items.reduce((a, i) => a + i.weight * i.qty, 0)
  const sub = subtotal()

  // Rincian biaya live dari server (fee 11% + admin + diskon + voucher) setiap isi berubah.
  useEffect(() => {
    let stale = false
    if (items.length === 0) return
    api
      .post<{ data: FeePreview }>('/checkout/preview', {
        merchant_slug: shopSlug,
        items: items.map((i) => ({ product_id: i.productId, variant_id: i.variantId, qty: i.qty })),
        shipping_cost: selected?.cost ?? 0,
        voucher_code: voucherCode || undefined,
      })
      .then((r) => {
        if (!stale) setFee(r.data)
      })
      .catch(() => {
        if (!stale) setFee(null)
      })
    return () => {
      stale = true
    }
  }, [items, shopSlug, selected?.cost, voucherCode])

  // Daftar voucher yang sedang tayang di toko ini.
  useEffect(() => {
    api
      .get<{ data: PublicVoucher[] }>(`/vouchers/public?merchant_slug=${encodeURIComponent(shopSlug)}`)
      .then((r) => setVouchers(r.data))
      .catch(() => setVouchers([]))
  }, [shopSlug])

  async function cekOngkir() {
    setLoadingCost(true)
    setOngkirErr('')
    setCosts(null)
    setSelected(null)
    try {
      const res = await api.post<CostResult>('/shipping/cost', {
        // Origin = alamat toko (registrasi seller), tujuan = kode pos pembeli.
        merchant_slug: shopSlug,
        destination_postal_code: form.destination_postal_code,
        weight: Math.max(totalWeight, 1),
        value: sub,
      })
      if (res.ok && res.data) setCosts(res.data)
      else setOngkirErr(res.error ?? 'Gagal cek ongkir.')
    } catch (e) {
      setOngkirErr(e instanceof Error ? e.message : 'Gagal cek ongkir.')
    } finally {
      setLoadingCost(false)
    }
  }

  async function checkout(e: React.FormEvent) {
    e.preventDefault()
    setError('')

    if (!selected) {
      setError('Pilih layanan pengiriman dulu.')
      return
    }

    setSubmitting(true)
    try {
      const res = await api.post<{
        data: { order_no: string; total: number; payment_url: string | null; payment_note: string | null }
      }>('/checkout', {
        merchant_slug: shopSlug,
        ...form,
        service: selected.service,
        shipping_cost: selected.cost,
        voucher_code: voucherCode || undefined,
        items: items.map((i) => ({ product_id: i.productId, variant_id: i.variantId, qty: i.qty })),
      })

      clear()

      if (res.data.payment_url) {
        window.location.href = res.data.payment_url
      } else {
        navigate(`/${shopSlug}/lacak?order=${res.data.order_no}`)
      }
    } catch (err) {
      setError(err instanceof Error ? err.message : 'Checkout gagal')
    } finally {
      setSubmitting(false)
    }
  }

  const input = 'mt-1 w-full rounded-lg border border-slate-300 px-3 py-2 text-sm'

  if (items.length === 0) {
    return (
      <div className="min-h-screen bg-slate-50">
        <Header shopSlug={shopSlug} shopName="Keranjang" />
        <div className="max-w-2xl mx-auto px-4 py-16 text-center">
          <p className="text-slate-500">Keranjang kosong.</p>
          <Link to={`/${shopSlug}`} className="mt-4 inline-block underline">
            Kembali belanja
          </Link>
        </div>
      </div>
    )
  }

  return (
    <div className="min-h-screen bg-slate-50">
      <Header shopSlug={shopSlug} shopName="Checkout" />

      <form onSubmit={checkout} className="max-w-6xl mx-auto px-4 py-8 grid lg:grid-cols-3 gap-6">
        <div className="lg:col-span-2 space-y-4">
          <section className="bg-white rounded-xl border border-slate-200 p-5">
            <h2 className="font-semibold mb-3">Keranjang</h2>
            <ul className="divide-y divide-slate-100">
              {items.map((i) => (
                <li key={`${i.productId}:${i.variantId ?? 0}`} className="py-3 flex items-center gap-3">
                  <div className="w-14 h-14 rounded-lg bg-slate-100 overflow-hidden shrink-0">
                    {i.image && <img src={i.image} alt="" className="w-full h-full object-cover" />}
                  </div>
                  <div className="flex-1 min-w-0">
                    <p className="text-sm font-medium truncate">{i.title}</p>
                    <p className="text-xs text-slate-500">
                      {rupiah(i.price)}
                      {i.variantName && <span className="text-slate-400"> · {i.variantName}</span>}
                    </p>
                  </div>
                  <div className="flex items-center rounded-lg border border-slate-300 text-sm">
                    <button type="button" onClick={() => setQty(i.productId, i.qty - 1, i.variantId)} className="px-2 py-1">
                      −
                    </button>
                    <span className="px-2 tabular-nums">{i.qty}</span>
                    <button type="button" onClick={() => setQty(i.productId, i.qty + 1, i.variantId)} className="px-2 py-1">
                      +
                    </button>
                  </div>
                  <button type="button" onClick={() => remove(i.productId, i.variantId)} className="text-xs text-rose-600 px-2">
                    hapus
                  </button>
                </li>
              ))}
            </ul>
          </section>

          <section className="bg-white rounded-xl border border-slate-200 p-5">
            <h2 className="font-semibold mb-3">Alamat Pengiriman</h2>
            <div className="grid md:grid-cols-2 gap-4">
              <label className="block">
                <span className="text-sm font-medium text-slate-700">Nama penerima</span>
                <input
                  className={input}
                  required
                  value={form.buyer_name}
                  onChange={(e) => setForm({ ...form, buyer_name: e.target.value })}
                />
              </label>
              <label className="block">
                <span className="text-sm font-medium text-slate-700">No. HP</span>
                <input
                  className={input}
                  required
                  value={form.buyer_phone}
                  onChange={(e) => setForm({ ...form, buyer_phone: e.target.value })}
                />
              </label>
              <label className="block md:col-span-2">
                <span className="text-sm font-medium text-slate-700">Email (opsional)</span>
                <input
                  type="email"
                  className={input}
                  value={form.buyer_email}
                  onChange={(e) => setForm({ ...form, buyer_email: e.target.value })}
                />
              </label>
              <label className="block md:col-span-2">
                <span className="text-sm font-medium text-slate-700">Alamat lengkap</span>
                <textarea
                  className={input}
                  rows={3}
                  required
                  value={form.shipping_address}
                  onChange={(e) => setForm({ ...form, shipping_address: e.target.value })}
                />
              </label>
              <label className="block">
                <span className="text-sm font-medium text-slate-700">Kode pos tujuan</span>
                <input
                  className={input}
                  required
                  inputMode="numeric"
                  maxLength={5}
                  placeholder="mis. 12950"
                  value={form.destination_postal_code}
                  onChange={(e) => setForm({ ...form, destination_postal_code: e.target.value })}
                />
              </label>
            </div>

            <button
              type="button"
              onClick={cekOngkir}
              disabled={loadingCost || !/^\d{5}$/.test(form.destination_postal_code)}
              className="mt-4 px-4 py-2 rounded-lg border border-slate-300 text-sm hover:bg-slate-50 disabled:opacity-40"
            >
              {loadingCost ? 'Mengecek…' : 'Cek Ongkir'}
            </button>

            {ongkirErr && (
              <p className="mt-3 text-sm text-amber-700 bg-amber-50 rounded-lg px-3 py-2">
                {ongkirErr} <span className="text-xs">(set API key Biteship di Admin → Pengaturan)</span>
              </p>
            )}

            {costs && (
              <div className="mt-3 space-y-2">
                {costs.services.map((s) => {
                  const key = `${s.courier}-${s.service}`
                  const isOn = selected && `${selected.courier}-${selected.service}` === key
                  return (
                    <label
                      key={key}
                      className={`flex items-center justify-between rounded-lg border px-3 py-2 cursor-pointer text-sm ${
                        isOn ? 'border-slate-900 bg-slate-50' : 'border-slate-200'
                      }`}
                    >
                      <span className="flex items-center gap-3">
                        <input
                          type="radio"
                          name="service"
                          checked={!!isOn}
                          onChange={() => setSelected(s)}
                        />
                        <span>
                          <span className="font-medium uppercase">{s.name || s.courier}</span>{' '}
                          <span className="text-slate-500">
                            · {s.description} {s.etd ? `· ${s.etd}` : ''}
                          </span>
                        </span>
                      </span>
                      <span className="font-medium">{rupiah(s.cost)}</span>
                    </label>
                  )
                })}
              </div>
            )}
          </section>
        </div>

        <aside className="lg:col-span-1">
          <div className="bg-white rounded-xl border border-slate-200 p-5 sticky top-24 space-y-3">
            <h2 className="font-semibold">Ringkasan</h2>
            <div className="flex justify-between text-sm">
              <span className="text-slate-500">Subtotal ({items.reduce((a, i) => a + i.qty, 0)} produk)</span>
              <span>{rupiah(fee?.subtotal ?? sub)}</span>
            </div>
            {fee && fee.discount_total > 0 && (
              <div className="flex justify-between text-sm">
                <span className="text-slate-500">Diskon</span>
                <span className="text-emerald-600">−{rupiah(fee.discount_total)}</span>
              </div>
            )}
            {fee?.voucher && fee.voucher.discount > 0 && (
              <div className="flex justify-between text-sm">
                <span className="text-slate-500">Voucher {fee.voucher.code}</span>
                <span className="text-emerald-600">−{rupiah(fee.voucher.discount)}</span>
              </div>
            )}
            {fee && (
              <div className="flex justify-between text-sm">
                <span className="text-slate-500">Fee layanan (11%)</span>
                <span>{rupiah(fee.buyer_fee)}</span>
              </div>
            )}
            {fee && (
              <div className="flex justify-between text-sm">
                <span className="text-slate-500">Biaya admin</span>
                <span>{fee.buyer_admin_fee > 0 ? rupiah(fee.buyer_admin_fee) : '—'}</span>
              </div>
            )}

            {/* Voucher */}
            <div className="pt-2 border-t border-slate-100 space-y-2">
              <div className="flex gap-2">
                <input
                  value={voucherCode}
                  onChange={(e) => setVoucherCode(e.target.value.toUpperCase())}
                  placeholder="Kode voucher"
                  className="flex-1 rounded-lg border border-slate-300 px-3 py-2 text-sm font-mono"
                />
                {voucherCode && (
                  <button
                    type="button"
                    onClick={() => setVoucherCode('')}
                    className="text-xs px-2 text-slate-400"
                  >
                    ✕
                  </button>
                )}
              </div>

              {fee?.voucher && (
                <p className="text-xs text-emerald-600">
                  ✓ {fee.voucher.name} — potongan {rupiah(fee.voucher.discount)}
                  {fee.voucher.free_shipping && ' + gratis ongkir'}
                </p>
              )}
              {fee?.voucher_disallowed && (
                <p className="text-xs text-amber-600">⚠ {fee.voucher_disallowed}</p>
              )}

              {vouchers.length > 0 && (
                <div className="space-y-1.5">
                  <p className="text-[11px] text-slate-400">Voucher tersedia:</p>
                  {vouchers.map((v) => (
                    <button
                      key={v.code}
                      type="button"
                      onClick={() => setVoucherCode(v.code)}
                      className={`w-full text-left rounded-lg border px-2.5 py-1.5 text-xs ${
                        voucherCode === v.code
                          ? 'border-slate-900 bg-slate-50'
                          : 'border-slate-200 hover:bg-slate-50'
                      }`}
                    >
                      <span className="font-mono font-semibold">{v.code}</span>
                      <span className="text-slate-500">
                        {' '}
                        · {v.name} ·{' '}
                        {v.type === 'percent' ? `${v.value}%` : rupiah(v.value)}
                        {v.free_shipping && ' + ongkir'}
                        {v.min_spend > 0 && ` (min ${rupiah(v.min_spend)})`}
                      </span>
                    </button>
                  ))}
                </div>
              )}
            </div>

            <div className="flex justify-between text-sm">
              <span className="text-slate-500">Ongkir ({totalWeight} g)</span>
              <span>{selected ? rupiah(selected.cost) : '—'}</span>
            </div>
            <div className="flex justify-between font-bold pt-3 border-t border-slate-100">
              <span>Total</span>
              <span>{rupiah(fee?.total ?? sub + (selected?.cost ?? 0))}</span>
            </div>
            <p className="text-[11px] text-slate-400">
              Fee 11% + biaya admin Rp1.000 per produk ditanggung pembeli, sesuai kebijakan toko.
            </p>

            {error && <p className="text-sm text-rose-600 bg-rose-50 rounded-lg px-3 py-2">{error}</p>}

            <button
              disabled={submitting}
              className="w-full rounded-lg bg-slate-900 text-white py-3 font-medium disabled:opacity-50"
            >
              {submitting ? 'Memproses…' : 'Bayar Sekarang'}
            </button>
            <p className="text-xs text-slate-400 text-center">
              Pembayaran lewat Midtrans/Xendit. Jika gateway belum aktif, pesanan tercatat sebagai belum dibayar.
            </p>
          </div>
        </aside>
      </form>
    </div>
  )
}
