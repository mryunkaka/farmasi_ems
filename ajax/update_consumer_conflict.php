<?php
require_once __DIR__ . '/../config/database.php';
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

// Pastikan direktori ada
$identityDir = __DIR__ . '/../storage/identity';
if (!is_dir($identityDir)) {
    mkdir($identityDir, 0777, true);
}

header('Content-Type: application/json');

$nik        = trim($_POST['nik'] ?? '');
$firstName  = trim($_POST['first_name'] ?? '');
$lastName   = trim($_POST['last_name'] ?? '');
$reason     = trim($_POST['reason'] ?? '');
$note       = trim($_POST['note'] ?? '');

if (!$nik || !$firstName || !$lastName || !$reason || empty($_FILES['ktp_image'])) {
    echo json_encode(['success' => false, 'message' => 'Data tidak lengkap']);
    exit;
}

// ambil data lama
$stmt = $pdo->prepare("SELECT id, ktp_photo FROM consumers WHERE citizen_id = ?");
$stmt->execute([$nik]);
$old = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$old) {
    echo json_encode(['success' => false, 'message' => 'Data lama tidak ditemukan']);
    exit;
}

// hapus foto lama
if ($old['ktp_photo'] && file_exists(__DIR__ . '/../storage/identity/' . $old['ktp_photo'])) {
    unlink(__DIR__ . '/../storage/identity/' . $old['ktp_photo']);
}

// simpan foto baru
$ext = pathinfo($_FILES['ktp_image']['name'], PATHINFO_EXTENSION);
$newFile = 'ktp_' . uniqid() . '.' . $ext;
move_uploaded_file(
    $_FILES['ktp_image']['tmp_name'],
    __DIR__ . '/../storage/identity/' . $newFile
);

// update data
$stmt = $pdo->prepare("
    UPDATE consumers
    SET first_name = ?, last_name = ?, ktp_photo = ?, updated_reason = ?, updated_at = NOW()
    WHERE citizen_id = ?
");
$stmt->execute([
    strtoupper($firstName),
    strtoupper($lastName),
    $newFile,
    $reason . ($note ? ' | ' . $note : ''),
    $nik
]);

echo json_encode([
    'success' => true,
    'id' => $old['id'],
    'first_name' => strtoupper($firstName),
    'last_name' => strtoupper($lastName)
]);
