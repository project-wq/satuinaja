import { create } from 'zustand'
import { persist } from 'zustand/middleware'
import { api, type User } from './services/api'

interface AuthState {
  user: User | null
  loading: boolean
  fetchMe: () => Promise<void>
  login: (email: string, password: string) => Promise<void>
  register: (payload: RegisterPayload) => Promise<void>
  logout: () => Promise<void>
}

export interface RegisterPayload {
  name: string
  email: string
  password: string
  password_confirmation: string
  store_name: string
}

export const useAuth = create<AuthState>()(
  persist(
    (set) => ({
      user: null,
      loading: true,

      fetchMe: async () => {
        try {
          const res = await api.get<{ data: User }>('/auth/me')
          set({ user: res.data, loading: false })
        } catch {
          set({ user: null, loading: false })
        }
      },

      login: async (email, password) => {
        const res = await api.post<{ data: User }>('/auth/login', { email, password })
        set({ user: res.data })
      },

      register: async (payload) => {
        await api.post('/auth/register', payload)
        // Login otomatis setelah daftar
        await useAuth.getState().login(payload.email, payload.password)
      },

      logout: async () => {
        try {
          await api.post('/auth/logout')
        } finally {
          set({ user: null })
        }
      },
    }),
    { name: 'satuinaja-auth', partialize: (s) => ({ user: s.user }) },
  ),
)

interface CartItem {
  productId: number
  slug: string
  title: string
  price: number
  weight: number
  image?: string
  qty: number
}

interface CartState {
  items: CartItem[]
  add: (item: CartItem) => void
  setQty: (productId: number, qty: number) => void
  remove: (productId: number) => void
  clear: () => void
  subtotal: () => number
}

export const useCart = create<CartState>()(
  persist(
    (set, get) => ({
      items: [],
      add: (item) =>
        set((s) => {
          const found = s.items.find((i) => i.productId === item.productId)
          if (found) {
            return {
              items: s.items.map((i) =>
                i.productId === item.productId ? { ...i, qty: i.qty + item.qty } : i,
              ),
            }
          }
          return { items: [...s.items, item] }
        }),
      setQty: (productId, qty) =>
        set((s) => ({
          items: qty <= 0
            ? s.items.filter((i) => i.productId !== productId)
            : s.items.map((i) => (i.productId === productId ? { ...i, qty } : i)),
        })),
      remove: (productId) => set((s) => ({ items: s.items.filter((i) => i.productId !== productId) })),
      clear: () => set({ items: [] }),
      subtotal: () => get().items.reduce((acc, i) => acc + i.price * i.qty, 0),
    }),
    { name: 'satuinaja-cart' },
  ),
)
