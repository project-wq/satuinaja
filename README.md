# Satuinaja

Platform **multichannel seller** untuk penjual online Indonesia. Upload produk **sekali**, lalu:

- tayang otomatis di **storefront publik** milik sendiri (`/:slug-toko`) — lengkap dengan katalog, keranjang, checkout, dan lacak pesanan;
- dipublikasikan ke **Facebook Page, Instagram Business, TikTok, Shopee, dan Tokopedia**;
- dilengkapi **cek ongkir** (RajaOngkir), **lacak resi** (BinderByte), **AI caption** (gateway OpenAI-compatible), dan **payment gateway** (Midtrans);
- punya sistem **langganan seller** (Free/Pro/Bisnis) dengan batas produk, channel aktif, dan publish bulanan — dibayar via Midtrans — plus **panel admin** (statistik, ganti plan, ban merchant) dan **sinkron stok otomatis** ke channel;
- sistem **fee marketplace ala Shopee**: pembeli bayar `(harga − diskon) + fee 11% + biaya admin Rp1.000/unit + ongkir`, seller menerima `(harga − diskon) − Rp500/unit` ke **saldo seller** otomatis saat order lunas, lengkap dengan **penarikan saldo** (withdraw ke rekening, disetujui admin) dan **riwayat mutasi saldo**;
- **panel admin lengkap**: kelola merchant (plan, ban), setujui/tolak penarikan saldo, dan atur **konfigurasi fee** (persen fee pembeli, admin fee/unit, potongan seller/unit) — semua endpoint admin terpisah dari panel seller.

Repo: https://github.com/project-wq/satuinaja

---

## Daftar isi

- [Arsitektur](#arsitektur)
- [Yang harus di-install](#yang-harus-di-install)
- [Menjalankan di komputer sendiri](#menjalankan-di-komputer-sendiri)
- [Menjalankan di server (produksi)](#menjalankan-di-server-produksi)
- [Variabel `.env`](#variabel-env)
- [Mendapatkan kredensial tiap platform](#mendapatkan-kredensial-tiap-platform)
- [Perintah yang sering dipakai](#perintah-yang-sering-dipakai)
- [Testing](#testing)
- [Struktur folder](#struktur-folder)
- [Otomatisasi push ke GitHub](#otomatisasi-push-ke-github)
- [Troubleshooting](#troubleshooting)
- [Keamanan](#keamanan)
- [Roadmap](#roadmap)

---

## Arsitektur

```
satuinaja/
├── backend/     Laravel 13 — REST API /api/v1, queue, publish ke channel
├── frontend/    React 19 + Vite + TypeScript + Tailwind v4
├── scripts/     auto-push.sh (commit & push otomatis)
└── .github/     CI (test backend + build frontend)
```

Backend dan frontend **terpisah**. Saat dev, Vite mem-proxy `/api` ke backend sehingga tidak ada masalah CORS. Saat produksi, frontend (statis) dan backend (PHP) boleh berada di server/domain berbeda — atur `FRONTEND_URLS` dan `SANCTUM_STATEFUL_DOMAINS`.

Auth memakai **Laravel Sanctum cookie HttpOnly** (bukan token di localStorage), jadi cookie tidak bisa dibaca JavaScript.

---

## Yang harus di-install

### Wajib

| Perangkat | Versi | Kegunaan |
|---|---|---|
| **PHP** | 8.3 atau lebih baru (disarankan 8.4) | menjalankan backend Laravel |
| **Ekstensi PHP** | `mbstring`, `pdo_sqlite` (atau `pdo_mysql`), `openssl`, `curl`, `json`, `bcmath`, `fileinfo`, `gd`, `zip` | Laravel + enkripsi + upload gambar |
| **Composer** | 2.x | memasang paket PHP |
| **Node.js** | 20 atau lebih baru (disarankan 22) | membangun frontend |
| **npm** | 10.x (ikut Node) | memasang paket JS |
| **Git** | apa saja | mengambil & mengirim kode |

Opsional tapi disarankan: **SQLite 3** (default, tanpa server) atau **MySQL 8 / MariaDB**.

### Cek cepat

```bash
php -v              # harus >= 8.3
php -m              # cek mbstring, pdo_sqlite, openssl, curl, gd, zip ada
composer -V
node -v             # harus >= 20
npm -v
git --version
```

### Cara memasang per sistem operasi

**Ubuntu / Debian**

```bash
sudo apt update
sudo apt install -y php8.4-cli php8.4-mbstring php8.4-sqlite3 php8.4-curl \
  php8.4-xml php8.4-zip php8.4-bcmath php8.4-gd php8.4-intl unzip git
# Composer
curl -sS https://getcomposer.org/installer | php
sudo mv composer.phar /usr/local/bin/composer
# Node 22
curl -fsSL https://deb.nodesource.com/setup_22.x | sudo -E bash -
sudo apt install -y nodejs
```

**Fedora / RHEL**

```bash
sudo dnf install -y php-cli php-mbstring php-pdo php-curl php-xml php-zip \
  php-bcmath php-gd php-intl composer git nodejs npm
```

**macOS (Homebrew)**

```bash
brew install php@8.4 composer node@22 git
```

**Windows** — pasang [Laravel Herd](https://herd.laravel.com) (sudah termasuk PHP + Composer) lalu install Node.js dari nodejs.org. Jalankan perintah di **Git Bash** atau **WSL**.

**Tanpa root / server terbatas (cara yang dipakai di server ini)**

Jika tidak punya akses root, PHP bisa dipakai sebagai binary statis:

```bash
mkdir -p ~/bin && cd ~/bin
curl -LO https://dl.static-php.dev/static-php-cli/common/php-8.4.10-cli-linux-x86_64.tar.gz
tar xzf php-8.4.10-cli-linux-x86_64.tar.gz
export PATH=~/bin:$PATH          # tambahkan ke ~/.bashrc agar permanen
```

Composer menyusul:

```bash
cd ~/bin
curl -sS https://getcomposer.org/installer -o composer-setup.php
~/bin/php composer-setup.php --install-dir=~/bin --filename=composer
```

> Jika jaringan Anda memakai proxy dan Composer gagal, jalankan dengan proxy dimatikan:
> `env -u HTTPS_PROXY -u HTTP_PROXY -u https_proxy -u http_proxy composer install`

---

## Menjalankan di komputer sendiri

### 1. Ambil kode

```bash
git clone https://github.com/project-wq/satuinaja.git
cd satuinaja
```

### 2. Backend

```bash
cd backend

cp .env.example .env
php artisan key:generate

# Database: paling cepat pakai SQLite (tanpa server)
touch database/database.sqlite

composer install
php artisan migrate --seed
```

`--seed` membuat akun demo + toko contoh. Kalau tidak mau data contoh, pakai `php artisan migrate` saja.

Jalankan:

```bash
php artisan serve --host=127.0.0.1 --port=8123
```

Uji: <http://127.0.0.1:8123/api/v1/platforms> → harus keluar JSON daftar platform.

### 3. Frontend

Buka terminal **baru** (biarkan backend tetap jalan):

```bash
cd frontend
npm install
npm run dev
```

Buka <http://127.0.0.1:5173>.

- Halaman toko demo: <http://127.0.0.1:5173/toko-demo>
- Login seller: <http://127.0.0.1:5173/login> → `demo@satuinaja.test` / `password`

### 4. Queue worker (untuk publish ke channel)

Publish ke Facebook/Instagram/TikTok/Shopee/Tokopedia berjalan di latar belakang. Buka terminal **ketiga**:

```bash
cd backend
php artisan queue:work
```

Tanpa worker, job publish masuk antrean tapi tidak pernah dieksekusi.

---

## Menjalankan di server (produksi)

### Ringkas (VPS Linux)

```bash
# 1. Kode
git clone https://github.com/project-wq/satuinaja.git /var/www/satuinaja
cd /var/www/satuinaja/backend

# 2. Dependensi produksi
composer install --no-dev --optimize-autoloader

# 3. Konfigurasi
cp .env.example .env
php artisan key:generate
```

Ubah `.env` untuk produksi:

```env
APP_ENV=production
APP_DEBUG=false
APP_URL=https://api.domainanda.com
APP_PUBLIC_URL=https://api.domainanda.com

DB_CONNECTION=mysql
DB_HOST=127.0.0.1
DB_DATABASE=satuinaja
DB_USERNAME=satuinaja
DB_PASSWORD=rahasia

SESSION_DOMAIN=.domainanda.com
SANCTUM_STATEFUL_DOMAINS=domainanda.com,www.domainanda.com
FRONTEND_URLS=https://domainanda.com,https://www.domainanda.com

CACHE_STORE=database
SESSION_DRIVER=database
QUEUE_CONNECTION=database
```

```bash
# 4. Migrasi
php artisan migrate --force

# 5. Optimasi (wajib di produksi)
php artisan config:cache
php artisan route:cache
php artisan view:cache

# 6. Permission folder (kalau pakai php-fpm, user = www-data)
chown -R www-data:www-data storage bootstrap/cache
chmod -R 775 storage bootstrap/cache
```

### 6. Frontend

```bash
cd /var/www/satuinaja/frontend
npm ci
npm run build          # hasil di frontend/dist/
```

Sajikan `frontend/dist/` lewat Nginx / Vercel / Netlify. Arahkan `/api` ke backend.

### 7. Nginx (contoh)

```nginx
server {
    listen 80;
    server_name api.domainanda.com;
    root /var/www/satuinaja/backend/public;
    index index.php;

    location / { try_files $uri $uri/ /index.php?$query_string; }

    location ~ \.php$ {
        fastcgi_pass unix:/run/php/php8.4-fpm.sock;
        fastcgi_param SCRIPT_FILENAME $realpath_root$fastcgi_script_name;
        include fastcgi_params;
    }

    location ~ /\.(?!well-known).* { deny all; }
}
```

### 8. Queue worker permanen (Supervisor)

```ini
; /etc/supervisor/conf.d/satuinaja.conf
[program:satuinaja-queue]
command=php /var/www/satuinaja/backend/artisan queue:work --sleep=3 --tries=3 --max-time=3600
directory=/var/www/satuinaja/backend
autostart=true
autorestart=true
user=www-data
numprocs=2
```

```bash
sudo supervisorctl reread && sudo supervisorctl update
```

### 9. Scheduler (wajib — langganan & sinkron stok)

Scheduler menjalankan 2 perintah (lihat `routes/console.php`):

- `satu:billing-rotate` — tiap hari 00.05: reset hitungan publish bulanan + batalkan langganan kedaluwarsa (otomatis turun ke Free).
- `satu:sync-stock` — tiap 5 menit: deteksi perubahan stok/harga/deskripsi produk lalu dorong ke channel aktif.

Tanpa cron ini, batas bulanan tidak pernah reset dan sinkron stok tidak jalan.

```bash
crontab -e
# tambahkan:
* * * * * cd /var/www/satuinaja/backend && php artisan schedule:run >> /dev/null 2>&1
```

**Ringkas:** yang harus ada di server = PHP 8.3+, Composer, Node 20+ (hanya untuk build), web server (Nginx/Apache), database (MySQL/SQLite), Supervisor untuk queue. Frontend bisa dititipkan ke Vercel/Netlify gratis; backend harus server sendiri karena butuh PHP.

---

## Variabel `.env`

| Variabel | Wajib | Contoh | Keterangan |
|---|---|---|---|
| `APP_KEY` | ya | `base64:...` | dihasilkan `php artisan key:generate`. **Jangan ganti setelah ada data** — kredensial channel tak bisa didekripsi lagi. |
| `APP_URL` | ya | `https://api.domain.com` | URL backend |
| `APP_PUBLIC_URL` | ya | `https://api.domain.com` | URL publik gambar produk (dipakai Instagram) |
| `APP_ENV` / `APP_DEBUG` | ya | `production` / `false` | di produksi **jangan** `true` |
| `DB_CONNECTION` | ya | `sqlite` atau `mysql` | |
| `SESSION_DOMAIN` | produksi | `.domain.com` | titik di depan = berlaku untuk semua subdomain |
| `SANCTUM_STATEFUL_DOMAINS` | ya | `domain.com,www.domain.com` | domain frontend yang dianggap "first-party" |
| `FRONTEND_URLS` | ya | `https://domain.com` | daftar asal CORS (jangan `*`) |
| `AI_BASE_URL` | AI | `http://127.0.0.1:20128/v1` | endpoint OpenAI-compatible |
| `AI_API_KEY` | AI | `sk-...` | kunci gateway AI |
| `AI_MODEL` | AI | `Ai` | nama model |
| `RAJAONGKIR_API_KEY` | ongkir | | [rajaongkir.com](https://rajaongkir.com) |
| `BINDERBYTE_API_KEY` | resi | | [binderbyte.com](https://binderbyte.com) |
| `MIDTRANS_SERVER_KEY` / `MIDTRANS_CLIENT_KEY` | payment | | dashboard Midtrans |
| `MIDTRANS_SANDBOX` | payment | `true` | `true` sampai produksi |

Semua fitur pihak ketiga **opsional**: kalau kuncinya kosong, aplikasi tetap jalan, hanya fitur terkait yang memberi pesan jelas.

---

## Mendapatkan kredensial tiap platform

Semua diisi di halaman **Seller → Channel**. Kredensial disimpan terenkripsi AES-256 dan **tidak pernah** dikirim balik ke browser (API hanya mengembalikan nama field-nya).

### Facebook Page

1. Buka <https://developers.facebook.com> → **My Apps** → **Create App** → tipe **Business**.
2. Tambahkan produk **Facebook Login**.
3. Buka **Graph API Explorer**, pilih app Anda, minta izin:
   `pages_manage_posts`, `pages_read_engagement`, `pages_show_list`.
4. **Generate Access Token** → pilih Page yang mau dipakai.
5. Tukar jadi **long-lived token** (60 hari) — lihat [dokumentasi Meta](https://developers.facebook.com/docs/facebook-login/guides/access-tokens/#extending).
6. Isi di form channel: `page_id` = ID Page (angka), `page_token` = token tadi.

### Instagram Business

Syarat: akun IG harus **Business/Creator** dan **tersambung ke Facebook Page**.

1. Pakai app Meta yang sama seperti Facebook, tambahkan produk **Instagram Graph API**.
2. Minta izin `instagram_basic`, `instagram_content_publish`, `pages_show_list`.
3. Ambil **IG Business Account ID** (angka, diawali `17841...`) lewat Graph API Explorer:
   `GET /me/accounts` → pilih page → `GET /{page-id}?fields=instagram_business_account`
4. Isi: `ig_user_id` + `access_token` (token Page).
5. **Penting:** IG wajib gambar ber-URL **HTTPS publik**. Isi `image_url` kalau gambar produk Anda belum di domain publik.

### TikTok

1. Buka <https://developers.tiktok.com> → **Manage apps** → buat app.
2. Tambahkan produk **Content Posting API**, minta scope `video.publish` (direct post) atau `video.upload`.
3. Lewati **app review** (wajib sebelum bisa posting publik).
4. Hasilkan access token via OAuth (Authorization Code).
5. **TikTok wajib video.** Isi `video_url` = link video HTTPS publik. Kalau kosong, sistem memberi caption siap-tempel untuk posting manual — bukan error.

### Shopee

1. Daftar **Shopee Open Platform**: <https://open.shopee.com> → **Register as Partner**.
2. Setelah disetujui, Anda dapat **Partner ID** dan **Partner Key** (di halaman Console).
3. Buat toko uji di **Shopee Sandbox**, lalu lakukan OAuth untuk mendapatkan **access_token** dan **shop_id**.
4. Isi: `partner_id`, `partner_key`, `shop_id`, `access_token`, dan `sandbox` (`1` saat uji, kosongkan saat produksi).

Signature dihitung backend:

```
base_string = partner_id + api_path + timestamp + access_token + shop_id
sign        = HMAC-SHA256(base_string, partner_key)
```

### Tokopedia

Tokopedia **tidak menyediakan API tambah-produk untuk seller umum**. Akses otomatis hanya lewat **kemitraan resmi** (Tokopedia Partner / program mitra). Karena itu:

- kalau Anda punya endpoint mitra → isi `api_base`, `endpoint`, `client_id`, `client_secret`, `fs_id`; sistem akan mengirim produk ke sana;
- kalau belum → sistem **tidak** berpura-pura sukses; ia memberi **payload JSON siap-tempel** (nama, harga, stok, berat, SKU) untuk diunggah manual di dashboard Tokopedia.

### RajaOngkir (cek ongkir)

Daftar di <https://rajaongkir.com> → pilih paket (Starter/Basic/Pro) → salin **API Key** ke `RAJAONGKIR_API_KEY`.

### BinderByte (lacak resi)

Daftar di <https://binderbyte.com> → ambil API key → isi `BINDERBYTE_API_KEY`.

### Midtrans (payment)

1. Daftar di <https://midtrans.com> → **Sandbox** dulu.
2. **Settings → Access Keys** → salin **Server Key** dan **Client Key**.
3. **Settings → Configuration → Payment Notification URL**:
   `https://api.domainanda.com/api/v1/webhooks/midtrans`
4. Selama uji, `MIDTRANS_SANDBOX=true`. Produksi butuh verifikasi dokumen usaha (KYC).

---

## Perintah yang sering dipakai

```bash
# Backend
php artisan serve --host=127.0.0.1 --port=8123   # server dev
php artisan queue:work                          # proses antrean publish
php artisan migrate                             # jalankan migrasi
php artisan migrate:fresh --seed                # reset + data contoh (HAPUS data!)
php artisan test                                # jalankan test
php artisan route:list                          # daftar endpoint
php artisan optimize:clear                      # bersihkan cache
php artisan config:cache                        # cache konfigurasi (produksi)
php artisan tinker                              # shell interaktif

# Frontend
npm run dev                                     # server dev (hot reload)
npm run build                                   # build produksi ke dist/
npm run preview                                 # preview hasil build

# Auto push ke GitHub
./scripts/auto-push.sh                          # commit + push kalau ada perubahan
```

---

## Testing

```bash
cd backend && php artisan test
```

Mencakup 59 test (202 assertions): registrasi & login, pembatasan percobaan login, isolasi data antar-seller, CRUD produk, checkout (stok & total), lacak pesanan, verifikasi signature webhook, **batas plan (produk/channel/publish), alur langganan Midtrans (settlement webhook), hak akses admin panel, fee marketplace + saldo/withdraw, laporan penjualan + export CSV, notifikasi in-app, refund (clawback saldo, otorisasi admin), kesiapan payment gateway (KYC + toggle mode), sinkronisasi stok dua arah (pull Shopee), dan webhook order marketplace (HMAC + idempotent)**.

CI di GitHub Actions (`.github/workflows/ci.yml`) menjalankan test backend **dan** build frontend setiap kali ada push ke `main`/`develop`. Cek hasilnya di tab **Actions** repo.

---

## Struktur folder

```
backend/
├── app/
│   ├── Http/Controllers/Api/V1/   Auth, Product, Channel, Ai,
│   │                              Storefront, Shipping, Checkout, Webhook,
│   │                              Billing (langganan), Admin (panel admin)
│   ├── Jobs/PublishToChannel.php  job publish (dijalankan queue)
│   ├── Models/                    Merchant, Product, Channel, Order, ...
│   ├── Policies/ProductPolicy.php aturan akses produk
│   └── Services/
│       ├── PublisherService.php   dispatcher: pilih service per platform
│       ├── FacebookService.php    Graph API
│       ├── InstagramService.php   IG Graph API (container → publish)
│       ├── TikTokService.php      Content Posting API v2
│       ├── ShopeeService.php      Open Platform v2 (HMAC-SHA256)
│       ├── TokopediaService.php   endpoint mitra / payload siap-tempel
│       ├── RajaOngkirService.php  cek ongkir
│       ├── ResiService.php        lacak resi
│       ├── MidtransService.php    payment + Snap langganan
│       ├── StockSyncService.php   sinkron stok/harga ke channel
│       └── AiCaptionService.php   caption otomatis
├── database/migrations/           skema tabel
├── routes/api.php                 semua endpoint /api/v1
└── tests/Feature/                 test fitur

frontend/src/
├── pages/                         Shop, ProductDetail, Checkout, Track, Login, Register
├── pages/Seller/                  Dashboard, Products, Channels, Orders,
│                                  Billing (paket), Admin
├── components/                    SellerLayout, ShopHeader
├── services/api.ts                pembungkus fetch (cookie session)
└── store.ts                       state auth + keranjang
```

---

## Otomatisasi push ke GitHub

Supaya setiap penambahan/edit file langsung muncul di GitHub, ada `scripts/auto-push.sh`:

```bash
./scripts/auto-push.sh
# -> [auto-push] main: 3 file -> GitHub
```

Kalau tidak ada perubahan, script **diam** (tidak ada output) — aman dijalankan berkala. Script juga menolak commit kalau `.env` sampai ikut ter-stage.

Otomatis tiap 5 menit lewat cron (di server ini, job **Satuinaja autopush**) — tidak perlu dijalankan manual.

Untuk menjalankannya di server sendiri:

```bash
crontab -e
# tambahkan:
*/5 * * * * /path/ke/satuinaja/scripts/auto-push.sh /path/ke/satuinaja >> /tmp/autopush.log 2>&1
```

---

## Troubleshooting

**`php: command not found`** — PHP belum terpasang atau belum di `PATH`. Tambahkan `export PATH=/opt/data/bin:$PATH` ke `~/.bashrc` (kalau pakai binary statis).

**`composer install` gagal / koneksi diblokir** — proxy mengganggu. Jalankan `env -u HTTPS_PROXY -u HTTP_PROXY -u https_proxy -u http_proxy composer install`.

**Halaman putih / JSON kosong** — cek `backend/storage/logs/laravel.log`. Biasanya `APP_KEY` kosong (`php artisan key:generate`).

**`419 Page Expired` atau tidak bisa login** — domain frontend belum terdaftar. Pastikan `SANCTUM_STATEFUL_DOMAINS` memuat domain frontend **dengan port** (mis. `localhost:5173`), dan `FRONTEND_URLS` memuat origin lengkapnya.

**Login berhasil tapi langsung logout** — backend & frontend beda domain tapi `supports_credentials` tidak aktif, atau cookie di-set `SameSite=Lax` untuk situs beda-domain. Set `SESSION_DOMAIN=.domainanda.com` dan pastikan keduanya HTTPS.

**Publish tidak terjadi** — queue worker belum jalan. Jalankan `php artisan queue:work` (dev) atau pastikan Supervisor hidup (produksi). Cek halaman **Channel → Riwayat** untuk pesan errornya.

**Facebook: `Invalid OAuth access token (code 190)`** — token kedaluwarsa/salah. Long-lived token berlaku 60 hari; perbarui di form channel.

**Instagram: `Instagram wajib punya gambar publik (HTTPS)`** — isi `image_url` berupa tautan HTTPS yang bisa diakses tanpa login.

**TikTok: `TikTok wajib video`** — isi `video_url` atau salin caption yang disediakan sistem lalu posting manual.

**Shopee: `Kredensial Shopee kurang: ...`** — lengkapi `partner_id`, `partner_key`, `shop_id`, `access_token`.

**Tokopedia selalu gagal** — memang, tanpa kemitraan resmi. Pakai tombol **payload siap-tempel** di riwayat channel.

**Stok berkurang padahal pembayaran belum masuk** — stok dipotong saat checkout. Pesanan yang kedaluwarsa perlu dikembalikan stoknya secara berkala (`php artisan schedule:run` bila scheduler dikonfigurasi).

---

## Keamanan

| Aspek | Implementasi |
|---|---|
| Password | hash **Argon2id** (default Laravel) |
| Sesi | **Sanctum cookie HttpOnly** — tidak bisa dibaca JavaScript |
| Pembatasan login | 5 percobaan/menit per email+IP |
| Rate limit API | limiter `global` per user/IP, `api-public` untuk endpoint publik |
| Kredensial channel | **AES-256** (`encrypted:array`), tidak pernah dikirim ke browser |
| Isolasi data | global scope `merchant_id` + Policy; akses produk seller lain → 404 |
| Webhook | verifikasi **HMAC-SHA512** signature Midtrans |
| Audit | setiap aksi sensitif dicatat di `audit_logs` (token diredaksi) |
| Upload | validasi tipe & ukuran gambar |
| Header | CSP, HSTS, `X-Frame-Options` |

---

## Roadmap

- [x] **Fase 1** — auth, produk, AI caption, simpan channel, checkout, audit log
- [x] **Fase 2** — publish Facebook, cek ongkir, lacak resi
- [x] **Fase 3** — publisher Instagram, TikTok, Shopee, Tokopedia (+ verifikasi channel, riwayat publish, CI, dokumentasi)
- [x] **Fase 4** — langganan seller (billing plan), panel admin, webhook Midtrans settlement
- [x] **Fase 5** — fee marketplace (buyer/seller/admin), saldo seller + withdraw, publisher (Facebook/IG/TikTok/Shopee/Tokopedia), diskon produk
- [x] **Fase 6** — laporan penjualan (ringkasan/harian/produk terlaris + export CSV), notifikasi in-app, refund dengan clawback saldo
- [x] **Fase 7** — kesiapan payment produksi (KYC Midtrans + toggle sandbox/production), sinkronisasi stok dua arah (push + pull Shopee), webhook order marketplace (Shopee/Tokopedia, HMAC + idempotent)

---

## Lisensi

MIT — bebas dipakai dan dimodifikasi.

