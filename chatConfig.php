<?php
// ✅ Lightweight .env loader
function loadEnv($path = __DIR__ . '/.env') {
  if (!file_exists($path)) return;

  foreach (file($path, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) as $line) {
    if (str_starts_with($line, '#') || !str_contains($line, '=')) continue;

    list($key, $value) = explode('=', $line, 2);
    $key = trim($key);
    $value = trim($value, " \t\n\r\0\x0B\"'");

    putenv("$key=$value");
    $_ENV[$key] = $value;
    $_SERVER[$key] = $value;
  }
}

loadEnv();

// ✅ Define for convenience
define('VAPID_PUBLIC_KEY', getenv('VAPID_PUBLIC_KEY'));
define('VAPID_PRIVATE_KEY', getenv('VAPID_PRIVATE_KEY'));

//error_log("✅ ENV Loaded. VAPID_PUBLIC_KEY = " . var_export(VAPID_PUBLIC_KEY, true));
