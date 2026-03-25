<?php
declare(strict_types=1);

require_once __DIR__ . '/includes/functions.php';
require_once __DIR__ . '/includes/sub_worldline_config.php';
sec_session_start();

header('Content-Type: application/json; charset=utf-8');

function tt_payment_error(string $message, int $status = 400, array $extra = []): void {
    http_response_code($status);
    echo json_encode(array_merge(['ok' => false, 'error' => $message], $extra), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

function tt_currency_exponent(string $currencyCode): int {
    return in_array(strtoupper($currencyCode), ['BIF', 'CLP', 'DJF', 'GNF', 'ISK', 'JPY', 'KMF', 'KRW', 'MGA', 'PYG', 'RWF', 'UGX', 'VND', 'VUV', 'XAF', 'XOF', 'XPF'], true) ? 0 : 2;
}

function tt_parse_amount_to_minor($label, string $currencyCode): int {
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

    $exponent = tt_currency_exponent($currencyCode);
    if ($exponent === 0) {
        return (int) round($amount);
    }
    return (int) round($amount * (10 ** $exponent));
}

function tt_format_minor_for_display(int $amountMinor, string $currencyCode): string {
    $exponent = tt_currency_exponent($currencyCode);
    if ($exponent === 0) {
        return (string) $amountMinor;
    }
    return number_format($amountMinor / (10 ** $exponent), $exponent, '.', '');
}

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'POST') {
    tt_payment_error('POST required.', 405);
}

$rawBody = file_get_contents('php://input');
$payload = json_decode((string) $rawBody, true);
if (!is_array($payload)) {
    tt_payment_error('Invalid payment payload.');
}

if (!defined('TT_WALLET_ENABLED') || !TT_WALLET_ENABLED) {
    tt_payment_error('Wallet-first payment is disabled in TapTray settings.', 409);
}

$cart = $payload['cart'] ?? null;
if (!is_array($cart) || !$cart) {
    tt_payment_error('No TapTray order items were provided.');
}

$googlePaymentData = $payload['googlePayPaymentData'] ?? null;
if (!is_array($googlePaymentData)) {
    tt_payment_error('No Google Pay payment data was provided.');
}

$token = trim((string) ($googlePaymentData['paymentMethodData']['tokenizationData']['token'] ?? ''));
if ($token === '') {
    tt_payment_error('Google Pay did not return a payment token.');
}

$currency = defined('TT_MERCHANT_CURRENCY') ? (string) TT_MERCHANT_CURRENCY : 'ISK';
$merchantName = defined('TT_MERCHANT_NAME') ? (string) TT_MERCHANT_NAME : 'TapTray';
$merchantCountry = defined('TT_MERCHANT_COUNTRY') ? (string) TT_MERCHANT_COUNTRY : 'IS';
$walletInfo = is_array($payload['wallet'] ?? null) ? $payload['wallet'] : [];
$deviceInfo = is_array($payload['device'] ?? null) ? $payload['device'] : [];

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
    $unitMinor = tt_parse_amount_to_minor($row['price_label'] ?? '', $currency);
    if ($unitMinor < 1) {
        continue;
    }

    $title = trim((string) ($row['title'] ?? 'Menu item'));
    if ($title === '') {
        $title = 'Menu item';
    }

    $normalizedItems[] = [
        'id' => (string) ($row['id'] ?? ''),
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
    tt_payment_error('Your TapTray order has no payable items.');
}

$orderReference = 'ttpay_' . gmdate('Ymd_His') . '_' . bin2hex(random_bytes(4));

$worldlinePayload = [
    'order' => [
        'amountOfMoney' => [
            'amount' => $totalMinor,
            'currencyCode' => $currency,
        ],
        'references' => [
            'merchantReference' => $orderReference,
        ],
        'customer' => [
            'merchantCustomerId' => 'taptray_guest',
            'billingAddress' => [
                'countryCode' => $merchantCountry,
            ],
            'locale' => (string) ($deviceInfo['language'] ?? $walletInfo['language'] ?? 'en-GB'),
            'device' => [
                'acceptHeader' => (string) ($deviceInfo['acceptHeader'] ?? '*/*'),
                'userAgent' => (string) ($deviceInfo['userAgent'] ?? ($_SERVER['HTTP_USER_AGENT'] ?? '')),
                'ipAddressCountryCode' => $merchantCountry,
            ],
        ],
    ],
    'mobilePaymentMethodSpecificInput' => [
        'paymentProductId' => 320,
        'encryptedPaymentData' => $token,
    ],
];

try {
    $payment = wl_create_payment($worldlinePayload);
} catch (Throwable $e) {
    error_log('[taptray_worldline_googlepay] Direct payment failed: ' . $e->getMessage());
    tt_payment_error('TapTray could not complete wallet payment right now.', 502, [
        'details' => $e->getMessage(),
    ]);
}

$paymentId = (string) ($payment['payment']['id'] ?? $payment['id'] ?? '');
$status = (string) ($payment['payment']['status'] ?? $payment['status'] ?? '');
$statusCategory = (string) ($payment['payment']['statusOutput']['statusCategory'] ?? $payment['statusOutput']['statusCategory'] ?? '');

$completedOrder = [
    'reference' => $orderReference,
    'created_at' => gmdate('c'),
    'merchant_name' => $merchantName,
    'merchant_country' => $merchantCountry,
    'currency' => $currency,
    'wallet' => [
        'requested_path' => (string) ($walletInfo['requestedPath'] ?? 'Phone wallet'),
        'detected_type' => (string) ($walletInfo['detectedType'] ?? 'wallet'),
        'has_payment_request' => !empty($walletInfo['hasPaymentRequest']),
        'platform' => (string) ($walletInfo['platform'] ?? ''),
        'language' => (string) ($walletInfo['language'] ?? ''),
    ],
    'items' => $normalizedItems,
    'totals' => [
        'quantity' => $totalQuantity,
        'amount_minor' => $totalMinor,
        'amount_display' => tt_format_minor_for_display($totalMinor, $currency),
    ],
    'worldline' => [
        'env' => defined('WL_ENV') ? WL_ENV : 'unknown',
        'merchant_id' => defined('WL_MERCHANT_ID') ? WL_MERCHANT_ID : '',
        'payment_id' => $paymentId,
        'status' => $status,
        'status_category' => $statusCategory,
        'raw' => $payment,
    ],
];

$_SESSION['taptray_pending_order'] = $completedOrder;
$_SESSION['taptray_completed_order'] = $completedOrder;

$successUrl = '/taptray_success_worldline.php?order=' . rawurlencode($orderReference);

echo json_encode([
    'ok' => true,
    'success_url' => $successUrl,
    'order_reference' => $orderReference,
    'payment_id' => $paymentId,
    'status' => $status,
    'status_category' => $statusCategory,
    'amount_minor' => $totalMinor,
    'amount_display' => tt_format_minor_for_display($totalMinor, $currency),
], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
