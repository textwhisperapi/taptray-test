<?php
declare(strict_types=1);

require_once __DIR__ . '/includes/functions.php';
require_once __DIR__ . '/includes/sub_worldline_config.php';
sec_session_start();

header('Content-Type: application/json; charset=utf-8');

$country = strtoupper(trim((string) ($_GET['country'] ?? (defined('TT_MERCHANT_COUNTRY') ? TT_MERCHANT_COUNTRY : 'IS')))) ?: 'IS';
$currency = strtoupper(trim((string) ($_GET['currency'] ?? (defined('TT_MERCHANT_CURRENCY') ? TT_MERCHANT_CURRENCY : 'EUR')))) ?: 'EUR';
$amountMinor = max(1, (int) ($_GET['amount_minor'] ?? 1));
$paymentProductId = max(1, (int) ($_GET['product_id'] ?? 1));

try {
    $response = wl_api_request(
        'GET',
        '/v2/' . rawurlencode(WL_MERCHANT_ID) . '/products/' . rawurlencode((string) $paymentProductId),
        [
            'countryCode' => $country,
            'currencyCode' => $currency,
            'amount' => $amountMinor,
            'isRecurring' => 'false',
        ]
    );

    wl_log_event('hello_worldline', [
        'status' => $response['status'],
        'merchant_id' => WL_MERCHANT_ID,
        'env' => WL_ENV,
        'endpoint' => WL_ENDPOINT,
        'paymentProductId' => $paymentProductId,
        'countryCode' => $country,
        'currencyCode' => $currency,
        'amountMinor' => $amountMinor,
        'body' => $response['body'],
        'raw' => $response['raw'],
    ]);

    echo json_encode([
        'ok' => $response['status'] >= 200 && $response['status'] < 300,
        'status' => $response['status'],
        'env' => WL_ENV,
        'merchant_id' => WL_MERCHANT_ID,
        'endpoint' => WL_ENDPOINT,
        'payment_product_id' => $paymentProductId,
        'country' => $country,
        'currency' => $currency,
        'amount_minor' => $amountMinor,
        'body' => $response['body'],
        'raw' => $response['raw'],
    ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
} catch (Throwable $e) {
    http_response_code(500);
    wl_log_event('hello_worldline_error', [
        'merchant_id' => defined('WL_MERCHANT_ID') ? WL_MERCHANT_ID : '',
        'env' => defined('WL_ENV') ? WL_ENV : 'unknown',
        'endpoint' => defined('WL_ENDPOINT') ? WL_ENDPOINT : '',
        'paymentProductId' => $paymentProductId,
        'countryCode' => $country,
        'currencyCode' => $currency,
        'amountMinor' => $amountMinor,
        'message' => $e->getMessage(),
    ]);

    echo json_encode([
        'ok' => false,
        'error' => $e->getMessage(),
        'env' => defined('WL_ENV') ? WL_ENV : 'unknown',
        'merchant_id' => defined('WL_MERCHANT_ID') ? WL_MERCHANT_ID : '',
        'endpoint' => defined('WL_ENDPOINT') ? WL_ENDPOINT : '',
        'payment_product_id' => $paymentProductId,
        'country' => $country,
        'currency' => $currency,
        'amount_minor' => $amountMinor,
    ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
}
