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

echo json_encode([
    'ok' => true,
    'orders' => tt_orders_list_open($mysqli),
    'past_orders' => filter_var($_GET['include_past'] ?? false, FILTER_VALIDATE_BOOLEAN)
        ? tt_orders_list_recent_closed($mysqli, (int) ($_GET['past_limit'] ?? 8))
        : [],
], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
