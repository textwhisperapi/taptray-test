<?php
declare(strict_types=1);

error_reporting(E_ALL);
ini_set('display_errors', '0');
ini_set('display_startup_errors', '0');

if (!function_exists('tt_env_value')) {
    function tt_env_value(string $key, string $default = ''): string {
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

if (!function_exists('tt_env_first')) {
    function tt_env_first(array $keys, string $default = ''): string {
        foreach ($keys as $key) {
            $value = tt_env_value((string) $key, '');
            if ($value !== '') {
                return $value;
            }
        }
        return $default;
    }
}

$rapydHost = $_SERVER['HTTP_HOST'] ?? '';
$rapydIsLive = stripos($rapydHost, 'taptray.com') !== false && stripos($rapydHost, 'test.taptray.com') === false;

if ($rapydIsLive) {
    define('RAPYD_ENV', 'live');
    define('RAPYD_ENDPOINT', tt_env_value('TT_RAPYD_LIVE_ENDPOINT', 'https://api.rapyd.net'));
    define('RAPYD_ACCESS_KEY', tt_env_first(['TT_RAPYD_LIVE_ACCESS_KEY', 'TT_RAPYD_LIVE_ACCESS_KEY_ID', 'TT_RAPYD_LIVE_API_KEY_ID']));
    define('RAPYD_SECRET_KEY', tt_env_first(['TT_RAPYD_LIVE_SECRET_KEY', 'TT_RAPYD_LIVE_ACCESS_SECRET', 'TT_RAPYD_LIVE_API_SECRET']));
    define('RAPYD_MERCHANT_ID', tt_env_first(['TT_RAPYD_LIVE_MERCHANT_ID', 'TT_RAPYD_LIVE_ORGANIZATION_ID']));
} else {
    define('RAPYD_ENV', 'sandbox');
    define('RAPYD_ENDPOINT', tt_env_value('TT_RAPYD_TEST_ENDPOINT', 'https://sandboxapi.rapyd.net'));
    define('RAPYD_ACCESS_KEY', tt_env_first(['TT_RAPYD_TEST_ACCESS_KEY', 'TT_RAPYD_TEST_ACCESS_KEY_ID', 'TT_RAPYD_TEST_API_KEY_ID']));
    define('RAPYD_SECRET_KEY', tt_env_first(['TT_RAPYD_TEST_SECRET_KEY', 'TT_RAPYD_TEST_ACCESS_SECRET', 'TT_RAPYD_TEST_API_SECRET']));
    define('RAPYD_MERCHANT_ID', tt_env_first(['TT_RAPYD_TEST_MERCHANT_ID', 'TT_RAPYD_TEST_ORGANIZATION_ID']));
}

function rapyd_compact_json($body): string {
    if ($body === null) {
        return '';
    }
    $json = json_encode($body, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    if (!is_string($json) || $json === '{}' || $json === 'null') {
        return '';
    }
    return $json;
}

function rapyd_random_string(int $length = 12): string {
    $alphabet = '0123456789abcdefghijklmnopqrstuvwxyzABCDEFGHIJKLMNOPQRSTUVWXYZ';
    $maxIndex = strlen($alphabet) - 1;
    $buffer = '';
    for ($i = 0; $i < $length; $i++) {
        $buffer .= $alphabet[random_int(0, $maxIndex)];
    }
    return $buffer;
}

function rapyd_build_path(string $path, array $query = []): string {
    $resource = '/' . ltrim($path, '/');
    if ($query !== []) {
        $resource .= '?' . http_build_query($query, '', '&', PHP_QUERY_RFC3986);
    }
    return $resource;
}

function rapyd_signature(string $method, string $pathWithQuery, string $salt, string $timestamp, string $bodyString): string {
    $toSign = strtolower($method) . $pathWithQuery . $salt . $timestamp . RAPYD_ACCESS_KEY . RAPYD_SECRET_KEY . $bodyString;
    $hash = hash_hmac('sha256', $toSign, RAPYD_SECRET_KEY);
    return base64_encode($hash);
}

function rapyd_mask_value(string $value, int $prefix = 4, int $suffix = 4): string {
    $value = trim($value);
    if ($value === '') {
        return '(empty)';
    }
    $length = strlen($value);
    if ($length <= ($prefix + $suffix)) {
        return str_repeat('*', $length);
    }
    return substr($value, 0, $prefix) . str_repeat('*', max(4, $length - $prefix - $suffix)) . substr($value, -$suffix);
}

function rapyd_request(string $method, string $path, ?array $body = null, array $query = []): array {
    $method = strtolower(trim($method));
    $pathWithQuery = rapyd_build_path($path, $query);
    $url = rtrim(RAPYD_ENDPOINT, '/') . $pathWithQuery;
    $bodyString = rapyd_compact_json($body);
    $salt = rapyd_random_string(12);
    $timestamp = (string) time();
    $idempotency = rapyd_random_string(16);
    $signature = rapyd_signature($method, $pathWithQuery, $salt, $timestamp, $bodyString);

    $headers = [
        'Content-Type: application/json',
        'access_key: ' . RAPYD_ACCESS_KEY,
        'salt: ' . $salt,
        'timestamp: ' . $timestamp,
        'signature: ' . $signature,
        'idempotency: ' . $idempotency,
    ];

    $ch = curl_init($url);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_CUSTOMREQUEST, strtoupper($method));
    curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
    curl_setopt($ch, CURLOPT_TIMEOUT, 30);
    if ($bodyString !== '' && $method !== 'get') {
        curl_setopt($ch, CURLOPT_POSTFIELDS, $bodyString);
    }

    $raw = curl_exec($ch);
    $curlError = curl_error($ch);
    $status = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
    curl_close($ch);

    $decoded = null;
    if (is_string($raw) && $raw !== '') {
        $decoded = json_decode($raw, true);
        if (!is_array($decoded)) {
            $decoded = null;
        }
    }

    return [
        'ok' => $curlError === '' && $status >= 200 && $status < 300,
        'status' => $status,
        'curl_error' => $curlError,
        'endpoint' => RAPYD_ENDPOINT,
        'path' => $pathWithQuery,
        'body_string' => $bodyString,
        'response' => $decoded,
        'raw' => is_string($raw) ? $raw : '',
        'meta' => [
            'env' => RAPYD_ENV,
            'merchant_id' => RAPYD_MERCHANT_ID,
            'access_key_masked' => rapyd_mask_value(RAPYD_ACCESS_KEY),
            'timestamp' => $timestamp,
        ],
    ];
}

function rapyd_origin_url(): string {
    $isHttps = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off')
        || (isset($_SERVER['SERVER_PORT']) && (string) $_SERVER['SERVER_PORT'] === '443');
    $scheme = $isHttps ? 'https' : 'http';
    $host = $_SERVER['HTTP_HOST'] ?? 'test.taptray.com';
    return $scheme . '://' . $host;
}

function rapyd_log_event(string $channel, array $payload): void {
    $logDir = dirname(__DIR__) . '/logs';
    if (!is_dir($logDir)) {
        @mkdir($logDir, 0775, true);
    }
    $logPath = $logDir . '/rapyd.log';
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
