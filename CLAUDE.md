# CLAUDE.md

File ini memberikan panduan kepada Claude Code (claude.ai/code) saat bekerja dengan kode dalam repository ini.

## Ringkasan Proyek

**Farmasi EMS** adalah sistem manajemen farmasi rumah sakit untuk ROXWOOD HOSPITAL. Aplikasi web berbasis PHP yang menangani operasi farmasi, penjadwalan staf, pemantauan status online, manajemen acara, dan pelacakan kepatuhan layanan medis.

## Arsitektur

Ini adalah **aplikasi PHP prosedural** dengan routing berbasis file. Arsitektur mengikuti pola ini:

- **Struktur Route**: URL langsung memetakan ke file PHP (tidak ada router framework)
- **Controller**: File di `dashboard/` dan `actions/` menangani pemrosesan request
- **Models**: Query PDO database langsung di seluruh aplikasi
- **Views**: HTML tertanam dalam file PHP
- **Autentikasi**: Berbasis session dengan dukungan cookie remember-me (`auth/auth_guard.php`)

## Struktur Direktori

| Directory | Kegunaan |
|-----------|---------|
| `actions/` | Handler logika bisnis (AI scoring, import/ekspor, push notifikasi) |
| `ajax/` | Endpoint AJAX untuk interaksi frontend dinamis |
| `api/` | Layer API untuk integrasi eksternal (sync sales) |
| `assets/` | Aset statis (CSS, JS, gambar) |
| `auth/` | Sistem autentikasi (login/logout, auth guard) |
| `config/` | File konfigurasi (database, helpers, rentang tanggal) |
| `dashboard/` | Tampilan utama aplikasi (UI utama setelah login) |
| `partials/` | Komponen UI yang dapat digunakan kembali (header, sidebar, footer) |
| `public/` | Halaman publik (form rekrutmen, tanpa autentikasi) |
| `storage/` | Penyimpanan aplikasi (error log, file yang diunggah) |
| `helpers/` | Fungsi utilitas dan helper bersama |
| `cron/` | Tugas terjadwal (cek status online, generate gaji) |
| `backup/` | Backup database dan file |

## Konfigurasi Utama

### Setup Database
- **Config**: `config/database.php`
- **Database**: `farmasi_ems` (MySQL)
- **Timezone**: Asia/Jakarta (+07:00)
- **Koneksi**: PDO dengan `ERRMODE_EXCEPTION`

### Dependencies
Install via Composer:
```bash
composer install
```

Dependency utama:
- `minishlink/web-push` - Web Push API untuk notifikasi browser
- `phpoffice/phpspreadsheet` - Import/ekspor Excel (untuk fitur sales sync)

### Google Sheets Integration
- **Config**: `dashboard/sheet_config.json`
- **Handler**: `dashboard/sync_from_sheet.php`
- **Setup**: Buka `dashboard/setting_spreadsheet.php` untuk konfigurasi ID spreadsheet

### Error Logging
- **File log**: `storage/error_log.txt`
- **Tampilan**: Dinonaktifkan di produksi (`display_errors = 0`)
- **Logging**: Diaktifkan (`log_errors = 1`)

## Perintah Pengembangan Umum

### Menjalankan Tugas Terjadwal secara Manual
```bash
# Cek status online dan kirim peringatan idle
php cron/check_farmasi_online.php

# Generate gaji mingguan
php cron/generate_weekly_salary.php

# Bersihkan data identitas sementara
php cron/cron_cleanup_identity_temp.php
```

### Migrasi Database
Impor skema SQL:
```bash
mysql -u root -p farmasi_ems < tes.sql
```

## Titik Masuk Aplikasi

- **Root**: `index.php` → redirect ke `/dashboard/rekap_farmasi.php`
- **Dashboard Utama**: `dashboard/rekap_farmasi.php` (rekap farmasi)
- **Login**: `auth/login.php`

## Autentikasi & Autorisasi

### Struktur Session
Session user disimpan di `$_SESSION['user_rh']`:
```php
[
    'id'       => int,
    'name'     => string,
    'role'     => string,
    'position' => string
]
```

### Melindungi Route
Sertakan `auth/auth_guard.php` di bagian atas halaman yang dilindungi:
```php
require_once __DIR__ . '/../auth/auth_guard.php';
```

### Akses Berbasis Peran
Contoh dari `rekap_farmasi.php` - Trainee diblokir:
```php
$position = strtolower(trim($medicJabatan));
if ($position === 'trainee') {
    http_response_code(403);
    // Tampilkan halaman 403
    exit;
}
```

### Reload Session Data
Untuk memaksa reload data user dari database:
```php
require_once __DIR__ . '/../helpers/session_helper.php';
forceReloadUserSession($pdo, $_SESSION['user_rh']['id']);
```

## Arsitektur CSS

### Struktur File CSS
- **`assets/css/app.css`** - Base styles, utility classes (`.hidden`, scrollbar, dll)
- **`assets/css/components.css`** - Komponen UI (`.stat-box`, modals, forms, dropdowns, dll)
- **`assets/css/layout.css`** - Layout-specific styles (grid, flexbox, dll)
- **`assets/css/responsive.css`** - Media queries dan responsive breakpoints
- **`assets/css/login.css`** - Halaman login spesifik

### Penting: Hindari Duplikasi CSS
**JANGAN** mendefinisikan class yang sama di lebih dari satu file CSS:
- ❌ Duplikasi `.stat-box` di app.css dan components.css → tumpang tindih
- ❌ Duplikasi `.hidden` di multiple files → gunakan yang sudah ada di app.css
- ✅ Definisi class di SATU file saja, atau gunakan @import jika perlu

### Class CSS Utama
- `.hidden` - Utility untuk menyembunyikan elemen (display: none)
- `.stat-box` - Box statistik dengan gradient background, digunakan di ringkasan gaji
- `.ringkasan-gaji-grid` - Grid layout untuk stat-box gaji (CSS Grid)
- `.modal-overlay` & `.modal-box` - Pattern modal pop-up
- `.consumer-search-dropdown` - Dropdown autocomplete
- `.consumer-search-item` - Item dalam dropdown autocomplete
- `.consumer-search-name` & `.consumer-search-meta` - Styling teks dalam autocomplete

## Pola Autocomplete (Search-as-You-Type)

### Referensi Implementasi
**`dashboard/events.php`** adalah referensi utama untuk pola autocomplete.

### Struktur Autocomplete
1. **Input Text** dengan `autocomplete="off"`:
```html
<input type="text" id="searchInput" autocomplete="off" placeholder="Ketik untuk mencari...">
<div id="searchDropdown" class="consumer-search-dropdown hidden"></div>
```

2. **JavaScript Event Handler**:
```javascript
const input = document.getElementById('searchInput');
const dropdown = document.getElementById('searchDropdown');
let controller = null;

input.addEventListener('input', () => {
    const keyword = input.value.trim();

    // Reset jika kurang dari 2 karakter
    if (keyword.length < 2) {
        dropdown.classList.add('hidden');
        dropdown.innerHTML = '';
        return;
    }

    // Abort request sebelumnya (debouncing)
    if (controller) controller.abort();
    controller = new AbortController();

    // Fetch data
    fetch('ajax/search_endpoint.php?q=' + encodeURIComponent(keyword), {
        signal: controller.signal
    })
    .then(res => res.json())
    .then(data => {
        // Clear dropdown
        dropdown.innerHTML = '';

        if (!data.length) {
            dropdown.classList.add('hidden');
            return;
        }

        // Create setiap item
        data.forEach(item => {
            const div = document.createElement('div');
            div.className = 'consumer-search-item';
            // ... Populate item HTML
            div.addEventListener('click', () => {
                input.value = item.name;
                dropdown.classList.add('hidden');
                dropdown.innerHTML = '';
            });
            dropdown.appendChild(div);
        });

        dropdown.classList.remove('hidden');
    })
    .catch(error => {
        // Handle abort atau error
    });
});

// Close dropdown saat klik di luar
document.addEventListener('click', (e) => {
    if (!input.contains(e.target) && !dropdown.contains(e.target)) {
        dropdown.classList.add('hidden');
    }
});
```

3. **AJAX Endpoint** - Case-insensitive search:
```php
// ajax/search_user_rh.php
$q = trim($_GET['q'] ?? '');

if (strlen($q) < 2) {
    echo json_encode([]);
    exit;
}

$stmt = $pdo->prepare("
    SELECT id, full_name, batch, position
    FROM user_rh
    WHERE LOWER(full_name) LIKE LOWER(CONCAT('%', ?, '%'))
      AND is_active = 1
    ORDER BY full_name ASC
    LIMIT 10
");
$stmt->execute([$q]);
echo json_encode($stmt->fetchAll(PDO::FETCH_ASSOC));
```

## Sistem Gaji (Salary)

### Halaman Utama
**`dashboard/gaji.php`** - Menampilkan rekap gaji mingguan dengan fitur:
- Filter rentang tanggal (today, week1-week4, custom)
- Ringkasan statistik: Total Transaksi, Total Rupiah, Total Bonus (40%)
- **Sudah Dibayarkan** - Total bonus yang sudah dibayar (status='paid')
- **Sisa Bonus** - Total Bonus - Sudah Dibayarkan
- Tabel daftar gaji dengan tombol "Bayar" untuk status pending
- Modal konfirmasi pembayaran dengan opsi:
  - **Langsung Dibayar** - paid_by = nama pelaksana
  - **Titip ke** - paid_by = "Titip ke: [nama] (oleh [pelaksana])"
- Tanggal pembayaran ditampilkan di bawah kolom "Dibayar Oleh"

### Proses Pembayaran
**`dashboard/gaji_pay_process.php`** - Handler JSON POST untuk pembayaran:
- Menerima: `salary_id`, `pay_method` (direct/titip), `titip_to` (user ID)
- Update tabel `salary`: status='paid', paid_at=NOW(), paid_by=...
- Gunakan transaction database untuk konsistensi

### Query Gaji
```php
// Total bonus dalam rentang waktu
$stmt = $pdo->prepare("
    SELECT SUM(bonus_40) AS total_bonus
    FROM salary
    WHERE period_end BETWEEN :start AND :end
");

// Total yang sudah dibayar
$stmtPaid = $pdo->prepare("
    SELECT SUM(bonus_40) AS total_paid_bonus
    FROM salary
    WHERE period_end BETWEEN :start AND :end
    AND status = 'paid'
");
```

## Tabel Database

### Manajemen User & Staf
- `user_rh` - Data staf/user (PIN, role, position, batch, dokumen)
- `user_farmasi_status` - Status online/offline real-time per user
- `user_farmasi_sessions` - Sesi farmasi dengan durasi dan alasan offline
- `user_farmasi_notifications` - Notifikasi push per user
- `user_push_subscriptions` - Subscription data untuk Web Push API
- `user_inbox` - Inbox pesan user (force offline, dll)
- `user_farmasi_force_logs` - Log force offline/manual intervention
- `remember_tokens` - Token untuk "remember me" login
- `account_logs` - Log perubahan akun (nama, posisi, PIN)
- `user_spreadsheets` - Link spreadsheet personal user

### Farmasi & Sales
- `farmasi_activities` - Log aktivitas farmasi (online/offline/transaction)
- `sales` - Transaksi farmasi (package-based)
- `ems_sales` - Transaksi EMS (operasi, treatment, dll)
- `packages` - Definisi paket (Bandage, IFAKS, Painkiller)
- `medical_regulations` - Harga layanan medis (FIXED/RANGE)
- `consumers` - Data konsumen/pasien
- `reimbursements` - Klaim reimbursement staf

### Identitas & OCR
- `identity_master` - Master data identitas (KTP) dengan versi aktif
- `identity_versions` - Riwayat perubahan data identitas (version control)

### Rekrutmen & AI Test
- `medical_applicants` - Data pelamar medis
- `ai_test_results` - Hasil test AI (score, personality, decision)
- `applicant_documents` - Dokumen pelamar (KTP, SKB, SIM)
- `applicant_interview_scores` - Nilai interview per kriteria
- `applicant_interview_results` - Hasil final interview (grade, ML flags)
- `applicant_final_decisions` - Keputusan akhir (lolos/tidak)
- `interview_criteria` - Kriteria penilaian interview (8 kriteria)

### Event & Grouping
- `events` - Event rumah sakit
- `event_participants` - Peserta event
- `event_groups` - Kelompok dalam event
- `event_group_members` - Anggota kelompok event

### Gaji & Operasi Plastik
- `salary` - Data gaji mingguan (bonus 40%)
- `medic_operasi_plastik` - Request operasi plastik

### API & Integrasi
- `api_tokens` - Token untuk integrasi eksternal (Apps Script)

### Field Penting `user_rh`
```php
[
    'id'                  => int,
    'full_name'           => string,
    'citizen_id'          => string,    // NIK
    'no_hp_ic'            => string,    // Nomor HP
    'jenis_kelamin'       => enum,
    'pin'                 => string,    // Hash password
    'role'                => enum,      // Staff, Staff Manager, Manager, Vice Director, Director
    'position'            => string,    // Jabatan (Dokter umum, Paramedic, dll)
    'batch'               => int,       // Batch angkatan
    'tanggal_masuk'       => date,      // Tanggal bergabung
    'is_verified'         => boolean,   // Status verifikasi dokumen
    'is_active'           => boolean,   // Status aktif/resign
]
```

## Push Notifications

Aplikasi menggunakan Web Push API untuk notifikasi real-time:
- **Service Worker**: `sw.js`
- **Push Handler**: `actions/push_send.php`
- **Tipe Notifikasi**: `idle_warning`, `offline`, pesan sistem

## Penanganan Rentang Tanggal

Halaman yang memerlukan filter tanggal menyertakan `config/date_range.php`:
```php
require_once __DIR__ . '/../config/date_range.php';
// Menyediakan: $rangeStart, $rangeEnd, $rangeLabel, $weeks, dll.
```

Rentang yang didukung: `today`, `yesterday`, `week1` sampai `week4` (4-week rolling)

## Penanganan Error

Gunakan fungsi helper untuk logging aplikasi:
```php
app_log('Pesan error Anda di sini');
```

Untuk error spesifik rekrutmen:
```php
logRecruitmentError('context_name', $exception);
// Log ke: storage/recruitment_error.log
```

## Helper Functions

Setelah include `config/helpers.php`:
- `initialsFromName(string $name): string` - Generate inisial dari nama
- `avatarColorFromName(string $name): string` - Generate warna avatar (HSL)
- `formatTanggalID($datetime): string` - Format tanggal lengkap Indonesia (11 Jan 2026 14:30)
- `formatTanggalIndo($date): string` - Format tanggal singkat Indonesia (11 Jan 26)
- `dollar($amount): string` - Format currency dollar ($1.000)
- `safeRegulation(PDO $pdo, string $code): int` - Ambil harga regulasi medis (dengan random range jika perlu)

## Bahasa & Konvensi

- **Bahasa**: Indonesia (komentar, nama variabel, teks UI)
- **Format Tanggal**: Format Indonesia (`formatTanggalID()` helper)
- **Gaya**: PHP prosedural dengan HTML tertanam
- **Penamaan**: snake_case untuk variabel, PascalCase untuk kelas (jarang)

## Fitur Pengujian

Beberapa halaman memiliki fungsi test/preview. Cari file `test_*.php` di direktori `cron/`.

### Public Routes (Tanpa Autentikasi)
- `/public/recruitment_form.php` - Form rekrutmen publik
- `/public/ai_test.php` - AI test untuk kandidat
- `/public/recruitment_submit.php` - Submit form rekrutmen
- `/public/ai_test_submit.php` - Submit AI test
- `/auth/login.php` - Halaman login

## Endpoint AJAX Utama

| Endpoint | Kegunaan |
|----------|----------|
| `ajax/ems_preview_price.php` | Preview harga EMS |
| `ajax/search_consumers.php` | Cari data konsumen |
| `ajax/store_consumer.php` | Simpan data konsumen |
| `ajax/ocr_ktp.php` | OCR KTP (ekstraksi teks dari gambar) |
| `ajax/check_nik.php` | Validasi NIK |
| `ajax/search_user_rh.php` | Cari data user untuk autocomplete (case-insensitive) |
| `actions/heartbeat.php` | Heartbeat user online |
| `actions/toggle_farmasi_status.php` | Toggle status farmasi |
| `actions/export_rekap_farmasi.php` | Ekspor rekap ke Excel |
| `actions/ai_scoring_engine.php` | Engine scoring AI untuk kandidat |

## Fitur Khusus

### OCR KTP
Aplikasi mendukung ekstraksi teks dari gambar KTP menggunakan `ajax/ocr_ktp.php`.

### AI Scoring
Sistem scoring AI untuk evaluasi kandidat:
- Handler: `actions/ai_scoring_engine.php`
- Hybrid interview: `actions/interview_hybrid_scoring.php`

### Import Sales Excel
Sinkronisasi data sales dari file Excel via `actions/import_sales_excel.php`.

## Relasi Database Penting

### Status Farmasi Flow
1. User login → `user_farmasi_status` dibuat/diupdate
2. Online → Record sesi baru di `user_farmasi_sessions`
3. Heartbeat → Update `last_activity_at` di `user_farmasi_status`
4. Idle > 3 menit → Auto offline via cron, update session dengan `end_reason: auto_offline`
5. Force offline → Manager bisa force offline, log ke `user_farmasi_force_logs`

### Penilaian Kandidat
1. `medical_applicants` - Form rekrutmen
2. `ai_test_results` - Test AI (score personality)
3. `applicant_interview_scores` - Interview oleh HR (8 kriteria)
4. `applicant_interview_results` - Agregat nilai + ML flags
5. `applicant_final_decisions` - Keputusan final (lolos/tidak)

### Sales & Package System
- `packages` berisi definisi paket dengan qty items
- `sales` mencatat transaksi dengan package_id
- Sync ke Google Sheets jika `synced_to_sheet = 0`

## Enum Values Penting

### Role (`user_rh`)
- `Staff` - Staf biasa
- `Staff Manager` - Manager staf
- `Manager` - Manager
- `Vice Director` - Wakil direktur
- `Director` - Direktur

### Status Online (`user_farmasi_status`)
- `online` - Sedang bertugas
- `offline` - Tidak bertugas

### End Reason (`user_farmasi_sessions`)
- `manual_offline` - Offline manual sendiri
- `auto_offline` - Auto idle timeout
- `force_offline` - Di-force oleh manager
- `system` - Sistem

### Applicant Status (`medical_applicants`)
- `submitted` - Form submitted
- `ai_test` - Sedang AI test
- `ai_completed` - AI test selesai
- `interview` - Tahap interview
- `final_review` - Review final
- `accepted` - Diterima
- `rejected` - Ditolak

### Payment Type (`medical_regulations`)
- `CASH` - Pembayaran tunai
- `INVOICE` - Invoice
- `BILLING` - Billing

### Price Type (`medical_regulations`)
- `FIXED` - Harga tetap (pakai `price_min`)
- `RANGE` - Harga range (random antara `price_min` - `price_max`)
