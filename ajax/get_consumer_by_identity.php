<?php
require_once __DIR__ . '/../config/database.php';

header('Content-Type: application/json; charset=utf-8');

$citizenId = trim($_GET['citizen_id'] ?? '');
$fullName  = trim($_GET['name'] ?? '');

if ($citizenId === '' && $fullName === '') {
    echo json_encode(['success' => false]);
    exit;
}

try {
    if ($citizenId !== '') {
        $stmt = $pdo->prepare("
            SELECT id, first_name, last_name
            FROM consumers
            WHERE citizen_id = ?
            LIMIT 1
        ");
        $stmt->execute([$citizenId]);
    } else {
        $stmt = $pdo->prepare("
            SELECT id, first_name, last_name
            FROM consumers
            WHERE CONCAT(first_name, ' ', last_name) = ?
            ORDER BY created_at DESC
            LIMIT 1
        ");
        $stmt->execute([$fullName]);
    }

    $row = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$row) {
        echo json_encode(['success' => false]);
        exit;
    }

    echo json_encode([
        'success' => true,
        'id'      => (int)$row['id'],
        'name'    => trim($row['first_name'] . ' ' . $row['last_name'])
    ]);
} catch (Throwable $e) {
    error_log('[GET_CONSUMER_REALTIME] ' . $e->getMessage());
    echo json_encode(['success' => false]);
}
