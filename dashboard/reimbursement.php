<?php
date_default_timezone_set('Asia/Jakarta');
session_start();

/*
|--------------------------------------------------------------------------
| HARD GUARD & CONFIG
|--------------------------------------------------------------------------
*/
require_once __DIR__ . '/../auth/auth_guard.php';
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../config/helpers.php';
require_once __DIR__ . '/../config/helpers.php';
require_once __DIR__ . '/../config/date_range.php';

/*
|--------------------------------------------------------------------------
| PAGE INFO
|--------------------------------------------------------------------------
*/
$pageTitle = 'Reimbursement';

/*
|--------------------------------------------------------------------------
| INCLUDE LAYOUT
|--------------------------------------------------------------------------
*/
include __DIR__ . '/../partials/header.php';
include __DIR__ . '/../partials/sidebar.php';

/*
|--------------------------------------------------------------------------
| ROLE USER
|--------------------------------------------------------------------------
*/
$userRole = strtolower(trim($_SESSION['user_rh']['role'] ?? ''));
$userId   = (int)($_SESSION['user_rh']['id'] ?? 0);

$isDirector = in_array($userRole, ['vice director', 'director'], true);

$canPayReimbursement = $userRole !== 'staff';

/*
|--------------------------------------------------------------------------
| FILTER INPUT
|--------------------------------------------------------------------------
*/
$startDate = $_GET['start_date'] ?? '';
$endDate   = $_GET['end_date'] ?? '';

/*
|--------------------------------------------------------------------------
| QUERY DATA (AMAN ONLY_FULL_GROUP_BY)
|--------------------------------------------------------------------------
*/
$sql = "
    SELECT
        r.reimbursement_code,
        MAX(r.billing_source_type) AS billing_source_type,
        MAX(r.billing_source_name) AS billing_source_name,
        MAX(r.status) AS status,
        MIN(r.created_at) AS created_at,
        SUM(r.amount) AS total_amount,
        MAX(r.receipt_file) AS receipt_file,
        MAX(r.paid_at) AS paid_at,
        MAX(u.full_name) AS paid_by_name
    FROM reimbursements r
    LEFT JOIN user_rh u ON u.id = r.paid_by
    WHERE 1=1
";

$params = [];

// 📅 FILTER TANGGAL
if ($startDate && $endDate) {
    $sql .= " AND DATE(created_at) BETWEEN :start_date AND :end_date";
    $params[':start_date'] = $startDate;
    $params[':end_date']   = $endDate;
}

$sql .= "
    GROUP BY reimbursement_code
    ORDER BY created_at DESC
";

$stmt = $pdo->prepare($sql);
$stmt->execute($params);
$rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

?>

<section class="content">
    <div class="page" style="max-width:1200px;margin:auto;">

        <h1>Reimbursement</h1>
        <p class="text-muted">Pengajuan penggantian dana operasional</p>

        <div class="card">
            <div class="card-header"
                style="display:flex;justify-content:space-between;align-items:center;">
                <span>Daftar Reimbursement</span>
                <button id="btnAddReim" class="btn-success">
                    ➕ Input Reimbursement
                </button>
            </div>

            <div class="search-panel">
                <form method="get" class="search-form search-form-inline">

                    <input type="hidden" name="start_date" id="startDate">
                    <input type="hidden" name="end_date" id="endDate">

                    <!-- CUSTOM DATE -->
                    <div class="search-field search-field-date" id="customDateWrapper" style="display:none;">
                        <input type="date" id="customStart">
                    </div>

                    <div class="search-field search-field-date" id="customDateWrapperEnd" style="display:none;">
                        <input type="date" id="customEnd">
                    </div>

                    <!-- RANGE -->
                    <div class="search-field search-field-range">
                        <select name="range" id="rangeSelect">
                            <option value="this_week">Minggu Ini</option>
                            <option value="last_week">Minggu Lalu</option>
                            <option value="2_weeks">2 Minggu Lalu</option>
                            <option value="3_weeks">3 Minggu Lalu</option>
                            <option value="custom">Custom</option>
                        </select>
                    </div>

                    <div class="search-actions">
                        <button type="submit" class="btn btn-primary">Filter</button>

                        <?php if (!empty($_GET['range'])): ?>
                            <a href="reimbursement.php" class="btn btn-secondary">Clear</a>
                        <?php endif; ?>
                    </div>

                </form>
            </div>

            <div class="table-wrapper">
                <table id="reimTable" class="table-custom">
                    <thead>
                        <tr>
                            <th>#</th>
                            <th>Tanggal</th>
                            <th>Kode</th>
                            <th>Sumber</th>
                            <th>Status</th>
                            <th>Bukti</th>
                            <th>Total</th>
                            <th>Dibayar Oleh</th>
                            <th>Aksi</th>
                        </tr>
                    </thead>

                    <tbody>
                        <?php foreach ($rows as $i => $r): ?>
                            <tr>
                                <!-- # -->
                                <td><?= $i + 1 ?></td>

                                <!-- TANGGAL PENGAJUAN -->
                                <td><?= date('d M Y H:i', strtotime($r['created_at'])) ?></td>

                                <!-- KODE -->
                                <td><?= htmlspecialchars($r['reimbursement_code']) ?></td>

                                <!-- SUMBER -->
                                <td>
                                    <?= ucfirst($r['billing_source_type']) ?> –
                                    <?= htmlspecialchars($r['billing_source_name']) ?>
                                </td>

                                <!-- STATUS -->
                                <td>
                                    <span class="badge-status badge-<?= htmlspecialchars($r['status']) ?>">
                                        <?= strtoupper($r['status']) ?>
                                    </span>
                                </td>

                                <!-- BUKTI -->
                                <td>
                                    <?php if (!empty($r['receipt_file'])): ?>
                                        <a href="#"
                                        class="doc-badge btn-preview-doc"
                                        data-src="/<?= htmlspecialchars($r['receipt_file']) ?>"
                                        data-title="Bukti Pembayaran <?= htmlspecialchars($r['reimbursement_code']) ?>">
                                            📄 Bukti
                                        </a>
                                    <?php else: ?>
                                        <span style="color:#9ca3af;">-</span>
                                    <?php endif; ?>
                                </td>

                                <!-- TOTAL -->
                                <td><?= dollar((int)$r['total_amount']) ?></td>

                                <!-- DIBAYAR OLEH (NAMA + WAKTU) -->
                                <td>
                                    <?php if (!empty($r['paid_by_name'])): ?>
                                        <div style="display:flex;flex-direction:column;">
                                            <strong><?= htmlspecialchars($r['paid_by_name']) ?></strong>
                                            <?php if (!empty($r['paid_at'])): ?>
                                                <small style="color:#64748b;">
                                                    <?= date('d M Y H:i', strtotime($r['paid_at'])) ?>
                                                </small>
                                            <?php endif; ?>
                                        </div>
                                    <?php else: ?>
                                        <span style="color:#9ca3af;">-</span>
                                    <?php endif; ?>
                                </td>

                                <!-- AKSI -->
                                <td style="white-space:nowrap;">
                                    <?php if ($canPayReimbursement && $r['status'] === 'submitted'): ?>
                                        <button class="btn-success"
                                            onclick="payReimbursement('<?= htmlspecialchars($r['reimbursement_code']) ?>')">
                                            💰 Dibayarkan
                                        </button>
                                    <?php endif; ?>

                                    <?php if (!empty($isDirector) && $isDirector): ?>
                                        <button class="btn-danger"
                                            onclick="deleteReimbursement('<?= htmlspecialchars($r['reimbursement_code']) ?>')">
                                            🗑 Hapus
                                        </button>
                                    <?php endif; ?>

                                    <?php if (
                                        ($userRole === 'staff' || $r['status'] !== 'submitted')
                                        && empty($isDirector)
                                    ): ?>
                                        <span style="color:#9ca3af;">-</span>
                                    <?php endif; ?>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>

        </div>
    </div>
</section>
<script>
function deleteReimbursement(code) {
    if (!confirm('Yakin hapus reimbursement ini? Data akan hilang permanen!')) return;

    fetch('reimbursement_delete.php', {
        method: 'POST',
        headers: {
            'Content-Type': 'application/x-www-form-urlencoded'
        },
        body: 'code=' + encodeURIComponent(code)
    }).then(() => location.reload());
}
</script>

<!-- =================================================
     MODAL INPUT REIMBURSEMENT
     ================================================= -->
<div id="reimModal" class="modal-overlay" style="display:none;">
    <div class="modal-box">

        <h3>Input Reimbursement</h3>

        <form method="POST"
            action="reimbursement_action.php"
            class="form"
            enctype="multipart/form-data">

            <input type="hidden"
                name="reimbursement_code"
                value="REIMB-<?= date('Ymd-His') ?>">

            <label>Sumber Tagihan</label>
            <select name="billing_source_type" required>
                <option value="instansi">Instansi</option>
                <option value="restoran">Restoran</option>
                <option value="toko">Toko</option>
                <option value="vendor">Vendor</option>
                <option value="lainnya">Lainnya</option>
            </select>

            <label>Nama Sumber</label>
            <input type="text" name="billing_source_name" required>

            <label>Nama Item</label>
            <input type="text" name="item_name" required>

            <div class="row-form-2">
                <div>
                    <label>Qty</label>
                    <input type="number" name="qty" value="1" min="1" required>
                </div>
                <div>
                    <label>Harga</label>
                    <input type="number" name="price" min="0" required>
                </div>
            </div>

            <!-- FILE UPLOAD STYLE (SETTING_AKUN) -->
            <div class="doc-upload-wrapper">
                <div class="doc-upload-header">
                    <label class="doc-label">Bukti Pembayaran</label>
                    <span class="badge-muted-mini">PNG / JPG</span>
                </div>

                <div class="doc-upload-input">
                    <label for="receipt_file" class="file-upload-label">
                        <span class="file-icon">📁</span>
                        <span class="file-text">
                            <strong>Pilih file</strong>
                            <small>PNG atau JPG</small>
                        </span>
                    </label>
                    <input type="file"
                        id="receipt_file"
                        name="receipt_file"
                        accept="image/png,image/jpeg"
                        style="display:none;">
                    <div class="file-selected-name" data-for="receipt_file"></div>
                </div>
            </div>

            <div class="modal-actions">
                <button type="button" class="btn-secondary btn-cancel">Batal</button>
                <button type="submit" class="btn-success">Simpan</button>
            </div>

        </form>
    </div>
</div>

<script>
document.getElementById('rangeSelect')?.addEventListener('change', function () {
    const range = this.value;

    const startHidden = document.getElementById('startDate');
    const endHidden   = document.getElementById('endDate');

    const customStart = document.getElementById('customStart');
    const customEnd   = document.getElementById('customEnd');

    const wrapStart = document.getElementById('customDateWrapper');
    const wrapEnd   = document.getElementById('customDateWrapperEnd');

    const today = new Date();
    let start, end;

    function format(d) {
        return d.toISOString().slice(0, 10);
    }

    wrapStart.style.display = 'none';
    wrapEnd.style.display = 'none';

    if (range === 'custom') {
        startHidden.value = '';
        endHidden.value = '';
        wrapStart.style.display = 'block';
        wrapEnd.style.display = 'block';
        customStart.focus();
        return;
    }

    if (range === 'this_week') {
        const day = today.getDay() || 7;
        start = new Date(today);
        start.setDate(today.getDate() - day + 1);
        end = new Date(start);
        end.setDate(start.getDate() + 6);
    }

    if (range === 'last_week') {
        const day = today.getDay() || 7;
        end = new Date(today);
        end.setDate(today.getDate() - day);
        start = new Date(end);
        start.setDate(end.getDate() - 6);
    }

    if (range === '2_weeks') {
        start = new Date(today);
        start.setDate(today.getDate() - 14);
        end = today;
    }

    if (range === '3_weeks') {
        start = new Date(today);
        start.setDate(today.getDate() - 21);
        end = today;
    }

    if (start && end) {
        startHidden.value = format(start);
        endHidden.value   = format(end);
    }
});

document.getElementById('customStart')?.addEventListener('change', function () {
    document.getElementById('startDate').value = this.value;
});
document.getElementById('customEnd')?.addEventListener('change', function () {
    document.getElementById('endDate').value = this.value;
});
</script>

<script>
    /* ===============================
   DATATABLES
   =============================== */
    document.addEventListener('DOMContentLoaded', function() {
        if (window.jQuery && jQuery.fn.DataTable) {
            jQuery('#reimTable').DataTable({
                pageLength: 10,
                order: [
                    [5, 'desc']
                ],
                language: {
                    url: 'https://cdn.datatables.net/plug-ins/1.13.8/i18n/id.json'
                }
            });
        }
    });

    /* ===============================
       FILE NAME DISPLAY (SETTING_AKUN STYLE)
       =============================== */
    document.addEventListener('DOMContentLoaded', function() {
        document.querySelectorAll('input[type="file"]').forEach(input => {
            input.addEventListener('change', function() {
                const display = document.querySelector(
                    '.file-selected-name[data-for="' + this.id + '"]'
                );
                if (!display) return;

                if (this.files.length > 0) {
                    const f = this.files[0];
                    display.innerHTML = `
                    <span class="selected-file-info">
                        <strong>${f.name}</strong>
                        <small>${(f.size / 1024).toFixed(1)} KB</small>
                    </span>
                `;
                    display.style.display = 'flex';
                } else {
                    display.style.display = 'none';
                }
            });
        });
    });

    /* ===============================
       MODAL HANDLER
       =============================== */
    document.addEventListener('DOMContentLoaded', function() {
        const modal = document.getElementById('reimModal');
        const btnOpen = document.getElementById('btnAddReim');

        btnOpen.addEventListener('click', () => {
            modal.style.display = 'flex';
            document.body.classList.add('modal-open');
        });

        document.body.addEventListener('click', function(e) {
            if (
                e.target.classList.contains('modal-overlay') ||
                e.target.closest('.btn-cancel')
            ) {
                modal.style.display = 'none';
                document.body.classList.remove('modal-open');
            }
        });

        document.addEventListener('keydown', function(e) {
            if (e.key === 'Escape') {
                modal.style.display = 'none';
                document.body.classList.remove('modal-open');
            }
        });
    });

    /* ===============================
       PAY REIMBURSEMENT
       =============================== */
    function payReimbursement(code) {
        if (!confirm('Tandai reimbursement ini sebagai DIBAYARKAN?')) return;

        fetch('reimbursement_pay.php', {
            method: 'POST',
            headers: {
                'Content-Type': 'application/x-www-form-urlencoded'
            },
            body: 'code=' + encodeURIComponent(code)
        }).then(() => location.reload());
    }
</script>

<!-- ======================================
     MODAL PREVIEW BUKTI PEMBAYARAN
     ====================================== -->
<div id="docPreviewModal" class="modal-overlay" style="display:none;">
    <div class="modal-card" style="max-width:900px;">

        <!-- HEADER -->
        <div class="modal-header">
            <strong id="docPreviewTitle">📄 Bukti Pembayaran</strong>
            <div style="display:flex;gap:8px;align-items:center;">
                <button type="button" class="zoom-control-btn" id="docZoomOut">➖</button>
                <button type="button" class="zoom-control-btn" id="docZoomIn">➕</button>
                <button type="button" class="zoom-control-btn" id="docZoomReset">🔄</button>
                <button type="button" onclick="closeDocModal()">✕</button>
            </div>
        </div>

        <!-- BODY -->
        <div class="modal-body"
            style="background:#f8fafc;display:flex;align-items:center;justify-content:center;min-height:60vh;">
            <img id="docPreviewImage"
                src=""
                alt="Bukti Pembayaran"
                style="max-width:100%;max-height:75vh;object-fit:contain;transition:transform 0.2s ease;">
        </div>

    </div>
</div>

<script>
    document.addEventListener('DOMContentLoaded', function() {
        const modal = document.getElementById('docPreviewModal');
        const img = document.getElementById('docPreviewImage');
        const title = document.getElementById('docPreviewTitle');

        let scale = 1;
        let currentSrc = '';

        document.body.addEventListener('click', function(e) {
            const btn = e.target.closest('.btn-preview-doc');
            if (!btn) return;

            e.preventDefault();

            currentSrc = btn.dataset.src;
            img.src = currentSrc;
            title.textContent = btn.dataset.title || 'Bukti Pembayaran';

            scale = 1;
            img.style.transform = 'scale(1)';

            modal.style.display = 'flex';
            document.body.classList.add('modal-open');
        });

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
            if (currentSrc) img.src = currentSrc + '?v=' + Date.now();
        };
    });
</script>


<?php include __DIR__ . '/../partials/footer.php'; ?>