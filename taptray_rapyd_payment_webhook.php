<?php
declare(strict_types=1);

require_once __DIR__ . '/includes/functions.php';
require_once __DIR__ . '/includes/db_connect.php';
require_once __DIR__ . '/includes/sub_rapyd_config.php';
require_once __DIR__ . '/includes/taptray_orders.php';

header('Content-Type: text/plain; charset=utf-8');

function tt_rapyd_webhook_fail(string $message, int $status = 400): void {
    rapyd_log_event('taptray_payment_webhook_error', [
        'http_status' => $status,
        'message' => $message,
        'request_url' => rapyd_current_request_url(),
    ]);
    http_response_code($status);
    echo $message;
    exit;
}

function tt_rapyd_webhook_get_event(mysqli $mysqli, string $eventId): ?array {
    $stmt = $mysqli->prepare("SELECT event_id, handled, payload FROM webhook_events WHERE event_id = ? LIMIT 1");
    if (!$stmt) {
        return null;
    }
    $stmt->bind_param('s', $eventId);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc() ?: null;
    $stmt->close();
    return $row;
}

function tt_rapyd_webhook_log_event(mysqli $mysqli, string $eventId, string $eventType, string $payloadJson): bool {
    $stmt = $mysqli->prepare("
        INSERT INTO webhook_events (event_id, event_type, payload)
        VALUES (?, ?, ?)
    ");
    if (!$stmt) {
        return false;
    }
    $stmt->bind_param('sss', $eventId, $eventType, $payloadJson);
    $ok = $stmt->execute();
    $stmt->close();
    return $ok;
}

function tt_rapyd_webhook_mark_handled(mysqli $mysqli, string $eventId): void {
    $stmt = $mysqli->prepare("UPDATE webhook_events SET handled = 1 WHERE event_id = ?");
    if (!$stmt) {
        return;
    }
    $stmt->bind_param('s', $eventId);
    $stmt->execute();
    $stmt->close();
}

function tt_rapyd_webhook_mark_paid(mysqli $mysqli, string $orderReference): ?array {
    $orderReference = trim($orderReference);
    if ($orderReference === '') {
        return null;
    }

    $existing = tt_orders_get_by_reference($mysqli, $orderReference);
    if (!$existing) {
        return null;
    }
    if (trim((string) ($existing['status'] ?? '')) === 'closed') {
        return $existing;
    }

    return tt_orders_update_status($mysqli, $orderReference, 'queued');
}

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'POST') {
    tt_rapyd_webhook_fail('POST required.', 405);
}

$rawPayload = (string) file_get_contents('php://input');
$payload = json_decode($rawPayload, true);
if (!is_array($payload)) {
    tt_rapyd_webhook_fail('Invalid JSON body.');
}

$bodyString = rapyd_compact_json($payload);
$salt = trim((string) ($_SERVER['HTTP_SALT'] ?? ''));
$timestamp = trim((string) ($_SERVER['HTTP_TIMESTAMP'] ?? ''));
$signature = trim((string) ($_SERVER['HTTP_SIGNATURE'] ?? ''));
$accessKey = trim((string) ($_SERVER['HTTP_ACCESS_KEY'] ?? ''));

if ($salt === '' || $timestamp === '' || $signature === '') {
    tt_rapyd_webhook_fail('Missing Rapyd webhook headers.');
}
if ($accessKey !== '' && !hash_equals(RAPYD_ACCESS_KEY, $accessKey)) {
    tt_rapyd_webhook_fail('Invalid access key.');
}

$expectedSignature = rapyd_webhook_signature(rapyd_current_request_url(), $salt, $timestamp, $bodyString);
if (!hash_equals($expectedSignature, $signature)) {
    tt_rapyd_webhook_fail('Invalid signature.');
}

$eventId = trim((string) ($payload['id'] ?? ''));
$eventType = strtoupper(trim((string) ($payload['type'] ?? 'UNKNOWN')));
$payloadJson = json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
if ($eventId === '' || !is_string($payloadJson)) {
    tt_rapyd_webhook_fail('Invalid webhook payload.');
}

rapyd_log_event('taptray_payment_webhook_received', [
    'event_id' => $eventId,
    'event_type' => $eventType,
    'request_url' => rapyd_current_request_url(),
]);

$existingEvent = tt_rapyd_webhook_get_event($mysqli, $eventId);
if ($existingEvent && (int) ($existingEvent['handled'] ?? 0) === 1) {
    rapyd_log_event('taptray_payment_webhook_duplicate', [
        'event_id' => $eventId,
        'event_type' => $eventType,
    ]);
    http_response_code(200);
    echo 'Webhook already handled.';
    exit;
}
if (!$existingEvent && !tt_rapyd_webhook_log_event($mysqli, $eventId, $eventType, $payloadJson)) {
    tt_rapyd_webhook_fail('Unable to log webhook.', 500);
}

$data = is_array($payload['data'] ?? null) ? $payload['data'] : [];
$orderReference = trim((string) ($data['merchant_reference_id'] ?? ''));
$paymentId = trim((string) ($data['id'] ?? ''));

rapyd_log_event('taptray_payment_webhook', [
    'event_id' => $eventId,
    'event_type' => $eventType,
    'order_reference' => $orderReference,
    'payment_id' => $paymentId,
    'status' => (string) ($data['status'] ?? ''),
]);

if ($eventType === 'PAYMENT_COMPLETED') {
    $order = tt_rapyd_webhook_mark_paid($mysqli, $orderReference);
    if (!$order) {
        tt_rapyd_webhook_fail('Order not found for completed payment.', 500);
    }
    rapyd_log_event('taptray_payment_webhook_completed', [
        'event_id' => $eventId,
        'event_type' => $eventType,
        'order_reference' => $orderReference,
        'payment_id' => $paymentId,
        'new_status' => (string) ($order['status'] ?? ''),
    ]);
    tt_rapyd_webhook_mark_handled($mysqli, $eventId);
    http_response_code(200);
    echo 'Payment completed.';
    exit;
}

if (in_array($eventType, ['PAYMENT_FAILED', 'PAYMENT_CANCELED', 'PAYMENT_EXPIRED'], true)) {
    rapyd_log_event('taptray_payment_webhook_outcome', [
        'event_id' => $eventId,
        'event_type' => $eventType,
        'order_reference' => $orderReference,
        'payment_id' => $paymentId,
    ]);
    tt_rapyd_webhook_mark_handled($mysqli, $eventId);
    http_response_code(200);
    echo 'Payment outcome recorded.';
    exit;
}

rapyd_log_event('taptray_payment_webhook_ignored', [
    'event_id' => $eventId,
    'event_type' => $eventType,
    'order_reference' => $orderReference,
    'payment_id' => $paymentId,
]);
tt_rapyd_webhook_mark_handled($mysqli, $eventId);
http_response_code(200);
echo 'Event ignored.';
