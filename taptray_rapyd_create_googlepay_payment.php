<?php
declare(strict_types=1);

require_once __DIR__ . '/includes/functions.php';
require_once __DIR__ . '/includes/sub_rapyd_config.php';
require_once __DIR__ . '/includes/taptray_orders.php';
sec_session_start();

header('Content-Type: application/json; charset=utf-8');

function tt_rapyd_payment_error(string $message, int $status = 400, array $extra = []): void {
    http_response_code($status);
    echo json_encode(array_merge(['ok' => false, 'error' => $message], $extra), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

function tt_rapyd_currency_exponent(string $currencyCode): int {
    return in_array(strtoupper($currencyCode), ['BIF', 'CLP', 'DJF', 'GNF', 'ISK', 'JPY', 'KMF', 'KRW', 'MGA', 'PYG', 'RWF', 'UGX', 'VND', 'VUV', 'XAF', 'XOF', 'XPF'], true) ? 0 : 2;
}

function tt_rapyd_parse_amount_to_minor($label, string $currencyCode): int {
    $raw = trim((string) $label);
    if ($raw === '') {
        return 0;
    }
    if (preg_match('/(\d+(?:[.,]\d{1,2})?)/', $raw, $match) !== 1) {
        return 0;
    }
    $normalized = str_replace(',', '.', $match[1]);
    $amount = (float) $normalized;
    if (!is_finite($amount) || $amount <= 0) {
        return 0;
    }
    $exponent = tt_rapyd_currency_exponent($currencyCode);
    return $exponent === 0 ? (int) round($amount) : (int) round($amount * (10 ** $exponent));
}

function tt_rapyd_minor_to_major(int $amountMinor, string $currencyCode): float|int {
    $exponent = tt_rapyd_currency_exponent($currencyCode);
    return $exponent === 0 ? $amountMinor : round($amountMinor / (10 ** $exponent), 2);
}

function tt_rapyd_format_minor_for_display(int $amountMinor, string $currencyCode): string {
    $exponent = tt_rapyd_currency_exponent($currencyCode);
    if ($exponent === 0) {
        return (string) $amountMinor;
    }
    return number_format($amountMinor / (10 ** $exponent), $exponent, '.', '');
}

function tt_rapyd_payment_method_map(string $country, string $currency): array {
    $probe = rapyd_request('get', '/v1/payment_methods/countries/' . rawurlencode($country), null, ['currency' => $currency]);
    $map = [];
    if (!$probe['ok'] || !is_array($probe['response']['data'] ?? null)) {
        return $map;
    }
    foreach ($probe['response']['data'] as $method) {
        if (!is_array($method) || (string) ($method['category'] ?? '') !== 'card' || (int) ($method['status'] ?? 0) !== 1) {
            continue;
        }
        $type = trim((string) ($method['type'] ?? ''));
        if ($type === '') {
            continue;
        }
        if (str_ends_with($type, '_visa_card')) {
            $map['VISA'] = $type;
        } elseif (str_ends_with($type, '_mastercard_card')) {
            $map['MASTERCARD'] = $type;
        } elseif (str_ends_with($type, '_amex_card')) {
            $map['AMEX'] = $type;
        } elseif (str_ends_with($type, '_discover_card')) {
            $map['DISCOVER'] = $type;
        } elseif (str_ends_with($type, '_jcb_card')) {
            $map['JCB'] = $type;
        }
    }
    return $map;
}

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'POST') {
    tt_rapyd_payment_error('POST required.', 405);
}

$payload = json_decode((string) file_get_contents('php://input'), true);
if (!is_array($payload)) {
    tt_rapyd_payment_error('Invalid payment payload.');
}

$googlePaymentData = $payload['googlePayPaymentData'] ?? null;
if (!is_array($googlePaymentData)) {
    tt_rapyd_payment_error('No Google Pay payment data was provided.');
}

$cardNetwork = strtoupper(trim((string) ($googlePaymentData['paymentMethodData']['info']['cardNetwork'] ?? '')));
if ($cardNetwork === '') {
    tt_rapyd_payment_error('Google Pay did not return a card network.');
}

$currency = defined('TT_MERCHANT_CURRENCY') ? (string) TT_MERCHANT_CURRENCY : 'EUR';
$merchantCountry = defined('TT_MERCHANT_COUNTRY') ? (string) TT_MERCHANT_COUNTRY : 'IS';
$destinationWallet = trim(tt_env_value('TT_RAPYD_EWALLET', ''));
$walletInfo = is_array($payload['wallet'] ?? null) ? $payload['wallet'] : [];
$orderName = trim((string) ($payload['order_name'] ?? ''));
$requestedOrderReference = trim((string) ($payload['order_reference'] ?? ''));
$customerToken = tt_orders_customer_token();
$customerUsername = isset($_SESSION['username']) ? trim((string) $_SESSION['username']) : '';
$draftOrder = tt_orders_get_customer_checkout_order($mysqli, $customerToken, $requestedOrderReference);
$ownerId = (int) ($draftOrder['owner_id'] ?? 0);
$ownerUsername = trim((string) ($draftOrder['owner_username'] ?? ''));
$ownerDisplayName = trim((string) ($draftOrder['items'][0]['owner_display_name'] ?? ''));
if ($ownerDisplayName === '') {
    $ownerDisplayName = $ownerUsername !== '' ? $ownerUsername : (defined('TT_MERCHANT_NAME') ? (string) TT_MERCHANT_NAME : 'TapTray');
}
$cart = $payload['cart'] ?? null;
if ($draftOrder) {
    $cart = is_array($draftOrder['items'] ?? null) ? $draftOrder['items'] : [];
    if ($orderName !== '') {
        $draftOrder = tt_orders_save_draft_for_owner($mysqli, $customerToken, $customerUsername, [
            'id' => $ownerId,
            'username' => $ownerUsername,
            'display_name' => $ownerDisplayName,
        ], $cart, $orderName) ?? $draftOrder;
    }
} elseif (!is_array($cart) || !$cart) {
    tt_rapyd_payment_error('No TapTray order items were provided.');
}

$normalizedItems = [];
$totalMinor = 0;
$totalQuantity = 0;
foreach ($cart as $row) {
    if (!is_array($row)) {
        continue;
    }
    $quantity = max(0, (int) ($row['quantity'] ?? 0));
    if ($quantity < 1) {
        continue;
    }
    $unitMinor = tt_rapyd_parse_amount_to_minor($row['price_label'] ?? '', $currency);
    if ($unitMinor < 1) {
        continue;
    }
    $title = trim((string) ($row['title'] ?? 'Menu item'));
    if ($title === '') {
        $title = 'Menu item';
    }
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
        'unit_minor' => $unitMinor,
        'line_minor' => $unitMinor * $quantity,
    ];
    $totalMinor += $unitMinor * $quantity;
    $totalQuantity += $quantity;
}

if (!$normalizedItems || $totalMinor < 1) {
    tt_rapyd_payment_error('Your TapTray order has no payable items.');
}

$paymentTypeMap = is_array($payload['rapydPaymentTypeMap'] ?? null) ? $payload['rapydPaymentTypeMap'] : [];
$paymentMethodType = trim((string) ($paymentTypeMap[$cardNetwork] ?? ''));
if ($paymentMethodType === '') {
    $serverMap = tt_rapyd_payment_method_map($merchantCountry, $currency);
    $paymentMethodType = trim((string) ($serverMap[$cardNetwork] ?? ''));
}
if ($paymentMethodType === '') {
    tt_rapyd_payment_error('Rapyd payment method type is missing for ' . $cardNetwork . '.');
}

$orderReference = trim((string) ($draftOrder['order_reference'] ?? ''));
if ($orderReference === '') {
    $orderReference = tt_orders_generate_reference('ttrpay_');
}
$existingOrder = tt_orders_get_existing_processed($mysqli, $orderReference);
if ($existingOrder) {
    echo json_encode([
        'ok' => true,
        'already_processed' => true,
        'success_url' => '/taptray_payment_success.php?order=' . rawurlencode($orderReference),
        'order_reference' => $orderReference,
    ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}
$returnUrl = rapyd_origin_url() . '/taptray_payment_success.php?order=' . rawurlencode($orderReference);

$completedOrder = [
    'reference' => $orderReference,
    'order_name' => $orderName,
    'created_at' => gmdate('c'),
    'owner_id' => $ownerId,
    'owner_username' => $ownerUsername,
    'owner_display_name' => $ownerDisplayName,
    'merchant_country' => $merchantCountry,
    'currency' => $currency,
    'wallet' => [
        'requested_path' => (string) ($walletInfo['requestedPath'] ?? 'Google Pay'),
        'detected_type' => (string) ($walletInfo['detectedType'] ?? 'google_pay'),
        'has_payment_request' => !empty($walletInfo['hasPaymentRequest']),
        'platform' => (string) ($walletInfo['platform'] ?? ''),
        'language' => (string) ($walletInfo['language'] ?? ''),
    ],
    'items' => $normalizedItems,
    'totals' => [
        'quantity' => $totalQuantity,
        'amount_minor' => $totalMinor,
        'amount_display' => tt_rapyd_format_minor_for_display($totalMinor, $currency),
    ],
    'rapyd' => [
        'env' => RAPYD_ENV,
        'merchant_id' => RAPYD_MERCHANT_ID,
        'payment_method_type' => $paymentMethodType,
        'card_network' => $cardNetwork,
    ],
];

$requestBody = [
    'amount' => tt_rapyd_minor_to_major($totalMinor, $currency),
    'currency' => $currency,
    'country' => $merchantCountry,
    'capture' => true,
    'description' => 'TapTray Google Pay order',
    'merchant_reference_id' => $orderReference,
    'complete_payment_url' => $returnUrl,
    'error_payment_url' => rapyd_origin_url() . '/checkout.php',
    'payment_method' => [
        'type' => $paymentMethodType,
        'metadata' => new stdClass(),
        'digital_wallet' => [
            'type' => 'google_pay',
            'details' => [
                'token' => $googlePaymentData,
                'type' => 'CARD',
            ],
        ],
    ],
];

if ($destinationWallet !== '') {
    $requestBody['ewallet'] = $destinationWallet;
}

$response = rapyd_request('post', '/v1/payments', $requestBody, [], 'taptray_rapyd_payment_' . $orderReference);
$data = is_array($response['response']['data'] ?? null) ? $response['response']['data'] : [];
$status = (string) ($data['status'] ?? '');
$paymentId = (string) ($data['id'] ?? '');
$redirectUrl = trim((string) ($data['redirect_url'] ?? ''));

rapyd_log_event('taptray_create_googlepay_payment', [
    'provider' => 'rapyd',
    'order_reference' => $orderReference,
    'payment_id' => $paymentId,
    'card_network' => $cardNetwork,
    'payment_method_type' => $paymentMethodType,
    'status' => $response['status'] ?? 0,
    'operation_status' => (string) ($response['response']['status']['status'] ?? ''),
    'payment_status' => $status,
    'redirect_url' => $redirectUrl,
]);

if (!$response['ok']) {
    tt_rapyd_payment_error('TapTray could not complete Rapyd Google Pay right now.', 502, [
        'details' => (string) ($response['response']['status']['message'] ?? ''),
    ]);
}

$completedOrder['rapyd']['payment_id'] = $paymentId;
$completedOrder['rapyd']['status'] = $status;
$completedOrder['rapyd']['raw'] = $data;
$_SESSION['taptray_pending_order'] = $completedOrder;
$_SESSION['taptray_completed_order'] = $completedOrder;

echo json_encode([
    'ok' => true,
    'success_url' => $returnUrl,
    'redirect_url' => $redirectUrl,
    'order_reference' => $orderReference,
    'payment_id' => $paymentId,
    'status' => $status,
    'amount_minor' => $totalMinor,
    'amount_display' => tt_rapyd_format_minor_for_display($totalMinor, $currency),
], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
