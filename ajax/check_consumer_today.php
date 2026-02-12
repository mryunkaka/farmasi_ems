<?php
// ajax/check_consumer_today.php
header('Content-Type: application/json');

require_once __DIR__ . '/../config/database.php'; // sesuaikan koneksi DB kamu

if (!isset($_POST['consumer_id']) || empty($_POST['consumer_id'])) {
    echo json_encode([
        'exists' => false,
        'message' => 'Consumer ID kosong'
    ]);
    exit;
}

$consumerId = (int) $_POST['consumer_id'];

$sql = "
    SELECT id
    FROM sales
    WHERE consumer_id = :consumer_id
      AND DATE(created_at) = CURDATE()
    LIMIT 1
";

$stmt = $pdo->prepare($sql);
$stmt->execute([
    ':consumer_id' => $consumerId
]);

$exists = $stmt->fetch(PDO::FETCH_ASSOC);

if ($exists) {
    echo json_encode([
        'exists' => true,
        'message' => 'Konsumen ini sudah memiliki transaksi hari ini'
    ]);
} else {
    echo json_encode([
        'exists' => false,
        'message' => 'Konsumen masih bisa melakukan transaksi'
    ]);
}
