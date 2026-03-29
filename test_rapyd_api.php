<?php
declare(strict_types=1);

require_once __DIR__ . '/includes/functions.php';
require_once __DIR__ . '/includes/sub_rapyd_config.php';
sec_session_start();

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
header('Pragma: no-cache');
header('Expires: 0');

$result = [
    'ok' => false,
    'env' => RAPYD_ENV,
    'endpoint' => RAPYD_ENDPOINT,
    'merchant_id' => RAPYD_MERCHANT_ID,
    'access_key_masked' => rapyd_mask_value(RAPYD_ACCESS_KEY),
    'secret_key_configured' => trim(RAPYD_SECRET_KEY) !== '',
    'checks' => [],
];

$result['checks'][] = [
    'name' => 'access_key',
    'status' => trim(RAPYD_ACCESS_KEY) !== '' ? 'pass' : 'fail',
    'message' => trim(RAPYD_ACCESS_KEY) !== '' ? 'Rapyd access key is configured.' : 'Rapyd access key is missing.',
];

$result['checks'][] = [
    'name' => 'secret_key',
    'status' => trim(RAPYD_SECRET_KEY) !== '' ? 'pass' : 'fail',
    'message' => trim(RAPYD_SECRET_KEY) !== '' ? 'Rapyd secret key is configured.' : 'Rapyd secret key is missing.',
];

if (trim(RAPYD_ACCESS_KEY) === '' || trim(RAPYD_SECRET_KEY) === '') {
    echo json_encode($result, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT);
    exit;
}

$probe = rapyd_request('get', '/v1/data/countries');
$result['ok'] = (bool) ($probe['ok'] ?? false);
$countryRows = [];
if (is_array($probe['response']['data'] ?? null)) {
    $countryRows = $probe['response']['data'];
}
$iceland = null;
foreach ($countryRows as $countryRow) {
    if (!is_array($countryRow)) {
        continue;
    }
    $isoAlpha2 = strtoupper((string) ($countryRow['iso_alpha2'] ?? ''));
    $name = strtolower((string) ($countryRow['name'] ?? ''));
    if ($isoAlpha2 === 'IS' || $name === 'iceland') {
        $iceland = [
            'name' => (string) ($countryRow['name'] ?? ''),
            'iso_alpha2' => $isoAlpha2,
            'currency_code' => (string) ($countryRow['currency_code'] ?? ''),
            'phone_code' => (string) ($countryRow['phone_code'] ?? ''),
        ];
        break;
    }
}

$result['probe'] = [
    'status' => $probe['status'] ?? 0,
    'path' => $probe['path'] ?? '',
    'curl_error' => $probe['curl_error'] ?? '',
    'operation_status' => (string) ($probe['response']['status']['status'] ?? ''),
    'country_count' => count($countryRows),
    'iceland' => $iceland,
    'sample' => array_slice($countryRows, 0, 5),
];

echo json_encode($result, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT);
