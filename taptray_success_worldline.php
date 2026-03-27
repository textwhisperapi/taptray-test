<?php
declare(strict_types=1);

require_once __DIR__ . '/includes/functions.php';
require_once __DIR__ . '/includes/taptray_orders.php';
sec_session_start();

$pendingOrder = is_array($_SESSION['taptray_completed_order'] ?? null)
    ? $_SESSION['taptray_completed_order']
    : (is_array($_SESSION['taptray_pending_order'] ?? null) ? $_SESSION['taptray_pending_order'] : null);
$hostedCheckoutId = trim((string) ($_GET['hostedCheckoutId'] ?? ''));
$orderReference = trim((string) ($_GET['order'] ?? ($pendingOrder['reference'] ?? '')));

function tt_success_currency_exponent(string $currencyCode): int {
    return in_array(strtoupper($currencyCode), ['BIF', 'CLP', 'DJF', 'GNF', 'ISK', 'JPY', 'KMF', 'KRW', 'MGA', 'PYG', 'RWF', 'UGX', 'VND', 'VUV', 'XAF', 'XOF', 'XPF'], true) ? 0 : 2;
}

function tt_success_format_minor(int $amountMinor, string $currencyCode): string {
    $exponent = tt_success_currency_exponent($currencyCode);
    if ($exponent === 0) {
        return number_format($amountMinor, 0, '.', '') . ' ' . $currencyCode;
    }
    return number_format($amountMinor / (10 ** $exponent), $exponent, '.', '') . ' ' . $currencyCode;
}

$testPayloadRaw = (string) ($_GET['payload'] ?? '');
if ($pendingOrder === null && ($testPayloadRaw !== '' || isset($_GET['test']))) {
    $decoded = json_decode($testPayloadRaw, true);
    if (is_array($decoded)) {
        $pendingOrder = $decoded;
        $orderReference = trim((string) ($decoded['reference'] ?? $orderReference));
    } else {
        $pendingOrder = [
            'reference' => $orderReference !== '' ? $orderReference : 'preview_order',
            'merchant_name' => defined('TT_MERCHANT_NAME') ? TT_MERCHANT_NAME : 'TapTray',
            'currency' => defined('TT_MERCHANT_CURRENCY') ? TT_MERCHANT_CURRENCY : 'ISK',
            'totals' => [
                'quantity' => 2,
                'amount_minor' => 549000,
            ],
            'wallet' => [
                'requested_path' => 'Phone wallet',
            ],
            'items' => [
                [
                    'title' => 'Soup of the day',
                    'short_description' => 'Warm vegetable soup with fresh herbs.',
                    'detailed_description' => 'Warm vegetable soup with fresh herbs and house seasoning.',
                    'quantity' => 1,
                    'price_label' => '2000',
                ],
                [
                    'title' => 'Vegan burger',
                    'short_description' => 'Plant-based burger with crispy toppings.',
                    'detailed_description' => 'Plant-based burger with crispy toppings, tomato, lettuce, and house sauce.',
                    'quantity' => 1,
                    'price_label' => '3490',
                ],
            ],
        ];
    }
}
$merchantName = (string) (($pendingOrder['merchant_name'] ?? (defined('TT_MERCHANT_NAME') ? TT_MERCHANT_NAME : 'TapTray')));
$currency = (string) (($pendingOrder['currency'] ?? (defined('TT_MERCHANT_CURRENCY') ? TT_MERCHANT_CURRENCY : 'ISK')));
$amountMinor = (int) (($pendingOrder['totals']['amount_minor'] ?? 0));
$totalQty = (int) (($pendingOrder['totals']['quantity'] ?? 0));
$items = is_array($pendingOrder['items'] ?? null) ? $pendingOrder['items'] : [];

$headline = 'Payment received';
$subline = 'Your order has been confirmed and sent on its way.';
$worldlineStatus = '';

if ($hostedCheckoutId !== '') {
    $worldlineStatus = 'Returned';
}

$formattedTotal = tt_success_format_minor($amountMinor, $currency);
$storedOrder = null;
if (is_array($pendingOrder)) {
    $storedOrder = tt_orders_upsert_paid_order($mysqli, $pendingOrder);
}
$subline = ($storedOrder['status'] ?? 'in_process') === 'ready'
    ? 'Your order is ready for pickup.'
    : 'Your order has been confirmed and is now in process.';
$vapidKey = getenv('VAPID_PUBLIC_KEY') ?: '';
$menuReturnUrl = '/';
if ($orderReference !== '') {
    $menuReturnUrl = '/?taptray_order=' . rawurlencode($orderReference);
}
?>
<!doctype html>
<html lang="en">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title>TapTray Order Confirmation</title>
  <style>
    :root {
      --bg: #f4f7fb;
      --surface: rgba(255,255,255,0.95);
      --surface-2: #f7faff;
      --border: #d8dfef;
      --text: #1a2230;
      --muted: #677388;
      --accent: #4b5ee4;
      --accent-soft: #eef1ff;
      --success: #1f8a70;
      --success-soft: #e8fbf4;
      --shadow: 0 18px 40px rgba(31,42,70,0.10);
      --radius: 24px;
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
      max-width: 1040px;
      margin: 0 auto;
      padding: 28px 18px 48px;
    }
    .topbar {
      display: flex;
      align-items: center;
      justify-content: space-between;
      gap: 12px;
      margin-bottom: 22px;
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
    .layout {
      display: grid;
      grid-template-columns: minmax(0, 1.08fr) minmax(320px, 0.92fr);
      gap: 20px;
    }
    .card {
      background: var(--surface);
      border: 1px solid var(--border);
      border-radius: var(--radius);
      box-shadow: var(--shadow);
      backdrop-filter: blur(10px);
    }
    .hero {
      padding: 28px 28px 24px;
    }
    .hero-kicker {
      font-size: 12px;
      font-weight: 800;
      letter-spacing: 0.08em;
      text-transform: uppercase;
      color: var(--accent);
    }
    .hero-head {
      display: flex;
      align-items: flex-start;
      justify-content: space-between;
      gap: 16px;
      margin-top: 12px;
    }
    h1 {
      margin: 0;
      font-size: clamp(30px, 4vw, 48px);
      line-height: 0.98;
    }
    .hero-copy {
      margin: 14px 0 0;
      color: var(--muted);
      font-size: 17px;
      line-height: 1.5;
      max-width: 58ch;
    }
    .hero-summary {
      display: grid;
      grid-template-columns: repeat(3, minmax(0, 1fr));
      gap: 12px;
      margin-top: 22px;
    }
    .summary-tile {
      padding: 16px;
      border-radius: 18px;
      border: 1px solid var(--border);
      background: linear-gradient(180deg, #ffffff, var(--surface-2));
    }
    .summary-label {
      font-size: 12px;
      font-weight: 800;
      letter-spacing: 0.06em;
      text-transform: uppercase;
      color: var(--muted);
    }
    .summary-value {
      margin-top: 8px;
      font-size: 24px;
      font-weight: 800;
      line-height: 1.05;
      color: var(--text);
    }
    .summary-value.is-small {
      font-size: 18px;
      line-height: 1.2;
      word-break: break-word;
    }
    .items-card {
      margin-top: 18px;
      padding: 18px;
    }
    .section-title {
      font-size: 13px;
      font-weight: 800;
      letter-spacing: 0.08em;
      text-transform: uppercase;
      color: var(--accent);
    }
    .items-list {
      display: grid;
      gap: 10px;
      margin-top: 14px;
    }
    .item-row {
      display: grid;
      grid-template-columns: minmax(0, 1fr) auto;
      gap: 12px;
      align-items: start;
      padding: 14px 16px;
      border-radius: 18px;
      background: linear-gradient(180deg, #ffffff, #f9fbff);
      border: 1px solid var(--border);
    }
    .item-title {
      font-size: 18px;
      font-weight: 800;
      line-height: 1.12;
    }
    .item-description {
      margin-top: 5px;
      color: var(--muted);
      font-size: 14px;
      line-height: 1.4;
    }
    .item-meta {
      display: grid;
      justify-items: end;
      gap: 8px;
    }
    .item-qty {
      font-size: 13px;
      font-weight: 700;
      color: var(--muted);
    }
    .item-price {
      display: inline-flex;
      align-items: center;
      justify-content: center;
      min-width: 90px;
      padding: 8px 12px;
      border-radius: 999px;
      background: var(--accent-soft);
      color: var(--accent);
      font-size: 14px;
      font-weight: 800;
      border: 1px solid rgba(75,94,228,0.18);
      white-space: nowrap;
    }
    .side {
      padding: 22px;
      display: grid;
      gap: 14px;
      align-content: start;
    }
    .side-note {
      color: var(--muted);
      font-size: 14px;
      line-height: 1.5;
    }
    .meta-list {
      display: grid;
      gap: 10px;
      padding: 14px;
      border-radius: 18px;
      border: 1px solid var(--border);
      background: linear-gradient(180deg, #ffffff, #f7faff);
    }
    .meta-row {
      display: flex;
      justify-content: space-between;
      gap: 12px;
      color: var(--muted);
      font-size: 14px;
    }
    .meta-row strong {
      color: var(--text);
      text-align: right;
    }
    .action-stack {
      display: grid;
      gap: 10px;
      margin-top: 4px;
    }
    .action-btn {
      display: inline-flex;
      align-items: center;
      justify-content: center;
      width: 100%;
      min-height: 50px;
      padding: 0 16px;
      border-radius: 18px;
      border: 1px solid var(--border);
      background: var(--surface);
      color: var(--text);
      text-decoration: none;
      font-size: 15px;
      font-weight: 800;
    }
    .action-btn.primary {
      background: var(--accent);
      border-color: var(--accent);
      color: #fff;
    }
    @media (max-width: 860px) {
      .layout { grid-template-columns: 1fr; }
      .hero-summary { grid-template-columns: 1fr; }
      .hero-head { flex-direction: column; align-items: flex-start; }
      .item-row { grid-template-columns: 1fr; }
      .item-meta { justify-items: start; }
      .meta-row { flex-direction: column; gap: 4px; }
      .meta-row strong { text-align: left; }
    }
  </style>
</head>
<body>
  <div class="shell">
    <div class="topbar">
      <a class="back" href="<?= htmlspecialchars($menuReturnUrl, ENT_QUOTES, 'UTF-8') ?>">← Back to menu</a>
      <div class="brand">TapTray Confirmation</div>
    </div>

    <div class="layout">
      <section class="card">
        <div class="hero">
          <div class="hero-kicker">Order confirmed</div>
          <div class="hero-head">
            <h1><?= htmlspecialchars($headline, ENT_QUOTES, 'UTF-8') ?></h1>
          </div>
          <p class="hero-copy"><?= htmlspecialchars($subline, ENT_QUOTES, 'UTF-8') ?></p>

          <div class="hero-summary">
            <div class="summary-tile">
              <div class="summary-label">Merchant</div>
              <div class="summary-value is-small"><?= htmlspecialchars($merchantName, ENT_QUOTES, 'UTF-8') ?></div>
            </div>
            <div class="summary-tile">
              <div class="summary-label">Items</div>
              <div class="summary-value"><?= htmlspecialchars((string) $totalQty, ENT_QUOTES, 'UTF-8') ?></div>
            </div>
            <div class="summary-tile">
              <div class="summary-label">Total paid</div>
              <div class="summary-value is-small"><?= htmlspecialchars($formattedTotal, ENT_QUOTES, 'UTF-8') ?></div>
            </div>
          </div>
        </div>

        <div class="items-card">
          <div class="section-title">Ordered items</div>
          <div class="items-list">
            <?php if ($items): ?>
              <?php foreach ($items as $item): ?>
                <?php
                  $title = trim((string) ($item['title'] ?? 'Menu item')) ?: 'Menu item';
                  $description = trim((string) ($item['detailed_description'] ?? $item['short_description'] ?? ''));
                  $quantity = max(0, (int) ($item['quantity'] ?? 0));
                  $priceLabel = trim((string) ($item['price_label'] ?? ''));
                ?>
                <article class="item-row">
                  <div>
                    <div class="item-title"><?= htmlspecialchars($title, ENT_QUOTES, 'UTF-8') ?></div>
                    <div class="item-description"><?= htmlspecialchars($description !== '' ? $description : 'Menu item included in this order.', ENT_QUOTES, 'UTF-8') ?></div>
                  </div>
                  <div class="item-meta">
                    <div class="item-qty">Qty <?= htmlspecialchars((string) $quantity, ENT_QUOTES, 'UTF-8') ?></div>
                    <div class="item-price"><?= htmlspecialchars($priceLabel !== '' ? $priceLabel : 'Paid', ENT_QUOTES, 'UTF-8') ?></div>
                  </div>
                </article>
              <?php endforeach; ?>
            <?php else: ?>
              <article class="item-row">
                <div>
                  <div class="item-title">Your TapTray order</div>
                  <div class="item-description">The order summary will appear here once the checkout session stores the selected items.</div>
                </div>
              </article>
            <?php endif; ?>
          </div>
        </div>
      </section>

      <aside class="card">
        <div class="side">
          <div class="section-title">Order details</div>
          <p class="side-note">Keep this screen as the post-purchase confirmation. It is the customer-facing success view that follows payment.</p>

          <div class="meta-list">
            <div class="meta-row">
              <span>Order reference</span>
              <strong><?= htmlspecialchars($orderReference !== '' ? $orderReference : 'Pending', ENT_QUOTES, 'UTF-8') ?></strong>
            </div>
            <div class="meta-row">
              <span>Payment session</span>
              <strong><?= htmlspecialchars($hostedCheckoutId !== '' ? $hostedCheckoutId : (($pendingOrder['worldline']['hosted_checkout_id'] ?? '') ?: 'Pending'), ENT_QUOTES, 'UTF-8') ?></strong>
            </div>
            <div class="meta-row">
              <span>Worldline status</span>
              <strong><?= htmlspecialchars($worldlineStatus !== '' ? $worldlineStatus : 'Returned', ENT_QUOTES, 'UTF-8') ?></strong>
            </div>
            <div class="meta-row">
              <span>Wallet path</span>
              <strong><?= htmlspecialchars((string) ($pendingOrder['wallet']['requested_path'] ?? 'Phone wallet'), ENT_QUOTES, 'UTF-8') ?></strong>
            </div>
          </div>

          <div class="action-stack">
            <a class="action-btn primary" href="<?= htmlspecialchars($menuReturnUrl, ENT_QUOTES, 'UTF-8') ?>">Back to menu</a>
          </div>
        </div>
      </aside>
    </div>
  </div>
  <script>
    window.TAPTRAY_MENU_RETURN_URL = <?= json_encode($menuReturnUrl) ?>;
    window.VAPID_PUBLIC_KEY = <?= json_encode($vapidKey) ?>;
    window.TAPTRAY_ORDER_REFERENCE = <?= json_encode((string) ($storedOrder['order_reference'] ?? $orderReference)) ?>;
  </script>
  <script>
    try {
      localStorage.removeItem("taptray:cart");
      window.taptrayCart = {};
    } catch (_err) {}
  </script>
  <script>
    (async function () {
      const returnUrl = String(window.TAPTRAY_MENU_RETURN_URL || "/");
      const finish = () => {
        try {
          window.location.replace(returnUrl);
        } catch (_err) {
          window.location.href = returnUrl;
        }
      };
      const fallbackTimer = setTimeout(finish, 1200);
      try {
        const orderReference = String(window.TAPTRAY_ORDER_REFERENCE || "").trim();
        if (!orderReference || !("serviceWorker" in navigator) || !("PushManager" in window) || !window.VAPID_PUBLIC_KEY) {
          finish();
          return;
        }
        if (Notification.permission === "default") {
          await Notification.requestPermission();
        }
        if (Notification.permission !== "granted") {
          finish();
          return;
        }
        const reg = await navigator.serviceWorker.ready;
        const key = (() => {
          const padding = "=".repeat((4 - window.VAPID_PUBLIC_KEY.length % 4) % 4);
          const base64 = (window.VAPID_PUBLIC_KEY + padding).replace(/-/g, "+").replace(/_/g, "/");
          const raw = atob(base64);
          return Uint8Array.from([...raw].map((ch) => ch.charCodeAt(0)));
        })();
        const subscription = await reg.pushManager.getSubscription() || await reg.pushManager.subscribe({
          userVisibleOnly: true,
          applicationServerKey: key
        });
        await fetch("/taptray_order_subscribe.php", {
          method: "POST",
          headers: { "Content-Type": "application/json", "Accept": "application/json" },
          credentials: "same-origin",
          body: JSON.stringify({
            order_reference: orderReference,
            env: location.host,
            subscription
          }),
          keepalive: true
        });
      } catch (err) {
        console.warn("TapTray order push registration failed", err);
      } finally {
        clearTimeout(fallbackTimer);
        finish();
      }
    })();
  </script>
</body>
</html>
