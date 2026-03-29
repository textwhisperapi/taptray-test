<?php
declare(strict_types=1);

require_once __DIR__ . '/includes/functions.php';
require_once __DIR__ . '/includes/sub_rapyd_config.php';
sec_session_start();

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
header('Pragma: no-cache');
header('Expires: 0');

$amount = (float) ($_GET['amount'] ?? 10.0);
if (!is_finite($amount) || $amount <= 0) {
    $amount = 10.0;
}

$country = strtoupper(trim((string) ($_GET['country'] ?? (defined('TT_MERCHANT_COUNTRY') ? TT_MERCHANT_COUNTRY : 'IS')))) ?: 'IS';
$currency = strtoupper(trim((string) ($_GET['currency'] ?? (defined('TT_MERCHANT_CURRENCY') ? TT_MERCHANT_CURRENCY : 'ISK')))) ?: 'ISK';
$origin = rapyd_origin_url();
$merchantReferenceId = 'taptray_rapyd_' . gmdate('Ymd_His') . '_' . substr(bin2hex(random_bytes(4)), 0, 8);

$body = [
    'amount' => round($amount, 2),
    'currency' => $currency,
    'country' => $country,
    'language' => 'en',
    'merchant_reference_id' => $merchantReferenceId,
    'payment_method_type_categories' => ['card'],
    'complete_checkout_url' => $origin . '/checkout.php?provider=rapyd',
    'cancel_checkout_url' => $origin . '/checkout.php?provider=rapyd',
    'complete_payment_url' => $origin . '/checkout.php?provider=rapyd',
    'error_payment_url' => $origin . '/checkout.php?provider=rapyd',
];

$response = rapyd_request('post', '/v1/checkout', $body);
$data = is_array($response['response']['data'] ?? null) ? $response['response']['data'] : [];

$result = [
    'ok' => (bool) ($response['ok'] ?? false),
    'env' => RAPYD_ENV,
    'endpoint' => RAPYD_ENDPOINT,
    'request' => $body,
    'probe' => [
        'status' => $response['status'] ?? 0,
        'path' => $response['path'] ?? '',
        'curl_error' => $response['curl_error'] ?? '',
        'operation_status' => (string) ($response['response']['status']['status'] ?? ''),
        'message' => (string) ($response['response']['status']['message'] ?? ''),
        'id' => (string) ($data['id'] ?? ''),
        'status_field' => (string) ($data['status'] ?? ''),
        'redirect_url' => (string) ($data['redirect_url'] ?? ''),
        'expiration' => $data['expiration'] ?? null,
    ],
    'raw' => $response['raw'] ?? '',
];

echo json_encode($result, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT);
