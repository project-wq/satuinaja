/**
 * API client: fetch wrapper dengan cookie session (credentials: include).
 * Semua endpoint /api/v1/*.
 */

const BASE = '/api/v1'

export class ApiError extends Error {
  status: number
  constructor(status: number, message: string) {
    super(message)
    this.status = status
  }
}

async function request<T>(
  path: string,
  options: RequestInit = {},
): Promise<T> {
  const headers: Record<string, string> = {
    Accept: 'application/json',
    ...((options.headers as Record<string, string>) ?? {}),
  }

  // FormData → biarkan browser set Content-Type (multipart boundary).
  const isForm = options.body instanceof FormData
  if (options.body && !isForm) {
    headers['Content-Type'] = 'application/json'
  }

  const res = await fetch(`${BASE}${path}`, {
    credentials: 'include',
    headers,
    ...options,
  })

  const text = await res.text()
  let data: any = null
  try {
    data = text ? JSON.parse(text) : null
  } catch {
    data = text
  }

  if (!res.ok) {
    const message =
      data?.message ??
      Object.values(data?.errors ?? {})
        .flat()
        .join(', ') ??
      `HTTP ${res.status}`
    throw new ApiError(res.status, typeof message === 'string' ? message : `HTTP ${res.status}`)
  }

  return data as T
}

export const api = {
  get: <T>(path: string) => request<T>(path),
  post: <T>(path: string, body?: unknown) =>
    request<T>(path, {
      method: 'POST',
      body: body instanceof FormData ? body : body != null ? JSON.stringify(body) : undefined,
    }),
  put: <T>(path: string, body?: unknown) =>
    request<T>(path, { method: 'PUT', body: JSON.stringify(body ?? {}) }),
  delete: <T>(path: string) => request<T>(path, { method: 'DELETE' }),
}

// ---- Tipe data ----
export interface Merchant {
  id: number
  name: string
  slug: string
  kyc_status?: 'pending' | 'approved' | 'rejected'
  postal_code?: string | null
}

export interface User {
  id: number
  name: string
  email: string
  role: string
  merchant: Merchant | null
}

export interface ProductVariant {
  id: number
  product_id: number
  name: string
  sku: string | null
  price: number | null
  stock: number
  position: number
}

export interface Product {
  id: number
  merchant_id: number
  title: string
  slug: string
  description: string | null
  price: number
  discount_price: number | null
  stock: number
  weight: number
  images: string[] | null
  status: 'draft' | 'active' | 'archived'
  variants?: ProductVariant[]
  created_at: string
}

export interface Channel {
  id: number
  platform: string
  label: string | null
  active: boolean
  credential_keys: string[]
  supported: boolean
  last_sync_at: string | null
  last_error: string | null
}

export interface PlatformField {
  key: string
  label: string
  secret: boolean
  placeholder: string
}

export interface PlatformSpec {
  key: string
  name: string
  supported: boolean
  note: string
  fields: PlatformField[]
}

export interface PublishRecord {
  id: number
  channel_id: number
  status: 'pending' | 'success' | 'failed'
  external_id: string | null
  external_url: string | null
  error: string | null
  meta: { caption?: string; payload?: Record<string, unknown> } | null
  published_at: string | null
  product: { id: number; title: string; slug: string } | null
}

export interface Order {
  id: number
  order_no: string
  buyer_name: string
  buyer_phone: string
  subtotal: number
  shipping_cost: number
  total: number
  tracking_no: string | null
  // Pengiriman (Biteship): data label resi.
  courier?: string | null
  service?: string | null
  shipping_address?: string | null
  destination_postal_code?: string | null
  biteship_order_id?: string | null
  routing_code?: string | null
  created_at?: string | null
  payment_status: string
  fulfillment_status: string
  voucher_code: string | null
  voucher_discount: number
  shipping_discount: number
  cancel_reason: string | null
  cancelled_by: string | null
  return_status: string | null
  return_reason: string | null
  seller_note: string | null
  items?: OrderItem[]
}

export interface Voucher {
  id: number
  scope: 'product' | 'shop' | 'platform'
  merchant_id: number | null
  product_id: number | null
  code: string
  name: string
  type: 'percent' | 'fixed'
  value: number
  min_spend: number
  max_discount: number | null
  quota: number | null
  used: number
  max_per_buyer: number
  free_shipping: boolean
  active: boolean
  start_at: string | null
  end_at: string | null
  product?: { id: number; title: string } | null
}

export interface OrderItem {
  id: number
  title: string
  price: number
  qty: number
  line_total: number
}

export interface Paginated<T> {
  data: T[]
  current_page: number
  last_page: number
  total: number
}
