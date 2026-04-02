<?php
declare(strict_types=1);

require_once __DIR__ . '/includes/functions.php';
require_once __DIR__ . '/includes/sub_worldline_config.php';
require_once __DIR__ . '/includes/taptray_orders.php';
sec_session_start();

use Worldline\Connect\Sdk\V1\Domain\AmountOfMoney;
use Worldline\Connect\Sdk\V1\Domain\CreateHostedCheckoutRequest;
use Worldline\Connect\Sdk\V1\Domain\HostedCheckoutSpecificInput;
use Worldline\Connect\Sdk\V1\Domain\Order;
use Worldline\Connect\Sdk\V1\Domain\OrderReferences;
use Worldline\Connect\Sdk\V1\ResponseException;

header('Content-Type: application/json; charset=utf-8');

function tt_checkout_error(string $message, int $status = 400): void {
    http_response_code($status);
    echo json_encode(['ok' => false, 'error' => $message], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

function tt_parse_price_to_minor($label): int {
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
    return (int) round($amount * 100);
}

function tt_current_origin(): string {
    $scheme = 'http';
    if (!empty($_SERVER['HTTP_X_FORWARDED_PROTO'])) {
        $scheme = strtolower((string) $_SERVER['HTTP_X_FORWARDED_PROTO']) === 'https' ? 'https' : 'http';
    } elseif (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') {
        $scheme = 'https';
    }
    $host = trim((string) ($_SERVER['HTTP_HOST'] ?? ''));
    if ($host === '') {
        return '';
    }
    return $scheme . '://' . $host;
}

function tt_checkout_locale(): string {
    $language = strtolower(substr((string) ($_SERVER['HTTP_ACCEPT_LANGUAGE'] ?? 'en'), 0, 2));
    return match ($language) {
        'is' => 'is_IS',
        'en' => 'en_GB',
        default => 'en_GB',
    };
}

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'POST') {
    tt_checkout_error('POST required.', 405);
}

$rawBody = file_get_contents('php://input');
$payload = json_decode((string) $rawBody, true);
if (!is_array($payload)) {
    tt_checkout_error('Invalid checkout payload.');
}

$requestedOrderReference = trim((string) ($payload['order_reference'] ?? ''));
$customerToken = tt_orders_customer_token();
$customerUsername = isset($_SESSION['username']) ? trim((string) $_SESSION['username']) : '';
$draftOrder = tt_orders_get_customer_checkout_order($mysqli, $customerToken, $requestedOrderReference);
$cart = $payload['cart'] ?? null;
if ($draftOrder) {
    $cart = is_array($draftOrder['items'] ?? null) ? $draftOrder['items'] : [];
} elseif (!is_array($cart) || !$cart) {
    tt_checkout_error('No TapTray order items were provided.');
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
    $unitMinor = tt_parse_price_to_minor($row['price_label'] ?? '');
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
    tt_checkout_error('Your TapTray order has no payable items.');
}

$orderReference = trim((string) ($draftOrder['order_reference'] ?? ''));
if ($orderReference === '') {
    $orderReference = tt_orders_generate_reference('ttord_');
}
$origin = tt_current_origin();
if ($origin === '') {
    tt_checkout_error('Could not determine TapTray origin URL.', 500);
}
$returnUrl = $origin . '/taptray_worldline_success.php?order=' . rawurlencode($orderReference);
$currency = defined('TT_MERCHANT_CURRENCY') ? (string) TT_MERCHANT_CURRENCY : 'ISK';
$merchantName = defined('TT_MERCHANT_NAME') ? (string) TT_MERCHANT_NAME : 'TapTray';
$merchantCountry = defined('TT_MERCHANT_COUNTRY') ? (string) TT_MERCHANT_COUNTRY : 'IS';
$wallet = is_array($payload['wallet'] ?? null) ? $payload['wallet'] : [];
$orderName = trim((string) ($payload['order_name'] ?? ''));
if ($draftOrder && $orderName !== '') {
    $draftOrder = tt_orders_save_draft($mysqli, $customerToken, $customerUsername, $cart, $orderName) ?? $draftOrder;
}

if (!defined('WL_SDK_AVAILABLE') || !WL_SDK_AVAILABLE) {
    tt_checkout_error('Worldline checkout is not available on this server yet. The wallet-first payment step is not wired to a live processor here yet.', 503);
}

$amount = new AmountOfMoney();
$amount->amount = $totalMinor;
$amount->currencyCode = $currency;

$order = new Order();
$order->amountOfMoney = $amount;
$orderReferences = new OrderReferences();
$orderReferences->merchantReference = $orderReference;
$order->references = $orderReferences;

$hostedInput = new HostedCheckoutSpecificInput();
$hostedInput->returnUrl = $returnUrl;
$hostedInput->locale = tt_checkout_locale();
$hostedInput->showResultPage = false;

$request = new CreateHostedCheckoutRequest();
$request->order = $order;
$request->hostedCheckoutSpecificInput = $hostedInput;

$_SESSION['taptray_pending_order'] = [
    'reference' => $orderReference,
    'order_name' => $orderName,
    'created_at' => gmdate('c'),
    'merchant_name' => $merchantName,
    'merchant_country' => $merchantCountry,
    'currency' => $currency,
    'wallet' => [
        'requested_path' => (string) ($wallet['requestedPath'] ?? ''),
        'detected_type' => (string) ($wallet['detectedType'] ?? ''),
        'has_apple_pay' => !empty($wallet['hasApplePay']),
        'has_payment_request' => !empty($wallet['hasPaymentRequest']),
        'platform' => (string) ($wallet['platform'] ?? ''),
        'language' => (string) ($wallet['language'] ?? ''),
    ],
    'items' => $normalizedItems,
    'totals' => [
        'quantity' => $totalQuantity,
        'amount_minor' => $totalMinor,
    ],
    'worldline' => [
        'env' => defined('WL_ENV') ? WL_ENV : 'unknown',
        'merchant_id' => defined('WL_MERCHANT_ID') ? WL_MERCHANT_ID : '',
        'return_url' => $returnUrl,
    ],
];

try {
    $response = wl_client()
        ->v1()
        ->merchant(WL_MERCHANT_ID)
        ->hostedcheckouts()
        ->create($request);

    $redirectUrl = wl_redirect_url($response);
    if (!$redirectUrl) {
        throw new RuntimeException('Worldline did not return a checkout redirect URL.');
    }

    $_SESSION['taptray_pending_order']['worldline']['hosted_checkout_id'] = (string) ($response->hostedCheckoutId ?? '');
    $_SESSION['taptray_pending_order']['worldline']['redirect_url'] = $redirectUrl;

    echo json_encode([
        'ok' => true,
        'redirect_url' => $redirectUrl,
        'order_reference' => $orderReference,
        'hosted_checkout_id' => (string) ($response->hostedCheckoutId ?? ''),
        'merchant' => $merchantName,
        'currency' => $currency,
        'amount_minor' => $totalMinor,
    ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
} catch (ResponseException $e) {
    error_log('[taptray_worldline] Worldline response exception: ' . $e->getMessage());
    tt_checkout_error('Worldline rejected this checkout request.', 502);
} catch (Throwable $e) {
    error_log('[taptray_worldline] Checkout creation failed: ' . $e->getMessage());
    tt_checkout_error('TapTray could not start payment right now.', 500);
}
