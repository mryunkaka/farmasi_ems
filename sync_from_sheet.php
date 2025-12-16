<?php
// -------------------------------------------
// Konfigurasi timezone (WITA / UTC+8)
// -------------------------------------------
date_default_timezone_set('Asia/Makassar');

// -------------------------------------------
// Konfigurasi database
// -------------------------------------------
$DB_HOST = 'localhost';
$DB_NAME = 'farmasi_db';
$DB_USER = 'root';
$DB_PASS = 'Jal&jar123';

// File konfigurasi spreadsheet (shared dengan halaman web)
$configFile = __DIR__ . '/sheet_config.json';

// Default (boleh disesuaikan, sama seperti di index.php)
$sheetConfig = [
    'spreadsheet_id' => '1300EqaCtHs8PrHKepzEQRk-ALwtfh1FcBAeaW95XKWU',
    'sheet_gid'      => '1891016011',
];

// Override dari file config kalau ada
if (file_exists($configFile)) {
    $json = file_get_contents($configFile);
    $data = json_decode($json, true);
    if (is_array($data)) {
        $sheetConfig = array_merge($sheetConfig, $data);
    }
}

// Build URL CSV Google Sheets dari config
$sheetId = $sheetConfig['spreadsheet_id'];
$sheetGid = $sheetConfig['sheet_gid'];

$sheetCsvUrl = "https://docs.google.com/spreadsheets/d/{$sheetId}/export?format=csv&gid={$sheetGid}";

// -------------------------------------------
// Koneksi PDO
// -------------------------------------------
try {
    $pdo = new PDO(
        "mysql:host={$DB_HOST};dbname={$DB_NAME};charset=utf8mb4",
        $DB_USER,
        $DB_PASS,
        [
            PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        ]
    );
} catch (PDOException $e) {
    echo "Koneksi database gagal: " . $e->getMessage() . PHP_EOL;
    exit(1);
}

// -------------------------------------------
// Ambil CSV dari Google Sheets
// -------------------------------------------
echo "Mengambil data dari Google Sheets...\n";
echo "URL: {$sheetCsvUrl}\n";

$csvContent = @file_get_contents($sheetCsvUrl);
if ($csvContent === false) {
    echo "Gagal mengambil CSV dari Google Sheets\n";
    exit(1);
}

// Split per baris
$lines = preg_split("/\r\n|\n|\r/", trim($csvContent));

// -------------------------------------------
// Siapkan statement DB (biar tidak prepare berkali-kali)
// -------------------------------------------

// Cari paket berdasarkan nama
$stmtPkg = $pdo->prepare("
    SELECT id, name, price, bandage_qty, ifaks_qty, painkiller_qty
    FROM packages
    WHERE name = :name
    LIMIT 1
");

// Cek duplikat
$stmtCheck = $pdo->prepare("
    SELECT COUNT(*) AS c
    FROM sales
    WHERE consumer_name = :cname
      AND medic_name    = :mname
      AND medic_jabatan = :mjab
      AND package_name  = :pname
      AND price         = :price
");

// Insert ke sales
$stmtInsert = $pdo->prepare("
    INSERT INTO sales
    (consumer_name, medic_name, medic_jabatan, package_id, package_name, price,
     qty_bandage, qty_ifaks, qty_painkiller, created_at)
    VALUES
    (:cname, :mname, :mjab, :pid, :pname, :price,
     :qb, :qi, :qp, :created_at)
");

// -------------------------------------------
// Loop baris CSV
// -------------------------------------------
$lineNum        = 0;
$importedCount  = 0;
$skippedCount   = 0;      // kosong / tidak valid / paket tidak ketemu
$duplicateCount = 0;      // sudah ada di DB

// Kita hanya peduli A3:E900 -> artinya skip baris 1 & 2, baris > 900 juga boleh di-skip kalau mau
$maxRow = 900;

foreach ($lines as $line) {
    $lineNum++;

    // Skip baris 1 & 2 (header / judul)
    if ($lineNum < 3) {
        continue;
    }

    if ($lineNum > $maxRow) {
        // Kalau mau ketat sesuai permintaan A3:E900
        break;
    }

    // Skip baris kosong
    if (trim($line) === '') {
        $skippedCount++;
        continue;
    }

    // Parse CSV: delimiter koma, enclosure tanda kutip, escape backslash
    $cols = str_getcsv($line, ',', '"', '\\');

    // Minimal 4 kolom (A–D), kolom E (harga) boleh kosong
    if (count($cols) < 4) {
        $skippedCount++;
        continue;
    }

    $buyerName  = trim($cols[0] ?? '');
    $medicName  = trim($cols[1] ?? '');
    $jabatan    = trim($cols[2] ?? '');
    $jenisPaket = trim($cols[3] ?? '');
    $totalHarga = trim($cols[4] ?? '');

    // Kalau semua kolom penting kosong, skip
    if ($buyerName === '' && $medicName === '' && $jenisPaket === '') {
        $skippedCount++;
        continue;
    }

    // Wajib: nama pembeli, nama medis, jabatan, jenis paket
    if ($buyerName === '' || $medicName === '' || $jabatan === '' || $jenisPaket === '') {
        $skippedCount++;
        continue;
    }

    // Cari paket di tabel packages
    $stmtPkg->execute([':name' => $jenisPaket]);
    $pkg = $stmtPkg->fetch();

    if (!$pkg) {
        // Paket tidak ditemukan di DB, skip baris ini
        $skippedCount++;
        continue;
    }

    $pkgId    = (int)$pkg['id'];
    $pkgName  = $pkg['name'];
    $pricePkg = (int)$pkg['price'];
    $qtyBand  = (int)$pkg['bandage_qty'];
    $qtyIfaks = (int)$pkg['ifaks_qty'];
    $qtyPain  = (int)$pkg['painkiller_qty'];

    // Tentukan harga yang akan disimpan:
    // - Kalau kolom E ada angkanya -> pakai itu
    // - Kalau kosong -> pakai price dari tabel packages
    $priceToSave = $pricePkg;
    if ($totalHarga !== '') {
        // Hilangkan titik/koma pemisah ribuan
        $norm = str_replace(['.', ','], '', $totalHarga);
        if (is_numeric($norm)) {
            $priceToSave = (int)$norm;
        }
    }

    // Cek apakah sudah ada data dengan kombinasi ini
    $stmtCheck->execute([
        ':cname' => $buyerName,
        ':mname' => $medicName,
        ':mjab'  => $jabatan,
        ':pname' => $pkgName,      // atau $jenisPaket, asalkan konsisten
        ':price' => $priceToSave,
    ]);

    $exists = (int)$stmtCheck->fetchColumn();
    if ($exists > 0) {
        // Sudah ada baris yang sama di DB, jangan di-insert lagi
        $duplicateCount++;
        continue;
    }

    // Insert ke sales
    $now = date('Y-m-d H:i:s');

    $stmtInsert->execute([
        ':cname'      => $buyerName,
        ':mname'      => $medicName,
        ':mjab'       => $jabatan,
        ':pid'        => $pkgId,
        ':pname'      => $pkgName,
        ':price'      => $priceToSave,
        ':qb'         => $qtyBand,
        ':qi'         => $qtyIfaks,
        ':qp'         => $qtyPain,
        ':created_at' => $now,
    ]);

    $importedCount++;
}

// -------------------------------------------
// Ringkasan
// -------------------------------------------
echo "Import selesai.\n";
echo "Baris diproses (mulai row 3 s/d {$maxRow}): {$lineNum}\n";
echo "Berhasil   : {$importedCount} baris.\n";
echo "Dilewati   : {$skippedCount} baris (kosong / invalid / paket tidak ketemu).\n";
echo "Duplikat   : {$duplicateCount} baris (sudah ada di DB).\n";

exit(0);
