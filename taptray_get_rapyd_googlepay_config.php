<?php
declare(strict_types=1);

require_once __DIR__ . '/includes/functions.php';
require_once __DIR__ . '/includes/sub_rapyd_config.php';
sec_session_start();

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
header('Pragma: no-cache');
header('Expires: 0');

function tt_rapyd_config_error(string $message, int $status = 400): void {
    http_response_code($status);
    echo json_encode(['ok' => false, 'error' => $message], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

$country = strtoupper(trim((string) ($_GET['country'] ?? (defined('TT_MERCHANT_COUNTRY') ? TT_MERCHANT_COUNTRY : 'IS')))) ?: 'IS';
$currency = strtoupper(trim((string) ($_GET['currency'] ?? (defined('TT_MERCHANT_CURRENCY') ? TT_MERCHANT_CURRENCY : 'EUR')))) ?: 'EUR';

if (trim(RAPYD_ACCESS_KEY) === '' || trim(RAPYD_SECRET_KEY) === '') {
    tt_rapyd_config_error('Rapyd sandbox credentials are missing on this server.', 503);
}

$probe = rapyd_request('get', '/v1/payment_methods/countries/' . rawurlencode($country), null, ['currency' => $currency]);
if (!$probe['ok'] || !is_array($probe['response']['data'] ?? null)) {
    tt_rapyd_config_error('Rapyd did not return payment methods for this market.', 502);
}

$paymentTypeMap = [];
foreach ($probe['response']['data'] as $method) {
    if (!is_array($method)) {
        continue;
    }
    $type = trim((string) ($method['type'] ?? ''));
    $category = trim((string) ($method['category'] ?? ''));
    $status = (int) ($method['status'] ?? 0);
    if ($type === '' || $category !== 'card' || $status !== 1) {
        continue;
    }

    if (str_ends_with($type, '_visa_card')) {
        $paymentTypeMap['VISA'] = $type;
    } elseif (str_ends_with($type, '_mastercard_card')) {
        $paymentTypeMap['MASTERCARD'] = $type;
    } elseif (str_ends_with($type, '_amex_card')) {
        $paymentTypeMap['AMEX'] = $type;
    } elseif (str_ends_with($type, '_discover_card')) {
        $paymentTypeMap['DISCOVER'] = $type;
    } elseif (str_ends_with($type, '_jcb_card')) {
        $paymentTypeMap['JCB'] = $type;
    }
}

$allowedNetworks = array_values(array_intersect(['MASTERCARD', 'VISA'], array_keys($paymentTypeMap)));
if ($allowedNetworks === []) {
    $allowedNetworks = ['MASTERCARD', 'VISA'];
}

echo json_encode([
    'ok' => true,
    'provider' => 'rapyd',
    'env' => RAPYD_ENV,
    'endpoint' => RAPYD_ENDPOINT,
    'country' => $country,
    'currency' => $currency,
    'gateway' => 'rapyd',
    'gatewayMerchantId' => RAPYD_ACCESS_KEY,
    'merchantId' => defined('TT_GOOGLE_PAY_MERCHANT_ID') ? (string) TT_GOOGLE_PAY_MERCHANT_ID : '',
    'allowedCardNetworks' => $allowedNetworks,
    'paymentTypeMap' => $paymentTypeMap,
], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
