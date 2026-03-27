<?php
declare(strict_types=1);

require_once __DIR__ . '/includes/taptray_orders.php';

sec_session_start();
header('Content-Type: application/json; charset=utf-8');

if (!login_check($mysqli) || empty($_SESSION['username'])) {
    http_response_code(403);
    echo json_encode(['ok' => false, 'error' => 'Not logged in.']);
    exit;
}

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'POST') {
    http_response_code(405);
    echo json_encode(['ok' => false, 'error' => 'POST required.']);
    exit;
}

$payload = json_decode((string) file_get_contents('php://input'), true);
if (!is_array($payload)) {
    http_response_code(400);
    echo json_encode(['ok' => false, 'error' => 'Invalid payload.']);
    exit;
}

$orderReference = trim((string) ($payload['order_reference'] ?? ''));
$status = trim((string) ($payload['status'] ?? ''));
$order = tt_orders_update_status($mysqli, $orderReference, $status);

echo json_encode([
    'ok' => $order !== null,
    'order' => $order,
], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
