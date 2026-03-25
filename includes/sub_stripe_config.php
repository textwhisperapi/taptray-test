<?php
require_once __DIR__ . '/psl-config.php';

$host = $_SERVER['HTTP_HOST'] ?? '';

// Stripe credentials must come from environment configuration, never from git-tracked files.
define('STRIPE_SECRET_KEY_LIVE', tw_get_env('TW_STRIPE_SECRET_KEY_LIVE', ''));
define('STRIPE_PUBLISHABLE_KEY_LIVE', tw_get_env('TW_STRIPE_PUBLISHABLE_KEY_LIVE', ''));
define('STRIPE_WEBHOOK_SECRET_LIVE', tw_get_env('TW_STRIPE_WEBHOOK_SECRET_LIVE', ''));

define('STRIPE_SECRET_KEY_TEST', tw_get_env('TW_STRIPE_SECRET_KEY_TEST', ''));
define('STRIPE_PUBLISHABLE_KEY_TEST', tw_get_env('TW_STRIPE_PUBLISHABLE_KEY_TEST', ''));
define('STRIPE_WEBHOOK_SECRET_TEST', tw_get_env('TW_STRIPE_WEBHOOK_SECRET_TEST', ''));

// ✅ Switch dynamically based on domain
if (strpos($host, 'textwhisper.com') !== false) {
    define('STRIPE_SECRET_KEY', STRIPE_SECRET_KEY_LIVE);
    define('STRIPE_PUBLISHABLE_KEY', STRIPE_PUBLISHABLE_KEY_LIVE);
    define('STRIPE_WEBHOOK_SECRET', STRIPE_WEBHOOK_SECRET_LIVE);
} else {
    define('STRIPE_SECRET_KEY', STRIPE_SECRET_KEY_TEST);
    define('STRIPE_PUBLISHABLE_KEY', STRIPE_PUBLISHABLE_KEY_TEST);
    define('STRIPE_WEBHOOK_SECRET', STRIPE_WEBHOOK_SECRET_TEST);
}
