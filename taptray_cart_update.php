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

$action = trim((string) ($payload['action'] ?? ''));
$item = is_array($payload['item'] ?? null) ? $payload['item'] : null;
if (!in_array($action, ['add', 'reduce', 'remove'], true) || !$item) {
    http_response_code(400);
    echo json_encode(['ok' => false, 'error' => 'Invalid cart mutation.']);
    exit;
}

$surrogate = (int) ($item['surrogate'] ?? 0);
$title = trim((string) ($item['title'] ?? ''));
$priceLabel = trim((string) ($item['price_label'] ?? ''));
$ownerId = (int) ($item['owner_id'] ?? 0);
$ownerUsername = trim((string) ($item['owner_username'] ?? ''));
$ownerDisplayName = trim((string) ($item['owner_display_name'] ?? ''));
if ($surrogate < 1 || $title === '' || $priceLabel === '' || ($ownerId < 1 && $ownerUsername === '')) {
    http_response_code(400);
    echo json_encode(['ok' => false, 'error' => 'Incomplete TapTray item data.']);
    exit;
}

$currentToken = tt_orders_customer_token();
$currentUsername = isset($_SESSION['username']) ? trim((string) $_SESSION['username']) : '';
$owner = [
    'id' => $ownerId,
    'username' => $ownerUsername,
    'display_name' => $ownerDisplayName,
];
$draft = tt_orders_get_draft_for_customer_owner($mysqli, $currentToken, $owner);
$items = is_array($draft['items'] ?? null) ? $draft['items'] : [];
$bySurrogate = [];
foreach ($items as $row) {
    if (!is_array($row)) {
        continue;
    }
    $key = (string) ((int) ($row['surrogate'] ?? 0));
    if ($key !== '0') {
        $bySurrogate[$key] = $row;
    }
}

$key = (string) $surrogate;
$existing = is_array($bySurrogate[$key] ?? null) ? $bySurrogate[$key] : [
    'id' => (string) ($item['id'] ?? $key),
    'surrogate' => $surrogate,
    'token' => trim((string) ($item['token'] ?? '')),
    'owner_id' => $ownerId,
    'owner_username' => $ownerUsername,
    'owner_display_name' => $ownerDisplayName !== '' ? $ownerDisplayName : $ownerUsername,
    'title' => $title,
    'quantity' => 0,
    'price_label' => $priceLabel,
    'short_description' => trim((string) ($item['short_description'] ?? '')),
    'detailed_description' => trim((string) ($item['detailed_description'] ?? '')),
    'image_url' => trim((string) ($item['image_url'] ?? '')),
    'unit_minor' => (int) ($item['unit_minor'] ?? 0),
    'line_minor' => 0,
];

if ((int) ($existing['owner_id'] ?? 0) < 1) {
    $existing['owner_id'] = $ownerId;
}
if (trim((string) ($existing['owner_username'] ?? '')) === '') {
    $existing['owner_username'] = $ownerUsername;
}
if (trim((string) ($existing['owner_display_name'] ?? '')) === '') {
    $existing['owner_display_name'] = $ownerDisplayName !== '' ? $ownerDisplayName : $ownerUsername;
}

if ($action === 'add') {
    $existing['quantity'] = max(0, (int) ($existing['quantity'] ?? 0)) + 1;
} elseif ($action === 'reduce') {
    $existing['quantity'] = max(0, (int) ($existing['quantity'] ?? 0) - 1);
} else {
    $existing['quantity'] = 0;
}

if ((int) ($existing['quantity'] ?? 0) > 0) {
    $unitMinor = (int) ($existing['unit_minor'] ?? 0);
    if ($unitMinor < 1 && preg_match('/(\d+(?:[.,]\d{1,2})?)/', $priceLabel, $match) === 1) {
        $normalized = str_replace(',', '.', $match[1]);
        $amount = (float) $normalized;
        $currency = defined('TT_MERCHANT_CURRENCY') ? strtoupper((string) TT_MERCHANT_CURRENCY) : 'EUR';
        $zeroDecimal = in_array($currency, ['BIF', 'CLP', 'DJF', 'GNF', 'ISK', 'JPY', 'KMF', 'KRW', 'MGA', 'PYG', 'RWF', 'UGX', 'VND', 'VUV', 'XAF', 'XOF', 'XPF'], true);
        $unitMinor = $zeroDecimal ? (int) round($amount) : (int) round($amount * 100);
        $existing['unit_minor'] = $unitMinor;
    }
    $existing['line_minor'] = max(0, (int) ($existing['unit_minor'] ?? 0)) * (int) $existing['quantity'];
    $bySurrogate[$key] = $existing;
} else {
    unset($bySurrogate[$key]);
}

$saved = tt_orders_save_draft_for_owner(
    $mysqli,
    $currentToken,
    $currentUsername,
    $owner,
    array_values($bySurrogate),
    trim((string) ($draft['order_name'] ?? ''))
);
$draftOrders = tt_orders_list_drafts_for_customer($mysqli, $currentToken);

echo json_encode([
    'ok' => true,
    'draft_order' => $saved,
    'draft_orders' => $draftOrders,
], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
