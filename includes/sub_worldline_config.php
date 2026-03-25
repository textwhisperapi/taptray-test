<?php
declare(strict_types=1);

error_reporting(E_ALL);
ini_set('display_errors', '0');
ini_set('display_startup_errors', '0');

if (!function_exists('wl_env_value')) {
    function wl_env_value(string $key, string $default = ''): string {
        $value = getenv($key);
        if ($value !== false && $value !== '') {
            return (string) $value;
        }
        if (isset($_ENV[$key]) && $_ENV[$key] !== '') {
            return (string) $_ENV[$key];
        }

        static $loaded = false;
        if (!$loaded) {
            $loaded = true;
            $envPath = dirname(__DIR__) . '/.env';
            if (is_readable($envPath)) {
                $lines = file($envPath, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
                foreach ($lines as $line) {
                    $line = trim($line);
                    if ($line === '' || $line[0] === '#' || strpos($line, '=') === false) {
                        continue;
                    }
                    [$k, $v] = array_map('trim', explode('=', $line, 2));
                    $v = trim($v, "\"'");
                    if ($k !== '') {
                        putenv($k . '=' . $v);
                        $_ENV[$k] = $v;
                    }
                }
            }
        }

        $value = getenv($key);
        if ($value !== false && $value !== '') {
            return (string) $value;
        }
        return $default;
    }
}

$taptrayWorldlineAutoloadCandidates = [
    __DIR__ . '/../vendor/autoload.php',
    '/home1/wecanrec/textwhisper_vendor/worldline/vendor/autoload.php',
];

$taptrayWorldlineAutoloadLoaded = false;
foreach ($taptrayWorldlineAutoloadCandidates as $taptrayWorldlineAutoloadPath) {
    if (is_file($taptrayWorldlineAutoloadPath)) {
        require_once $taptrayWorldlineAutoloadPath;
        $taptrayWorldlineAutoloadLoaded = true;
        break;
    }
}

if ($taptrayWorldlineAutoloadLoaded && class_exists('Worldline\\Connect\\Sdk\\Client')) {
    class_alias('Worldline\\Connect\\Sdk\\Client', 'TapTrayWorldlineClient');
    class_alias('Worldline\\Connect\\Sdk\\Communicator', 'TapTrayWorldlineCommunicator');
    class_alias('Worldline\\Connect\\Sdk\\CommunicatorConfiguration', 'TapTrayWorldlineCommunicatorConfiguration');
}

$host = $_SERVER['HTTP_HOST'] ?? '';
$forcedEnv = defined('WL_FORCE_ENV') ? strtolower(trim((string) WL_FORCE_ENV)) : '';
$isLive = (stripos($host, 'textwhisper.com') !== false) || (stripos($host, 'taptray.com') !== false && stripos($host, 'test.taptray.com') === false);

if ($forcedEnv === 'sandbox' || $forcedEnv === 'test') {
    $isLive = false;
} elseif ($forcedEnv === 'live') {
    $isLive = true;
}

if ($isLive) {
    define('WL_API_KEY_ID',   wl_env_value('TT_WL_LIVE_API_KEY_ID', ''));
    define('WL_API_SECRET',   wl_env_value('TT_WL_LIVE_API_SECRET', ''));
    define('WL_MERCHANT_ID',  wl_env_value('TT_WL_LIVE_MERCHANT_ID', ''));
    define('WL_ENDPOINT',     wl_env_value('TT_WL_LIVE_ENDPOINT', 'https://payment.direct.worldline-solutions.com'));
    define('WL_CHECKOUT_SUBDOMAIN', wl_env_value('TT_WL_LIVE_CHECKOUT_SUBDOMAIN', ''));
    define('WL_ENV', 'live');
} else {
    define('WL_API_KEY_ID',   wl_env_value('TT_WL_TEST_API_KEY_ID', ''));
    define('WL_API_SECRET',   wl_env_value('TT_WL_TEST_API_SECRET', ''));
    define('WL_MERCHANT_ID',  wl_env_value('TT_WL_TEST_MERCHANT_ID', ''));
    define('WL_ENDPOINT',     wl_env_value('TT_WL_TEST_ENDPOINT', 'https://payment.preprod.direct.worldline-solutions.com'));
    define('WL_CHECKOUT_SUBDOMAIN', wl_env_value('TT_WL_TEST_CHECKOUT_SUBDOMAIN', 'https://payment.pay1.preprod.checkout.worldline-solutions.com'));
    define('WL_ENV', 'sandbox');
}

if ($isLive) {
    define('WL_WEBHOOK_KEYID',   'YOUR_LIVE_WEBHOOK_KEYID');
    define('WL_WEBHOOK_SECRET',  'YOUR_LIVE_WEBHOOK_SECRET');
} else {
    define('WL_WEBHOOK_KEYID',   '0a2cada7-f3db-4809-907e-f2ab5deca365');
    define('WL_WEBHOOK_SECRET',  'PocEMeoxqE6zCh/obN9SkfRuxkz40fnWNdb7MB5RrFI=');
}

if ($isLive) {
    define('WL_RETURN_URL', 'https://taptray.com/sub_success_worldline.php');
} else {
    define('WL_RETURN_URL', 'https://test.taptray.com/sub_success_worldline.php');
}

define('WL_INTEGRATOR', 'TapTray');

define('WL_SDK_AVAILABLE', $taptrayWorldlineAutoloadLoaded && class_exists('TapTrayWorldlineClient'));

function wl_client() {
    static $client = null;
    if ($client) return $client;
    if (!WL_SDK_AVAILABLE) {
        throw new RuntimeException('Worldline SDK is not available on this server.');
    }

    $cfg = new TapTrayWorldlineCommunicatorConfiguration(
        WL_API_KEY_ID,
        WL_API_SECRET,
        WL_ENDPOINT,
        WL_INTEGRATOR
    );
    $client = new TapTrayWorldlineClient(new TapTrayWorldlineCommunicator($cfg));
    return $client;
}

function wl_redirect_url(object $resp): ?string {
    if (!empty($resp->redirectUrl) && is_string($resp->redirectUrl)) {
        return $resp->redirectUrl;
    }

    if (!empty($resp->partialRedirectUrl) && is_string($resp->partialRedirectUrl)) {
        $partial = $resp->partialRedirectUrl;
        if (preg_match('#^https?://#i', $partial)) {
            return $partial;
        }
        if (stripos($partial, 'worldline-solutions.com') !== false) {
            return rtrim(WL_CHECKOUT_SUBDOMAIN, '/') . '/' . preg_replace('#^.*?/checkout/#', 'checkout/', $partial);
        }
        return rtrim(WL_CHECKOUT_SUBDOMAIN, '/') . '/' . ltrim($partial, '/');
    }

    if (!empty($resp->hostedCheckoutId) && is_string($resp->hostedCheckoutId)) {
        return rtrim(WL_CHECKOUT_SUBDOMAIN, '/')
             . '/checkout/'
             . rawurlencode(WL_MERCHANT_ID . '-' . $resp->hostedCheckoutId);
    }

    return null;
}

function wl_get_hosted_checkout(string $hostedCheckoutId) {
    if (!WL_SDK_AVAILABLE) {
        throw new RuntimeException('Worldline SDK is not available on this server.');
    }
    return wl_client()->v1()->merchant(WL_MERCHANT_ID)->hostedcheckouts()->get($hostedCheckoutId);
}

function wl_validate_returnmac(string $expectedMac, ?string $macFromQuery): bool {
    return is_string($macFromQuery) && hash_equals($expectedMac, $macFromQuery);
}

function wl_build_canonical_resource(string $path, array $query = []): string {
    $resource = '/' . ltrim($path, '/');
    if ($query) {
        $resource .= '?' . rawurldecode(http_build_query($query, '', '&', PHP_QUERY_RFC3986));
    }
    return $resource;
}

function wl_build_canonicalized_headers(array $headers = []): string {
    if ($headers === []) {
        return '';
    }

    $normalized = [];
    foreach ($headers as $name => $value) {
        $headerName = strtolower(trim((string) $name));
        if ($headerName === '') {
            continue;
        }
        $normalized[$headerName] = preg_replace('/\s+/', ' ', trim((string) $value));
    }

    ksort($normalized, SORT_STRING);

    $parts = [];
    foreach ($normalized as $name => $value) {
        $parts[] = $name . ':' . $value;
    }

    return implode("\n", $parts);
}

function wl_server_meta_info_value(): string {
    $platformIdentifier = sprintf(
        'PHP/%s (%s; %s)',
        PHP_VERSION,
        php_uname('s'),
        php_uname('m')
    );

    $payload = [
        'platformIdentifier' => $platformIdentifier,
        'sdkIdentifier' => 'TapTrayServerSDK/v0.1',
        'sdkCreator' => 'TapTray',
        'integrator' => WL_INTEGRATOR,
    ];

    return base64_encode(json_encode($payload, JSON_UNESCAPED_SLASHES));
}

function wl_build_signature(
    string $method,
    string $contentType,
    string $dateHeader,
    string $canonicalizedHeaders,
    string $canonicalResource
): string {
    $stringToHash = strtoupper($method) . "\n"
        . $contentType . "\n"
        . $dateHeader . "\n";

    if ($canonicalizedHeaders !== '') {
        $stringToHash .= $canonicalizedHeaders . "\n";
    }

    $stringToHash .= $canonicalResource . "\n";

    return base64_encode(hash_hmac('sha256', $stringToHash, WL_API_SECRET, true));
}

function wl_log_event(string $channel, array $payload): void {
    $logDir = dirname(__DIR__) . '/logs';
    if (!is_dir($logDir)) {
        @mkdir($logDir, 0775, true);
    }
    $logPath = $logDir . '/worldline.log';
    $entry = [
        'ts' => gmdate('c'),
        'channel' => $channel,
        'payload' => $payload,
    ];
    @file_put_contents(
        $logPath,
        json_encode($entry, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . PHP_EOL,
        FILE_APPEND
    );
}

function wl_api_request(string $method, string $path, array $query = [], ?array $body = null): array {
    $url = rtrim(WL_ENDPOINT, '/') . '/' . ltrim($path, '/');
    if ($query) {
        $url .= '?' . http_build_query($query);
    }

    $contentType = $body !== null ? 'application/json; charset=utf-8' : '';
    $dateHeader = gmdate('D, d M Y H:i:s') . ' UTC';
    $serverMetaInfo = wl_server_meta_info_value();
    $canonicalizedHeaders = wl_build_canonicalized_headers([
        'X-GCS-ServerMetaInfo' => $serverMetaInfo,
    ]);
    $canonicalResource = wl_build_canonical_resource($path, $query);
    $signature = wl_build_signature($method, $contentType, $dateHeader, $canonicalizedHeaders, $canonicalResource);

    $headers = [
        'Accept: application/json',
        'Date: ' . $dateHeader,
        'X-GCS-ServerMetaInfo: ' . $serverMetaInfo,
        'Authorization: GCS v1HMAC:' . WL_API_KEY_ID . ':' . $signature,
    ];
    if ($contentType !== '') {
        $headers[] = 'Content-Type: ' . $contentType;
    }

    $ch = curl_init($url);
    if ($ch === false) {
        throw new RuntimeException('Could not initialize Worldline request.');
    }

    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_CUSTOMREQUEST => strtoupper($method),
        CURLOPT_HTTPHEADER => $headers,
        CURLOPT_TIMEOUT => 30,
    ]);

    if ($body !== null) {
        curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($body, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
    }

    $raw = curl_exec($ch);
    if ($raw === false) {
        $err = curl_error($ch);
        curl_close($ch);
        throw new RuntimeException('Worldline request failed: ' . $err);
    }

    $status = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
    curl_close($ch);

    $decoded = json_decode($raw, true);
    return [
        'status' => $status,
        'body' => is_array($decoded) ? $decoded : null,
        'raw' => $raw,
    ];
}

function wl_get_payment_product_320(string $countryCode, string $currencyCode, int $amountMinor = 100): array {
    $countryCode = strtoupper(trim($countryCode)) ?: 'IS';
    $currencyCode = strtoupper(trim($currencyCode)) ?: 'ISK';
    $amountMinor = max(1, $amountMinor);

    $response = wl_api_request(
        'GET',
        '/v2/' . rawurlencode(WL_MERCHANT_ID) . '/products/320',
        [
            'countryCode' => $countryCode,
            'currencyCode' => $currencyCode,
            'amount' => $amountMinor,
            'isRecurring' => 'false',
        ]
    );

    if ($response['status'] < 200 || $response['status'] >= 300 || !is_array($response['body'])) {
        wl_log_event('product_320_error', [
            'status' => $response['status'],
            'merchant_id' => WL_MERCHANT_ID,
            'env' => WL_ENV,
            'endpoint' => WL_ENDPOINT,
            'countryCode' => $countryCode,
            'currencyCode' => $currencyCode,
            'amountMinor' => $amountMinor,
            'body' => $response['body'],
            'raw' => $response['raw'],
        ]);
        throw new RuntimeException('Worldline product 320 lookup failed with status ' . $response['status']);
    }

    return $response['body'];
}

function wl_create_payment(array $payload): array {
    $response = wl_api_request(
        'POST',
        '/v2/' . rawurlencode(WL_MERCHANT_ID) . '/payments',
        [],
        $payload
    );

    if ($response['status'] < 200 || $response['status'] >= 300 || !is_array($response['body'])) {
        wl_log_event('create_payment_error', [
            'status' => $response['status'],
            'merchant_id' => WL_MERCHANT_ID,
            'env' => WL_ENV,
            'endpoint' => WL_ENDPOINT,
            'body' => $response['body'],
            'raw' => $response['raw'],
        ]);
        $message = 'Worldline payment creation failed with status ' . $response['status'];
        if (is_array($response['body'])) {
            $errors = $response['body']['errors'] ?? null;
            if (is_array($errors) && isset($errors[0]['message'])) {
                $message .= ': ' . (string) $errors[0]['message'];
            } elseif (isset($response['body']['message'])) {
                $message .= ': ' . (string) $response['body']['message'];
            }
        }
        throw new RuntimeException($message);
    }

    return $response['body'];
}
