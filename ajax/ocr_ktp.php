<?php

header('Content-Type: application/json; charset=utf-8');
require_once __DIR__ . '/../config/database.php';

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

/**
 * FINAL – OCR KTP (MATCH identity_test.php)
 * JSON ONLY
 */

ini_set('display_errors', 0);
error_reporting(E_ALL);
header('Content-Type: application/json');
error_log('=== OCR REQUEST START ===');
error_log('POST action: ' . ($_POST['action'] ?? 'NONE'));
error_log('FILES: ' . print_r($_FILES, true));

define('OCR_API_KEY', 'K85527757488957');
define('UPLOAD_DIR', __DIR__ . '/../storage/identity/');
if (!is_dir(UPLOAD_DIR)) {
    mkdir(UPLOAD_DIR, 0777, true);
}
define('MAX_WIDTH', 1200);
define('TARGET_FILE_SIZE', 300 * 1024);
define('MIN_QUALITY', 70);

// =================================================
// HELPER
// =================================================
function jsonError($msg)
{
    echo json_encode(['success' => false, 'message' => $msg]);
    exit;
}

function compressImage($image, $targetPath)
{
    $quality = 90;
    while ($quality >= MIN_QUALITY) {
        imagejpeg($image, $targetPath, $quality);
        if (filesize($targetPath) <= TARGET_FILE_SIZE) return true;
        $quality -= 5;
    }
    return false;
}

// =================================================
// VALIDASI FILE (PENTING)
// =================================================
if (
    $_SERVER['REQUEST_METHOD'] !== 'POST' ||
    ($_POST['action'] ?? '') !== 'ocr_ajax'
) {
    jsonError('Invalid request');
}

if (empty($_FILES['image']['tmp_name'])) {
    jsonError('File tidak ditemukan');
}

$tmp = $_FILES['image']['tmp_name'];

if (!is_uploaded_file($tmp)) {
    jsonError('Upload file tidak valid');
}

$mime = mime_content_type($tmp);
if (!in_array($mime, ['image/jpeg', 'image/png'], true)) {
    jsonError('Format gambar harus JPG atau PNG');
}

// =================================================
// RESIZE & COMPRESS (SAMA PERSIS)
// =================================================
$src = imagecreatefromstring(file_get_contents($tmp));
if (!$src) jsonError('Gambar tidak valid');

$w = imagesx($src);
$h = imagesy($src);

if ($w > MAX_WIDTH) {
    $ratio = MAX_WIDTH / $w;
    $nw = MAX_WIDTH;
    $nh = (int)($h * $ratio);
    $dst = imagecreatetruecolor($nw, $nh);
    imagecopyresampled($dst, $src, 0, 0, 0, 0, $nw, $nh, $w, $h);
    imagedestroy($src);
} else {
    $dst = $src;
}

if (!is_dir(UPLOAD_DIR)) {
    mkdir(UPLOAD_DIR, 0777, true);
}

$tmpFile = UPLOAD_DIR . 'tmp_' . uniqid() . '.jpg';
imageinterlace($dst, true);
compressImage($dst, $tmpFile);
imagedestroy($dst);

// =================================================
// OCR API CALL (IDENTIK)
// =================================================
$ch = curl_init();
curl_setopt_array($ch, [
    CURLOPT_URL => 'https://api.ocr.space/parse/image',
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_POST => true,
    CURLOPT_POSTFIELDS => [
        'apikey' => OCR_API_KEY,
        'language' => 'eng',
        'OCREngine' => '2',
        'scale' => 'true',
        'isTable' => 'true',
        'detectOrientation' => 'true',
        'isOverlayRequired' => 'false',
        'file' => new CURLFile($tmpFile)
    ]
]);

$res = curl_exec($ch);
$http = curl_getinfo($ch, CURLINFO_HTTP_CODE);
curl_close($ch);

$json = json_decode($res, true);

if ($http !== 200 || empty($json['ParsedResults'][0]['ParsedText'])) {
    jsonError('OCR gagal membaca teks');
}

$text = strtoupper($json['ParsedResults'][0]['ParsedText']);
// ✅ DEBUG: SIMPAN TEXT KE FILE (SEMENTARA)
file_put_contents(__DIR__ . '/../storage/ocr_debug.txt', $text);
error_log('[OCR_TEXT] ' . $text);

// =================================================
// PARSING (SAMA)
// =================================================
function extractField($text, $patterns)
{
    foreach ($patterns as $p) {
        if (preg_match($p, $text, $m)) return trim($m[1]);
    }
    return '';
}

$data = [
    'first_name' => extractField($text, [
        '/FIRST\s*NAME[:\s]+([A-Z]+)/',
        '/FIRST[:\s]+([A-Z]+)/'
    ]),
    'last_name' => extractField($text, [
        '/LAST\s*NAME[:\s]+([A-Z]+)/',
        '/SURNAME[:\s]+([A-Z]+)/'
    ]),
    'dob' => extractField($text, [
        '/DOB[:\s]+([\d\-\/]+)/',
        '/(\d{4}-\d{2}-\d{2})/'
    ]),
    'sex' => extractField($text, [
        '/\b(MALE|FEMALE)\b/'
    ]),
    'citizen_id' => extractField($text, [
        '/\b([A-Z]{2,4}\d{6,10})\b/',           // Format: AB123456
        '/\bCITIZEN[:\s]+([A-Z0-9]{6,15})\b/',  // "CITIZEN ID: ABC123"
        '/\bID[:\s]+([A-Z0-9]{6,15})\b/',       // "ID: ABC123"
        '/\b([A-Z]{2}\d{4,8})\b/',              // Format pendek: AB1234
    ]),
    'nationality' => 'Indonesia',
    'temp_file' => $tmpFile
];

// Cek apakah NIK sudah ada di database
$stmtCheck = $pdo->prepare("
    SELECT id, first_name, last_name, ktp_photo 
    FROM consumers 
    WHERE citizen_id = ?
");
$stmtCheck->execute([$data['citizen_id']]);
$existing = $stmtCheck->fetch(PDO::FETCH_ASSOC);

if ($existing) {
    // NIK sudah ada
    $oldName = trim($existing['first_name'] . ' ' . $existing['last_name']);
    $newName = trim($data['first_name'] . ' ' . $data['last_name']);

    if ($oldName !== $newName) {
        // Nama berbeda -> conflict
        $data['conflict'] = true;
        $data['existing'] = $existing;
    } else {
        // Nama sama -> duplicate
        $data['duplicate'] = true;
        $data['existing_id'] = $existing['id'];
    }
}

// Cek apakah NIK sudah ada di database
try {
    if (!empty($data['citizen_id'])) {
        $stmtCheck = $pdo->prepare("
            SELECT id, first_name, last_name, ktp_photo 
            FROM consumers 
            WHERE citizen_id = ?
        ");
        $stmtCheck->execute([$data['citizen_id']]);
        $existing = $stmtCheck->fetch(PDO::FETCH_ASSOC);

        if ($existing) {
            // NIK sudah ada
            $oldName = trim($existing['first_name'] . ' ' . $existing['last_name']);
            $newName = trim($data['first_name'] . ' ' . $data['last_name']);

            if (strtoupper($oldName) !== strtoupper($newName)) {
                // Nama berbeda -> conflict
                $data['conflict'] = true;
                $data['existing'] = $existing;
            } else {
                // Nama sama -> duplicate
                $data['duplicate'] = true;
                $data['existing_id'] = (int)$existing['id'];
            }
        }
    }
} catch (Exception $e) {
    error_log('[OCR_CHECK_ERROR] ' . $e->getMessage());
}

echo json_encode($data, JSON_UNESCAPED_UNICODE);
exit;
