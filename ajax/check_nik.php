<?php
require_once __DIR__ . '/../config/database.php';
header('Content-Type: application/json');

$input = json_decode(file_get_contents('php://input'), true);

$nik       = trim($input['nik'] ?? $_GET['nik'] ?? '');
$firstName = trim($input['first_name'] ?? '');
$lastName  = trim($input['last_name'] ?? '');

if ($nik === '') {
    echo json_encode(['status' => 'available']);
    exit;
}

$stmt = $pdo->prepare("
    SELECT id, first_name, last_name, ktp_photo
    FROM consumers
    WHERE citizen_id = ?
    LIMIT 1
");
$stmt->execute([$nik]);
$row = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$row) {
    echo json_encode(['status' => 'available']);
    exit;
}

// Ada data
$oldName = trim($row['first_name'] . ' ' . $row['last_name']);
$newName = trim($firstName . ' ' . $lastName);

if ($newName === '') {
    // Cuma cek NIK aja
    echo json_encode([
        'status' => 'duplicate',
        'message' => 'Citizen ID sudah terdaftar'
    ]);
    exit;
}

if ($oldName === $newName) {
    echo json_encode([
        'status' => 'duplicate',
        'message' => 'Citizen ID dan nama sudah terdaftar'
    ]);
    exit;
}

// Nama beda
echo json_encode([
    'status' => 'conflict',
    'existing' => $row,
    'message' => 'Citizen ID terdaftar dengan nama berbeda'
]);
