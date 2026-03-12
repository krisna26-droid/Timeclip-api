# TimeClip API — Backend Documentation

> Laravel 11 REST API untuk aplikasi AI Video Clip Generator.
> AI otomatis mentranskripsi video, mendeteksi momen viral, dan merender short clips dengan subtitle karaoke.

---

## Daftar Isi

1. [Tech Stack](#1-tech-stack)
2. [Instalasi & Setup](#2-instalasi--setup)
3. [Konfigurasi .env](#3-konfigurasi-env)
4. [Menjalankan Server](#4-menjalankan-server)
5. [Autentikasi](#5-autentikasi)
6. [Sistem Kredit & Tier](#6-sistem-kredit--tier)
7. [API Endpoints](#7-api-endpoints)
   - [Auth](#71-auth)
   - [Dashboard](#72-dashboard)
   - [Video](#73-video)
   - [Transcription](#74-transcription)
   - [Clips](#75-clips)
   - [AI Agent](#76-ai-agent)
   - [Payment](#77-payment)
   - [Admin](#78-admin)
8. [WebSocket Events](#8-websocket-events)
9. [Alur Proses Video (Pipeline)](#9-alur-proses-video-pipeline)
10. [Database Schema](#10-database-schema)
11. [Services & Jobs](#11-services--jobs)
12. [Error Reference](#12-error-reference)
13. [Catatan Internal Dev](#13-catatan-internal-dev)

---

## 1. Tech Stack

| Komponen | Teknologi |
|---|---|
| Framework | Laravel 11 |
| Auth | Laravel Sanctum (Token-based) |
| OAuth | Laravel Socialite (GitHub) |
| Queue | Laravel Queue (Database Driver) |
| WebSocket | Laravel Reverb |
| Storage | Supabase Storage (S3-Compatible) |
| AI / Transkripsi | Google Gemini 2.5 Flash |
| Video Processing | FFmpeg |
| Video Download | yt-dlp |
| Payment | Midtrans Snap |
| Database | MySQL 8 |

---

## 2. Instalasi & Setup

### Prasyarat

Pastikan semua software berikut sudah terinstall sebelum mulai:

- PHP >= 8.2
- Composer >= 2.x
- MySQL >= 8.0
- Node.js >= 18.x (untuk Reverb asset)
- FFmpeg (wajib ada di PATH atau tentukan path di .env)
- yt-dlp executable

### Langkah Instalasi

```bash
# 1. Clone repository
git clone <repo-url> timeclip-api
cd timeclip-api

# 2. Install dependencies PHP
composer install

# 3. Copy environment file
cp .env.example .env

# 4. Generate application key
php artisan key:generate

# 5. Buat database MySQL
# Jalankan di MySQL client:
# CREATE DATABASE timeclip_db CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;

# 6. Jalankan migrasi
php artisan migrate

# 7. (Opsional) Seed data dummy
php artisan db:seed
```

### Buat User Admin Pertama

```bash
php artisan tinker

# Di dalam tinker:
\App\Models\User::create([
    'name'              => 'Admin',
    'email'             => 'admin@timeclip.com',
    'password'          => bcrypt('password123'),
    'role'              => 'admin',
    'tier'              => 'business',
    'remaining_credits' => 9999,
    'last_reset_date'   => now()->toDateString(),
]);
```

---

## 3. Konfigurasi .env

Salin `.env.example` ke `.env` lalu isi bagian berikut:

### App

```env
APP_NAME=TimeClip
APP_ENV=local
APP_URL=http://localhost:8000
FRONTEND_URL=http://localhost:5173
SANCTUM_STATEFUL_DOMAINS=localhost:5173,127.0.0.1:5173
```

### Database

```env
DB_CONNECTION=mysql
DB_HOST=127.0.0.1
DB_PORT=3306
DB_DATABASE=timeclip_db
DB_USERNAME=root
DB_PASSWORD=
```

### Queue & Session

```env
QUEUE_CONNECTION=database
SESSION_DRIVER=database
BROADCAST_CONNECTION=reverb
FILESYSTEM_DISK=supabase
```

### Supabase Storage

```env
SUPABASE_STORAGE_URL=https://<project-id>.storage.supabase.co/storage/v1/s3
SUPABASE_STORAGE_KEY=<anon-key>
SUPABASE_STORAGE_SECRET=<service-role-key>
SUPABASE_STORAGE_REGION=ap-northeast-1
SUPABASE_STORAGE_BUCKET=timeclip
```

> Buat bucket bernama `timeclip` di Supabase dengan visibility **Public**.

### Gemini AI

```env
GEMINI_API_KEY=your_gemini_api_key
GEMINI_MODEL=gemini-2.5-flash
```

### FFmpeg & yt-dlp

```env
# Windows:
FFMPEG_PATH=C:\ffmpeg\bin\ffmpeg.exe
FFPROBE_PATH=C:\ffmpeg\bin\ffprobe.exe
YTDLP_PATH=C:\tools\yt-dlp.exe

# Mac/Linux:
FFMPEG_PATH=/usr/local/bin/ffmpeg
FFPROBE_PATH=/usr/local/bin/ffprobe
YTDLP_PATH=/usr/local/bin/yt-dlp
```

### GitHub OAuth

```env
GITHUB_CLIENT_ID=your_client_id
GITHUB_CLIENT_SECRET=your_client_secret
GITHUB_REDIRECT_URI=http://localhost:8000/api/auth/github/callback
```

> Daftarkan OAuth App di: GitHub → Settings → Developer Settings → OAuth Apps → New OAuth App

### Midtrans

```env
MIDTRANS_MERCHANT_ID=your_merchant_id
MIDTRANS_CLIENT_KEY=Mid-client-xxxxx
MIDTRANS_SERVER_KEY=Mid-server-xxxxx
MIDTRANS_IS_PRODUCTION=false
```

> Gunakan sandbox key dari: https://dashboard.sandbox.midtrans.com

### Reverb WebSocket

```env
REVERB_APP_ID=your_app_id
REVERB_APP_KEY=your_app_key
REVERB_APP_SECRET=your_app_secret
REVERB_HOST=127.0.0.1
REVERB_PORT=8080
REVERB_SCHEME=http
```

---

## 4. Menjalankan Server

Butuh **3 terminal** yang berjalan bersamaan:

```bash
# Terminal 1 — Laravel development server
php artisan serve
# Berjalan di: http://localhost:8000

# Terminal 2 — Queue worker (WAJIB agar video bisa diproses)
php artisan queue:work --tries=3 --timeout=3600

# Terminal 3 — Reverb WebSocket server (WAJIB untuk realtime update)
php artisan reverb:start
# Berjalan di: ws://127.0.0.1:8080
```

> **Penting:** Jika `queue:work` tidak berjalan, semua video yang disubmit akan stuck di status `pending` selamanya.

---

## 5. Autentikasi

API menggunakan **Laravel Sanctum** dengan token-based authentication.

### Cara Kerja

1. Login/Register → backend return `access_token`
2. Setiap request ke protected endpoint wajib sertakan header:

```
Authorization: Bearer <access_token>
Content-Type: application/json
Accept: application/json
```

### Token Storage (Rekomendasi Frontend)

```javascript
// Simpan token setelah login
localStorage.setItem('timeclip_token', response.data.access_token);

// Baca token untuk setiap request
const token = localStorage.getItem('timeclip_token');

// Hapus token saat logout atau 401
localStorage.removeItem('timeclip_token');
```

### Broadcasting Auth

Endpoint auth WebSocket private channel:

```
POST /api/broadcasting/auth
```

Sudah dikonfigurasi dengan middleware `auth:sanctum`. Sertakan token Bearer seperti biasa.

---

## 6. Sistem Kredit & Tier

### Tabel Tier

| Tier | Kredit Maksimum | Reset | Catatan |
|---|---|---|---|
| `free` | 10 | Setiap hari otomatis | Default saat register |
| `starter` | 100 | Tidak reset otomatis | Upgrade via payment |
| `pro` | 300 | Tidak reset otomatis | Upgrade via payment |
| `business` | 9999 | Tidak reset | Tidak dipotong saat proses |

### Aturan Kredit

- 1 submit video = **potong 10 kredit**
- Tier `business` = kredit tidak pernah dipotong
- Tier `free` = kredit auto-reset ke 10 setiap hari (dicheck saat hit `/api/dashboard`)
- Kredit dipotong **setelah** job masuk queue, bukan setelah selesai
- Maksimal **2 video aktif** diproses bersamaan per user

### Harga Upgrade

| Tier | Harga |
|---|---|
| starter | Rp 100.000 |
| pro | Rp 250.000 |
| business | Rp 500.000 |

---

## 7. API Endpoints

**Base URL:** `http://localhost:8000/api`

**Legend Auth kolom:**
- `—` = Public, tidak butuh token
- `🔒` = Butuh token Bearer
- `👑` = Butuh token + role admin

---

### 7.1 Auth

#### POST `/register`

Registrasi akun baru. Langsung return token tanpa perlu login ulang.

**Request Body:**

```json
{
    "name": "Budi Santoso",
    "email": "budi@example.com",
    "password": "password123",
    "password_confirmation": "password123"
}
```

**Response 201:**

```json
{
    "status": "success",
    "message": "Registrasi berhasil!",
    "access_token": "1|abc123xyz...",
    "token_type": "Bearer",
    "user": {
        "id": 1,
        "name": "Budi Santoso",
        "email": "budi@example.com",
        "role": "creator",
        "tier": "free",
        "remaining_credits": 10,
        "last_reset_date": "2026-03-11",
        "provider_name": null,
        "created_at": "2026-03-11T10:00:00.000000Z"
    }
}
```

**Error 422 — Validasi Gagal:**

```json
{
    "message": "The email has already been taken.",
    "errors": {
        "email": ["The email has already been taken."]
    }
}
```

---

#### POST `/login`

Login dengan email dan password.

**Request Body:**

```json
{
    "email": "budi@example.com",
    "password": "password123"
}
```

**Response 200:**

```json
{
    "status": "success",
    "message": "Login berhasil!",
    "access_token": "2|def456uvw...",
    "token_type": "Bearer",
    "user": {
        "id": 1,
        "name": "Budi Santoso",
        "email": "budi@example.com",
        "role": "creator",
        "tier": "free",
        "remaining_credits": 10,
        "last_reset_date": "2026-03-11"
    }
}
```

**Error 401:**

```json
{
    "status": "error",
    "message": "Email atau password salah!"
}
```

---

#### POST `/logout` 🔒

Menghapus token yang sedang aktif. Token langsung invalid setelah ini.

**Response 200:**

```json
{
    "status": "success",
    "message": "Logout berhasil."
}
```

---

#### GET `/auth/github/redirect`

Redirect browser ke halaman login GitHub. **Buka langsung di browser, bukan fetch/axios.**

**Behaviour:** Return HTTP 302 Redirect ke GitHub OAuth URL.

---

#### GET `/auth/github/callback`

Dipanggil otomatis oleh GitHub setelah user authorize. Jika user belum terdaftar, otomatis dibuat akun baru.

**Response 200:**

```json
{
    "status": "success",
    "message": "Login via GitHub berhasil!",
    "access_token": "3|ghi789rst...",
    "token_type": "Bearer",
    "user": {
        "id": 2,
        "name": "budisantoso",
        "email": "budi@github.com",
        "role": "creator",
        "tier": "free",
        "remaining_credits": 10,
        "provider_name": "github",
        "provider_id": "12345678"
    }
}
```

**Error 500:**

```json
{
    "status": "error",
    "message": "Gagal login via GitHub: ..."
}
```

> **Catatan Internal:** User GitHub bisa punya `password = null`. Jangan paksa user GitHub untuk set password. Cek `provider_name` di frontend jika perlu.

---

### 7.2 Dashboard

#### GET `/dashboard` 🔒

Mengambil semua data yang dibutuhkan untuk halaman dashboard dalam satu request. Juga trigger auto-reset kredit untuk user tier `free`.

**Response 200:**

```json
{
    "status": "success",
    "data": {
        "profile": {
            "name": "Budi Santoso",
            "email": "budi@example.com",
            "tier": "FREE",
            "credits": {
                "remaining": 10,
                "max_capacity": 10,
                "is_low": false
            },
            "last_reset": "2026-03-11"
        },
        "stats": {
            "total_videos_processed": 5,
            "total_clips_generated": 18,
            "active_tasks": 1
        },
        "active_tasks": [
            {
                "id": 12,
                "title": "Tutorial React 2026",
                "status": "processing",
                "progress_percentage": 40,
                "step": "Transcribing Audio...",
                "created_at": "5 minutes ago"
            }
        ],
        "clip_gallery": [
            {
                "id": 7,
                "title": "Momen Lucu di Menit ke-5",
                "viral_score": 87,
                "video_url": "https://sjladpjq...supabase.co/storage/v1/object/public/timeclip/clips/7.mp4",
                "thumbnail_url": "https://sjladpjq...supabase.co/storage/v1/object/public/timeclip/thumbnails/7.jpg",
                "source_video": "Tutorial React 2026",
                "duration_seconds": 45.5
            }
        ]
    }
}
```

> **Catatan Internal:** `progress_percentage` adalah estimasi kasar (10/40/70), bukan progress real. Untuk progress real gunakan WebSocket event `VideoStatusUpdated`.

---

#### GET `/user/credits` 🔒

```json
{
    "remaining_credits": 10,
    "tier": "free",
    "max_cap": 10,
    "last_reset": "2026-03-11"
}
```

> **Edge Case:** Untuk tier `business`, `max_cap` akan bernilai string `"unlimited"` bukan integer. Frontend harus handle ini.

---

### 7.3 Video

#### GET `/videos` 🔒

Mengambil semua video milik user yang sedang login, diurutkan terbaru.

**Response 200:**

```json
{
    "status": "success",
    "data": [
        {
            "id": 1,
            "user_id": 1,
            "title": "Tutorial React 2026",
            "source_url": "https://youtube.com/watch?v=xxx",
            "file_path": "private/raw_videos/1.mp4",
            "duration": 1200,
            "status": "completed",
            "created_at": "2026-03-11T10:00:00.000000Z",
            "updated_at": "2026-03-11T10:15:00.000000Z"
        }
    ]
}
```

**Status Video:**

| Status | Keterangan |
|---|---|
| `pending` | Masuk queue, belum mulai diproses |
| `processing` | Sedang download / transkripsi / analisis |
| `completed` | Selesai, klip sudah dibuat |
| `failed` | Gagal di salah satu tahap |

---

#### POST `/videos/process` 🔒

Submit video baru untuk diproses. Memotong 10 kredit dari user.

**Request Body:**

```json
{
    "title": "Tutorial React 2026",
    "url": "https://youtube.com/watch?v=xxx",
    "duration": 1200
}
```

| Field | Tipe | Aturan |
|---|---|---|
| `title` | string | Required, max 255 |
| `url` | string | Required, valid URL |
| `duration` | integer | Required, max 1800 (30 menit) |

**Response 201:**

```json
{
    "status": "success",
    "message": "Video masuk antrean.",
    "data": {
        "id": 5,
        "user_id": 1,
        "title": "Tutorial React 2026",
        "source_url": "https://youtube.com/watch?v=xxx",
        "duration": 1200,
        "status": "pending",
        "created_at": "2026-03-11T10:00:00.000000Z"
    }
}
```

**Error 403 — Kredit Kurang:**

```json
{
    "status": "error",
    "message": "Kredit tidak cukup (butuh 10)."
}
```

**Error 429 — Terlalu Banyak Proses Aktif:**

```json
{
    "status": "error",
    "message": "Maksimal 2 proses aktif."
}
```

> **Catatan Internal:** Kredit dipotong saat job masuk queue (`DownloadVideoJob::dispatch()`), bukan saat selesai. Jika job gagal, kredit tidak dikembalikan otomatis. Perlu fitur refund manual via admin jika diperlukan.

---

#### GET `/videos/{id}` 🔒

Detail satu video. Hanya bisa diakses oleh pemilik video.

**Response 200:**

```json
{
    "status": "success",
    "data": {
        "id": 1,
        "title": "Tutorial React 2026",
        "source_url": "https://youtube.com/watch?v=xxx",
        "duration": 1200,
        "status": "completed"
    }
}
```

**Error 404:**

```json
{
    "status": "error",
    "message": "Video tidak ditemukan."
}
```

---

### 7.4 Transcription

#### GET `/videos/{video_id}/transcription` 🔒

Mengambil data transkripsi lengkap beserta word-level timestamps.

**Response 200:**

```json
{
    "status": "success",
    "data": {
        "video_id": 1,
        "full_text": "Halo semua selamat datang di tutorial ini...",
        "words": [
            { "word": "Halo", "start": 0.5, "end": 0.9 },
            { "word": "semua", "start": 1.0, "end": 1.4 },
            { "word": "selamat", "start": 1.5, "end": 2.0 }
        ]
    }
}
```

> **Catatan Internal:** `start` dan `end` di sini adalah timestamp **absolut dari awal video** (dalam detik). Ini berbeda dengan subtitle klip yang sudah dinormalisasi mulai dari 0.

**Error 404:**

```json
{
    "status": "error",
    "message": "Transkripsi belum tersedia."
}
```

---

#### PUT `/videos/{video_id}/transcription` 🔒

Update/edit teks transkripsi. Biasanya dipanggil dari editor transkripsi di frontend.

**Request Body:**

```json
{
    "full_text": "Halo semua selamat datang di tutorial ini...",
    "words": [
        { "word": "Halo", "start": 0.5, "end": 0.9 },
        { "word": "semua", "start": 1.0, "end": 1.4 }
    ]
}
```

**Response 200:**

```json
{
    "status": "success",
    "message": "Transkripsi berhasil diperbarui. Siap untuk render ulang.",
    "data": { "...transcription object..." }
}
```

> **Catatan Internal:** Update transkripsi TIDAK otomatis trigger re-render klip. User harus secara eksplisit tekan tombol "Re-render" yang memanggil endpoint `rerender` di bawah.

---

#### POST `/videos/{video_id}/transcription/rerender` 🔒

Re-render semua klip milik video ini menggunakan transkripsi yang sudah diedit. Hanya klip berstatus `ready` yang akan di-queue ulang.

**Response 200:**

```json
{
    "status": "success",
    "message": "3 klip sedang di-render ulang dengan caption baru.",
    "data": {
        "clips_queued": 3
    }
}
```

**Error 404 — Tidak ada klip yang bisa di-render:**

```json
{
    "status": "error",
    "message": "Tidak ada klip yang bisa di-render ulang."
}
```

---

### 7.5 Clips

#### GET `/clips/gallery` 🔒

Gallery semua klip berstatus `ready` milik user, diurutkan berdasarkan `viral_score` tertinggi. **Paginated 12 per halaman.**

**Query Params:**

| Param | Tipe | Default | Keterangan |
|---|---|---|---|
| `page` | integer | 1 | Nomor halaman |

**Response 200:**

```json
{
    "status": "success",
    "data": [
        {
            "id": 7,
            "title": "Momen Lucu di Menit ke-5",
            "viral_score": 87.0,
            "duration": 45.5,
            "video_title": "Tutorial React 2026",
            "clip_url": "https://...supabase.co/.../clips/7.mp4",
            "thumbnail_url": "https://...supabase.co/.../thumbnails/7.jpg",
            "created_at": "5 days ago"
        }
    ],
    "meta": {
        "current_page": 1,
        "last_page": 3,
        "total": 34
    }
}
```

> **Edge Case:** Jika user belum punya klip sama sekali, response tetap `200` dengan `data: []`, bukan `404`.

---

#### GET `/videos/{video_id}/clips` 🔒

Semua klip dari satu video (semua status, termasuk `rendering` dan `failed`).

**Response 200:**

```json
{
    "status": "success",
    "video_title": "Tutorial React 2026",
    "data": [
        {
            "id": 7,
            "title": "Momen Lucu",
            "viral_score": 87.0,
            "status": "ready",
            "start_time": 305.0,
            "end_time": 350.5,
            "clip_url": "https://...supabase.co/...",
            "thumbnail_url": "https://...supabase.co/...",
            "subtitle": {
                "full_text": "ini adalah subtitle lengkap klip",
                "words": [
                    { "word": "ini", "start": 0.0, "end": 0.3 }
                ]
            }
        }
    ]
}
```

---

#### GET `/clips/{id}` 🔒

Detail satu klip. Hanya bisa diakses pemilik.

**Response 200:** Sama dengan struktur clip object di atas, ditambah:

```json
{
    "status": "success",
    "data": {
        "...clip fields...",
        "parent_video": "Tutorial React 2026"
    }
}
```

---

#### PUT `/clips/{id}` 🔒

Edit judul klip.

**Request Body:**

```json
{
    "title": "Judul Baru Yang Lebih Keren"
}
```

**Response 200:**

```json
{
    "status": "success",
    "message": "Judul klip berhasil diperbarui.",
    "data": {
        "id": 7,
        "title": "Judul Baru Yang Lebih Keren"
    }
}
```

---

#### GET `/clips/{id}/stream` 🔒

Redirect ke URL Supabase untuk streaming video. Return **HTTP 302 Redirect**.

> **Catatan Internal:** Frontend bisa langsung pakai URL endpoint ini sebagai `src` di tag `<video>`, atau ambil `clip_url` dari response GET clip dan gunakan langsung.

---

#### GET `/clips/{id}/download` 🔒

Redirect ke URL Supabase untuk download. Return **HTTP 302 Redirect**.

---

#### POST `/clips/{id}/rerender` 🔒

Trigger re-render satu klip. Status klip akan berubah ke `rendering`.

**Response 200:**

```json
{
    "status": "success",
    "message": "Klip sedang di-render ulang."
}
```

---

#### GET `/clips/{id}/subtitle` 🔒

Ambil subtitle klip. Jika user belum pernah edit subtitle, backend otomatis generate dari transkripsi video induk dengan timestamp yang sudah **dinormalisasi mulai dari 0**.

**Response 200:**

```json
{
    "status": "success",
    "data": {
        "full_text": "ini subtitle dari klip",
        "words": [
            { "word": "ini", "start": 0.0, "end": 0.3 },
            { "word": "subtitle", "start": 0.4, "end": 0.9 },
            { "word": "dari", "start": 1.0, "end": 1.2 },
            { "word": "klip", "start": 1.3, "end": 1.6 }
        ]
    }
}
```

> **Catatan Internal:** Timestamp di sini dimulai dari `0.0` (relatif dari awal klip), berbeda dengan transkripsi video yang absolutnya dari awal video. Ini penting untuk editor subtitle frontend.

---

#### PUT `/clips/{id}/subtitle` 🔒

Update subtitle + otomatis trigger re-render klip.

**Request Body:**

```json
{
    "full_text": "teks subtitle yang sudah diedit",
    "words": [
        { "word": "teks", "start": 0.0, "end": 0.4 },
        { "word": "subtitle", "start": 0.5, "end": 1.0 }
    ]
}
```

**Response 200:**

```json
{
    "status": "success",
    "message": "Subtitle diperbarui."
}
```

> **Catatan Internal:** Berbeda dengan `PUT /transcription`, endpoint ini langsung trigger `ProcessVideoClipJob` untuk re-render. Status klip otomatis berubah ke `rendering`.

---

### 7.6 AI Agent

#### POST `/videos/{video_id}/ask-ai` 🔒

Minta AI mencari momen spesifik dari video berdasarkan query teks. AI akan membaca transkripsi lalu mengidentifikasi segmen yang relevan dan langsung membuat klip baru.

**Request Body:**

```json
{
    "query": "momen lucu atau bagian yang bikin penonton ketawa"
}
```

**Response 200:**

```json
{
    "status": "success",
    "message": "3 momen baru ditemukan.",
    "data": [
        {
            "id": 12,
            "video_id": 1,
            "title": "Momen Ketawa Pertama",
            "start_time": 120.0,
            "end_time": 165.0,
            "viral_score": 78,
            "status": "rendering"
        }
    ]
}
```

**Error 404:**

```json
{
    "status": "error",
    "message": "Video atau transkripsi tidak ditemukan."
}
```

**Error 422 — AI Tidak Menemukan:**

```json
{
    "status": "error",
    "message": "AI tidak menemukan momen yang sesuai."
}
```

> **Catatan Internal:** Fitur ini hanya bisa digunakan jika video sudah `completed` dan punya transkripsi. Jangan tampilkan tombol Ask AI sebelum transkripsi tersedia. Setiap panggilan ke endpoint ini akan memanggil Gemini API dan dikenai kuota.

---

### 7.7 Payment

#### POST `/payment/subscribe` 🔒

Membuat transaksi baru dan mendapatkan Snap Token dari Midtrans untuk membuka popup pembayaran.

**Request Body:**

```json
{
    "tier_plan": "pro"
}
```

Nilai valid: `starter`, `pro`, `business`

**Response 200:**

```json
{
    "status": "success",
    "snap_token": "snap-token-xxxx-yyyy-zzzz",
    "order_id": "TC-ABCDE12345"
}
```

**Cara Pakai Snap Token di Frontend:**

```html
<!-- Load script Midtrans Snap (sandbox) -->
<script
  src="https://app.sandbox.midtrans.com/snap/snap.js"
  data-client-key="Mid-client-aEqU69on5xcoikLJ">
</script>
```

```javascript
// Panggil popup setelah dapat snap_token
window.snap.pay(snapToken, {
    onSuccess: (result) => {
        // Refresh data user untuk ambil tier & kredit terbaru
        // GET /user/credits atau GET /dashboard
    },
    onPending: (result) => {
        // Tampilkan pesan "Menunggu pembayaran"
    },
    onError: (result) => {
        // Tampilkan pesan error
    },
    onClose: () => {
        // User tutup popup tanpa bayar
    }
});
```

---

#### POST `/payment/callback`

**Khusus untuk Midtrans server. Jangan dipanggil dari frontend.**

Midtrans akan memanggil endpoint ini setelah pembayaran. Backend akan otomatis:
1. Verifikasi signature dari Midtrans
2. Update status transaksi
3. Update tier dan kredit user

> **Catatan Internal:** Pastikan URL ini bisa diakses dari internet saat production (bukan localhost). Gunakan ngrok atau tunneling saat development jika ingin test webhook Midtrans.

---

### 7.8 Admin

> Semua endpoint di bawah membutuhkan token **dan** user dengan `role = "admin"`. Response 403 jika bukan admin.

#### GET `/admin/stats` 👑

**Response 200:**

```json
{
    "status": "success",
    "data": {
        "summary": {
            "total_users": 150,
            "total_videos": 423,
            "total_clips": 1872,
            "total_system_errors": 12
        },
        "gemini_chart": [
            { "date": "2026-03-05", "total": 23 },
            { "date": "2026-03-06", "total": 31 }
        ],
        "ffmpeg_health": {
            "success": 410,
            "failed": 13
        },
        "queue_status": {
            "pending": 2,
            "processing": 1
        }
    }
}
```

---

#### GET `/admin/logs` 👑

20 log sistem terbaru, diurutkan terbaru.

**Response 200:**

```json
{
    "status": "success",
    "data": [
        {
            "id": 999,
            "service": "GEMINI",
            "level": "INFO",
            "category": "USAGE",
            "message": "Berhasil melakukan transkripsi audio.",
            "payload": { "model": "gemini-2.5-flash", "usage": { "totalTokenCount": 12500 } },
            "user_id": 5,
            "user": { "id": 5, "name": "Budi Santoso" },
            "created_at": "2026-03-11T10:30:00.000000Z"
        }
    ]
}
```

**Nilai Service:**

| Service | Keterangan |
|---|---|
| `GEMINI` | Panggilan ke Gemini AI |
| `FFMPEG` | Proses render video |
| `YT-DLP` | Download video |
| `SYSTEM` | Event sistem internal |

**Nilai Level:**

| Level | Keterangan |
|---|---|
| `INFO` | Proses berhasil |
| `WARNING` | Peringatan, tapi tidak fatal |
| `ERROR` | Kegagalan yang perlu diperhatikan |

---

#### GET `/admin/users` 👑

List semua user dengan pagination dan search.

**Query Params:**

| Param | Keterangan |
|---|---|
| `search` | Filter by name atau email |
| `page` | Nomor halaman (10 user per halaman) |

**Contoh:** `GET /admin/users?search=budi&page=1`

---

#### PUT `/admin/users/{id}` 👑

Update data user.

**Request Body (semua field opsional):**

```json
{
    "name": "Nama Baru",
    "role": "admin",
    "tier": "pro",
    "remaining_credits": 150
}
```

**Response 200:**

```json
{
    "status": "success",
    "message": "Data user berhasil diperbarui.",
    "user": { "...updated user object..." }
}
```

---

#### DELETE `/admin/users/{id}` 👑

Hapus user beserta semua data terkait (cascade).

**Error 400 — Coba hapus diri sendiri:**

```json
{
    "message": "Tidak bisa menghapus diri sendiri."
}
```

---

#### POST `/admin/users/{id}/adjust-credits` 👑

Tambah atau kurangi kredit user secara manual. Semua adjustment dicatat ke system_logs.

**Request Body:**

```json
{
    "amount": 50,
    "reason": "Kompensasi karena video gagal diproses"
}
```

> `amount` bisa negatif untuk mengurangi kredit.

**Response 200:**

```json
{
    "status": "success",
    "message": "Kredit Budi Santoso berhasil disesuaikan.",
    "new_balance": 60
}
```

---

## 8. WebSocket Events

Backend menggunakan **Laravel Reverb** untuk broadcast event realtime ke frontend.

### Setup Laravel Echo di Frontend

```javascript
import Echo from 'laravel-echo';
import Pusher from 'pusher-js';

window.Pusher = Pusher;

const echo = new Echo({
    broadcaster: 'reverb',
    key: import.meta.env.VITE_REVERB_APP_KEY,
    wsHost: import.meta.env.VITE_REVERB_HOST,
    wsPort: import.meta.env.VITE_REVERB_PORT,
    forceTLS: false,
    enabledTransports: ['ws', 'wss'],
    authEndpoint: `${import.meta.env.VITE_API_BASE_URL}/broadcasting/auth`,
    auth: {
        headers: {
            Authorization: `Bearer ${localStorage.getItem('timeclip_token')}`
        }
    }
});
```

### Channel & Event

**Channel:** `App.Models.User.{userId}` (Private Channel)

**Event:** `VideoStatusUpdated`

**Payload:**

```json
{
    "video_id": 5,
    "user_id": 1,
    "status": "processing",
    "message": "Sedang melakukan transkripsi audio..."
}
```

**Subscribe:**

```javascript
echo.private(`App.Models.User.${userId}`)
    .listen('VideoStatusUpdated', (event) => {
        console.log(event.video_id, event.status, event.message);
        // Update state UI berdasarkan event
    });
```

### Semua Nilai Status yang Mungkin Dikirim via WebSocket

| Status | Message Contoh | Keterangan |
|---|---|---|
| `downloading` | "Video sedang didownload..." | yt-dlp mulai jalan |
| `processing` | "Download selesai, memulai transkripsi..." | Selesai download |
| `processing` | "Sedang melakukan transkripsi audio..." | Gemini sedang transkripsi |
| `processing` | "Transkripsi selesai, AI sedang menganalisis highlight..." | Gemini sedang analisis |
| `rendering` | "3 klip ditemukan, sedang dirender..." | FFmpeg render klip |
| `failed` | "Download video gagal." | yt-dlp error |
| `failed` | "Transkripsi gagal: ..." | Gemini error |
| `failed` | "Analisis highlight gagal permanen." | Job max retry |

---

## 9. Alur Proses Video (Pipeline)

```
Frontend POST /videos/process
        │
        ▼
[Video record dibuat — status: pending]
[10 kredit dipotong dari user]
        │
        ▼
DownloadVideoJob (queue)
  ├── yt-dlp download video dari URL
  ├── Simpan ke storage/app/private/raw_videos/{id}.mp4
  ├── Update status video: processing
  ├── Broadcast: "downloading"
  └── Dispatch → ProcessTranscription
        │
        ▼
ProcessTranscription (queue)
  ├── FFmpeg ekstrak audio → .mp3
  ├── GeminiService.transcribe() → full_text + words[]
  ├── Simpan ke tabel transcriptions
  ├── Hapus file .mp3 sementara
  ├── Broadcast: "processing - transkripsi selesai"
  └── Dispatch → DiscoverHighlightsJob
        │
        ▼
DiscoverHighlightsJob (queue)
  ├── AIHighlightService.getHighlights() → highlights[]
  ├── Buat record clips (status: rendering) untuk setiap highlight
  ├── Update status video: completed
  ├── Broadcast: "rendering - X klip ditemukan"
  └── Dispatch → ProcessVideoClipJob (untuk setiap klip)
        │
        ▼
ProcessVideoClipJob (queue) — berjalan paralel per klip
  ├── Ambil subtitle dari transkripsi
  ├── CaptionService.generateAss() → file .ass karaoke
  ├── FFmpeg render klip + burn subtitle
  ├── Upload clip .mp4 ke Supabase
  ├── Upload thumbnail .jpg ke Supabase
  ├── Update clip: status=ready, clip_path, thumbnail_path
  └── Hapus file sementara lokal
```

---

## 10. Database Schema

### Tabel `users`

| Kolom | Tipe | Keterangan |
|---|---|---|
| id | bigint | Primary key |
| name | string | Nama user |
| email | string | Email unik |
| password | string\|null | Null untuk user OAuth |
| role | enum | `admin` atau `creator` |
| tier | enum | `free`, `starter`, `pro`, `business` |
| remaining_credits | integer | Default 10 |
| last_reset_date | date | Untuk tracking reset kredit harian |
| provider_name | string\|null | `github` atau null |
| provider_id | string\|null | ID dari OAuth provider |

### Tabel `videos`

| Kolom | Tipe | Keterangan |
|---|---|---|
| id | bigint | Primary key |
| user_id | bigint | FK ke users |
| title | string | Judul video |
| source_url | text | URL YouTube/TikTok/dll |
| file_path | string\|null | Path file lokal setelah download |
| duration | integer | Durasi dalam detik |
| status | enum | `pending`, `processing`, `completed`, `failed` |

### Tabel `transcriptions`

| Kolom | Tipe | Keterangan |
|---|---|---|
| id | bigint | Primary key |
| video_id | bigint | FK ke videos (1:1) |
| full_text | longtext | Teks transkripsi lengkap |
| json_data | json | `{ full_text, words: [{word, start, end}] }` |

### Tabel `clips`

| Kolom | Tipe | Keterangan |
|---|---|---|
| id | bigint | Primary key |
| video_id | bigint | FK ke videos |
| title | string | Judul klip dari AI |
| start_time | float | Detik mulai dari video asal |
| end_time | float | Detik selesai dari video asal |
| viral_score | integer | Skor 0-100 dari AI |
| clip_path | string\|null | Path di Supabase |
| thumbnail_path | string\|null | Path thumbnail di Supabase |
| status | enum | `rendering`, `ready`, `failed` |

### Tabel `clip_subtitles`

| Kolom | Tipe | Keterangan |
|---|---|---|
| id | bigint | Primary key |
| clip_id | bigint | FK ke clips (1:1) |
| full_text | longtext | Teks subtitle klip |
| words | json | `[{word, start, end}]` — timestamp relatif dari awal klip |

### Tabel `transactions`

| Kolom | Tipe | Keterangan |
|---|---|---|
| id | bigint | Primary key |
| external_id | string | Order ID unik (format: TC-XXXXXXXXXX) |
| user_id | bigint | FK ke users |
| tier_plan | string | `starter`, `pro`, atau `business` |
| amount | decimal | Nominal pembayaran |
| status | string | `pending`, `settlement`, `failed`, `expired` |
| snap_token | string\|null | Token Midtrans Snap |

### Tabel `system_logs`

| Kolom | Tipe | Keterangan |
|---|---|---|
| id | bigint | Primary key |
| service | string | `GEMINI`, `FFMPEG`, `YT-DLP`, `SYSTEM` |
| level | string | `INFO`, `WARNING`, `ERROR` |
| category | string | `USAGE`, `RENDER`, `DOWNLOAD`, `AUTH`, dll |
| message | text | Pesan log |
| payload | json\|null | Data tambahan |
| user_id | bigint\|null | FK ke users (nullable) |

---

## 11. Services & Jobs

### Services

| Service | File | Fungsi |
|---|---|---|
| `GeminiService` | `app/Services/GeminiService.php` | Transkripsi audio ke teks + timestamps via Gemini AI |
| `AIHighlightService` | `app/Services/AIHighlightService.php` | Analisis transkripsi untuk temukan momen viral |
| `CaptionService` | `app/Services/CaptionService.php` | Generate file subtitle .ass format karaoke |

### Jobs (Queue Workers)

| Job | Timeout | Tries | Fungsi |
|---|---|---|---|
| `DownloadVideoJob` | 3600s | 2 | Download video dari URL pakai yt-dlp |
| `ProcessTranscription` | 900s | 2 | Ekstrak audio + transkripsi via Gemini |
| `DiscoverHighlightsJob` | 300s | 2 | Analisis highlight + buat record klip |
| `ProcessVideoClipJob` | — | — | Render klip + subtitle + upload Supabase |

---

## 12. Error Reference

### HTTP Status Codes

| Code | Arti |
|---|---|
| 200 | Sukses |
| 201 | Sukses — data baru dibuat |
| 302 | Redirect (stream/download) |
| 401 | Unauthenticated — token tidak ada atau invalid |
| 403 | Forbidden — bukan admin atau kredit kurang |
| 404 | Data tidak ditemukan |
| 422 | Validasi gagal |
| 429 | Rate limit — terlalu banyak proses aktif |
| 500 | Server error internal |

### Format Error Validasi (422)

```json
{
    "message": "The title field is required.",
    "errors": {
        "title": ["The title field is required."],
        "url": ["The url field must be a valid URL."]
    }
}
```

---

## 13. Catatan Internal Dev

### Hal yang Perlu Diperhatikan

1. **Kredit tidak refund otomatis.** Jika video gagal diproses, kredit user tidak dikembalikan. Gunakan endpoint `POST /admin/users/{id}/adjust-credits` untuk refund manual.

2. **Timestamp transcription vs subtitle berbeda.** Transcription: absolut dari awal video. Subtitle klip: relatif dari awal klip (mulai dari 0). Jangan campur aduk keduanya di frontend.

3. **Queue WAJIB jalan.** Tanpa `php artisan queue:work`, semua video stuck di `pending`. Ini sumber masalah paling umum saat development.

4. **Supabase bucket harus Public.** `clip_url` dan `thumbnail_url` adalah direct URL Supabase. Jika bucket bukan Public, URL tidak bisa diakses browser tanpa autentikasi tambahan.

5. **Midtrans webhook butuh URL publik.** Endpoint `/payment/callback` harus bisa dicapai dari internet. Pakai `ngrok` atau sejenisnya untuk testing lokal.

6. **OAuth GitHub: password bisa null.** User yang register via GitHub tidak punya password. Jangan validasi field password untuk user ini.

7. **`business` tier: max_cap = "unlimited" (string).** Endpoint `/user/credits` mengembalikan string "unlimited" untuk tier business, bukan integer. Handle di frontend.

8. **Re-render tidak bisa di-cancel.** Sekali `ProcessVideoClipJob` di-dispatch, tidak ada mekanisme cancel. Jika user edit subtitle lagi, job baru akan di-dispatch dan akan ada 2 job berjalan. Hasil terakhir yang akan tersimpan.

9. **File video lokal tidak dihapus otomatis.** File raw video di `storage/app/private/raw_videos/` tidak dihapus setelah diproses. Perlu cron job atau manual cleanup untuk production.

10. **Gallery dashboard mengambil top 8.** `GET /dashboard` hanya return 8 klip teratas. Untuk gallery penuh, gunakan `GET /clips/gallery`.

---

*Dokumen ini di-generate dari source code TimeClip API. Update dokumen ini setiap ada perubahan endpoint atau logic bisnis.*