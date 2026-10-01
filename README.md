# Satuinaja

Platform **multichannel seller**: seller upload produk sekali, otomatis tayang di storefront sendiri **dan** ter-publish ke Facebook, Instagram, TikTok, Shopee, Tokopedia — plus cek ongkir, lacak resi, dan payment gateway.

## Arsitektur

```
Seller (React) ──► Laravel API ──► MySQL/PostgreSQL
                      │
                      ├── AI caption        (9Router / OpenAI-compatible)
                      ├── Facebook Graph    (Page / IG Business)
                      ├── TikTok / Shopee / Tokopedia   (Open API)
                      ├── RajaOngkir        (cek ongkir)
                      ├── BinderByte        (lacak resi)
                      └── Midtrans Snap     (payment gateway)
```

- **Backend** — Laravel 13 API (`/api/v1`), Sanctum (cookie HttpOnly), Policy + global tenant scope, queue job untuk publish, webhook signature-verified.
- **Frontend** — React 19 + Vite + TypeScript + Tailwind v4, React Router, TanStack Query, Zustand.
- **Storefront publik** — `/{slug-toko}` ala e-commerce: katalog, detail produk, keranjang, checkout, lacak pesanan.
- **Dashboard seller** — `/seller`: produk, channel, pesanan, AI caption, publish.

## Struktur

```
backend/     Laravel API
  app/Http/Controllers/Api/V1/   AuthController, ProductController,
                                 ChannelController, CheckoutController,
                                 ShippingController, AiController,
                                 StorefrontController, WebhookController
  app/Services/                  FacebookService, RajaOngkirService,
                                 ResiService, MidtransService,
                                 AiCaptionService, PublisherService
  app/Jobs/PublishToChannel.php  async publish + retry/backoff
  app/Policies/ProductPolicy.php tenant isolation
  app/Support/Audit.php          audit log + redaksi kredensial

frontend/    React + Vite
  src/pages/Seller/              Dashboard, Products, Channels, Orders
  src/pages/                     Login, Register, Shop, ProductDetail,
                                 Checkout, Track
  src/services/api.ts            fetch wrapper (credentials: include)
  src/store.ts                   Zustand: auth + cart
```

## Keamanan

| Area | Implementasi |
|---|---|
| Password | hash bcrypt/argon (Laravel default) |
| Sesi | Sanctum SPA, cookie **HttpOnly** — token tidak pernah ke JS |
| Kredensial seller | cast `encrypted:array` (AES-256) — hanya `credential_keys` yang dikembalikan API |
| Tenant isolation | global scope `merchant_id` + `ProductPolicy` |
| Rate limit | global 500/mnt, publik 60/mnt, auth 300/mnt, login 5/mnt per email+IP |
| Upload | validasi mime + max 4 MB, disimpan di disk `public` |
| Webhook | validasi `signature_key` SHA-512 Midtrans (wajib) |
| Audit | tabel `audit_logs`, kredensial otomatis di-redact |

## Setup lokal

```bash
# Backend
cd backend
composer install
cp .env.example .env
php artisan key:generate
php artisan migrate --seed
php artisan serve --port=8123

# Frontend (terminal lain)
cd frontend
npm install
npm run dev            # http://localhost:5173, proxy /api → 8123
```

Akun demo: `demo@satuinaja.test` / `password` → toko `toko-demo`.

## Konfigurasi `.env` backend

```env
AI_BASE_URL=http://127.0.0.1:20128/v1   # gateway AI (OpenAI-compatible)
AI_API_KEY=
AI_MODEL=Ai

RAJAONGKIR_API_KEY=      # cek ongkir
BINDERBYTE_API_KEY=      # lacak resi
MIDTRANS_SERVER_KEY=     # payment gateway
MIDTRANS_CLIENT_KEY=
MIDTRANS_SANDBOX=true
```

Queue publish: `php artisan queue:work` (driver database, tanpa Redis).

## Roadmap

- [x] Fase 1 — auth, produk, AI caption, channel store, checkout, audit log
- [x] Fase 2 — Facebook publish (Graph API), cek ongkir, lacak resi
- [ ] Fase 3 — Instagram / TikTok / Shopee / Tokopedia publisher
- [ ] Fase 4 — payment gateway produksi, langganan seller, admin panel

## Lisensi

MIT
