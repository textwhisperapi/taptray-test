<?php
declare(strict_types=1);

require_once __DIR__ . '/includes/functions.php';
require_once __DIR__ . '/includes/sub_worldline_config.php';
sec_session_start();

header('Content-Type: application/json; charset=utf-8');

function tt_self_test_add(array &$checks, string $name, string $status, string $message, array $meta = []): void {
    $checks[] = [
        'name' => $name,
        'status' => $status,
        'message' => $message,
        'meta' => $meta,
    ];
}

$merchantId = trim((string) WL_MERCHANT_ID);
$merchantCountry = trim((string) (defined('TT_MERCHANT_COUNTRY') ? TT_MERCHANT_COUNTRY : ''));
$merchantCurrency = trim((string) (defined('TT_MERCHANT_CURRENCY') ? TT_MERCHANT_CURRENCY : ''));
$googlePayMerchantId = trim((string) (defined('TT_GOOGLE_PAY_MERCHANT_ID') ? TT_GOOGLE_PAY_MERCHANT_ID : ''));
$gatewayMerchantId = trim((string) WL_MERCHANT_ID);

$checks = [];

tt_self_test_add(
    $checks,
    'sandbox_pspid',
    $merchantId !== '' ? 'pass' : 'fail',
    $merchantId !== '' ? 'Sandbox PSPID is configured.' : 'Sandbox PSPID is missing.',
    ['merchant_id' => $merchantId]
);

tt_self_test_add(
    $checks,
    'api_key',
    trim((string) WL_API_KEY_ID) !== '' ? 'pass' : 'fail',
    trim((string) WL_API_KEY_ID) !== '' ? 'Worldline API key ID is configured.' : 'Worldline API key ID is missing.'
);

tt_self_test_add(
    $checks,
    'api_secret',
    trim((string) WL_API_SECRET) !== '' ? 'pass' : 'fail',
    trim((string) WL_API_SECRET) !== '' ? 'Worldline API secret is configured.' : 'Worldline API secret is missing.'
);

tt_self_test_add(
    $checks,
    'market',
    ($merchantCountry !== '' && $merchantCurrency !== '') ? 'pass' : 'fail',
    ($merchantCountry !== '' && $merchantCurrency !== '')
        ? 'TapTray market is configured.'
        : 'TapTray market is incomplete.',
    ['country' => $merchantCountry, 'currency' => $merchantCurrency]
);

tt_self_test_add(
    $checks,
    'google_pay_merchant_id',
    $googlePayMerchantId !== '' ? 'pass' : 'warn',
    $googlePayMerchantId !== ''
        ? 'Google Pay merchant ID is configured for TapTray wallet checkout.'
        : 'Google Pay merchant ID is empty, so Google Pay cannot complete even after Direct API access works.',
    ['google_pay_merchant_id_masked' => substr($googlePayMerchantId, 0, 4) . ($googlePayMerchantId !== '' ? '…' : '')]
);

$gatewayStatus = 'pass';
$gatewayMessage = 'Gateway merchant ID matches the current PSPID.';
if ($gatewayMerchantId === '') {
    $gatewayStatus = 'warn';
    $gatewayMessage = 'Gateway merchant ID is empty. TapTray checkout will block before Google Pay tokenization.';
} elseif ($merchantId !== '' && $gatewayMerchantId !== $merchantId) {
    $gatewayStatus = 'warn';
    $gatewayMessage = 'Gateway merchant ID does not match the current PSPID. This looks like a legacy or cross-product value.';
}

tt_self_test_add(
    $checks,
    'gateway_merchant_id',
    $gatewayStatus,
    $gatewayMessage,
    ['gateway_merchant_id' => $gatewayMerchantId, 'merchant_id' => $merchantId]
);

tt_self_test_add(
    $checks,
    'direct_api_status',
    'info',
    'This self-test does not prove Worldline access. Use the hello test and product 320 test for live preprod responses.'
);

$hasFail = false;
$hasWarn = false;
foreach ($checks as $check) {
    if (($check['status'] ?? '') === 'fail') {
        $hasFail = true;
    }
    if (($check['status'] ?? '') === 'warn') {
        $hasWarn = true;
    }
}

echo json_encode([
    'ok' => !$hasFail,
    'summary' => $hasFail ? 'Configuration has blocking issues.' : ($hasWarn ? 'Configuration is usable but has warnings.' : 'Configuration passes local sanity checks.'),
    'env' => WL_ENV,
    'merchant_id' => $merchantId,
    'endpoint' => WL_ENDPOINT,
    'checks' => $checks,
], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
