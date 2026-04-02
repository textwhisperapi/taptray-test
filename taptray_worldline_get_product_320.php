<?php
declare(strict_types=1);

require_once __DIR__ . '/includes/functions.php';
require_once __DIR__ . '/includes/sub_worldline_config.php';
sec_session_start();

header('Content-Type: application/json; charset=utf-8');

$country = strtoupper(trim((string) ($_GET['country'] ?? (defined('TT_MERCHANT_COUNTRY') ? TT_MERCHANT_COUNTRY : 'NL')))) ?: 'NL';
$currency = strtoupper(trim((string) ($_GET['currency'] ?? (defined('TT_MERCHANT_CURRENCY') ? TT_MERCHANT_CURRENCY : 'EUR')))) ?: 'EUR';
$amountMinor = max(1, (int) ($_GET['amount_minor'] ?? 100));

try {
    $product = wl_get_payment_product_320($country, $currency, $amountMinor);
    $specific = is_array($product['paymentProduct320SpecificData'] ?? null) ? $product['paymentProduct320SpecificData'] : [];
    $networks = [];
    if (isset($specific['networks']) && is_array($specific['networks'])) {
        $networks = array_values(array_filter(array_map('strval', $specific['networks'])));
    }

    echo json_encode([
        'ok' => true,
        'env' => WL_ENV,
        'merchant_id' => WL_MERCHANT_ID,
        'payment_product_id' => 320,
        'country' => $country,
        'currency' => $currency,
        'gateway' => (string) ($specific['gateway'] ?? ''),
        'merchant_id_for_wallet' => defined('TT_GOOGLE_PAY_MERCHANT_ID') ? TT_GOOGLE_PAY_MERCHANT_ID : '',
        'gateway_merchant_id' => defined('TT_WORLDLINE_GOOGLEPAY_GATEWAY_MERCHANT_ID') ? TT_WORLDLINE_GOOGLEPAY_GATEWAY_MERCHANT_ID : '',
        'networks' => $networks,
        'raw_product' => $product,
    ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
} catch (Throwable $e) {
    http_response_code(500);
    echo json_encode([
        'ok' => false,
        'error' => $e->getMessage(),
        'env' => defined('WL_ENV') ? WL_ENV : 'unknown',
        'merchant_id' => defined('WL_MERCHANT_ID') ? WL_MERCHANT_ID : '',
    ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
}
