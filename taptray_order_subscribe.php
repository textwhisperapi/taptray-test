<?php
declare(strict_types=1);

require_once __DIR__ . '/includes/taptray_orders.php';

sec_session_start();
header('Content-Type: application/json; charset=utf-8');

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
$subscription = is_array($payload['subscription'] ?? null) ? $payload['subscription'] : [];
$env = preg_replace('/[^a-z0-9.\-]/i', '', (string) ($payload['env'] ?? ($_SERVER['HTTP_HOST'] ?? 'taptray.com')));

if ($orderReference === '') {
    http_response_code(400);
    echo json_encode(['ok' => false, 'error' => 'Order reference is required.']);
    exit;
}

$saved = tt_orders_register_push_subscription($mysqli, $orderReference, $subscription, $env);
echo json_encode([
    'ok' => $saved,
    'order_reference' => $orderReference,
], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
