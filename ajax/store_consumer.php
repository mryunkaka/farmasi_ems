<?php
session_start();

require_once __DIR__ . '/../config/database.php';

// ===============================
// VALIDASI SESSION USER
// ===============================
$registeredByUserId = (int)($_SESSION['user_rh']['id'] ?? 0);

// ===============================
// AMBIL PAYLOAD JSON
// ===============================
$data = json_decode(file_get_contents('php://input'), true);

if (!is_array($data)) {
    echo json_encode([
        'success' => false,
        'message' => 'Data tidak valid'
    ]);
    exit;
}

// ===============================
// NORMALISASI & VALIDASI INPUT
// ===============================
$firstName   = trim($data['first_name'] ?? '');
$lastName    = trim($data['last_name'] ?? '');
$dob         = $data['dob'] ?? '';
$sex         = $data['sex'] ?? '';
$nationality = trim($data['nationality'] ?? 'Indonesia');
$citizenId   = trim($data['citizen_id'] ?? '');
$pekerjaan   = trim($data['pekerjaan'] ?? 'Freelance');

if (
    $firstName === '' ||
    $lastName === '' ||
    $dob === '' ||
    $sex === '' ||
    $citizenId === ''
) {
    echo json_encode([
        'success' => false,
        'message' => 'Semua field wajib diisi'
    ]);
    exit;
}

// ===============================
// INSERT DATABASE
// ===============================
try {
    $stmt = $pdo->prepare("
    INSERT INTO consumers
            (
                first_name,
                last_name,
                dob,
                sex,
                nationality,
                citizen_id,
                pekerjaan,
                registered_by_user_id
            )
        VALUES
            (?, ?, ?, ?, ?, ?, ?, ?)
    ");

    $stmt->execute([
        $firstName,
        $lastName,
        $dob,
        $sex,
        $nationality,
        $citizenId,
        $pekerjaan,
        $registeredByUserId
    ]);

    echo json_encode([
        'success' => true,
        'id'      => (int)$pdo->lastInsertId() // 🔑 KUNCI OPSI 3
    ]);
    exit;
} catch (PDOException $e) {

    // DUPLICATE citizen_id
    if ($e->getCode() === '23000') {
        echo json_encode([
            'success' => false,
            'message' => 'Citizen ID sudah terdaftar'
        ]);
    } else {
        error_log('[STORE_CONSUMER_ERROR] ' . $e->getMessage());

        echo json_encode([
            'success' => false,
            'message' => 'Gagal menyimpan data konsumen'
        ]);
    }
}
