<?php
declare(strict_types=1);

require_once __DIR__ . '/includes/functions.php';
require_once __DIR__ . '/includes/sub_rapyd_config.php';
require_once __DIR__ . '/includes/taptray_orders.php';
sec_session_start();

header('Content-Type: application/json; charset=utf-8');

function tt_rapyd_checkout_error(string $message, int $status = 400): void {
    http_response_code($status);
    echo json_encode(['ok' => false, 'error' => $message], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

function tt_rapyd_parse_price_to_amount($label): float {
    $raw = trim((string) $label);
    if ($raw === '') {
        return 0.0;
    }
    if (preg_match('/(\d+(?:[.,]\d{1,2})?)/', $raw, $match) !== 1) {
        return 0.0;
    }
    $normalized = str_replace(',', '.', $match[1]);
    $amount = (float) $normalized;
    if (!is_finite($amount) || $amount <= 0) {
        return 0.0;
    }
    return round($amount, 2);
}

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'POST') {
    tt_rapyd_checkout_error('POST required.', 405);
}

$payload = json_decode((string) file_get_contents('php://input'), true);
if (!is_array($payload)) {
    tt_rapyd_checkout_error('Invalid checkout payload.');
}

$requestedOrderReference = trim((string) ($payload['order_reference'] ?? ''));
$customerToken = tt_orders_customer_token();
$customerUsername = isset($_SESSION['username']) ? trim((string) $_SESSION['username']) : '';
$draftOrder = tt_orders_get_customer_checkout_order($mysqli, $customerToken, $requestedOrderReference);
$cart = $payload['cart'] ?? null;
if ($draftOrder) {
    $cart = is_array($draftOrder['items'] ?? null) ? $draftOrder['items'] : [];
} elseif (!is_array($cart) || !$cart) {
    tt_rapyd_checkout_error('No TapTray order items were provided.');
}

$normalizedItems = [];
$totalAmount = 0.0;
$totalQuantity = 0;
foreach ($cart as $row) {
    if (!is_array($row)) {
        continue;
    }
    $quantity = max(0, (int) ($row['quantity'] ?? 0));
    if ($quantity < 1) {
        continue;
    }
    $unitAmount = tt_rapyd_parse_price_to_amount($row['price_label'] ?? '');
    if ($unitAmount <= 0) {
        continue;
    }
    $title = trim((string) ($row['title'] ?? 'Menu item'));
    if ($title === '') {
        $title = 'Menu item';
    }
    $lineAmount = round($unitAmount * $quantity, 2);
    $normalizedItems[] = [
        'id' => (string) ($row['id'] ?? ''),
        'surrogate' => (int) ($row['surrogate'] ?? 0),
        'token' => trim((string) ($row['token'] ?? '')),
        'title' => $title,
        'quantity' => $quantity,
        'price_label' => trim((string) ($row['price_label'] ?? '')),
        'short_description' => trim((string) ($row['short_description'] ?? '')),
        'detailed_description' => trim((string) ($row['detailed_description'] ?? '')),
        'image_url' => trim((string) ($row['image_url'] ?? '')),
        'unit_minor' => (int) round($unitAmount * 100),
        'line_minor' => (int) round($lineAmount * 100),
    ];
    $totalAmount += $lineAmount;
    $totalQuantity += $quantity;
}

if (!$normalizedItems || $totalAmount <= 0) {
    tt_rapyd_checkout_error('Your TapTray order has no payable items.');
}

$orderReference = trim((string) ($draftOrder['order_reference'] ?? ''));
if ($orderReference === '') {
    $orderReference = tt_orders_generate_reference('ttrapyd_');
}
$origin = rapyd_origin_url();
$returnUrl = $origin . '/taptray_worldline_success.php?order=' . rawurlencode($orderReference);
$currency = defined('TT_MERCHANT_CURRENCY') ? (string) TT_MERCHANT_CURRENCY : 'ISK';
$merchantName = defined('TT_MERCHANT_NAME') ? (string) TT_MERCHANT_NAME : 'TapTray';
$merchantCountry = defined('TT_MERCHANT_COUNTRY') ? (string) TT_MERCHANT_COUNTRY : 'IS';
$wallet = is_array($payload['wallet'] ?? null) ? $payload['wallet'] : [];
$orderName = trim((string) ($payload['order_name'] ?? ''));
if ($draftOrder && $orderName !== '') {
    $draftOrder = tt_orders_save_draft($mysqli, $customerToken, $customerUsername, $cart, $orderName) ?? $draftOrder;
}

$_SESSION['taptray_pending_order'] = [
    'reference' => $orderReference,
    'order_name' => $orderName,
    'created_at' => gmdate('c'),
    'merchant_name' => $merchantName,
    'merchant_country' => $merchantCountry,
    'currency' => $currency,
    'wallet' => [
        'requested_path' => (string) ($wallet['requestedPath'] ?? 'Phone wallet'),
        'detected_type' => (string) ($wallet['detectedType'] ?? ''),
        'has_apple_pay' => !empty($wallet['hasApplePay']),
        'has_payment_request' => !empty($wallet['hasPaymentRequest']),
        'platform' => (string) ($wallet['platform'] ?? ''),
        'language' => (string) ($wallet['language'] ?? ''),
    ],
    'items' => $normalizedItems,
    'totals' => [
        'quantity' => $totalQuantity,
        'amount_minor' => (int) round($totalAmount * 100),
    ],
    'rapyd' => [
        'env' => RAPYD_ENV,
        'merchant_id' => RAPYD_MERCHANT_ID,
        'return_url' => $returnUrl,
    ],
];

$body = [
    'amount' => round($totalAmount, 2),
    'currency' => $currency,
    'country' => $merchantCountry,
    'language' => 'en',
    'merchant_reference_id' => $orderReference,
    'complete_checkout_url' => $returnUrl,
    'cancel_checkout_url' => $origin . '/checkout.php?provider=rapyd',
    'complete_payment_url' => $returnUrl,
    'error_payment_url' => $origin . '/checkout.php?provider=rapyd',
];

$response = rapyd_request('post', '/v1/checkout', $body);
$data = is_array($response['response']['data'] ?? null) ? $response['response']['data'] : [];
$redirectUrl = trim((string) ($data['redirect_url'] ?? ''));
$checkoutId = trim((string) ($data['id'] ?? ''));

if (!$response['ok'] || $redirectUrl === '') {
    error_log('[taptray_rapyd] Checkout creation failed: ' . ($response['raw'] ?? ''));
    tt_rapyd_checkout_error('TapTray could not start Rapyd checkout right now.', 502);
}

$_SESSION['taptray_pending_order']['rapyd']['checkout_id'] = $checkoutId;
$_SESSION['taptray_pending_order']['rapyd']['redirect_url'] = $redirectUrl;

echo json_encode([
    'ok' => true,
    'redirect_url' => $redirectUrl,
    'order_reference' => $orderReference,
    'checkout_id' => $checkoutId,
    'merchant' => $merchantName,
    'currency' => $currency,
    'amount_minor' => (int) round($totalAmount * 100),
], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
