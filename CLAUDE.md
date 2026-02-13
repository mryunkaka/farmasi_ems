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

**Keamanan**: File `config/database.php` berisi kredensial database hardcoded. Untuk production, pertimbangkan menggunakan:
- Environment variables untuk menyimpan kredensial
- File konfigurasi di luar web root
- File `.env` (pastikan ditambahkan ke `.gitignore`)

### Dependencies
Install via Composer:
```bash
composer install
```

**PENTING**: Pastikan dependency berikut sudah terinstall di `composer.json`:
- `minishlink/web-push` - Web Push API untuk notifikasi browser (sudah ada)
- `phpoffice/phpspreadsheet` - Import Excel untuk fitur sales sync (`actions/import_sales_excel.php`)

Jika `phpoffice/phpspreadsheet` belum ada, jalankan:
```bash
composer require phpoffice/phpspreadsheet
```

Catatan: Ekspor Excel menggunakan HTML table sederhana dengan header `.xls`, bukan PhpSpreadsheet.

### Google Sheets Integration
- **Config**: `dashboard/sheet_config.json`
- **Handler**: `dashboard/sync_from_sheet.php`
- **Setup**: Buka `dashboard/setting_spreadsheet.php` untuk konfigurasi ID spreadsheet

### Error Logging
- **File log**: `storage/error_log.txt`
- **Tampilan**: Dinonaktifkan di produksi (`display_errors = 0`)
- **Logging**: Diaktifkan (`log_errors = 1`)

### Environment Requirements
- **PHP**: 7.4 atau lebih tinggi
- **Extensions**: PDO, PDO_MySQL, mbstring, json, curl
- **Web Server**: Apache (dengan mod_rewrite) atau Nginx
- **Database**: MySQL 5.7+ atau MariaDB 10.2+

### Setup Cron Jobs
Untuk menjalankan tugas terjadwal otomatis, tambahkan ke crontab:
```bash
# Cek status online setiap 1 menit
* * * * * php /path/to/farmasi-ems/cron/check_farmasi_online.php

# Generate gaji mingguan setiap hari Minggu jam 23:59
59 23 * * 0 php /path/to/farmasi-ems/cron/generate_weekly_salary.php

# Cleanup data identitas sementara setiap jam
0 * * * * php /path/to/farmasi-ems/cron/cron_cleanup_identity_temp.php
```

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

File skema database yang tersedia:
- `tes.sql` - Skema database utama (ukuran kecil, untuk development)
- `hark8423_ems (18).sql` - Skema database lengkap dengan data

**PENTING**: Jangan commit file SQL yang berisi data produksi ke git.

## Titik Masuk Aplikasi

- **Root**: `index.php` → redirect ke `/dashboard/rekap_farmasi.php`
- **Dashboard Utama**: `dashboard/rekap_farmasi.php` (rekap farmasi)
- **Dashboard Trainee**: `dashboard/index.php` (untuk position='trainee')
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

### Flash Messages Pattern
Untuk menampilkan notifikasi setelah redirect:
```php
// Set flash message sebelum redirect
$_SESSION['flash_messages'][] = 'Pesan sukses';
$_SESSION['flash_warnings'][] = 'Peringatan';
$_SESSION['flash_errors'][] = 'Error message';
header('Location: another_page.php');
exit;
```

Di halaman tujuan, ambil dan tampilkan:
```php
$messages = $_SESSION['flash_messages'] ?? [];
$warnings = $_SESSION['flash_warnings'] ?? [];
$errors   = $_SESSION['flash_errors']   ?? [];
unset($_SESSION['flash_messages'], $_SESSION['flash_warnings'], $_SESSION['flash_errors']);
```

### Reload Session Data
Untuk memaksa reload data user dari database:
```php
require_once __DIR__ . '/../helpers/session_helper.php';
forceReloadUserSession($pdo, $_SESSION['user_rh']['id']);
```

**Catatan**: `forceReloadUserSession()` menyimpan field tambahan di session (`batch`, `tanggal_masuk`, `citizen_id`, `no_hp_ic`, `jenis_kelamin`, `kode_nomor_induk_rs`) yang tidak ada di login standar `auth_guard.php`.

### Login Flow & Redirects
Login diproses di `auth/login_process.php` dengan fitur:
- **Anti Double Login** - Cek token aktif di device lain, konfirmasi force login
- **Verifikasi Akun** - `is_verified = 1` dan `is_active = 1` wajib
- **Redirect berdasarkan position**:
  - `trainee` → `/dashboard/index.php`
  - Lainnya → `/dashboard/rekap_farmasi.php`

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

**Penggunaan API**:
- Untuk akses API eksternal (misalnya Google Apps Script), gunakan token yang tersimpan di tabel ini
- Handler API utama: `api/` directory
- Validasi token biasanya dilakukan di awal setiap endpoint API

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
- **Service Worker**: `sw.js` (di root, scope: `/`)
- **Push Handler**: `actions/push_send.php`
- **Tipe Notifikasi**: `idle_warning`, `offline`, pesan sistem
- **Subscription**: Disimpan di tabel `user_push_subscriptions`

Untuk setup HTTPS (wajib untuk push notifications), pastikan:
- Service worker terdaftar dengan benar di dashboard
- Endpoint push valid dan subscription tersimpan
- VAPID keys dikonfigurasi (jika menggunakan VAPID)

## Penanganan Rentang Tanggal

Halaman yang memerlukan filter tanggal menyertakan `config/date_range.php`:
```php
require_once __DIR__ . '/../config/date_range.php';
// Menyediakan: $rangeStart, $rangeEnd, $rangeLabel, $weeks, dll.
```

Rentang yang didukung: `today`, `yesterday`, `week1` sampai `week4` (4-week rolling)

## Penanganan Error

**Catatan Penting**: Fungsi `app_log()` **TIDAK** tersedia sebagai helper terpusat. Setiap file yang membutuhkan logging harus mendefinisikan fungsi ini sendiri di awal file:

```php
function app_log($message)
{
    $line = '[' . date('Y-m-d H:i:s') . '] ' . $message . PHP_EOL;
    error_log($line, 3, __DIR__ . '/../storage/error_log.txt');
}
```

Setelah mendefinisikan fungsi di atas, gunakan untuk logging aplikasi:
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
- `dollar($amount): string` - Format currency dollar ($1.000) - dengan format Indonesia (titik sebagai pemisah ribuan)
- `safeRegulation(PDO $pdo, string $code): int` - Ambil harga regulasi medis (dengan random range jika perlu)

**Catatan**: Fungsi `dollar()` menggunakan format Indonesia (titik sebagai pemisah ribuan), bukan format US. Nama fungsinya adalah `dollar()` tetapi formatnya adalah `$1.000` (bukan `$1,000`).

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

## Rekomendasi Pengembangan

### Git (.gitignore)
Buat file `.gitignore` di root project:
```gitignore
# Dependencies
/vendor/

# Sensitive files
/config/database.php
*.sql
storage/*.log

# IDE
.vscode/
.idea/
*.sublime-*

# OS
.DS_Store
Thumbs.db

# Temporary files
*.tmp
*.temp
storage/temp/*
```

### Security Checklist
- [ ] Ganti kredensial database di production
- [ ] Pastikan file `config/database.php` tidak di-commit
- [ ] Validasi semua input user (terutama di AJAX endpoints)
- [ ] Gunakan prepared statements PDO (sudah diterapkan)
- [ ] Pastikan `auth/auth_guard.php` di-include di semua halaman yang dilindungi
- [ ] Rate limiting untuk endpoint penting
- [ ] Sanitasi output dengan `htmlspecialchars()` untuk mencegah XSS

### Troubleshooting Common Issues

**Session tidak tersimpan**:
- Pastikan `session_start()` dipanggil sebelum output HTML
- Cek permission directory session storage

**Export Excel gagal**:
- Export menggunakan HTML table dengan header `.xls` (bukan true XLS)
- Pastikan tidak ada output sebelum `header()` calls

**Push notification tidak berfungsi**:
- Harus menggunakan HTTPS (wajib untuk Service Worker)
- Cek subscription di tabel `user_push_subscriptions`
- Pastikan VAPID keys valid (jika menggunakan VAPID)

**Import Excel gagal**:
- Pastikan `phpoffice/phpspreadsheet` sudah di-install via Composer
- Cek format file harus `.xlsx` atau `.xls`
- Pastikan kolom Excel sesuai format: Consumer Name, Package Name, Citizen ID (opsional)
