<?php
declare(strict_types=1);

define('WL_FORCE_ENV', 'sandbox');
require_once __DIR__ . '/includes/functions.php';
require_once __DIR__ . '/includes/sub_worldline_config.php';
sec_session_start();

function tt_test_string(string $key, string $default = ''): string {
    $value = $_POST[$key] ?? $_GET[$key] ?? $default;
    return trim((string) $value);
}

function tt_test_int(string $key, int $default = 1): int {
    $value = $_POST[$key] ?? $_GET[$key] ?? $default;
    return max(1, (int) $value);
}

function tt_test_history_add(array $entry): void {
    if (!isset($_SESSION['tt_wl_api_test_log']) || !is_array($_SESSION['tt_wl_api_test_log'])) {
        $_SESSION['tt_wl_api_test_log'] = [];
    }
    array_unshift($_SESSION['tt_wl_api_test_log'], $entry);
    $_SESSION['tt_wl_api_test_log'] = array_slice($_SESSION['tt_wl_api_test_log'], 0, 12);
}

$defaultPspid = defined('WL_MERCHANT_ID') ? (string) WL_MERCHANT_ID : 'TapTrayTest';
$defaultCountry = strtoupper((string) (defined('TT_MERCHANT_COUNTRY') ? TT_MERCHANT_COUNTRY : 'IS')) ?: 'IS';
$defaultCurrency = strtoupper((string) (defined('TT_MERCHANT_CURRENCY') ? TT_MERCHANT_CURRENCY : 'EUR')) ?: 'EUR';

$presets = [
    ['id' => 1, 'label' => 'Cards'],
    ['id' => 302, 'label' => 'Apple Pay'],
    ['id' => 320, 'label' => 'Google Pay'],
    ['id' => 809, 'label' => 'iDEAL'],
    ['id' => 3301, 'label' => 'Klarna'],
];

$state = [
    'pspid' => tt_test_string('pspid', $defaultPspid),
    'product_id' => tt_test_int('product_id', 320),
    'country' => strtoupper(tt_test_string('country', $defaultCountry)) ?: 'IS',
    'currency' => strtoupper(tt_test_string('currency', $defaultCurrency)) ?: 'EUR',
    'amount_minor' => tt_test_int('amount_minor', 2),
    'is_recurring' => tt_test_string('is_recurring', 'false') === 'true' ? 'true' : 'false',
    'api_version' => tt_test_string('api_version', 'v2'),
];

$result = null;
$requestPreview = null;
$requestUrl = null;
$history = is_array($_SESSION['tt_wl_api_test_log'] ?? null) ? $_SESSION['tt_wl_api_test_log'] : [];

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
    $action = tt_test_string('action', 'run');
    if ($action === 'clear_log') {
        $_SESSION['tt_wl_api_test_log'] = [];
        $history = [];
    } else {
    $path = '/' . $state['api_version'] . '/' . rawurlencode($state['pspid']) . '/products/' . rawurlencode((string) $state['product_id']);
    $query = [
        'countryCode' => $state['country'],
        'currencyCode' => $state['currency'],
        'amount' => $state['amount_minor'],
        'isRecurring' => $state['is_recurring'],
    ];
    $requestUrl = rtrim((string) (defined('WL_ENDPOINT') ? WL_ENDPOINT : ''), '/') . $path . '?' . http_build_query($query);
    $requestPreview = [
        'method' => 'GET',
        'endpoint' => (string) (defined('WL_ENDPOINT') ? WL_ENDPOINT : ''),
        'path' => $path,
        'query' => $query,
        'merchant_runtime' => (string) (defined('WL_MERCHANT_ID') ? WL_MERCHANT_ID : ''),
    ];

    try {
        $response = wl_api_request('GET', $path, $query);
        $result = [
            'ok' => $response['status'] >= 200 && $response['status'] < 300,
            'status' => (int) $response['status'],
            'body' => $response['body'],
            'raw' => $response['raw'],
        ];
        tt_test_history_add([
            'ts' => gmdate('c'),
            'request' => $requestPreview,
            'url' => $requestUrl,
            'status' => $result['status'],
            'ok' => $result['ok'],
            'body' => $result['body'],
        ]);
        $history = $_SESSION['tt_wl_api_test_log'];
    } catch (Throwable $e) {
        $result = [
            'ok' => false,
            'status' => 0,
            'body' => ['error' => $e->getMessage()],
            'raw' => '',
        ];
        tt_test_history_add([
            'ts' => gmdate('c'),
            'request' => $requestPreview,
            'url' => $requestUrl,
            'status' => 0,
            'ok' => false,
            'body' => ['error' => $e->getMessage()],
        ]);
        $history = $_SESSION['tt_wl_api_test_log'];
    }
    }
}
?>
<!doctype html>
<html lang="en">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title>TapTray Worldline API Test</title>
  <style>
    :root {
      --bg: #eef4f5;
      --surface: rgba(255,255,255,0.96);
      --surface-2: #f6fbfb;
      --border: #d6e1e2;
      --text: #193339;
      --muted: #5d7277;
      --accent: #23737b;
      --accent-2: #0f5a61;
      --danger: #9f3247;
      --danger-soft: #fff1f4;
      --ok: #1c7b57;
      --ok-soft: #edf9f4;
      --shadow: 0 18px 40px rgba(20, 45, 48, 0.10);
      --radius: 22px;
    }
    * { box-sizing: border-box; }
    body {
      margin: 0;
      min-height: 100vh;
      color: var(--text);
      font-family: Georgia, "Times New Roman", serif;
      background:
        radial-gradient(circle at top left, rgba(35,115,123,0.18), transparent 30%),
        radial-gradient(circle at bottom right, rgba(9,68,83,0.12), transparent 34%),
        linear-gradient(180deg, #f8fbfb, var(--bg));
    }
    .shell {
      max-width: 1180px;
      margin: 0 auto;
      padding: 28px 18px 42px;
    }
    .hero {
      display: grid;
      grid-template-columns: minmax(0, 1.1fr) minmax(300px, 0.9fr);
      gap: 18px;
      margin-bottom: 18px;
    }
    .card {
      background: var(--surface);
      border: 1px solid var(--border);
      border-radius: var(--radius);
      box-shadow: var(--shadow);
      padding: 22px;
    }
    .eyebrow {
      font-size: 12px;
      font-weight: 700;
      letter-spacing: 0.14em;
      text-transform: uppercase;
      color: var(--accent);
    }
    h1 {
      margin: 10px 0 0;
      font-size: clamp(30px, 4vw, 48px);
      line-height: 1.02;
      font-weight: 700;
    }
    .lede {
      margin: 12px 0 0;
      color: var(--muted);
      font-size: 17px;
      line-height: 1.5;
      max-width: 58ch;
    }
    .meta {
      display: grid;
      gap: 10px;
      align-content: start;
      background: linear-gradient(180deg, #f8fcfc, #eef6f7);
    }
    .meta-row {
      display: flex;
      justify-content: space-between;
      gap: 16px;
      padding-bottom: 10px;
      border-bottom: 1px solid var(--border);
      font-size: 14px;
    }
    .meta-row:last-child { border-bottom: 0; padding-bottom: 0; }
    .meta-label { color: var(--muted); font-weight: 700; }
    .meta-value { font-weight: 700; text-align: right; word-break: break-word; }
    .grid {
      display: grid;
      grid-template-columns: minmax(0, 0.9fr) minmax(0, 1.1fr);
      gap: 18px;
      align-items: start;
    }
    .form-grid {
      display: grid;
      grid-template-columns: repeat(2, minmax(0, 1fr));
      gap: 14px;
      margin-top: 18px;
    }
    .field {
      display: grid;
      gap: 7px;
    }
    .field.full { grid-column: 1 / -1; }
    label {
      font-size: 12px;
      font-weight: 700;
      letter-spacing: 0.08em;
      text-transform: uppercase;
      color: var(--muted);
    }
    input, select {
      width: 100%;
      min-height: 46px;
      border-radius: 14px;
      border: 1px solid var(--border);
      padding: 0 14px;
      background: #fff;
      color: var(--text);
      font: inherit;
      font-size: 16px;
    }
    .presets {
      display: flex;
      flex-wrap: wrap;
      gap: 10px;
      margin-top: 16px;
    }
    .preset-btn, .submit-btn {
      appearance: none;
      border: 0;
      border-radius: 999px;
      min-height: 42px;
      padding: 0 16px;
      cursor: pointer;
      font: inherit;
      font-size: 14px;
      font-weight: 700;
    }
    .preset-btn {
      background: #ecf5f5;
      color: var(--accent-2);
      border: 1px solid rgba(35,115,123,0.16);
    }
    .actions {
      display: flex;
      flex-wrap: wrap;
      gap: 12px;
      margin-top: 18px;
    }
    .submit-btn {
      background: linear-gradient(180deg, var(--accent), var(--accent-2));
      color: #fff;
      box-shadow: 0 10px 20px rgba(15,90,97,0.18);
    }
    .submit-btn.secondary {
      background: #fff;
      color: var(--text);
      border: 1px solid var(--border);
      box-shadow: none;
    }
    .status {
      display: inline-flex;
      align-items: center;
      gap: 8px;
      min-height: 38px;
      padding: 0 14px;
      border-radius: 999px;
      font-size: 13px;
      font-weight: 700;
    }
    .status.ok { background: var(--ok-soft); color: var(--ok); }
    .status.fail { background: var(--danger-soft); color: var(--danger); }
    .stack {
      display: grid;
      gap: 18px;
    }
    .log-box {
      background: #0f1a1d;
      color: #d8e8ec;
      border-radius: 18px;
      padding: 16px;
      overflow: auto;
      font-family: "SFMono-Regular", Consolas, "Liberation Mono", Menlo, monospace;
      font-size: 13px;
      line-height: 1.55;
      white-space: pre-wrap;
      word-break: break-word;
    }
    .mini {
      font-size: 13px;
      color: var(--muted);
    }
    .history {
      display: grid;
      gap: 12px;
    }
    .history-item {
      border: 1px solid var(--border);
      border-radius: 18px;
      padding: 16px;
      background: linear-gradient(180deg, #fff, var(--surface-2));
    }
    .history-top {
      display: flex;
      flex-wrap: wrap;
      gap: 10px;
      justify-content: space-between;
      margin-bottom: 10px;
      font-size: 13px;
    }
    .history-url {
      margin: 0 0 8px;
      font-family: "SFMono-Regular", Consolas, "Liberation Mono", Menlo, monospace;
      font-size: 12px;
      color: var(--accent-2);
      word-break: break-all;
    }
    @media (max-width: 920px) {
      .hero, .grid, .form-grid { grid-template-columns: 1fr; }
    }
  </style>
</head>
<body>
  <div class="shell">
    <section class="hero">
      <div class="card">
        <div class="eyebrow">TapTrayTest</div>
        <h1>Worldline API Test UI</h1>
        <p class="lede">Use this page to test product lookup calls against the current Worldline sandbox credentials. Edit the defaults, try wallet product IDs, and keep the raw response visible on the same screen.</p>
      </div>
      <aside class="card meta">
        <div class="meta-row"><span class="meta-label">Runtime env</span><span class="meta-value"><?= htmlspecialchars((string) (defined('WL_ENV') ? WL_ENV : 'unknown')) ?></span></div>
        <div class="meta-row"><span class="meta-label">Loaded PSPID</span><span class="meta-value"><?= htmlspecialchars((string) (defined('WL_MERCHANT_ID') ? WL_MERCHANT_ID : '')) ?></span></div>
        <div class="meta-row"><span class="meta-label">Endpoint</span><span class="meta-value"><?= htmlspecialchars((string) (defined('WL_ENDPOINT') ? WL_ENDPOINT : '')) ?></span></div>
        <div class="meta-row"><span class="meta-label">Forced mode</span><span class="meta-value"><?= defined('WL_FORCE_ENV') ? htmlspecialchars((string) WL_FORCE_ENV) : 'none' ?></span></div>
        <div class="meta-row"><span class="meta-label">Google Pay merchant</span><span class="meta-value"><?= htmlspecialchars((string) (defined('TT_GOOGLE_PAY_MERCHANT_ID') ? TT_GOOGLE_PAY_MERCHANT_ID : '')) ?></span></div>
      </aside>
    </section>

    <section class="grid">
      <div class="card">
        <div class="eyebrow">Request</div>
        <form method="post">
          <div class="presets">
            <?php foreach ($presets as $preset): ?>
              <button class="preset-btn" type="submit" name="product_id" value="<?= (int) $preset['id'] ?>"><?= htmlspecialchars($preset['label']) ?> <?= (int) $preset['id'] ?></button>
            <?php endforeach; ?>
          </div>

          <div class="form-grid">
            <div class="field">
              <label for="pspid">Merchant / PSPID</label>
              <input id="pspid" name="pspid" value="<?= htmlspecialchars($state['pspid']) ?>" spellcheck="false">
            </div>
            <div class="field">
              <label for="product_id">Product ID</label>
              <input id="product_id" name="product_id" type="number" min="1" value="<?= (int) $state['product_id'] ?>">
            </div>
            <div class="field">
              <label for="country">Country</label>
              <input id="country" name="country" maxlength="2" value="<?= htmlspecialchars($state['country']) ?>" spellcheck="false">
            </div>
            <div class="field">
              <label for="currency">Currency</label>
              <input id="currency" name="currency" maxlength="3" value="<?= htmlspecialchars($state['currency']) ?>" spellcheck="false">
            </div>
            <div class="field">
              <label for="amount_minor">Amount Minor</label>
              <input id="amount_minor" name="amount_minor" type="number" min="1" value="<?= (int) $state['amount_minor'] ?>">
            </div>
            <div class="field">
              <label for="is_recurring">Recurring</label>
              <select id="is_recurring" name="is_recurring">
                <option value="false"<?= $state['is_recurring'] === 'false' ? ' selected' : '' ?>>false</option>
                <option value="true"<?= $state['is_recurring'] === 'true' ? ' selected' : '' ?>>true</option>
              </select>
            </div>
            <div class="field full">
              <label for="api_version">API Version</label>
              <select id="api_version" name="api_version">
                <option value="v2"<?= $state['api_version'] === 'v2' ? ' selected' : '' ?>>v2</option>
                <option value="v1"<?= $state['api_version'] === 'v1' ? ' selected' : '' ?>>v1</option>
              </select>
            </div>
          </div>

          <div class="actions">
            <button class="submit-btn" type="submit" name="action" value="run">Confirm / Post Test</button>
            <button class="submit-btn secondary" type="submit" name="action" value="run">Run Current Inputs</button>
            <button class="submit-btn secondary" type="submit" name="action" value="clear_log">Clear Result Log</button>
          </div>
        </form>
      </div>

      <div class="stack">
        <section class="card">
          <div class="eyebrow">Latest Result</div>
          <?php if ($result !== null): ?>
            <p>
              <span class="status <?= !empty($result['ok']) ? 'ok' : 'fail' ?>">
                <?= !empty($result['ok']) ? 'Success' : 'Failed' ?><?= isset($result['status']) ? ' · HTTP ' . (int) $result['status'] : '' ?>
              </span>
            </p>
            <?php if ($requestUrl): ?>
              <p class="mini">Request URL</p>
              <div class="log-box"><?= htmlspecialchars($requestUrl) ?></div>
            <?php endif; ?>
            <p class="mini">Request payload</p>
            <div class="log-box"><?= htmlspecialchars(json_encode($requestPreview, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) ?: '{}') ?></div>
            <p class="mini">Response body</p>
            <div class="log-box"><?= htmlspecialchars(json_encode($result['body'], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) ?: (string) $result['raw']) ?></div>
          <?php else: ?>
            <p class="mini">No request sent yet. Use a preset or adjust the fields and run a test.</p>
          <?php endif; ?>
        </section>

        <section class="card">
          <div class="eyebrow">Result Log</div>
          <div class="history">
            <?php if ($history): ?>
              <?php foreach ($history as $entry): ?>
                <article class="history-item">
                  <div class="history-top">
                    <strong><?= htmlspecialchars((string) ($entry['ts'] ?? '')) ?></strong>
                    <span class="status <?= !empty($entry['ok']) ? 'ok' : 'fail' ?>">
                      <?= !empty($entry['ok']) ? 'Success' : 'Failed' ?><?= isset($entry['status']) ? ' · HTTP ' . (int) $entry['status'] : '' ?>
                    </span>
                  </div>
                  <p class="history-url"><?= htmlspecialchars((string) ($entry['url'] ?? '')) ?></p>
                  <div class="log-box"><?= htmlspecialchars(json_encode($entry['body'] ?? [], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) ?: '{}') ?></div>
                </article>
              <?php endforeach; ?>
            <?php else: ?>
              <p class="mini">No stored test results yet.</p>
            <?php endif; ?>
          </div>
        </section>
      </div>
    </section>
  </div>
</body>
</html>
