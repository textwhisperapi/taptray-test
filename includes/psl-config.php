<?php
/**
 * These are the database login details
 */  

if (!function_exists('tw_get_env')) {
    function tw_get_env(string $key, ?string $default = null): ?string {
        $value = getenv($key);
        if ($value !== false && $value !== '') {
            return $value;
        }
        if (isset($_ENV[$key]) && $_ENV[$key] !== '') {
            return $_ENV[$key];
        }

        static $loaded = false;
        if (!$loaded) {
            $loaded = true;
            $envPath = dirname(__DIR__) . '/.env';
            if (is_readable($envPath)) {
                $lines = file($envPath, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
                foreach ($lines as $line) {
                    $line = trim($line);
                    if ($line === '' || $line[0] === '#') continue;
                    if (strpos($line, '=') === false) continue;
                    [$k, $v] = array_map('trim', explode('=', $line, 2));
                    $v = trim($v, "\"'");
                    if ($k !== '' && getenv($k) === false) {
                        putenv("$k=$v");
                        $_ENV[$k] = $v;
                    }
                }
            }
        }

        $value = getenv($key);
        if ($value !== false && $value !== '') {
            return $value;
        }
        return $default;
    }
}

// $con = mysql_connect('localhost','wecanrec_text','gotext');

//define("HOST", "localhost");     // The host you want to connect to.
//define("USER", "wecanrec_login");    // The database username. 
//define("PASSWORD", "LTmD78FH3VSxT27A");    // The database password. 
//define("DATABASE", "wecanrec_text");    // The database name.


define("HOST", tw_get_env('TW_DB_HOST', 'localhost'));
define("USER", tw_get_env('TW_DB_USER', ''));
define("PASSWORD", tw_get_env('TW_DB_PASSWORD', ''));
define("DATABASE", tw_get_env('TW_DB_NAME', ''));

 
define("CAN_REGISTER", "any");
define("DEFAULT_ROLE", "member");
 
define("SECURE", FALSE);    // FOR DEVELOPMENT ONLY!!!!

//define('BASE_URL', (isset($_SERVER['HTTPS']) ? 'https' : 'http') . '://' . $_SERVER['HTTP_HOST']);

$httpHost = $_SERVER['HTTP_HOST'] ?? $_SERVER['SERVER_NAME'] ?? 'textwhisper.com';
$scheme   = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';

define('BASE_URL', $scheme . '://' . $httpHost);


define('SMTP_PASSWORD', tw_get_env('TW_SMTP_PASSWORD', ''));

// Secret for signing invite links; rotate as needed.
define('INVITE_TOKEN_SECRET', tw_get_env('INVITE_TOKEN_SECRET', ''));
define('INVITE_TOKEN_MAX_AGE_DAYS', max(1, min(30, (int)tw_get_env('INVITE_TOKEN_MAX_AGE_DAYS', '7'))));

// TapTray payment context.
define('TT_PAYMENT_MODEL', tw_get_env('TT_PAYMENT_MODEL', 'merchant_of_record'));
define('TT_MERCHANT_NAME', tw_get_env('TT_MERCHANT_NAME', 'TapTray'));
define('TT_MERCHANT_COUNTRY', strtoupper(tw_get_env('TT_MERCHANT_COUNTRY', 'NL')));
define('TT_MERCHANT_CURRENCY', strtoupper(tw_get_env('TT_MERCHANT_CURRENCY', 'EUR')));
define('TT_MERCHANT_DESCRIPTOR', tw_get_env('TT_MERCHANT_DESCRIPTOR', TT_MERCHANT_NAME));
define('TT_PLATFORM_FEE_BPS', max(0, (int)tw_get_env('TT_PLATFORM_FEE_BPS', '0')));
define('TT_WALLET_MODE', tw_get_env('TT_WALLET_MODE', 'default_wallet_first'));
define('TT_WALLET_ENABLED', tw_get_env('TT_WALLET_ENABLED', '1') === '1');
define('TT_GOOGLE_PAY_ENVIRONMENT', strtoupper(tw_get_env('TT_GOOGLE_PAY_ENVIRONMENT', 'TEST')));
define('TT_GOOGLE_PAY_MERCHANT_ID', tw_get_env('TT_GOOGLE_PAY_MERCHANT_ID', ''));
define(
    'TT_WORLDLINE_GOOGLEPAY_GATEWAY_MERCHANT_ID',
    defined('WL_MERCHANT_ID') ? (string) WL_MERCHANT_ID : ''
);


?>
