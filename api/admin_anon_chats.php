<?php
declare(strict_types=1);

header('Content-Type: application/json; charset=utf-8');

require_once __DIR__ . '/auth_helpers.php';

bootstrap_session();
require_admin();
$pdo = db_or_fail();

if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'GET') {
    http_response_code(405);
    echo json_encode(['error' => 'Unsupported method.']);
    exit;
}

$id = $_GET['id'] ?? null;

if ($id !== null) {
    $stmt = $pdo->prepare('SELECT id, title, messages, created_at, updated_at FROM anonymous_chats WHERE id = ?');
    $stmt->execute([(int)$id]);
    $row = $stmt->fetch();
    if (!$row) {
        http_response_code(404);
        echo json_encode(['error' => 'Not found.']);
        exit;
    }
    $row['messages'] = json_decode($row['messages'], true) ?? [];
    echo json_encode($row);
    exit;
}

$stmt = $pdo->query('SELECT id, title, created_at, updated_at FROM anonymous_chats ORDER BY updated_at DESC LIMIT 200');
echo json_encode($stmt->fetchAll());
