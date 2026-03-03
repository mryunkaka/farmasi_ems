<?php
ini_set('log_errors', 1);
ini_set('display_errors', 0);
ini_set('error_log', __DIR__ . '/error_log.txt');

// -------------------------------------------
// Konfigurasi timezone (WITA / UTC+8)
// -------------------------------------------
date_default_timezone_set('Asia/Jakarta');

// -------------------------------------------
// Session & Cookie untuk petugas medis
// -------------------------------------------
session_start();

// -------------------------------------------
// Konfigurasi database
// -------------------------------------------
require_once __DIR__ . '/../config/database.php';

// -------------------------------------------
// File konfigurasi spreadsheet (JSON)
// -------------------------------------------
$configFile = __DIR__ . '/sheet_config.json';

// Default (boleh disesuaikan)
$sheetConfig = [
    'spreadsheet_id' => '1300EqaCtHs8PrHKepzEQRk-ALwtfh1FcBAeaW95XKWU',
    'sheet_gid'      => '1891016011',
];

// Jika file config ada, pakai yang tersimpan
if (file_exists($configFile)) {
    $json = file_get_contents($configFile);
    $data = json_decode($json, true);
    if (is_array($data)) {
        $sheetConfig = array_merge($sheetConfig, $data);
    }
}

// Ambil flash message dari session (kalau ada), lalu hapus
$messages  = $_SESSION['flash_messages'] ?? [];
$warnings  = $_SESSION['flash_warnings'] ?? [];
$errors    = $_SESSION['flash_errors']   ?? [];
unset($_SESSION['flash_messages'], $_SESSION['flash_warnings'], $_SESSION['flash_errors']);

// Flag untuk tahu apakah form harus dikosongkan (setelah transaksi sukses)
$shouldClearForm = !empty($_SESSION['clear_form'] ?? false);
unset($_SESSION['clear_form']);

// Flag sementara untuk request POST saat ini
$clearFormNextLoad = false;

$medicName    = $_COOKIE['medic_name']    ?? '';
$medicJabatan = $_COOKIE['medic_jabatan'] ?? '';

// Tanggal lokal hari ini (WITA)
$todayDate = date('Y-m-d');

// Flag untuk PRG
$redirectAfterPost = false;

// ======================================================
// HANDLE REQUEST POST (SEMUA ACTION FORM DI SINI)
// ======================================================
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';

    // 0) Set Spreadsheet (Spreadsheet ID & GID)
    if ($action === 'set_sheet') {
        $spreadsheetId = trim($_POST['spreadsheet_id'] ?? '');
        $sheetGid      = trim($_POST['sheet_gid'] ?? '');

        if ($spreadsheetId === '' || $sheetGid === '') {
            $errors[] = "Spreadsheet ID dan Sheet GID wajib diisi.";
        } else {
            $data = [
                'spreadsheet_id' => $spreadsheetId,
                'sheet_gid'      => $sheetGid,
            ];
            if (!is_writable(__DIR__)) {
                $errors[] = "Folder backup tidak memiliki izin tulis.";
            }
            // Simpan ke file JSON
            if (@file_put_contents($configFile, json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE)) === false) {
                $errors[] = "Gagal menyimpan konfigurasi spreadsheet ke file.";
            } else {
                $sheetConfig = $data;
                $messages[]  = "Konfigurasi spreadsheet berhasil disimpan.";
            }
        }

        $redirectAfterPost = true;
    }

    // 1) Set / update nama petugas medis
    if ($action === 'set_medic') {
        $name    = trim($_POST['medic_name'] ?? '');
        $jabatan = trim($_POST['medic_jabatan'] ?? '');

        if ($name === '' || $jabatan === '') {
            $errors[] = "Nama petugas dan jabatan wajib diisi.";
        } else {
            // Simpan ke cookie 1 tahun
            setcookie('medic_name', $name, time() + 365 * 24 * 60 * 60, '/');
            setcookie('medic_jabatan', $jabatan, time() + 365 * 24 * 60 * 60, '/');
            $medicName    = $name;
            $medicJabatan = $jabatan;
            $messages[]   = "Petugas aktif diset ke: {$medicName} ({$medicJabatan}).";
        }

        $redirectAfterPost = true;
    }

    // 2) Akhiri session petugas
    if ($action === 'end_medic') {
        setcookie('medic_name', '', time() - 3600, '/');
        setcookie('medic_jabatan', '', time() - 3600, '/');
        $medicName    = '';
        $medicJabatan = '';
        $messages[]   = "Session petugas medis telah diakhiri. Silakan isi nama baru.";

        $redirectAfterPost = true;
    }

    // 2.6) Hapus banyak transaksi (checkbox + bulk delete)
    if ($action === 'delete_selected') {
        if ($medicName === '' || $medicJabatan === '') {
            $errors[] = "Anda harus login sebagai petugas medis untuk menghapus transaksi.";
        } else {
            $ids = $_POST['sale_ids'] ?? [];
            if (!is_array($ids) || empty($ids)) {
                $errors[] = "Tidak ada transaksi yang dipilih untuk dihapus.";
            } else {
                // Sanitasi -> integer > 0
                $cleanIds = [];
                foreach ($ids as $id) {
                    $id = (int)$id;
                    if ($id > 0) {
                        $cleanIds[] = $id;
                    }
                }

                if (empty($cleanIds)) {
                    $errors[] = "ID transaksi tidak valid.";
                } else {
                    $placeholders = implode(',', array_fill(0, count($cleanIds), '?'));
                    $params       = $cleanIds;
                    $params[]     = $medicName;

                    $stmtDel = $pdo->prepare("
                        DELETE FROM sales
                        WHERE id IN ($placeholders)
                          AND medic_name = ?
                    ");
                    $stmtDel->execute($params);
                    $deleted = $stmtDel->rowCount();

                    if ($deleted > 0) {
                        $messages[] = "{$deleted} transaksi berhasil dihapus (sesuai hak akses Anda).";
                    } else {
                        $errors[] = "Tidak ada transaksi yang dapat dihapus. Pastikan transaksi milik Anda.";
                    }
                }
            }
        }

        $redirectAfterPost = true;
    }

    // 3) Tambah transaksi penjualan (bisa beberapa paket sekaligus)
    if ($action === 'add_sale') {
        // Pastikan ada petugas aktif
        if ($medicName === '' || $medicJabatan === '') {
            $errors[] = "Set dulu nama petugas medis sebelum input transaksi.";
        } else {
            $consumerName   = trim($_POST['consumer_name'] ?? '');
            $pkgMainId      = (int)($_POST['package_main'] ?? 0);       // Paket A / B
            $pkgBandageId   = (int)($_POST['package_bandage'] ?? 0);    // Paket Bandage
            $pkgIfaksId     = (int)($_POST['package_ifaks'] ?? 0);      // Paket IFAKS
            $pkgPainId      = (int)($_POST['package_painkiller'] ?? 0); // Paket Painkiller

            // Flag dari front-end: apakah user setuju override batas harian
            $forceOverLimit = isset($_POST['force_overlimit']) && $_POST['force_overlimit'] === '1';

            if ($consumerName === '') {
                $errors[] = "Nama konsumen wajib diisi.";
            }

            // Kumpulkan paket yang dipilih (boleh kombinasi, nanti dicek batas harian)
            $selectedIds = [];

            if ($pkgMainId > 0)     $selectedIds[] = $pkgMainId;      // Paket A / B
            if ($pkgBandageId > 0)  $selectedIds[] = $pkgBandageId;   // Paket Bandage
            if ($pkgIfaksId > 0)    $selectedIds[] = $pkgIfaksId;     // Paket IFAKS
            if ($pkgPainId > 0)     $selectedIds[] = $pkgPainId;      // Paket Painkiller

            if (empty($selectedIds) && empty($errors)) {
                $errors[] = "Pilih minimal satu paket.";
            }

            if (empty($errors)) {
                // Ambil detail semua paket yang dipilih
                $placeholders = implode(',', array_fill(0, count($selectedIds), '?'));
                $stmt = $pdo->prepare("SELECT * FROM packages WHERE id IN ($placeholders)");
                $stmt->execute($selectedIds);
                $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

                // Index paket by id
                $packagesSelected = [];
                foreach ($rows as $row) {
                    $packagesSelected[(int)$row['id']] = $row;
                }

                // Pastikan semua id valid
                foreach ($selectedIds as $id) {
                    if (!isset($packagesSelected[$id])) {
                        $errors[] = "Ada paket yang tidak ditemukan di database.";
                        break;
                    }
                }
            }

            if (empty($errors)) {
                // Hitung total tambahan dari semua paket yang dipilih
                $addBandage = 0;
                $addIfaks   = 0;
                $addPain    = 0;

                foreach ($selectedIds as $id) {
                    $p = $packagesSelected[$id];
                    $addBandage += (int)$p['bandage_qty'];
                    $addIfaks   += (int)$p['ifaks_qty'];
                    $addPain    += (int)$p['painkiller_qty'];
                    $txHash = hash('sha256', uniqid('tx_', true));
                }

                // Ambil total pembelian hari ini (pakai tanggal lokal WITA)
                $stmt = $pdo->prepare("
                    SELECT 
                        COALESCE(SUM(qty_bandage),0)    AS total_bandage,
                        COALESCE(SUM(qty_ifaks),0)      AS total_ifaks,
                        COALESCE(SUM(qty_painkiller),0) AS total_painkiller
                    FROM sales
                    WHERE consumer_name = :name
                      AND DATE(created_at) = :today
                ");
                $stmt->execute([
                    ':name'  => $consumerName,
                    ':today' => $todayDate,
                ]);
                $totals = $stmt->fetch(PDO::FETCH_ASSOC) ?: [
                    'total_bandage'    => 0,
                    'total_ifaks'      => 0,
                    'total_painkiller' => 0,
                ];

                $newTotalBandage    = $totals['total_bandage']    + $addBandage;
                $newTotalIfaks      = $totals['total_ifaks']      + $addIfaks;
                $newTotalPainkiller = $totals['total_painkiller'] + $addPain;

                // Batas harian
                $maxBandage    = 30;
                $maxIfaks      = 10;
                $maxPainkiller = 10;

                $overLimit = false;

                if ($newTotalBandage > $maxBandage) {
                    $warnings[] = "⚠️ {$consumerName} sudah melebihi batas harian BANDAGE ({$newTotalBandage}/{$maxBandage}).";
                    $overLimit = true;
                }
                if ($newTotalIfaks > $maxIfaks) {
                    $warnings[] = "⚠️ {$consumerName} sudah melebihi batas harian IFAKS ({$newTotalIfaks}/{$maxIfaks}).";
                    $overLimit = true;
                }
                if ($newTotalPainkiller > $maxPainkiller) {
                    $warnings[] = "⚠️ {$consumerName} sudah melebihi batas harian PAINKILLER ({$newTotalPainkiller}/{$maxPainkiller}).";
                    $overLimit = true;
                }

                if ($overLimit && !$forceOverLimit) {
                    // Batas harian terlewati, tapi user TIDAK setuju override → batal
                    $errors[] = "Transaksi untuk {$consumerName} dibatalkan karena melebihi batas harian.";
                } else {
                    // Waktu sekarang (WITA)
                    $now = date('Y-m-d H:i:s');

                    // Insert 1 row per paket yang dipilih
                    $stmtInsert = $pdo->prepare("
                        INSERT INTO sales 
                        (tx_hash, consumer_name, medic_name, medic_jabatan, 
                        package_id, package_name, price, 
                        qty_bandage, qty_ifaks, qty_painkiller, created_at)
                        VALUES
                        (:tx, :cname, :mname, :mjab,
                        :pid, :pname, :price,
                        :qb, :qi, :qp, :created_at)
                    ");

                    foreach ($selectedIds as $id) {
                        $p = $packagesSelected[$id];

                        // WAJIB: tx_hash unik per row
                        $txHash = bin2hex(random_bytes(16)); // lebih aman dari uniqid

                        $stmtInsert->execute([
                            ':tx'         => $txHash, // ⬅️ INI YANG SELAMA INI HILANG
                            ':cname'      => $consumerName,
                            ':mname'      => $medicName,
                            ':mjab'       => $medicJabatan,
                            ':pid'        => (int)$p['id'],
                            ':pname'      => $p['name'],
                            ':price'      => (int)$p['price'],
                            ':qb'         => (int)$p['bandage_qty'],
                            ':qi'         => (int)$p['ifaks_qty'],
                            ':qp'         => (int)$p['painkiller_qty'],
                            ':created_at' => $now,
                        ]);
                    }

                    if ($overLimit && $forceOverLimit) {
                        // Disimpan walaupun lewat batas
                        $warnings[] = "Transaksi untuk {$consumerName} tetap disimpan walaupun melebihi batas harian (override oleh petugas).";
                    } else {
                        $messages[] = "Transaksi untuk {$consumerName} berhasil disimpan (" . count($selectedIds) . " paket).";
                    }

                    $clearFormNextLoad = true; // tandai bahwa form boleh dikosongkan setelah redirect
                }
            }
        }

        $redirectAfterPost = true;
    }
}

// ======================================================
// POST-REDIRECT-GET: lakukan redirect setelah POST
// ======================================================
if ($redirectAfterPost) {
    // simpan pesan ke session agar bisa ditampilkan setelah redirect
    $_SESSION['flash_messages'] = $messages;
    $_SESSION['flash_warnings'] = $warnings;
    $_SESSION['flash_errors']   = $errors;

    // kalau transaksi barusan sukses, beri tanda untuk kosongkan form setelah redirect
    if ($clearFormNextLoad) {
        $_SESSION['clear_form'] = true;
    }

    // redirect ke URL yang sama tapi dengan GET (tanpa resubmit)
    $redirectUrl = $_SERVER['REQUEST_URI'] ?? '/backup/rekap.php';
    $redirectUrl = strtok($redirectUrl, '#');
    header('Location: ' . $redirectUrl);
    exit;
}


// -------------------------------------------
// Data untuk tampilan: paket & konsumen
// -------------------------------------------

// Ambil semua paket
$packages = $pdo->query("SELECT * FROM packages ORDER BY name ASC")->fetchAll(PDO::FETCH_ASSOC);

// Kelompokkan paket menjadi 4 kategori
$paketAB            = []; // Paket A / B (combo)
$bandagePackages    = [];
$ifaksPackages      = [];
$painkillerPackages = [];

$packagesById = [];

foreach ($packages as $p) {
    $id   = (int)$p['id'];
    $name = strtoupper($p['name']);

    $packagesById[$id] = [
        'name'       => $p['name'],
        'price'      => (int)$p['price'],
        'bandage'    => (int)$p['bandage_qty'],
        'ifaks'      => (int)$p['ifaks_qty'],
        'painkiller' => (int)$p['painkiller_qty'],
    ];

    if (strpos($name, 'PAKET A') === 0 || strpos($name, 'PAKET B') === 0) {
        $paketAB[] = $p;
    } elseif ($p['bandage_qty'] > 0 && $p['ifaks_qty'] == 0 && $p['painkiller_qty'] == 0) {
        $bandagePackages[] = $p;
    } elseif ($p['ifaks_qty'] > 0 && $p['bandage_qty'] == 0 && $p['painkiller_qty'] == 0) {
        $ifaksPackages[] = $p;
    } elseif ($p['painkiller_qty'] > 0 && $p['bandage_qty'] == 0 && $p['ifaks_qty'] == 0) {
        $painkillerPackages[] = $p;
    }
}

// Ambil nama konsumen unik untuk datalist
$consumerNames = $pdo->query("
    SELECT DISTINCT consumer_name 
    FROM sales 
    ORDER BY consumer_name ASC
")->fetchAll(PDO::FETCH_COLUMN);

// Totals harian per konsumen untuk limit-check di JS
$stmtDailyTotals = $pdo->prepare("
    SELECT consumer_name,
           COALESCE(SUM(qty_bandage),0)    AS total_bandage,
           COALESCE(SUM(qty_ifaks),0)      AS total_ifaks,
           COALESCE(SUM(qty_painkiller),0) AS total_painkiller
    FROM sales
    WHERE DATE(created_at) = :today
    GROUP BY consumer_name
");
$stmtDailyTotals->execute([':today' => $todayDate]);
$dailyTotalsRows = $stmtDailyTotals->fetchAll(PDO::FETCH_ASSOC);

$dailyTotalsJS = [];
foreach ($dailyTotalsRows as $row) {
    $key = mb_strtolower(trim($row['consumer_name']));
    $dailyTotalsJS[$key] = [
        'bandage'    => (int)$row['total_bandage'],
        'ifaks'      => (int)$row['total_ifaks'],
        'painkiller' => (int)$row['total_painkiller'],
    ];
}

// Detail transaksi harian per konsumen (untuk ditampilkan di warning JS)
$stmtDailyDetail = $pdo->prepare("
    SELECT consumer_name, medic_name, package_name, created_at,
           qty_bandage, qty_ifaks, qty_painkiller
    FROM sales
    WHERE DATE(created_at) = :today
    ORDER BY created_at ASC
");
$stmtDailyDetail->execute([':today' => $todayDate]);
$detailRows = $stmtDailyDetail->fetchAll(PDO::FETCH_ASSOC);

$dailyDetailJS = [];
foreach ($detailRows as $row) {
    $key = mb_strtolower(trim($row['consumer_name']));
    if (!isset($dailyDetailJS[$key])) {
        $dailyDetailJS[$key] = [];
    }
    $dailyDetailJS[$key][] = [
        'medic'      => $row['medic_name'],
        'package'    => $row['package_name'],
        'time'       => $row['created_at'],
        'bandage'    => (int)$row['qty_bandage'],
        'ifaks'      => (int)$row['qty_ifaks'],
        'painkiller' => (int)$row['qty_painkiller'],
    ];
}

// -------------------------------------------
// FILTER TANGGAL (GET)
// -------------------------------------------
$validRanges = ['today', 'yesterday', 'last7', 'week1', 'week2', 'week3', 'week4', 'month', 'custom'];
$range       = $_GET['range'] ?? 'today';
if (!in_array($range, $validRanges, true)) {
    $range = 'today';
}

$fromInput = $_GET['from'] ?? '';
$toInput   = $_GET['to']   ?? '';

// Mode tampilan transaksi: default hanya medis aktif, bisa ubah jadi semua medis
$showAll = isset($_GET['show_all']) && $_GET['show_all'] === '1';

// Hitung rentang tanggal
$now          = new DateTime(); // timezone WITA
$year         = (int)$now->format('Y');
$month        = (int)$now->format('m');
$firstOfMonth = new DateTime(sprintf('%04d-%02d-01 00:00:00', $year, $month));
$lastOfMonth  = (clone $firstOfMonth);
$lastOfMonth->modify('last day of this month')->setTime(23, 59, 59);

switch ($range) {
    case 'yesterday':
        // 1 hari sebelumnya, tanpa hari ini
        $startDT = new DateTime($todayDate . ' 00:00:00');
        $startDT->modify('-1 day');
        $endDT = new DateTime($todayDate . ' 23:59:59');
        $endDT->modify('-1 day');
        break;

    case 'last7':
        // Mundur 1–7 hari sebelumnya (7 hari terakhir TANPA hari ini)
        // Misal hari ini 10 → range 3 s/d 9
        $endDT = new DateTime($todayDate . ' 23:59:59');
        $endDT->modify('-1 day');          // kemarin
        $startDT = clone $endDT;
        $startDT->modify('-6 days');       // 6 hari sebelum kemarin → total 7 hari
        break;
    case 'week1':
        $startDT = clone $firstOfMonth;
        $endDT   = new DateTime(sprintf('%04d-%02d-%02d 23:59:59', $year, $month, min(7, (int)$lastOfMonth->format('d'))));
        break;
    case 'week2':
        $startDT = new DateTime(sprintf('%04d-%02d-08 00:00:00', $year, $month));
        $endDay  = min(14, (int)$lastOfMonth->format('d'));
        $endDT   = new DateTime(sprintf('%04d-%02d-%02d 23:59:59', $year, $month, $endDay));
        break;
    case 'week3':
        $startDT = new DateTime(sprintf('%04d-%02d-15 00:00:00', $year, $month));
        $endDay  = min(21, (int)$lastOfMonth->format('d'));
        $endDT   = new DateTime(sprintf('%04d-%02d-%02d 23:59:59', $year, $month, $endDay));
        break;
    case 'week4':
        $startDT = new DateTime(sprintf('%04d-%02d-22 00:00:00', $year, $month));
        $endDT   = clone $lastOfMonth;
        break;
    case 'month':
        $startDT = clone $firstOfMonth;
        $endDT   = clone $lastOfMonth;
        break;
    case 'custom':
        if ($fromInput && $toInput) {
            try {
                $startDT = new DateTime($fromInput . ' 00:00:00');
                $endDT   = new DateTime($toInput . ' 23:59:59');
                if ($endDT < $startDT) {
                    $tmp     = $startDT;
                    $startDT = $endDT;
                    $endDT   = $tmp;
                }
            } catch (Exception $e) {
                $startDT = new DateTime($todayDate . ' 00:00:00');
                $endDT   = new DateTime($todayDate . ' 23:59:59');
            }
        } else {
            $startDT = new DateTime($todayDate . ' 00:00:00');
            $endDT   = new DateTime($todayDate . ' 23:59:59');
        }
        break;
    case 'today':
    default:
        $startDT = new DateTime($todayDate . ' 00:00:00');
        $endDT   = new DateTime($todayDate . ' 23:59:59');
        break;
}

$rangeStart = $startDT->format('Y-m-d H:i:s');
$rangeEnd   = $endDT->format('Y-m-d H:i:s');

// Label range untuk ditampilkan
$rangeLabel = $startDT->format('Y-m-d') . " s/d " . $endDT->format('Y-m-d');

// -------------------------------------------
// EXPORT EXCEL (HTML TABLE → DIBUKA DI EXCEL)
// -------------------------------------------
$export = $_GET['export'] ?? '';

if ($export === 'medic' && $medicName === '') {
    $errors[] = "Set dulu nama petugas medis sebelum export khusus medis.";
    $export = '';
}

if ($export === 'all' || $export === 'medic') {
    // Query data sesuai filter tanggal (rangeStart & rangeEnd sudah dihitung di atas)
    $sql = "
        SELECT consumer_name, medic_name, medic_jabatan, package_name, price
        FROM sales
        WHERE created_at BETWEEN :start AND :end
    ";

    $params = [
        ':start' => $rangeStart,
        ':end'   => $rangeEnd,
    ];

    if ($export === 'medic') {
        $sql .= " AND medic_name = :mname";
        $params[':mname'] = $medicName;
    }

    $sql .= " ORDER BY created_at ASC";

    $stmtExport = $pdo->prepare($sql);
    $stmtExport->execute($params);
    $rowsExport = $stmtExport->fetchAll(PDO::FETCH_ASSOC);

    $fileSuffix = $export === 'all' ? 'global' : 'medis';
    $fileName   = 'rekap_farmasi_' . $fileSuffix . '_' . date('Ymd_His') . '.xls';

    header('Content-Type: application/vnd.ms-excel; charset=utf-8');
    header('Content-Disposition: attachment; filename="' . $fileName . '"');

?>
    <!DOCTYPE html>
    <html>

    <head>
        <meta charset="UTF-8">
        <title>Rekap Farmasi EMS</title>
    </head>

    <body>
        <table border="1" cellspacing="0" cellpadding="4">
            <?php foreach ($rowsExport as $row): ?>
                <tr>
                    <td><?= htmlspecialchars($row['consumer_name'] ?? '') ?></td>
                    <td><?= htmlspecialchars($row['medic_name'] ?? '') ?></td>
                    <td><?= htmlspecialchars($row['medic_jabatan'] ?? '') ?></td>
                    <td><?= htmlspecialchars($row['package_name'] ?? '') ?></td>
                    <td><?= (int)($row['price'] ?? 0) ?></td>
                </tr>
            <?php endforeach; ?>
        </table>
    </body>

    </html>
<?php
    exit;
}

// Ambil data transaksi sesuai filter tanggal.
// Default: hanya transaksi milik medis aktif (session).
// Jika ?show_all=1 → tampilkan semua medis.
$sqlSales = "
    SELECT * FROM sales
    WHERE created_at BETWEEN :start AND :end
";

$paramsSales = [
    ':start' => $rangeStart,
    ':end'   => $rangeEnd,
];

// Kalau TIDAK show_all dan ada medis aktif → filter berdasarkan medic_name
if (!$showAll && $medicName !== '') {
    $sqlSales .= " AND medic_name = :mname";
    $paramsSales[':mname'] = $medicName;
}

$sqlSales .= " ORDER BY created_at DESC";

$stmtSales = $pdo->prepare($sqlSales);
$stmtSales->execute($paramsSales);
$filteredSales = $stmtSales->fetchAll(PDO::FETCH_ASSOC);

// Ranking 10 medis berdasarkan filter tanggal
$stmtRank = $pdo->prepare("
    SELECT 
        medic_name,
        medic_jabatan,
        COUNT(*) AS total_transaksi,

        -- Hitung berapa kali jual Paket A & Paket B (per-row)
        SUM(CASE WHEN package_name LIKE 'Paket A%' THEN 1 ELSE 0 END) AS total_paket_a,
        SUM(CASE WHEN package_name LIKE 'Paket B%' THEN 1 ELSE 0 END) AS total_paket_b,

        -- Akumulasi item fisik yang keluar
        SUM(qty_bandage)    AS total_bandage,
        SUM(qty_ifaks)      AS total_ifaks,
        SUM(qty_painkiller) AS total_painkiller,

        -- Total item (bandage+ifaks+painkiller) untuk ranking
        SUM(qty_bandage + qty_ifaks + qty_painkiller) AS total_item,
        SUM(price) AS total_rupiah
    FROM sales
    WHERE created_at BETWEEN :start AND :end
    GROUP BY medic_name, medic_jabatan
    ORDER BY total_item DESC, total_rupiah DESC
    LIMIT 10
");

$stmtRank->execute([
    ':start' => $rangeStart,
    ':end'   => $rangeEnd,
]);
$medicRanking = $stmtRank->fetchAll(PDO::FETCH_ASSOC);

// Rekapan bonus: selalu berdasarkan medis aktif (session)
$singleMedicStats = null;

if ($medicName !== '') {
    $stmtSingle = $pdo->prepare("
        SELECT 
            medic_name,
            medic_jabatan,
            COUNT(*) AS total_transaksi,
            SUM(qty_bandage + qty_ifaks + qty_painkiller) AS total_item,
            SUM(price) AS total_rupiah
        FROM sales
        WHERE created_at BETWEEN :start AND :end
          AND medic_name = :mname
        GROUP BY medic_name, medic_jabatan
        LIMIT 1
    ");
    $stmtSingle->execute([
        ':start' => $rangeStart,
        ':end'   => $rangeEnd,
        ':mname' => $medicName,
    ]);
    $singleMedicStats = $stmtSingle->fetch(PDO::FETCH_ASSOC) ?: null;
}

// Untuk form custom date supaya tetap isi
$fromDateInput = $range === 'custom' ? $startDT->format('Y-m-d') : $fromInput;
$toDateInput   = $range === 'custom' ? $endDT->format('Y-m-d')   : $toInput;

$currentCsvUrl = sprintf(
    'https://docs.google.com/spreadsheets/d/%s/export?format=csv&gid=%s',
    $sheetConfig['spreadsheet_id'],
    $sheetConfig['sheet_gid']
);

$sheetEditUrl = sprintf(
    'https://docs.google.com/spreadsheets/d/%s/edit?gid=%s#gid=%s',
    $sheetConfig['spreadsheet_id'],
    $sheetConfig['sheet_gid'],
    $sheetConfig['sheet_gid']
);
?>
<!DOCTYPE html>
<html lang="id">

<head>
    <meta charset="UTF-8">
    <title>Rekap Farmasi EMS</title>
    <meta name="viewport" content="width=device-width, initial-scale=1">

    <!-- DataTables CSS -->
    <link rel="stylesheet" href="https://cdn.datatables.net/1.13.8/css/jquery.dataTables.min.css">

    <style>
        :root {
            --bg-body: #050816;
            --bg-card: #0f172a;
            --border-card: #1e293b;
            --text-main: #e5e7eb;
            --text-muted: #9ca3af;
        }

        * {
            box-sizing: border-box;
        }

        body {
            font-family: system-ui, -apple-system, BlinkMacSystemFont, sans-serif;
            background: var(--bg-body);
            color: var(--text-main);
            margin: 0;
            padding: 12px;
        }

        .container {
            max-width: 1120px;
            margin: 0 auto;
            padding: 0 8px;
        }

        h1 {
            font-size: 24px;
            margin-bottom: 4px;
        }

        .card {
            background: var(--bg-card);
            border: 1px solid var(--border-card);
            border-radius: 10px;
            padding: 14px;
            margin-bottom: 14px;
        }

        .card-header {
            font-weight: 600;
            margin-bottom: 8px;
        }

        .btn-version-2 {
            background: #1d4ed8;
            color: #ffffff;
            padding: 8px 14px;
            border-radius: 8px;
            font-size: 14px;
            font-weight: 500;
            text-decoration: none;
            white-space: nowrap;
            display: inline-flex;
            align-items: center;
            justify-content: center;
            transition: background 0.2s ease;
        }

        .btn-version-2:hover {
            background: #2563eb;
        }

        label {
            display: block;
            font-size: 14px;
            margin-bottom: 4px;
        }

        input[type="text"],
        input[type="date"],
        select {
            width: 100%;
            padding: 6px 8px;
            border-radius: 6px;
            border: 1px solid #1f2933;
            background: #020617;
            color: #e5e7eb;
            box-sizing: border-box;
            font-size: 13px;
        }

        button {
            padding: 6px 12px;
            border-radius: 6px;
            border: none;
            cursor: pointer;
            font-size: 14px;
        }

        .btn-primary {
            background: #2563eb;
            color: white;
        }

        .btn-danger {
            background: #dc2626;
            color: white;
        }

        .btn-secondary {
            background: #12cd1fff;
            color: white;
        }

        .row {
            display: flex;
            flex-wrap: wrap;
            gap: 12px;
        }

        .col {
            flex: 1 1 220px;
        }

        .alert {
            padding: 8px 10px;
            border-radius: 6px;
            margin-bottom: 8px;
            font-size: 13px;
        }

        .alert-info {
            background: #0ea5e922;
            border: 1px solid #0ea5e955;
        }

        .alert-warning {
            background: #f9731622;
            border: 1px solid #f9731655;
        }

        .alert-error {
            background: #dc262622;
            border: 1px solid #dc262655;
        }

        table {
            width: 100%;
            border-collapse: collapse;
            font-size: 13px;
            min-width: 600px;
        }

        th,
        td {
            border: 1px solid #1f2937;
            padding: 6px;
            text-align: left;
            vertical-align: middle;
        }

        th {
            background: #020617;
            font-weight: 600;
        }

        /* Wrapper tabel agar di HP scroll-nya cuma tabel, bukan seluruh halaman */
        .table-wrapper {
            overflow-x: auto;
            -webkit-overflow-scrolling: touch;
        }

        .table-wrapper table {
            min-width: 650px;
        }

        .table-wrapper-sm table {
            min-width: 500px;
        }

        .total-display {
            margin-top: 10px;
            padding: 10px 12px;
            border-radius: 8px;
            background: #020617;
            border: 1px solid #1f2937;
            display: flex;
            justify-content: space-between;
            align-items: center;
            flex-wrap: wrap;
            gap: 8px;
        }

        .total-display-label {
            font-size: 13px;
            color: var(--text-muted);
        }

        .total-amount {
            font-size: 22px;
            font-weight: 700;
        }

        code {
            font-size: 12px;
            background: #020617;
            padding: 4px 6px;
            border-radius: 4px;
            display: inline-block;
            word-break: break-all;
        }

        small {
            font-size: 12px;
            color: var(--text-muted);
        }

        /* DataTables dark theme tweaks */
        .dataTables_wrapper .dataTables_length label,
        .dataTables_wrapper .dataTables_filter label,
        .dataTables_wrapper .dataTables_info,
        .dataTables_wrapper .dataTables_paginate {
            color: var(--text-main);
        }

        .dataTables_wrapper .dataTables_filter input,
        .dataTables_wrapper .dataTables_length select {
            background: #020617;
            border: 1px solid #1f2937;
            color: var(--text-main);
        }

        .dataTables_wrapper .dataTables_paginate .paginate_button {
            border-radius: 4px;
            border: 1px solid #374151 !important;
            background: #111827 !important;
            color: #e5e7eb !important;
        }

        .dataTables_wrapper .dataTables_paginate .paginate_button.current {
            background: #2563eb !important;
            border-color: #2563eb !important;
        }

        @media (max-width: 768px) {
            .card-header-actions {
                flex-direction: column;
                align-items: stretch;
            }

            .card-header-actions-right {
                width: 100%;
                flex-direction: column;
                gap: 8px;
            }

            .card-header-actions-right a,
            .card-header-actions-right button,
            .card-header-actions-right form {
                width: 100%;
            }

            .card-header-actions-right a {
                display: flex;
                justify-content: center;
                align-items: center;
            }
        }

        @media (max-width: 480px) {
            body {
                padding: 8px;
            }

            .container {
                padding: 0;
            }

            .card {
                padding: 10px;
            }

            .card-header-actions-title {
                font-size: 13px;
            }
        }

        tfoot th {
            background: #020617;
            font-weight: 700;
            border-top: 2px solid #2563eb;
        }

        .card-header-actions {
            display: flex;
            justify-content: space-between;
            align-items: center;
            gap: 12px;
            flex-wrap: wrap;
        }

        .card-header-actions-title {
            font-size: 15px;
            font-weight: 600;
            color: #e5e7eb;
        }

        .card-header-actions-right {
            display: flex;
            align-items: center;
            gap: 10px;
            flex-wrap: wrap;
        }

        /* Tombol kembali */
        .btn-back {
            background: #1d54a1ff;
            color: #e5e7eb;
            padding: 6px 12px;
            border-radius: 6px;
            font-size: 13px;
            text-decoration: none;
            white-space: nowrap;
        }

        .btn-back:hover {
            background: #475569;
        }

        /* Link spreadsheet */
        .link-sheet {
            color: #93c5fd;
            font-size: 13px;
            text-decoration: none;
            white-space: nowrap;
        }

        .link-sheet:hover {
            text-decoration: underline;
        }

        /* Form inline agar sejajar */
        .form-inline {
            margin: 0;
        }
    </style>
</head>

<body>
    <div class="container">
        <h1>Rekap Farmasi EMS</h1>

        <div id="localClock" style="font-size:13px;color:#9ca3af;margin-bottom:6px;">
            <!-- Jam & tanggal realtime akan muncul di sini -->
        </div>

        <p style="font-size:13px;color:#9ca3af;margin-bottom:16px;">
            Input penjualan Bandage / IFAKS / Painkiller dengan batas harian per konsumen.
            <br>
            <small>
                Zona waktu server: <?= htmlspecialchars(date_default_timezone_get()) ?>.
                Jam di atas mengikuti <strong>waktu lokal perangkat</strong> (otomatis WIB / WITA / WIT).
            </small>
            <br>
            <small>
                <!-- Link Spreadsheet -->
                <a href="<?= htmlspecialchars($sheetEditUrl) ?>"
                    target="_blank"
                    class="link-sheet">
                    Klik di sini untuk memastikan Spreadsheets (update)
                </a>
            </small>
        </p>

        <!-- Notifikasi -->
        <?php foreach ($messages as $m): ?>
            <div class="alert alert-info"><?= htmlspecialchars($m) ?></div>
        <?php endforeach; ?>
        <?php foreach ($warnings as $w): ?>
            <div class="alert alert-warning"><?= htmlspecialchars($w) ?></div>
        <?php endforeach; ?>
        <?php foreach ($errors as $e): ?>
            <div class="alert alert-error"><?= htmlspecialchars($e) ?></div>
        <?php endforeach; ?>

        <!-- Card Petugas Medis (hanya muncul jika BELUM ada petugas) -->
        <?php if (!$medicName): ?>
            <!-- Card Set Spreadsheet -->
            <div class="card">
                <div class="card-header">Set Spreadsheet Google Sheets (untuk sync_from_sheet.php)</div>
                <form method="post">
                    <input type="hidden" name="action" value="set_sheet">
                    <div class="row">
                        <div class="col">
                            <label>Spreadsheet ID</label>
                            <input type="text" name="spreadsheet_id"
                                value="<?= htmlspecialchars($sheetConfig['spreadsheet_id']) ?>"
                                placeholder="mis: 1300EqaCtHs8PrHKepzEQRk-ALwtfh1FcBAeaW95XKWU">
                        </div>
                        <div class="col">
                            <label>Sheet GID</label>
                            <input type="text" name="sheet_gid"
                                value="<?= htmlspecialchars($sheetConfig['sheet_gid']) ?>"
                                placeholder="mis: 1891016011">
                        </div>
                    </div>
                    <div style="margin-top:8px;display:flex;gap:8px;flex-wrap:wrap;align-items:center;">
                        <button type="submit" class="btn-secondary">Simpan Spreadsheet</button>
                        <span style="font-size:12px;color:#9ca3af;">
                            URL CSV aktif:
                            <code><?= htmlspecialchars($currentCsvUrl) ?></code>
                        </span>
                    </div>
                </form>
            </div>

            <div class="card">
                <div class="card-header">Petugas Medis Aktif</div>
                <p style="margin:4px 0 8px;color:#f97316;">
                    Belum ada petugas aktif. Silakan isi nama petugas di bawah.
                </p>

                <form method="post" style="margin-bottom:8px;">
                    <input type="hidden" name="action" value="set_medic">
                    <div class="row">
                        <div class="col">
                            <label>Nama Petugas Medis</label>
                            <input type="text" name="medic_name" value="" required>
                        </div>
                        <div class="col">
                            <label>Jabatan</label>
                            <select name="medic_jabatan" required>
                                <?php
                                $jabatanOptions = ['Dokter Spesialis', 'Dokter Umum', 'Paramedic', '(Co.Ast)', 'Trainee', 'Lainnya'];
                                $currentJab = 'Paramedic';
                                foreach ($jabatanOptions as $opt):
                                ?>
                                    <option value="<?= htmlspecialchars($opt) ?>" <?= $opt === $currentJab ? 'selected' : '' ?>>
                                        <?= htmlspecialchars($opt) ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                    </div>
                    <div style="margin-top:8px;display:flex;gap:8px;flex-wrap:wrap;">
                        <button type="submit" class="btn-primary">Simpan Petugas</button>
                    </div>
                </form>
            </div>
        <?php endif; ?>

        <?php if ($medicName): ?>
            <!-- Card Input Transaksi -->
            <div class="card">
                <div class="card-header card-header-actions">
                    <!-- KIRI: Judul -->
                    <div class="card-header-actions-title">
                        Input Transaksi Baru
                    </div>

                    <!-- KANAN: Aksi -->
                    <div class="card-header-actions-right">

                        <!-- Tombol Kembali -->
                        <a href="/dashboard/rekap_farmasi.php"
                            class="btn-back">
                            Rekap Farmasi Versi 2
                        </a>

                        <!-- Tombol Akhiri Session -->
                        <form method="post" class="form-inline">
                            <input type="hidden" name="action" value="end_medic">
                            <button type="submit" class="btn-danger">
                                Akhiri Session
                            </button>
                        </form>

                    </div>
                </div>


                <p style="margin:4px 0 8px;">
                    Saat ini login sebagai:
                    <strong><?= htmlspecialchars($medicName) ?></strong>
                    (<?= htmlspecialchars($medicJabatan) ?>)
                </p>

                <form method="post" id="saleForm">
                    <input type="hidden" name="action" value="add_sale">
                    <!-- Tambahan: flag untuk override batas harian -->
                    <input type="hidden" name="force_overlimit" id="force_overlimit" value="0">
                    <div class="row">
                        <div class="col">
                            <label>Nama Konsumen</label>
                            <input type="text" name="consumer_name" list="consumer-list" required>
                            <datalist id="consumer-list">
                                <?php foreach ($consumerNames as $cn): ?>
                                    <option value="<?= htmlspecialchars($cn) ?>"></option>
                                <?php endforeach; ?>
                            </datalist>
                            <small>
                                Ketik nama, kalau sudah pernah beli akan muncul dan bisa diklik.
                            </small>
                        </div>
                        <div class="col">
                            <label>Paket A / B (Combo)</label>
                            <select name="package_main" id="pkg_main">
                                <option value="">-- Tidak Pakai Paket A/B --</option>
                                <?php foreach ($paketAB as $pkg): ?>
                                    <option value="<?= (int)$pkg['id'] ?>">
                                        <?= htmlspecialchars($pkg['name']) ?> (<?= (int)$pkg['price'] ?>)
                                    </option>
                                <?php endforeach; ?>
                            </select>
                            <small>
                                Paket A bisa dikombinasikan dengan paket lain selama tidak melewati batas harian.
                                Jika total item melewati batas, sistem akan memberi peringatan dan minta konfirmasi sebelum tetap disimpan.
                            </small>

                        </div>
                    </div>

                    <div class="row" style="margin-top:10px;">
                        <div class="col">
                            <label>Paket Bandage</label>
                            <select name="package_bandage" id="pkg_bandage">
                                <option value="">-- Tidak pilih paket Bandage --</option>
                                <?php foreach ($bandagePackages as $pkg): ?>
                                    <option value="<?= (int)$pkg['id'] ?>">
                                        <?= htmlspecialchars($pkg['name']) ?> (<?= (int)$pkg['price'] ?>)
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="col">
                            <label>Paket IFAKS</label>
                            <select name="package_ifaks" id="pkg_ifaks">
                                <option value="">-- Tidak pilih paket IFAKS --</option>
                                <?php foreach ($ifaksPackages as $pkg): ?>
                                    <option value="<?= (int)$pkg['id'] ?>">
                                        <?= htmlspecialchars($pkg['name']) ?> (<?= (int)$pkg['price'] ?>)
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="col">
                            <label>Paket Painkiller</label>
                            <select name="package_painkiller" id="pkg_painkiller">
                                <option value="">-- Tidak pilih paket Painkiller --</option>
                                <?php foreach ($painkillerPackages as $pkg): ?>
                                    <option value="<?= (int)$pkg['id'] ?>">
                                        <?= htmlspecialchars($pkg['name']) ?> (<?= (int)$pkg['price'] ?>)
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                    </div>

                    <div style="margin-top:10px;font-size:13px;color:#e5e7eb;">
                        <strong>Total item terpilih:</strong>
                        Bandage: <span id="totalBandage">0</span>,
                        IFAKS: <span id="totalIfaks">0</span>,
                        Painkiller: <span id="totalPainkiller">0</span>,
                        Bonus 40% (estimasi): <span id="totalBonus">0</span>
                    </div>

                    <!-- Peringatan limit harian -->
                    <div id="limitWarning"
                        style="margin-top:6px;font-size:13px;color:#f97316;display:none;">
                    </div>

                    <!-- DISPLAY KASIR: Total Harga besar -->
                    <div class="total-display">
                        <div class="total-display-label">Total yang harus dibayar</div>
                        <div class="total-amount" id="totalPriceDisplay">$ 0</div>
                    </div>

                    <div style="margin-top:10px;display:flex;gap:8px;flex-wrap:wrap;">
                        <button type="button" id="btnSubmit" class="btn-primary" onclick="handleSaveClick();">
                            Simpan Transaksi
                        </button>

                        <!-- Tombol CLEAR untuk menghapus inputan yang lengket -->
                        <button type="button" class="btn-secondary" onclick="clearFormInputs();">
                            Clear
                        </button>
                    </div>
                </form>
            </div>

            <!-- Card Filter, Ranking & Transaksi -->
            <div class="card">
                <div class="card-header">Filter Tanggal, Ranking Medis & Transaksi</div>

                <!-- Form Filter (GET) -->
                <form method="get" style="margin-bottom:10px;">
                    <div class="row">
                        <div class="col">
                            <label>Rentang Tanggal</label>
                            <select name="range" id="rangeSelect">
                                <option value="today" <?= $range === 'today' ? 'selected' : '' ?>>Hari ini</option>
                                <option value="yesterday" <?= $range === 'yesterday' ? 'selected' : '' ?>>
                                    Kemarin (1 hari sebelumnya)
                                </option>
                                <option value="last7" <?= $range === 'last7' ? 'selected' : '' ?>>
                                    7 hari terakhir (mundur 1–7 hari)
                                </option>
                                <option value="week1" <?= $range === 'week1' ? 'selected' : '' ?>>Minggu 1 (1-7)</option>
                                <option value="week2" <?= $range === 'week2' ? 'selected' : '' ?>>Minggu 2 (8-14)</option>
                                <option value="week3" <?= $range === 'week3' ? 'selected' : '' ?>>Minggu 3 (15-21)</option>
                                <option value="week4" <?= $range === 'week4' ? 'selected' : '' ?>>Minggu 4 (22-akhir bulan)</option>
                                <option value="month" <?= $range === 'month' ? 'selected' : '' ?>>Bulanan (bulan ini)</option>
                                <option value="custom" <?= $range === 'custom' ? 'selected' : '' ?>>Custom (pilih tanggal)</option>
                            </select>
                        </div>
                    </div>

                    <div class="row" id="customDateRow" style="margin-top:8px; <?= $range === 'custom' ? 'display:flex;' : 'display:none;' ?>">
                        <div class="col">
                            <label>Dari tanggal</label>
                            <input type="date" name="from" value="<?= htmlspecialchars($fromDateInput) ?>">
                        </div>
                        <div class="col">
                            <label>Sampai tanggal</label>
                            <input type="date" name="to" value="<?= htmlspecialchars($toDateInput) ?>">
                        </div>
                    </div>

                    <?php if ($showAll): ?>
                        <!-- Kalau lagi mode "tampilkan semua data", pertahankan saat ganti filter -->
                        <input type="hidden" name="show_all" value="1">
                    <?php endif; ?>

                    <div style="margin-top:8px;">
                        <button type="submit" class="btn-secondary">Terapkan Filter</button>
                    </div>
                </form>

                <p style="font-size:13px;color:#9ca3af;margin-top:0;">
                    Rentang aktif: <strong><?= htmlspecialchars($rangeLabel) ?></strong>
                </p>

                <!-- Tombol Export Excel -->
                <form method="get" style="margin:4px 0 12px; display:flex; gap:8px; flex-wrap:wrap;">
                    <input type="hidden" name="range" value="<?= htmlspecialchars($range) ?>">
                    <?php if ($range === 'custom'): ?>
                        <input type="hidden" name="from" value="<?= htmlspecialchars($fromDateInput) ?>">
                        <input type="hidden" name="to" value="<?= htmlspecialchars($toDateInput) ?>">
                    <?php endif; ?>

                    <button type="submit" name="export" value="medic" class="btn-primary">
                        Export Excel (Khusus Medis Aktif)
                    </button>
                </form>

                <!-- Rekapan Bonus Medis (berdasarkan filter tanggal) -->
                <h3 style="font-size:15px;margin:8px 0;">Rekapan Bonus Medis (berdasarkan filter tanggal)</h3>
                <p style="font-size:13px;color:#9ca3af;margin-top:0;margin-bottom:8px;">
                    Ditampilkan berdasarkan <strong>petugas medis yang sedang aktif</strong> pada rentang tanggal aktif.
                </p>

                <?php if ($singleMedicStats): ?>
                    <div class="table-wrapper table-wrapper-sm" style="margin-bottom:12px;">
                        <table>
                            <thead>
                                <tr>
                                    <th>#</th>
                                    <th>Nama Medis</th>
                                    <th>Jabatan</th>
                                    <th>Total Transaksi</th>
                                    <th>Total Item</th>
                                    <th>Total Harga</th>
                                    <th>Bonus (40%)</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php
                                $bonusSingle = (int)floor((int)$singleMedicStats['total_rupiah'] * 0.4);
                                ?>
                                <tr>
                                    <td>1</td>
                                    <td><?= htmlspecialchars($singleMedicStats['medic_name']) ?></td>
                                    <td><?= htmlspecialchars($singleMedicStats['medic_jabatan']) ?></td>
                                    <td><?= (int)$singleMedicStats['total_transaksi'] ?></td>
                                    <td><?= (int)$singleMedicStats['total_item'] ?></td>
                                    <td><?= (int)$singleMedicStats['total_rupiah'] ?></td>
                                    <td><?= $bonusSingle ?></td>
                                </tr>
                            </tbody>
                        </table>
                    </div>
                <?php else: ?>
                    <p style="font-size:13px;color:#9ca3af;margin-bottom:12px;">
                        Belum ada data untuk petugas medis aktif pada rentang tanggal yang dipilih.
                    </p>
                <?php endif; ?>

                <!-- Ranking 10 Medis -->
                <h3 style="font-size:15px;margin:8px 0;">Top 10 Medis (berdasarkan filter tanggal)</h3>
                <?php if (!$medicRanking): ?>
                    <p style="font-size:13px;color:#9ca3af;">Belum ada data untuk rentang ini.</p>
                <?php else: ?>
                    <div class="table-wrapper table-wrapper-sm" style="margin-bottom:12px;">
                        <table>
                            <thead>
                                <tr>
                                    <th>#</th>
                                    <th>Nama Medis</th>
                                    <th>Jabatan</th>
                                    <th>Total Transaksi</th>

                                    <th>Jumlah Paket A</th>
                                    <th>Jumlah Paket B</th>

                                    <th>Jumlah Bandage</th>
                                    <th>Jumlah IFAKS</th>
                                    <th>Jumlah Painkiller</th>

                                    <th>Total Item</th>
                                    <th>Total Harga</th>
                                    <th>Bonus (40%)</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php $rank = 1; ?>
                                <?php foreach ($medicRanking as $row): ?>
                                    <?php
                                    $bonus = (int)floor(((int)$row['total_rupiah']) * 0.4);
                                    ?>
                                    <tr>
                                        <td><?= $rank++ ?></td>
                                        <td><?= htmlspecialchars($row['medic_name']) ?></td>
                                        <td><?= htmlspecialchars($row['medic_jabatan']) ?></td>
                                        <td><?= (int)$row['total_transaksi'] ?></td>

                                        <td><?= (int)$row['total_paket_a'] ?></td>
                                        <td><?= (int)$row['total_paket_b'] ?></td>

                                        <td><?= (int)$row['total_bandage'] ?></td>
                                        <td><?= (int)$row['total_ifaks'] ?></td>
                                        <td><?= (int)$row['total_painkiller'] ?></td>

                                        <td><?= (int)$row['total_item'] ?></td>
                                        <td><?= (int)$row['total_rupiah'] ?></td>
                                        <td><?= $bonus ?></td>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                <?php endif; ?>

                <!-- Tabel Transaksi dengan DataTables + checkbox -->
                <div style="display:flex;justify-content:space-between;align-items:center;margin:8px 0;">
                    <div>
                        <h3 style="font-size:15px;margin:0;">Transaksi (sesuai filter)</h3>
                        <div style="font-size:11px;color:#9ca3af;margin-top:2px;">
                            <?php if ($showAll): ?>
                                Mode: <strong>Semua medis</strong>
                            <?php else: ?>
                                Mode: <strong>Medis aktif (<?= htmlspecialchars($medicName) ?>)</strong>
                            <?php endif; ?>
                        </div>
                    </div>

                    <form method="get" style="margin:0;display:flex;gap:6px;align-items:center;">
                        <!-- bawa filter range yang sedang aktif -->
                        <input type="hidden" name="range" value="<?= htmlspecialchars($range) ?>">
                        <?php if ($range === 'custom'): ?>
                            <input type="hidden" name="from" value="<?= htmlspecialchars($fromDateInput) ?>">
                            <input type="hidden" name="to" value="<?= htmlspecialchars($toDateInput) ?>">
                        <?php endif; ?>

                        <?php if ($showAll): ?>
                            <!-- Sedang mode "tampilkan semua data" → tombol kembali ke hanya medis aktif -->
                            <button type="submit" class="btn-secondary">
                                Kembali (Hanya Medis Aktif)
                            </button>
                        <?php else: ?>
                            <!-- Sedang mode hanya medis aktif → tombol untuk tampilkan semua data -->
                            <input type="hidden" name="show_all" value="1">
                            <button type="submit" class="btn-secondary">
                                Tampilkan Semua Data
                            </button>
                        <?php endif; ?>
                    </form>
                </div>

                <?php if (!$filteredSales): ?>
                    <p style="font-size:13px;color:#9ca3af;">Belum ada transaksi pada rentang ini.</p>
                <?php else: ?>
                    <form method="post" id="bulkDeleteForm" onsubmit="return confirmBulkDelete();">
                        <input type="hidden" name="action" value="delete_selected">
                        <div class="table-wrapper">
                            <table id="salesTable">
                                <thead>
                                    <tr>
                                        <th style="width:32px;text-align:center;">
                                            <input type="checkbox" id="selectAll">
                                        </th>
                                        <th>Waktu</th>
                                        <th>Nama Konsumen</th>
                                        <th>Nama Medis</th>
                                        <th>Jabatan</th>
                                        <th>Paket</th>
                                        <th>Bandage</th>
                                        <th>IFAKS</th>
                                        <th>Painkiller</th>
                                        <th>Harga</th>
                                        <th>Bonus (40%)</th>
                                    </tr>
                                </thead>
                                <tfoot>
                                    <tr>
                                        <th colspan="6" style="text-align:right;">TOTAL</th>
                                        <th></th>
                                        <th></th>
                                        <th></th>
                                        <th></th>
                                        <th></th>
                                    </tr>
                                </tfoot>
                                <tbody>
                                    <?php foreach ($filteredSales as $s): ?>
                                        <?php $bonus = (int)floor(((int)$s['price']) * 0.4); ?>
                                        <tr>
                                            <td style="text-align:center;">
                                                <?php if ($medicName && $s['medic_name'] === $medicName): ?>
                                                    <input type="checkbox"
                                                        class="row-check"
                                                        name="sale_ids[]"
                                                        value="<?= (int)$s['id'] ?>">
                                                <?php else: ?>
                                                    <span style="font-size:11px;color:#6b7280;">-</span>
                                                <?php endif; ?>
                                            </td>
                                            <td><?= htmlspecialchars($s['created_at']) ?></td>
                                            <td><?= htmlspecialchars($s['consumer_name']) ?></td>
                                            <td><?= htmlspecialchars($s['medic_name']) ?></td>
                                            <td><?= htmlspecialchars($s['medic_jabatan']) ?></td>
                                            <td><?= htmlspecialchars($s['package_name']) ?></td>
                                            <td><?= (int)$s['qty_bandage'] ?></td>
                                            <td><?= (int)$s['qty_ifaks'] ?></td>
                                            <td><?= (int)$s['qty_painkiller'] ?></td>
                                            <td><?= (int)$s['price'] ?></td>
                                            <td><?= $bonus ?></td>
                                        </tr>
                                    <?php endforeach; ?>
                                </tbody>
                            </table>
                        </div>

                        <div style="margin-top:8px;display:flex;gap:8px;flex-wrap:wrap;align-items:center;">
                            <button type="submit" class="btn-danger" id="btnBulkDelete" disabled>
                                Hapus Data Terpilih
                            </button>
                            <small style="color:#9ca3af;">
                                Checklist baris yang ingin dihapus. Hanya transaksi milik Anda yang akan dihapus.
                            </small>
                        </div>
                    </form>
                <?php endif; ?>
            </div>
        <?php else: ?>
            <!-- Kalau tidak ada petugas, info kecil saja -->
            <p style="font-size:13px;color:#9ca3af;">
                Silakan set <strong>Petugas Medis Aktif</strong> terlebih dahulu untuk dapat input transaksi dan melihat rekap.
            </p>
        <?php endif; ?>

    </div>

    <!-- jQuery & DataTables JS -->
    <script src="https://code.jquery.com/jquery-3.7.1.min.js"></script>
    <script src="https://cdn.datatables.net/1.13.8/js/jquery.dataTables.min.js"></script>

    <script>
        // Flag dari PHP: apakah form perlu dikosongkan setelah transaksi sukses?
        const SHOULD_CLEAR_FORM = <?= $shouldClearForm ? 'true' : 'false'; ?>;

        // Konstanta batas harian
        const MAX_BANDAGE = 30;
        const MAX_IFAKS = 10;
        const MAX_PAINKILLER = 10;

        // Data paket dari PHP untuk perhitungan realtime
        const PACKAGES = <?= json_encode($packagesById, JSON_UNESCAPED_UNICODE); ?>;
        // Total harian per konsumen dari PHP (key = nama kecil trim)
        const DAILY_TOTALS = <?= json_encode($dailyTotalsJS, JSON_UNESCAPED_UNICODE); ?>;
        const DAILY_DETAIL = <?= json_encode($dailyDetailJS, JSON_UNESCAPED_UNICODE); ?>;
        // Flag global: apakah pilihan saat ini menyebabkan melewati batas harian
        let IS_OVER_LIMIT = false;

        const STORAGE_KEY = 'farmasi_ems_form';

        function escapeHtml(str) {
            return (str || '').replace(/[&<>"']/g, function(c) {
                return ({
                    '&': '&amp;',
                    '<': '&lt;',
                    '>': '&gt;',
                    '"': '&quot;',
                    "'": '&#39;'
                })[c] || c;
            });
        }

        function formatDollar(num) {
            num = parseInt(num || 0, 10);
            if (typeof Intl !== 'undefined' && Intl.NumberFormat) {
                return '$ ' + new Intl.NumberFormat('en-US').format(num);
            }
            return '$ ' + num.toString().replace(/\B(?=(\d{3})+(?!\d))/g, ",");
        }

        function getBaseTotalsForConsumer(name) {
            if (!name) {
                return {
                    bandage: 0,
                    ifaks: 0,
                    painkiller: 0
                };
            }
            const key = name.trim().toLowerCase();
            const data = DAILY_TOTALS[key];
            if (!data) {
                return {
                    bandage: 0,
                    ifaks: 0,
                    painkiller: 0
                };
            }
            return {
                bandage: parseInt(data.bandage || 0, 10),
                ifaks: parseInt(data.ifaks || 0, 10),
                painkiller: parseInt(data.painkiller || 0, 10),
            };
        }

        function saveFormState() {
            const consumerInput = document.querySelector('input[name="consumer_name"]');
            const data = {
                consumer_name: consumerInput ? consumerInput.value : '',
                pkg_main: document.getElementById('pkg_main')?.value || '',
                pkg_bandage: document.getElementById('pkg_bandage')?.value || '',
                pkg_ifaks: document.getElementById('pkg_ifaks')?.value || '',
                pkg_painkiller: document.getElementById('pkg_painkiller')?.value || '',
            };
            try {
                localStorage.setItem(STORAGE_KEY, JSON.stringify(data));
            } catch (e) {
                // abaikan
            }
        }

        function restoreFormState() {
            try {
                const raw = localStorage.getItem(STORAGE_KEY);
                if (!raw) return;
                const data = JSON.parse(raw);
                const consumerInput = document.querySelector('input[name="consumer_name"]');
                if (consumerInput && data.consumer_name) {
                    consumerInput.value = data.consumer_name;
                }
                ['pkg_main', 'pkg_bandage', 'pkg_ifaks', 'pkg_painkiller'].forEach(function(id) {
                    const el = document.getElementById(id);
                    if (!el) return;
                    if (data[id]) {
                        el.value = data[id];
                    }
                });
            } catch (e) {
                // abaikan
            }
        }

        function recalcTotals() {
            // Kumpulkan ID paket yang dipilih
            const ids = [];
            ['pkg_main', 'pkg_bandage', 'pkg_ifaks', 'pkg_painkiller'].forEach(function(id) {
                const el = document.getElementById(id);
                if (el && el.value) {
                    ids.push(el.value);
                }
            });

            let totalBandage = 0;
            let totalIfaks = 0;
            let totalPain = 0;
            let totalPrice = 0;

            ids.forEach(function(id) {
                const pkg = PACKAGES[id];
                if (!pkg) return;

                totalBandage += parseInt(pkg.bandage || 0, 10);
                totalIfaks += parseInt(pkg.ifaks || 0, 10);
                totalPain += parseInt(pkg.painkiller || 0, 10);
                totalPrice += parseInt(pkg.price || 0, 10);
            });

            const bonus = Math.floor(totalPrice * 0.4);

            // Update teks "Total item terpilih"
            document.getElementById('totalBandage').textContent = totalBandage;
            document.getElementById('totalIfaks').textContent = totalIfaks;
            document.getElementById('totalPainkiller').textContent = totalPain;

            // Update display kasir besar
            const totalPriceDisplay = document.getElementById('totalPriceDisplay');
            if (totalPriceDisplay) {
                totalPriceDisplay.textContent = formatDollar(totalPrice);
            }

            // Update bonus 40%
            const bonusEl = document.getElementById('totalBonus');
            if (bonusEl) {
                bonusEl.textContent = formatDollar(bonus);
            }

            // ===== Cek limit harian berdasarkan nama konsumen =====
            const consumerInput = document.querySelector('input[name="consumer_name"]');
            const cname = consumerInput ? consumerInput.value.trim() : '';
            const baseTotals = getBaseTotalsForConsumer(cname);

            const newBandage = baseTotals.bandage + totalBandage;
            const newIfaks = baseTotals.ifaks + totalIfaks;
            const newPain = baseTotals.painkiller + totalPain;

            const warningBox = document.getElementById('limitWarning');
            const btnSubmit = document.getElementById('btnSubmit');

            let messageParts = [];
            if (newBandage > MAX_BANDAGE) {
                messageParts.push(`Bandage ${newBandage}/${MAX_BANDAGE}`);
            }
            if (newIfaks > MAX_IFAKS) {
                messageParts.push(`IFAKS ${newIfaks}/${MAX_IFAKS}`);
            }
            if (newPain > MAX_PAINKILLER) {
                messageParts.push(`Painkiller ${newPain}/${MAX_PAINKILLER}`);
            }

            if (messageParts.length > 0) {
                let html = '';

                if (cname) {
                    const key = cname.trim().toLowerCase();
                    const detail = DAILY_DETAIL[key] || [];

                    html += '⚠️ <strong>' + escapeHtml(cname) +
                        '</strong> telah melebihi batas harian: ' +
                        messageParts.join(', ') +
                        '.<br>Secara aturan normal, transaksi ini <strong>melebihi batas harian</strong> obat.<br>' +
                        'Jika tetap ingin menyimpan, klik tombol <strong>Simpan Transaksi</strong> lalu konfirmasi di popup.<br>';

                    if (detail.length > 0) {
                        html += '<br><strong>Riwayat pembelian hari ini (data dari database):</strong>';
                        html += '<ul style="margin-top:4px;padding-left:18px;">';
                        detail.forEach(function(d) {
                            const waktu = d.time ? d.time.substring(0, 16) : ''; // yyyy-mm-dd hh:mm
                            html += '<li>' +
                                escapeHtml(waktu) + ' — ' +
                                escapeHtml(d.medic || '-') + ' menjual ' +
                                escapeHtml(d.package || '-') +
                                ' (Bandage ' + d.bandage +
                                ', IFAKS ' + d.ifaks +
                                ', Painkiller ' + d.painkiller +
                                ')</li>';
                        });
                        html += '</ul>';
                    } else {
                        html += '<br><small>Catatan: untuk nama ini belum ada riwayat di database pada hari ini, atau transaksi sebelumnya belum tersimpan.</small>';
                    }

                    html += '<br><small>Pastikan konsumen sudah mendapat penjelasan sebelum melakukan override batas harian.</small>';
                } else {
                    html += '⚠️ Batas harian akan terlewati. Isi nama konsumen untuk melihat riwayat pembelian dari database.';
                }

                warningBox.style.display = 'block';
                warningBox.innerHTML = html;
                // Tandai bahwa transaksi ini akan melewati batas
                IS_OVER_LIMIT = true;
            } else {
                warningBox.style.display = 'none';
                warningBox.innerHTML = '';
                IS_OVER_LIMIT = false;
            }
        }

        function onPackageChange() {
            saveFormState();
            recalcTotals();
        }

        function updateCustomDateVisibility() {
            const rangeSel = document.getElementById('rangeSelect');
            const customRow = document.getElementById('customDateRow');
            if (!rangeSel || !customRow) return;
            if (rangeSel.value === 'custom') {
                customRow.style.display = 'flex';
            } else {
                customRow.style.display = 'none';
            }
        }

        function clearFormInputs() {
            const consumerInput = document.querySelector('input[name="consumer_name"]');

            if (consumerInput) {
                consumerInput.value = '';
            }

            ['pkg_main', 'pkg_bandage', 'pkg_ifaks', 'pkg_painkiller'].forEach(function(id) {
                const el = document.getElementById(id);
                if (el) {
                    el.value = '';
                }
            });

            // Hapus state di localStorage supaya benar-benar bersih
            try {
                localStorage.removeItem(STORAGE_KEY);
            } catch (e) {
                // abaikan error
            }

            // Sembunyikan warning limit (kalau ada)
            const warningBox = document.getElementById('limitWarning');
            if (warningBox) {
                warningBox.style.display = 'none';
                warningBox.innerHTML = '';
            }

            const btnSubmit = document.getElementById('btnSubmit');
            if (btnSubmit) {
                btnSubmit.disabled = false;
            }

            // Reset flag over-limit & hidden input override
            IS_OVER_LIMIT = false;
            const forceOver = document.getElementById('force_overlimit');
            if (forceOver) {
                forceOver.value = '0';
            }

            // Hitung ulang total (akan jadi 0 semua)
            recalcTotals();
        }

        function handleSaveClick() {
            const btnSubmit = document.getElementById('btnSubmit');
            const form = document.getElementById('saleForm');
            const consumerInput = document.querySelector('input[name="consumer_name"]');
            const cname = consumerInput ? consumerInput.value.trim() : '';

            const baseTotals = getBaseTotalsForConsumer(cname);
            const hasPrevious = (baseTotals.bandage + baseTotals.ifaks + baseTotals.painkiller) > 0;

            const totalPriceDisplay = document.getElementById('totalPriceDisplay');
            const totalText = totalPriceDisplay ? totalPriceDisplay.textContent : '$ 0';

            const forceOverInput = document.getElementById('force_overlimit');
            if (forceOverInput) {
                // default: tidak override
                forceOverInput.value = '0';
            }

            let msg = "";

            if (IS_OVER_LIMIT) {
                // Kasus: kalau disimpan, dia akan MELEBIHI batas harian
                msg += "⚠️ Orang ini telah mencapai / akan melewati batas maksimal pembelian harian.\n\n";

                if (cname) {
                    msg += "Nama konsumen: " + cname + "\n\n" +
                        "Total SEBELUM transaksi ini (data di database):\n" +
                        "- Bandage   : " + baseTotals.bandage + "/" + MAX_BANDAGE + "\n" +
                        "- IFAKS     : " + baseTotals.ifaks + "/" + MAX_IFAKS + "\n" +
                        "- Painkiller: " + baseTotals.painkiller + "/" + MAX_PAINKILLER + "\n\n";
                }

                msg +=
                    "Yakin ingin TETAP memasukkan transaksi ini ke database " +
                    "walaupun batas maksimal satu hari sudah tercapai?\n\n" +
                    "Total saat ini: " + totalText + "\n\n" +
                    "Pilih OK (Yes) untuk menyimpan ke database, atau Cancel untuk membatalkan.";

                const ok = confirm(msg);
                if (!ok) {
                    return;
                }

                // User setuju override → beritahu server
                if (forceOverInput) {
                    forceOverInput.value = '1';
                }
            } else {
                // Tidak melewati batas, tapi mungkin sudah pernah beli
                msg += "Yakin ingin menyimpan transaksi ke database?\n\n";

                if (cname) {
                    msg += "Nama konsumen: " + cname + "\n\n";

                    if (hasPrevious) {
                        msg +=
                            "Catatan: orang ini sudah pernah melakukan pembelian hari ini.\n" +
                            "Total SEBELUM transaksi ini (data di database):\n" +
                            "- Bandage   : " + baseTotals.bandage + "/" + MAX_BANDAGE + "\n" +
                            "- IFAKS     : " + baseTotals.ifaks + "/" + MAX_IFAKS + "\n" +
                            "- Painkiller: " + baseTotals.painkiller + "/" + MAX_PAINKILLER + "\n\n";
                    }
                }

                msg +=
                    "Total saat ini: " + totalText + "\n\n" +
                    "Pilih OK (Yes) untuk menyimpan, atau Cancel untuk kembali mengecek.";

                const ok = confirm(msg);
                if (!ok) {
                    return;
                }
            }

            // Lindungi dari double submit / klik cepat
            if (btnSubmit) {
                btnSubmit.disabled = true;
            }

            if (form) {
                form.submit();
            }
        }

        function confirmBulkDelete() {
            const checked = document.querySelectorAll('.row-check:checked').length;
            if (!checked) {
                alert('Tidak ada transaksi yang dipilih untuk dihapus.');
                return false;
            }
            return confirm(
                'Yakin ingin menghapus ' + checked + ' transaksi terpilih?\n' +
                'Hanya transaksi milik Anda yang akan dihapus di server.'
            );
        }

        // ===== JAM & TANGGAL REALTIME (BERDASARKAN WAKTU LOKAL PERANGKAT) =====
        function getIndonesiaTimeZoneName(date) {
            // getTimezoneOffset = selisih terhadap UTC dalam menit (WIB = -420, WITA = -480, WIT = -540)
            const offsetMinutes = -date.getTimezoneOffset();

            if (offsetMinutes === 7 * 60) return 'WIB (UTC+7)';
            if (offsetMinutes === 8 * 60) return 'WITA (UTC+8)';
            if (offsetMinutes === 9 * 60) return 'WIT (UTC+9)';

            // Jika di luar Indonesia, fallback ke label umum
            return 'Zona waktu lokal';
        }

        function updateLocalClock() {
            const el = document.getElementById('localClock');
            if (!el) return;

            const now = new Date();
            const tzName = getIndonesiaTimeZoneName(now);

            const tanggal = now.toLocaleDateString('id-ID', {
                weekday: 'long',
                year: 'numeric',
                month: 'long',
                day: 'numeric'
            });

            const jam = now.toLocaleTimeString('id-ID', {
                hour: '2-digit',
                minute: '2-digit',
                second: '2-digit',
                hour12: false
            });

            el.textContent = `${tanggal} • ${jam} (${tzName})`;
        }

        document.addEventListener('DOMContentLoaded', function() {
            // ===== Inisialisasi jam & tanggal realtime =====
            updateLocalClock();
            setInterval(updateLocalClock, 1000);

            // Bersihkan atau restore form state tergantung flag dari server
            if (SHOULD_CLEAR_FORM) {
                try {
                    localStorage.removeItem(STORAGE_KEY);
                } catch (e) {
                    // abaikan error localStorage
                }
                // form dibiarkan kosong (tidak di-restore)
            } else {
                // Kalau transaksi sebelumnya gagal / batal, tetap restore agar user bisa koreksi
                restoreFormState();
            }

            // Listener perubahan paket
            ['pkg_main', 'pkg_bandage', 'pkg_ifaks', 'pkg_painkiller'].forEach(function(id) {
                const el = document.getElementById(id);
                if (el) {
                    el.addEventListener('change', onPackageChange);
                }
            });

            // Listener nama konsumen → cek limit + save state
            const consumerInput = document.querySelector('input[name="consumer_name"]');
            if (consumerInput) {
                ['input', 'change', 'blur'].forEach(function(evt) {
                    consumerInput.addEventListener(evt, function() {
                        saveFormState();
                        recalcTotals();
                    });
                });
            }

            const rangeSel = document.getElementById('rangeSelect');
            if (rangeSel) {
                rangeSel.addEventListener('change', updateCustomDateVisibility);
            }

            updateCustomDateVisibility();
            recalcTotals(); // hitung awal berdasarkan form yang dipulihkan

            // ===== Auto hide alert setelah 5 detik =====
            setTimeout(function() {
                document.querySelectorAll('.alert-warning, .alert-error, .alert-info').forEach(function(el) {
                    el.style.transition = 'opacity 0.5s';
                    el.style.opacity = '0';
                    setTimeout(function() {
                        if (el.parentNode) {
                            el.parentNode.removeChild(el);
                        }
                    }, 600);
                });
            }, 5000);

            // ===== Inisialisasi DataTables untuk tabel transaksi =====
            if (window.jQuery && jQuery.fn.DataTable) {
                const table = jQuery('#salesTable').DataTable({
                    pageLength: 10,
                    order: [
                        [1, 'desc']
                    ],
                    language: {
                        url: "https://cdn.datatables.net/plug-ins/1.13.8/i18n/id.json"
                    },
                    footerCallback: function(row, data, start, end, display) {
                        let api = this.api();

                        function intVal(i) {
                            return typeof i === 'string' ?
                                i.replace(/[^\d]/g, '') * 1 :
                                typeof i === 'number' ?
                                i :
                                0;
                        }

                        let totalBandage = api.column(6, {
                                search: 'applied'
                            }).data()
                            .reduce((a, b) => intVal(a) + intVal(b), 0);

                        let totalIfaks = api.column(7, {
                                search: 'applied'
                            }).data()
                            .reduce((a, b) => intVal(a) + intVal(b), 0);

                        let totalPain = api.column(8, {
                                search: 'applied'
                            }).data()
                            .reduce((a, b) => intVal(a) + intVal(b), 0);

                        let totalPrice = api.column(9, {
                                search: 'applied'
                            }).data()
                            .reduce((a, b) => intVal(a) + intVal(b), 0);

                        let totalBonus = api.column(10, {
                                search: 'applied'
                            }).data()
                            .reduce((a, b) => intVal(a) + intVal(b), 0);

                        // ⬇️ INI KUNCI UTAMANYA
                        jQuery(api.column(6).footer()).html(totalBandage);
                        jQuery(api.column(7).footer()).html(totalIfaks);
                        jQuery(api.column(8).footer()).html(totalPain);
                        jQuery(api.column(9).footer()).html(totalPrice);
                        jQuery(api.column(10).footer()).html(totalBonus);
                    }
                });

                const $selectAll = jQuery('#selectAll');
                const $bulkBtn = jQuery('#btnBulkDelete');

                function updateBulkButton() {
                    const anyChecked = jQuery('.row-check:checked').length > 0;
                    $bulkBtn.prop('disabled', !anyChecked);
                }

                // Select/Deselect all (hanya yang ada checkboxnya)
                $selectAll.on('click', function() {
                    const checked = this.checked;
                    jQuery('#salesTable tbody .row-check').prop('checked', checked);
                    updateBulkButton();
                });

                // Per row checkbox
                jQuery(document).on('change', '.row-check', function() {
                    const total = jQuery('#salesTable tbody .row-check').length;
                    const checked = jQuery('#salesTable tbody .row-check:checked').length;

                    if (!this.checked) {
                        $selectAll.prop('checked', false);
                    } else if (checked === total && total > 0) {
                        $selectAll.prop('checked', true);
                    }

                    updateBulkButton();
                });
            }
        });
    </script>

</body>

</html>
<?php
register_shutdown_function(function () {
    $e = error_get_last();
    if ($e && in_array($e['type'], [E_ERROR, E_PARSE, E_CORE_ERROR])) {
        error_log('[FATAL] ' . $e['message'] . ' in ' . $e['file'] . ':' . $e['line']);
    }
});
?>