<?php
require_once __DIR__ . '/../config/database.php';

header('Content-Type: application/json; charset=utf-8');
ini_set('display_errors', 0);
error_reporting(E_ALL);

$q = trim($_GET['q'] ?? '');

if (strlen($q) < 2) {
    echo json_encode([]);
    exit;
}

try {
    $stmt = $pdo->prepare("
        SELECT
            c.id,
            c.first_name,
            c.last_name,
            c.citizen_id,
            c.sex,
            DATE_FORMAT(c.created_at, '%d %b %Y %H:%i') AS created_date,
            COALESCE(ur.full_name, 'SYSTEM') AS registered_by
        FROM consumers c
        LEFT JOIN user_rh ur
            ON ur.id = c.registered_by_user_id
        WHERE
            c.first_name LIKE :q
            OR c.last_name LIKE :q
            OR c.citizen_id LIKE :q
        ORDER BY c.created_at DESC
        LIMIT 8
    ");

    $stmt->execute([
        ':q' => "%{$q}%"
    ]);

    echo json_encode(
        $stmt->fetchAll(PDO::FETCH_ASSOC),
        JSON_UNESCAPED_UNICODE
    );
} catch (Throwable $e) {
    error_log('[SEARCH_CONSUMERS_ERROR] ' . $e->getMessage());
    http_response_code(500);
    echo json_encode([]);
}
