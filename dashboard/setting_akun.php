<?php
date_default_timezone_set('Asia/Jakarta');
session_start();

/*
|--------------------------------------------------------------------------
| HARD GUARD
|--------------------------------------------------------------------------
*/
require_once __DIR__ . '/../auth/auth_guard.php';
require_once __DIR__ . '/../config/database.php';

/*
|--------------------------------------------------------------------------
| DATA USER SESSION (SISTEM LAMA)
|--------------------------------------------------------------------------
*/
$userSession = $_SESSION['user_rh'] ?? [];
$userId = (int)($userSession['id'] ?? 0);

$stmt = $pdo->prepare("
    SELECT 
        full_name,
        position,
        batch,
        kode_nomor_induk_rs,
        tanggal_masuk,
        citizen_id,
        no_hp_ic,
        jenis_kelamin,
        file_ktp,
        file_sim,
        file_kta,
        file_skb,
        sertifikat_heli,
        sertifikat_operasi,
        dokumen_lainnya
    FROM user_rh
    WHERE id = ?
    LIMIT 1
");

$stmt->execute([$userId]);
$userDb = $stmt->fetch(PDO::FETCH_ASSOC);

$citizenId    = $userDb['citizen_id'] ?? '';
$jenisKelamin = $userDb['jenis_kelamin'] ?? '';
$noHpIc = $userDb['no_hp_ic'] ?? '';

$medicName  = $userDb['full_name'] ?? '';
$medicPos   = $userDb['position'] ?? '';
$medicBatch = $userDb['batch'] ?? '';
$nomorInduk = $userDb['kode_nomor_induk_rs'] ?? '';
$tanggalMasuk = $userDb['tanggal_masuk'] ?? '';

$batchLocked = !empty($nomorInduk);
$kodeBatch   = $nomorInduk;

$batchLocked = !empty($nomorInduk);

$pageTitle = 'Setting Akun';

$kodeBatch = $nomorInduk; // tampilkan PERSIS seperti di database

/*
|--------------------------------------------------------------------------
| FLASH MESSAGE (SISTEM EMS)
|--------------------------------------------------------------------------
*/
$messages = $_SESSION['flash_messages'] ?? [];
$warnings = $_SESSION['flash_warnings'] ?? [];
$errors   = $_SESSION['flash_errors']   ?? [];

unset($_SESSION['flash_messages'], $_SESSION['flash_warnings'], $_SESSION['flash_errors']);
?>

<?php include __DIR__ . '/../partials/header.php'; ?>
<?php include __DIR__ . '/../partials/sidebar.php'; ?>

<section class="content">
    <div class="page" style="max-width:640px;margin:auto;">

        <h1>⚙️ Setting Akun</h1>

        <!-- ===============================
             NOTIFIKASI (SAMA DENGAN REKAP FARMASI)
             =============================== -->
        <?php foreach ($messages as $m): ?>
            <div class="alert alert-info"><?= htmlspecialchars($m) ?></div>
        <?php endforeach; ?>

        <?php foreach ($warnings as $w): ?>
            <div class="alert alert-warning"><?= htmlspecialchars($w) ?></div>
        <?php endforeach; ?>

        <?php foreach ($errors as $e): ?>
            <div class="alert alert-error"><?= htmlspecialchars($e) ?></div>
        <?php endforeach; ?>

        <div class="card">
            <div class="card-header">Informasi Akun</div>

            <form method="POST"
                action="setting_akun_action.php"
                class="form"
                enctype="multipart/form-data">

                <!-- ===============================
                IDENTITAS MEDIS
                =============================== -->
                <h3 class="section-form-title">Identitas Medis</h3>

                <div class="row-form-2">
                    <div>
                        <label>Batch <span class="required">*</span></label>
                        <input type="number"
                            name="batch"
                            min="1"
                            max="26"
                            required
                            value="<?= htmlspecialchars($medicBatch) ?>"
                            <?= $batchLocked ? 'disabled style="background:#f3f3f3;cursor:not-allowed;"' : '' ?>>
                        <?php if ($batchLocked): ?>
                            <small class="hint-locked">
                                🔒 Batch terkunci karena Kode Medis telah dibuat
                            </small>
                        <?php endif; ?>
                    </div>

                    <?php if ($batchLocked): ?>
                        <input type="hidden" name="batch" value="<?= (int)$medicBatch ?>">
                    <?php endif; ?>

                    <div>
                        <label>Tanggal Masuk <span class="required">*</span></label>
                        <input type="date"
                            name="tanggal_masuk"
                            value="<?= htmlspecialchars($tanggalMasuk) ?>"
                            required>
                        <small class="hint-info">
                            📅 Tanggal Anda join ke <strong>Rumah Sakit Roxwood</strong>
                        </small>
                    </div>
                </div>

                <!-- ===============================
                DATA PERSONAL
                =============================== -->
                <hr class="section-divider">
                <h3 class="section-form-title">Data Personal</h3>

                <label>Nama Medis <span class="required">*</span></label>
                <input type="text"
                    name="full_name"
                    required
                    placeholder="Masukkan nama lengkap Anda"
                    value="<?= htmlspecialchars($medicName) ?>">

                <label>Jabatan <span class="required">*</span></label>
                <select name="position" required>
                    <option value="">-- Pilih Jabatan --</option>
                    <option value="Trainee" <?= $medicPos === 'Trainee' ? 'selected' : '' ?>>Trainee</option>
                    <option value="Paramedic" <?= $medicPos === 'Paramedic' ? 'selected' : '' ?>>Paramedic</option>
                    <option value="(Co.Ast)" <?= $medicPos === '(Co.Ast)' ? 'selected' : '' ?>>(Co.Ast)</option>
                    <option value="Dokter Umum" <?= $medicPos === 'Dokter Umum' ? 'selected' : '' ?>>Dokter Umum</option>
                    <option value="Dokter Spesialis" <?= $medicPos === 'Dokter Spesialis' ? 'selected' : '' ?>>Dokter Spesialis</option>
                </select>

                <!-- BARIS 1 -->
                <div class="row-form-2">
                    <div>
                        <label>Citizen ID <span class="required">*</span></label>
                        <input type="text"
                            id="citizenIdInput"
                            name="citizen_id"
                            required
                            placeholder="RH39IQLC"
                            pattern="[A-Z0-9]+"
                            title="Hanya huruf BESAR dan angka, tanpa spasi"
                            value="<?= htmlspecialchars($citizenId) ?>"
                            style="text-transform:uppercase;">
                        <small class="hint-warning">
                            ⚠️ Format: <strong>HURUF BESAR + ANGKA</strong>, tanpa spasi
                        </small>
                    </div>

                    <div>
                        <label>Jenis Kelamin <span class="required">*</span></label>
                        <select name="jenis_kelamin" required>
                            <option value="">-- Pilih --</option>
                            <option value="Laki-laki" <?= $jenisKelamin === 'Laki-laki' ? 'selected' : '' ?>>
                                👨 Laki-laki
                            </option>
                            <option value="Perempuan" <?= $jenisKelamin === 'Perempuan' ? 'selected' : '' ?>>
                                👩 Perempuan
                            </option>
                        </select>
                    </div>
                </div>

                <!-- BARIS 2 -->
                <div class="row-form-1">
                    <label>No HP IC <span class="required">*</span></label>
                    <input type="number"
                        name="no_hp_ic"
                        required
                        inputmode="numeric"
                        placeholder="Contoh: 8123456789"
                        value="<?= htmlspecialchars($noHpIc) ?>">
                    <small class="hint-info">
                        📱 Nomor HP yang terdaftar di sistem IC
                    </small>
                </div>

                <!-- ===============================
DOKUMEN PENDUKUNG
=============================== -->
                <?php
                function renderDocInput($label, $name, $path = null)
                {
                ?>
                    <div class="doc-upload-wrapper">
                        <div class="doc-upload-header">
                            <label class="doc-label"><?= htmlspecialchars($label) ?></label>

                            <?php if (!empty($path)): ?>
                                <div class="doc-status-badge">
                                    <span class="badge-success-mini">✔ Sudah diunggah</span>
                                    <a href="#"
                                        class="btn-link btn-preview-doc"
                                        data-src="/<?= htmlspecialchars($path) ?>"
                                        data-title="<?= htmlspecialchars($label) ?>">
                                        Lihat dokumen
                                    </a>
                                </div>
                            <?php else: ?>
                                <span class="badge-muted-mini">Belum ada</span>
                            <?php endif; ?>
                        </div>

                        <div class="doc-upload-input">
                            <label for="<?= htmlspecialchars($name) ?>" class="file-upload-label">
                                <span class="file-icon">📁</span>
                                <span class="file-text">
                                    <strong>Pilih file</strong>
                                    <small>PNG atau JPG</small>
                                </span>
                            </label>
                            <input type="file"
                                id="<?= htmlspecialchars($name) ?>"
                                name="<?= htmlspecialchars($name) ?>"
                                accept="image/png,image/jpeg"
                                style="display:none;">
                            <div class="file-selected-name" data-for="<?= htmlspecialchars($name) ?>"></div>
                        </div>

                        <?php if (!empty($path)): ?>
                            <small class="doc-hint">Upload ulang akan menggantikan file sebelumnya</small>
                        <?php endif; ?>
                    </div>
                <?php
                }
                ?>

                <hr class="section-divider">
                <h3 class="section-form-title">Dokumen Pendukung</h3>
                <p class="text-muted">Unggah dokumen identitas & sertifikasi (PNG / JPG)</p>

                <?php
                renderDocInput('KTP', 'file_ktp', $userDb['file_ktp']);
                renderDocInput('SIM', 'file_sim', $userDb['file_sim']);
                renderDocInput('KTA', 'file_kta', $userDb['file_kta']);
                renderDocInput('SKB', 'file_skb', $userDb['file_skb']);
                renderDocInput('Sertifikat Heli', 'sertifikat_heli', $userDb['sertifikat_heli']);
                renderDocInput('Sertifikat Operasi', 'sertifikat_operasi', $userDb['sertifikat_operasi']);
                renderDocInput('Dokumen Lainnya', 'dokumen_lainnya', $userDb['dokumen_lainnya']);
                ?>

                <!-- (sisanya tetap sama sampai bagian PIN) -->

                <!-- ===============================
                KEAMANAN AKUN
                =============================== -->
                <hr class="section-divider">
                <h3 class="section-form-title">Keamanan Akun</h3>

                <div class="info-box">
                    <span class="info-icon">ℹ️</span>
                    <span>Kosongkan semua field PIN jika tidak ingin mengubah password</span>
                </div>

                <label>PIN Lama <small>(opsional)</small></label>
                <input type="password"
                    id="oldPinInput"
                    name="old_pin"
                    inputmode="numeric"
                    pattern="[0-9]{4}"
                    maxlength="4"
                    placeholder="****">

                <div class="row-form-2">
                    <div>
                        <label>PIN Baru <small>(opsional)</small></label>
                        <input type="password"
                            id="newPinInput"
                            name="new_pin"
                            inputmode="numeric"
                            pattern="[0-9]{4}"
                            maxlength="4"
                            placeholder="****">
                    </div>

                    <div>
                        <label>Konfirmasi PIN Baru <small>(opsional)</small></label>
                        <input type="password"
                            id="confirmPinInput"
                            name="confirm_pin"
                            inputmode="numeric"
                            pattern="[0-9]{4}"
                            maxlength="4"
                            placeholder="****">
                    </div>
                </div>

                <!-- ===============================
                SUBMIT
                =============================== -->
                <div class="form-submit-wrapper">
                    <button type="submit" class="btn-primary btn-submit">
                        <span>💾</span>
                        <span>Simpan Perubahan</span>
                    </button>
                </div>

            </form>
        </div>

    </div>
    <!-- ======================================
     MODAL PREVIEW DOKUMEN
     ====================================== -->
    <div id="docPreviewModal" class="modal-overlay" style="display:none;">
        <div class="modal-card" style="max-width:900px;">

            <!-- HEADER -->
            <div class="modal-header">
                <strong id="docPreviewTitle">📄 Preview Dokumen</strong>
                <div style="display:flex;gap:8px;align-items:center;">
                    <button type="button" class="zoom-control-btn" id="docZoomOut" title="Perkecil">➖</button>
                    <button type="button" class="zoom-control-btn" id="docZoomIn" title="Perbesar">➕</button>
                    <button type="button" class="zoom-control-btn" id="docZoomReset" title="Reset">🔄</button>
                    <button type="button" class="zoom-control-btn" id="docReload" title="Reload">⟳</button>
                    <button type="button" onclick="closeDocModal()">✕</button>
                </div>
            </div>

            <!-- BODY -->
            <div class="modal-body" style="background:#f8fafc;display:flex;align-items:center;justify-content:center;min-height:60vh;">
                <img id="docPreviewImage"
                    src=""
                    alt="Dokumen"
                    style="max-width:100%;max-height:75vh;object-fit:contain;transition:transform 0.2s ease;">
            </div>

        </div>
    </div>
    <script>
        // Show selected filename
        document.addEventListener('DOMContentLoaded', function() {
            document.querySelectorAll('input[type="file"]').forEach(function(input) {
                input.addEventListener('change', function() {
                    const nameDisplay = document.querySelector('.file-selected-name[data-for="' + this.id + '"]');
                    if (nameDisplay) {
                        if (this.files.length > 0) {
                            const fileName = this.files[0].name;
                            const fileSize = (this.files[0].size / 1024).toFixed(1);
                            nameDisplay.innerHTML = `
                        <span class="selected-file-info">
                            <strong>${fileName}</strong>
                            <small>${fileSize} KB</small>
                        </span>
                    `;
                            nameDisplay.style.display = 'flex';
                        } else {
                            nameDisplay.style.display = 'none';
                        }
                    }
                });
            });
        });
    </script>
</section>

<script>
    document.addEventListener('DOMContentLoaded', function() {
        setTimeout(function() {
            document.querySelectorAll(
                '.alert-info, .alert-warning, .alert-error'
            ).forEach(function(el) {
                el.style.transition = 'opacity 0.5s ease';
                el.style.opacity = '0';

                setTimeout(function() {
                    if (el.parentNode) {
                        el.parentNode.removeChild(el);
                    }
                }, 600);
            });
        }, 5000); // 5 detik
    });
</script>

<script>
    document.addEventListener('DOMContentLoaded', function() {
        const modal = document.getElementById('docPreviewModal');
        const img = document.getElementById('docPreviewImage');
        const titleEl = document.getElementById('docPreviewTitle');

        let scale = 1;
        let currentSrc = '';

        // OPEN MODAL
        document.body.addEventListener('click', function(e) {
            const btn = e.target.closest('.btn-preview-doc');
            if (!btn) return;

            e.preventDefault();

            currentSrc = btn.dataset.src;
            img.src = currentSrc;
            titleEl.textContent = '📄 ' + (btn.dataset.title || 'Dokumen');

            scale = 1;
            img.style.transform = 'scale(1)';

            modal.style.display = 'flex';
            document.body.classList.add('modal-open');
        });

        // CLOSE MODAL
        window.closeDocModal = function() {
            modal.style.display = 'none';
            document.body.classList.remove('modal-open');
            img.src = '';
            scale = 1;
        };

        modal.addEventListener('click', function(e) {
            if (e.target === modal) closeDocModal();
        });

        document.addEventListener('keydown', function(e) {
            if (e.key === 'Escape' && modal.style.display === 'flex') {
                closeDocModal();
            }
        });

        // ZOOM CONTROLS
        document.getElementById('docZoomIn').onclick = () => {
            scale = Math.min(scale + 0.2, 3);
            img.style.transform = `scale(${scale})`;
        };

        document.getElementById('docZoomOut').onclick = () => {
            scale = Math.max(scale - 0.2, 0.5);
            img.style.transform = `scale(${scale})`;
        };

        document.getElementById('docZoomReset').onclick = () => {
            scale = 1;
            img.style.transform = 'scale(1)';
        };

        document.getElementById('docReload').onclick = () => {
            if (!currentSrc) return;
            img.src = currentSrc + '?v=' + Date.now();
        };
    });
</script>

<script>
    document.addEventListener('DOMContentLoaded', function() {
        const citizenIdInput = document.getElementById('citizenIdInput');

        if (citizenIdInput) {
            // Auto uppercase saat mengetik
            citizenIdInput.addEventListener('input', function(e) {
                let value = e.target.value;

                // Hapus spasi dan karakter selain huruf & angka
                value = value.replace(/[^A-Z0-9]/gi, '');

                // Convert ke uppercase
                e.target.value = value.toUpperCase();
            });

            // Validasi sebelum submit
            citizenIdInput.closest('form').addEventListener('submit', function(e) {
                const value = citizenIdInput.value.trim();

                // Validasi: tidak boleh kosong
                if (value === '') {
                    e.preventDefault();
                    alert('❌ Citizen ID wajib diisi');
                    citizenIdInput.focus();
                    return false;
                }

                // Validasi: tidak boleh ada spasi
                if (/\s/.test(value)) {
                    e.preventDefault();
                    alert('❌ Citizen ID tidak boleh mengandung spasi');
                    citizenIdInput.focus();
                    return false;
                }

                // Validasi: harus ada minimal 1 angka
                if (!/\d/.test(value)) {
                    e.preventDefault();
                    alert('❌ Citizen ID harus mengandung minimal 1 angka');
                    citizenIdInput.focus();
                    return false;
                }

                // Validasi: harus ada minimal 1 huruf
                if (!/[A-Z]/i.test(value)) {
                    e.preventDefault();
                    alert('❌ Citizen ID harus mengandung minimal 1 huruf');
                    citizenIdInput.focus();
                    return false;
                }

                // Validasi: panjang minimal 6 karakter
                if (value.length < 6) {
                    e.preventDefault();
                    alert('❌ Citizen ID minimal 6 karakter');
                    citizenIdInput.focus();
                    return false;
                }

                // Validasi: tidak boleh sama dengan nama lengkap
                const fullNameInput = document.querySelector('input[name="full_name"]');
                if (fullNameInput) {
                    const fullName = fullNameInput.value.trim().toUpperCase();
                    const cleanedFullName = fullName.replace(/\s+/g, '');

                    if (value.toUpperCase() === cleanedFullName) {
                        e.preventDefault();
                        alert('❌ Citizen ID tidak boleh sama dengan Nama Medis!\n\nContoh Citizen ID yang benar: RH39IQLC');
                        citizenIdInput.focus();
                        return false;
                    }
                }

                // Validasi: tidak boleh hanya huruf saja atau angka saja
                if (/^[A-Z]+$/.test(value)) {
                    e.preventDefault();
                    alert('❌ Citizen ID tidak boleh hanya huruf saja.\n\nHarus kombinasi huruf BESAR dan angka.');
                    citizenIdInput.focus();
                    return false;
                }

                if (/^[0-9]+$/.test(value)) {
                    e.preventDefault();
                    alert('❌ Citizen ID tidak boleh hanya angka saja.\n\nHarus kombinasi huruf BESAR dan angka.');
                    citizenIdInput.focus();
                    return false;
                }
            });
        }
    });
</script>

<script>
    document.addEventListener('DOMContentLoaded', function() {
        const form = document.querySelector('form[action="setting_akun_action.php"]');
        const oldPinInput = document.getElementById('oldPinInput');
        const newPinInput = document.getElementById('newPinInput');
        const confirmPinInput = document.getElementById('confirmPinInput');

        if (form && oldPinInput && newPinInput && confirmPinInput) {
            form.addEventListener('submit', function(e) {
                const oldPin = oldPinInput.value.trim();
                const newPin = newPinInput.value.trim();
                const confirmPin = confirmPinInput.value.trim();

                // Jika salah satu field PIN diisi, semua harus diisi
                const anyPinFilled = oldPin !== '' || newPin !== '' || confirmPin !== '';

                if (anyPinFilled) {
                    // Validasi: semua field PIN harus diisi
                    if (oldPin === '') {
                        e.preventDefault();
                        alert('❌ PIN Lama wajib diisi jika ingin mengganti PIN');
                        oldPinInput.focus();
                        return false;
                    }

                    if (newPin === '') {
                        e.preventDefault();
                        alert('❌ PIN Baru wajib diisi jika ingin mengganti PIN');
                        newPinInput.focus();
                        return false;
                    }

                    if (confirmPin === '') {
                        e.preventDefault();
                        alert('❌ Konfirmasi PIN wajib diisi jika ingin mengganti PIN');
                        confirmPinInput.focus();
                        return false;
                    }

                    // Validasi: PIN harus 4 digit
                    if (oldPin.length !== 4 || !/^\d{4}$/.test(oldPin)) {
                        e.preventDefault();
                        alert('❌ PIN Lama harus 4 digit angka');
                        oldPinInput.focus();
                        return false;
                    }

                    if (newPin.length !== 4 || !/^\d{4}$/.test(newPin)) {
                        e.preventDefault();
                        alert('❌ PIN Baru harus 4 digit angka');
                        newPinInput.focus();
                        return false;
                    }

                    // Validasi: PIN baru dan konfirmasi harus sama
                    if (newPin !== confirmPin) {
                        e.preventDefault();
                        alert('❌ PIN Baru dan Konfirmasi PIN tidak sama');
                        confirmPinInput.focus();
                        return false;
                    }

                    // Validasi: PIN baru tidak boleh sama dengan PIN lama
                    if (oldPin === newPin) {
                        e.preventDefault();
                        alert('❌ PIN Baru tidak boleh sama dengan PIN Lama');
                        newPinInput.focus();
                        return false;
                    }
                }
            });
        }
    });
</script>

<?php include __DIR__ . '/../partials/footer.php'; ?>