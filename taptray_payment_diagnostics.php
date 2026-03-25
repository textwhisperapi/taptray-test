<?php
declare(strict_types=1);

require_once __DIR__ . '/includes/functions.php';
require_once __DIR__ . '/includes/sub_worldline_config.php';
sec_session_start();

function tt_mask_middle(string $value, int $prefix = 4, int $suffix = 4): string {
    $value = trim($value);
    if ($value === '') {
        return '(empty)';
    }
    $len = strlen($value);
    if ($len <= ($prefix + $suffix)) {
        return str_repeat('*', $len);
    }
    return substr($value, 0, $prefix) . str_repeat('*', max(4, $len - $prefix - $suffix)) . substr($value, -$suffix);
}

function tt_load_last_worldline_log(): ?array {
    $logPath = __DIR__ . '/logs/worldline.log';
    if (!is_readable($logPath)) {
        return null;
    }
    $lines = @file($logPath, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
    if (!is_array($lines) || $lines === []) {
        return null;
    }
    $decoded = json_decode((string) end($lines), true);
    return is_array($decoded) ? $decoded : null;
}

$diag = [
    'host' => $_SERVER['HTTP_HOST'] ?? '',
    'env' => WL_ENV,
    'merchant_id' => WL_MERCHANT_ID,
    'endpoint' => WL_ENDPOINT,
    'api_key_id_masked' => tt_mask_middle(WL_API_KEY_ID),
    'google_pay_merchant_id_masked' => tt_mask_middle((string) (defined('TT_GOOGLE_PAY_MERCHANT_ID') ? TT_GOOGLE_PAY_MERCHANT_ID : '')),
    'worldline_gateway_merchant_id' => WL_MERCHANT_ID,
    'merchant_name' => (string) (defined('TT_MERCHANT_NAME') ? TT_MERCHANT_NAME : 'TapTray'),
    'country' => (string) (defined('TT_MERCHANT_COUNTRY') ? TT_MERCHANT_COUNTRY : 'NL'),
    'currency' => (string) (defined('TT_MERCHANT_CURRENCY') ? TT_MERCHANT_CURRENCY : 'EUR'),
];

$lastLog = tt_load_last_worldline_log();
$lastError = null;
if (is_array($lastLog)) {
    $payload = is_array($lastLog['payload'] ?? null) ? $lastLog['payload'] : [];
    $body = is_array($payload['body'] ?? null) ? $payload['body'] : [];
    $firstError = is_array($body['errors'][0] ?? null) ? $body['errors'][0] : [];
    $lastError = [
        'timestamp' => (string) ($lastLog['ts'] ?? ''),
        'channel' => (string) ($lastLog['channel'] ?? ''),
        'status' => (string) ($payload['status'] ?? ''),
        'error_code' => (string) ($firstError['code'] ?? ''),
        'error_id' => (string) ($firstError['id'] ?? ''),
        'message' => (string) ($firstError['message'] ?? ''),
    ];
}
?>
<!doctype html>
<html lang="en">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title>TapTray Payment Diagnostics</title>
  <style>
    :root {
      --bg: #f4f7fb;
      --surface: rgba(255,255,255,0.95);
      --border: #d8dfef;
      --text: #1a2230;
      --muted: #677388;
      --accent: #4b5ee4;
      --danger: #b55454;
      --danger-soft: #fff5f5;
      --shadow: 0 18px 40px rgba(31,42,70,0.10);
      --radius: 22px;
    }
    * { box-sizing: border-box; }
    body {
      margin: 0;
      min-height: 100vh;
      font-family: system-ui, -apple-system, BlinkMacSystemFont, "Segoe UI", sans-serif;
      color: var(--text);
      background:
        radial-gradient(circle at top left, rgba(75,94,228,0.12), transparent 34%),
        radial-gradient(circle at bottom right, rgba(83,179,159,0.14), transparent 36%),
        var(--bg);
    }
    .shell {
      max-width: 980px;
      margin: 0 auto;
      padding: 24px 18px 40px;
    }
    .topbar {
      display: flex;
      align-items: center;
      justify-content: space-between;
      gap: 12px;
      margin-bottom: 20px;
    }
    .back {
      display: inline-flex;
      align-items: center;
      gap: 8px;
      padding: 10px 14px;
      border-radius: 999px;
      border: 1px solid var(--border);
      background: var(--surface);
      color: var(--text);
      text-decoration: none;
      font-weight: 700;
      box-shadow: var(--shadow);
    }
    .brand {
      font-size: 14px;
      font-weight: 800;
      letter-spacing: 0.08em;
      text-transform: uppercase;
      color: var(--muted);
    }
    .card {
      background: var(--surface);
      border: 1px solid var(--border);
      border-radius: var(--radius);
      box-shadow: var(--shadow);
      padding: 22px;
      margin-bottom: 18px;
    }
    .kicker {
      font-size: 12px;
      font-weight: 800;
      letter-spacing: 0.08em;
      text-transform: uppercase;
      color: var(--accent);
    }
    h1 {
      margin: 8px 0 0;
      font-size: clamp(28px, 4vw, 42px);
      line-height: 1.04;
    }
    .sub {
      margin: 10px 0 0;
      color: var(--muted);
      font-size: 16px;
      line-height: 1.45;
      max-width: 62ch;
    }
    .grid {
      display: grid;
      grid-template-columns: repeat(2, minmax(0, 1fr));
      gap: 12px;
      margin-top: 18px;
    }
    .tile {
      padding: 16px;
      border-radius: 18px;
      border: 1px solid var(--border);
      background: linear-gradient(180deg, #ffffff, #f7faff);
    }
    .label {
      font-size: 12px;
      font-weight: 800;
      letter-spacing: 0.06em;
      text-transform: uppercase;
      color: var(--muted);
    }
    .value {
      margin-top: 8px;
      font-size: 18px;
      font-weight: 800;
      line-height: 1.2;
      word-break: break-word;
    }
    .status {
      padding: 18px;
      border-radius: 18px;
      border: 1px solid rgba(181, 84, 84, 0.22);
      background: var(--danger-soft);
      color: var(--danger);
      display: grid;
      gap: 8px;
    }
    .status strong { color: var(--text); }
    .muted { color: var(--muted); }
    .actions {
      display: flex;
      flex-wrap: wrap;
      gap: 10px;
      margin-top: 16px;
    }
    .btn {
      display: inline-flex;
      align-items: center;
      justify-content: center;
      min-height: 42px;
      padding: 0 16px;
      border-radius: 999px;
      border: 1px solid var(--border);
      background: #fff;
      color: var(--text);
      text-decoration: none;
      font-weight: 700;
    }
    .btn-primary {
      background: var(--accent);
      color: #fff;
      border-color: var(--accent);
    }
    @media (max-width: 760px) {
      .grid { grid-template-columns: 1fr; }
    }
  </style>
</head>
<body>
  <div class="shell">
    <div class="topbar">
      <a class="back" href="/checkout.php">← Back to checkout</a>
      <div class="brand">TapTray Payment Diagnostics</div>
    </div>

    <section class="card">
      <div class="kicker">Current state</div>
      <h1>Waiting on Worldline merchant access</h1>
      <p class="sub">TapTray is using the sandbox PSPID <strong><?= htmlspecialchars($diag['merchant_id']) ?></strong>, but Worldline is still rejecting Direct API access for the current preprod test market <strong><?= htmlspecialchars($diag['country']) ?> / <?= htmlspecialchars($diag['currency']) ?></strong>. This page keeps the current config and latest error visible so retesting is quick once Worldline confirms the fix.</p>

      <div class="grid">
        <div class="tile"><div class="label">Environment</div><div class="value"><?= htmlspecialchars($diag['env']) ?></div></div>
        <div class="tile"><div class="label">Merchant / PSPID</div><div class="value"><?= htmlspecialchars($diag['merchant_id']) ?></div></div>
        <div class="tile"><div class="label">Endpoint</div><div class="value"><?= htmlspecialchars($diag['endpoint']) ?></div></div>
        <div class="tile"><div class="label">API key</div><div class="value"><?= htmlspecialchars($diag['api_key_id_masked']) ?></div></div>
        <div class="tile"><div class="label">Google Pay merchant ID</div><div class="value"><?= htmlspecialchars($diag['google_pay_merchant_id_masked']) ?></div></div>
        <div class="tile"><div class="label">Gateway merchant ID</div><div class="value"><?= htmlspecialchars($diag['worldline_gateway_merchant_id']) ?></div></div>
        <div class="tile"><div class="label">Merchant name</div><div class="value"><?= htmlspecialchars($diag['merchant_name']) ?></div></div>
        <div class="tile"><div class="label">Country / currency</div><div class="value"><?= htmlspecialchars($diag['country']) ?> / <?= htmlspecialchars($diag['currency']) ?></div></div>
      </div>
    </section>

    <section class="card">
      <div class="kicker">Latest Worldline result</div>
      <?php if ($lastError): ?>
        <div class="status">
          <div><strong>Timestamp:</strong> <?= htmlspecialchars($lastError['timestamp']) ?></div>
          <div><strong>Channel:</strong> <?= htmlspecialchars($lastError['channel']) ?></div>
          <div><strong>Status:</strong> <?= htmlspecialchars($lastError['status']) ?></div>
          <div><strong>Error code:</strong> <?= htmlspecialchars($lastError['error_code']) ?></div>
          <div><strong>Error id:</strong> <?= htmlspecialchars($lastError['error_id']) ?></div>
          <div><strong>Message:</strong> <?= htmlspecialchars($lastError['message']) ?></div>
          <div class="muted">Current state: Worldline recognizes the request but still denies merchant access on the preprod Direct API side.</div>
        </div>
      <?php else: ?>
        <div class="tile">
          <div class="label">Log status</div>
          <div class="value">No Worldline log entry yet</div>
        </div>
      <?php endif; ?>

		      <div class="actions">
		        <a class="btn" href="/taptray_payment_self_test.php" target="_blank" rel="noopener">Run config self-test</a>
		        <a class="btn" href="/taptray_worldline_hello.php?country=<?= rawurlencode($diag['country']) ?>&currency=<?= rawurlencode($diag['currency']) ?>&amount_minor=1&product_id=1" target="_blank" rel="noopener">Run hello test</a>
		        <a class="btn btn-primary" href="/taptray_get_worldline_product_320.php?country=<?= rawurlencode($diag['country']) ?>&currency=<?= rawurlencode($diag['currency']) ?>&amount_minor=2" target="_blank" rel="noopener">Retest product 320</a>
		        <a class="btn" href="/checkout.php">Open checkout</a>
	        <a class="btn" href="/taptray_success_worldline.php?test=1">Open post-purchase preview</a>
	      </div>
    </section>
  </div>
</body>
</html>
