<?php
require_once __DIR__ . '/includes/db_connect.php';
require_once __DIR__ . '/includes/functions.php';
require_once __DIR__ . '/includes/taptray_orders.php';
require_once __DIR__ . '/includes/sub_worldline_config.php';
require_once __DIR__ . '/includes/sub_rapyd_config.php';
sec_session_start();

$requestedProvider = strtolower(trim((string) ($_GET['provider'] ?? '')));
$requestedOrderReference = trim((string) ($_GET['order_reference'] ?? ''));
$configuredProvider = strtolower(trim((string) (function_exists('tt_env_value') ? tt_env_value('TT_PAYMENT_PROVIDER', 'worldline') : 'worldline')));
$paymentProvider = in_array($configuredProvider, ['worldline', 'rapyd'], true) ? $configuredProvider : 'worldline';
if ($requestedOrderReference !== '') {
  $checkoutOrder = tt_orders_get_by_reference($mysqli, $requestedOrderReference);
  $ownerUsername = trim((string) ($checkoutOrder['owner_username'] ?? ''));
  $ownerPaymentSettings = $ownerUsername !== '' ? tt_orders_get_owner_payment_settings($mysqli, $ownerUsername) : null;
  $ownerProvider = strtolower(trim((string) ($ownerPaymentSettings['provider'] ?? '')));
  if (in_array($ownerProvider, ['worldline', 'rapyd'], true)) {
    $paymentProvider = $ownerProvider;
  } elseif (in_array($requestedProvider, ['worldline', 'rapyd'], true)) {
    $paymentProvider = $requestedProvider;
  }
} elseif (in_array($requestedProvider, ['worldline', 'rapyd'], true)) {
  $paymentProvider = $requestedProvider;
}

$ttMerchantConfig = [
  'paymentProvider' => $paymentProvider,
  'paymentModel' => defined('TT_PAYMENT_MODEL') ? TT_PAYMENT_MODEL : 'merchant_of_record',
  'merchantName' => defined('TT_MERCHANT_NAME') ? TT_MERCHANT_NAME : 'TapTray',
  'merchantCountry' => defined('TT_MERCHANT_COUNTRY') ? TT_MERCHANT_COUNTRY : 'NL',
  'merchantCurrency' => defined('TT_MERCHANT_CURRENCY') ? TT_MERCHANT_CURRENCY : 'EUR',
  'merchantDescriptor' => defined('TT_MERCHANT_DESCRIPTOR') ? TT_MERCHANT_DESCRIPTOR : 'TapTray',
  'platformFeeBps' => defined('TT_PLATFORM_FEE_BPS') ? TT_PLATFORM_FEE_BPS : 0,
  'walletMode' => defined('TT_WALLET_MODE') ? TT_WALLET_MODE : 'default_wallet_first',
  'walletEnabled' => defined('TT_WALLET_ENABLED') ? TT_WALLET_ENABLED : true,
  'googlePayEnvironment' => defined('TT_GOOGLE_PAY_ENVIRONMENT') ? TT_GOOGLE_PAY_ENVIRONMENT : 'TEST',
  'googlePayMerchantId' => defined('TT_GOOGLE_PAY_MERCHANT_ID') ? TT_GOOGLE_PAY_MERCHANT_ID : '',
  'rapydEnvironment' => defined('RAPYD_ENV') ? RAPYD_ENV : 'sandbox',
  'rapydEndpoint' => defined('RAPYD_ENDPOINT') ? RAPYD_ENDPOINT : 'https://sandboxapi.rapyd.net',
  'worldlineGatewayMerchantId' => defined('WL_MERCHANT_ID') ? WL_MERCHANT_ID : (defined('TT_WORLDLINE_GOOGLEPAY_GATEWAY_MERCHANT_ID') ? TT_WORLDLINE_GOOGLEPAY_GATEWAY_MERCHANT_ID : ''),
];
?>
<!doctype html>
<html lang="en">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title>TapTray Checkout</title>
  <style>
    :root {
      --bg: #f4f7fb;
      --surface: rgba(255,255,255,0.94);
      --surface-2: #edf2fb;
      --border: #d8dfef;
      --text: #1a2230;
      --muted: #677388;
      --accent: #4b5ee4;
      --accent-soft: #eef1ff;
      --danger: #b55454;
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
    .checkout-shell {
      max-width: 960px;
      margin: 0 auto;
      padding: 14px 14px 28px;
    }
    .checkout-topbar {
      display: flex;
      align-items: center;
      justify-content: space-between;
      gap: 12px;
      margin-bottom: 12px;
    }
    .checkout-back {
      display: inline-flex;
      align-items: center;
      gap: 8px;
      padding: 8px 12px;
      border-radius: 999px;
      border: 1px solid var(--border);
      background: var(--surface);
      color: var(--text);
      text-decoration: none;
      font-weight: 700;
      box-shadow: var(--shadow);
    }
    .checkout-brand {
      font-size: 14px;
      font-weight: 800;
      letter-spacing: 0.08em;
      text-transform: uppercase;
      color: var(--muted);
    }
    .checkout-layout {
      display: grid;
      grid-template-columns: 1fr;
      gap: 14px;
      max-width: 760px;
      margin: 0 auto;
    }
    .checkout-card {
      background: var(--surface);
      border: 1px solid var(--border);
      border-radius: var(--radius);
      box-shadow: var(--shadow);
      backdrop-filter: blur(10px);
    }
    .checkout-top-pay {
      padding: 14px 14px 0;
    }
    .checkout-card-head {
      padding: 14px 14px 8px;
    }
    .checkout-kicker {
      font-size: 12px;
      font-weight: 800;
      letter-spacing: 0.08em;
      text-transform: uppercase;
      color: var(--accent);
    }
    h1 {
      margin: 6px 0 0;
      font-size: clamp(20px, 3vw, 28px);
      line-height: 1.05;
    }
    .checkout-sub {
      margin: 6px 0 0;
      color: var(--muted);
      font-size: 13px;
      line-height: 1.35;
    }
    .checkout-items {
      display: grid;
      gap: 8px;
      padding: 4px 12px 12px;
    }
    .checkout-order-name {
      padding: 0 12px 12px;
    }
    .checkout-order-name label {
      display: block;
      margin-bottom: 6px;
      color: var(--muted);
      font-size: 12px;
      font-weight: 800;
      letter-spacing: 0.06em;
      text-transform: uppercase;
    }
    .checkout-order-name input {
      width: 100%;
      min-height: 44px;
      border-radius: 14px;
      border: 1px solid var(--border);
      padding: 10px 12px;
      font: inherit;
      color: var(--text);
      background: linear-gradient(180deg, #ffffff, #f9fbff);
    }
    .checkout-item {
      display: grid;
      grid-template-columns: 52px minmax(0, 1fr) auto;
      gap: 10px;
      align-items: center;
      padding: 8px 10px;
      border-radius: 14px;
      background: linear-gradient(180deg, #ffffff, #f9fbff);
      border: 1px solid var(--border);
    }
    .checkout-thumb {
      position: relative;
      width: 52px;
      height: 52px;
      border-radius: 12px;
      overflow: hidden;
      border: 1px solid var(--border);
      background: var(--surface-2);
    }
    .checkout-thumb img {
      width: 100%;
      height: 100%;
      object-fit: cover;
      display: block;
    }
    .checkout-thumb-fallback {
      width: 100%;
      height: 100%;
      display: flex;
      align-items: center;
      justify-content: center;
      color: var(--muted);
      font-size: 11px;
      font-weight: 800;
      letter-spacing: 0.06em;
    }
    .checkout-qty {
      position: absolute;
      top: 4px;
      left: 4px;
      min-width: 17px;
      height: 17px;
      padding: 0 4px;
      border-radius: 999px;
      background: rgba(255,255,255,0.95);
      border: 1px solid rgba(0,0,0,0.06);
      display: inline-flex;
      align-items: center;
      justify-content: center;
      font-size: 10px;
      font-weight: 800;
    }
    .checkout-item-title {
      font-size: 16px;
      font-weight: 800;
      line-height: 1.1;
    }
    .checkout-item-description {
      margin-top: 2px;
      color: var(--muted);
      font-size: 12px;
      line-height: 1.3;
      display: -webkit-box;
      -webkit-line-clamp: 2;
      -webkit-box-orient: vertical;
      overflow: hidden;
    }
    .checkout-item-price {
      display: inline-flex;
      align-self: start;
      padding: 5px 10px;
      border-radius: 999px;
      background: var(--accent-soft);
      color: var(--accent);
      font-size: 12px;
      font-weight: 800;
      border: 1px solid rgba(75,94,228,0.18);
      white-space: nowrap;
    }
    .checkout-summary {
      padding: 14px;
      display: grid;
      gap: 10px;
    }
    .checkout-row {
      display: flex;
      align-items: center;
      justify-content: space-between;
      gap: 12px;
      color: var(--muted);
      font-size: 13px;
    }
    .checkout-row strong {
      color: var(--text);
      font-size: 15px;
    }
    .checkout-total {
      padding-top: 10px;
      border-top: 1px solid var(--border);
    }
    .wallet-stack {
      display: grid;
      gap: 10px;
      margin-top: 2px;
    }
    .wallet-btn {
      width: 100%;
      padding: 14px 16px;
      border-radius: 16px;
      border: 1px solid var(--border);
      font-size: 15px;
      font-weight: 800;
      cursor: pointer;
      transition: opacity 0.18s ease, transform 0.18s ease;
    }
    .wallet-btn:disabled {
      cursor: wait;
      opacity: 0.72;
      transform: none;
    }
    .wallet-btn.apple { background: #111; color: #fff; border-color: #111; }
    .wallet-btn.google { background: #fff; color: #111; }
    .wallet-btn.primary { background: var(--accent); color: #fff; border-color: var(--accent); }
    #checkoutTopPayBtn {
      padding: 12px 14px;
      font-size: 14px;
    }
    #checkoutTopPrimaryLabel {
      font-size: 14px;
      line-height: 1.2;
    }
    .checkout-amount {
      display: grid;
      gap: 4px;
      padding: 10px 0 4px;
    }
    .checkout-amount-label {
      color: var(--muted);
      font-size: 12px;
      font-weight: 800;
      letter-spacing: 0.08em;
      text-transform: uppercase;
    }
    .checkout-amount-value {
      color: var(--text);
      font-size: clamp(34px, 6vw, 48px);
      line-height: 1;
      font-weight: 900;
    }
    .wallet-fallback {
      display: none;
      gap: 10px;
      margin-top: 8px;
    }
    .wallet-fallback.is-visible {
      display: grid;
    }
    .checkout-primary-label {
      display: block;
    }
    .checkout-primary-note {
      color: var(--muted);
      font-size: 13px;
      line-height: 1.45;
    }
    .checkout-note {
      color: var(--muted);
      font-size: 13px;
      line-height: 1.45;
    }
    .checkout-provider-badge {
      display: inline-flex;
      align-items: center;
      justify-content: center;
      min-height: 34px;
      padding: 0 12px;
      border-radius: 999px;
      border: 1px solid var(--border);
      background: linear-gradient(180deg, #ffffff, #f7faff);
      color: var(--text);
      font-size: 12px;
      font-weight: 800;
      letter-spacing: 0.06em;
      text-transform: uppercase;
    }
    .checkout-test-link {
      display: inline-flex;
      align-items: center;
      justify-content: center;
      min-height: 44px;
      width: 100%;
      padding: 0 14px;
      border-radius: 16px;
      border: 1px dashed rgba(75,94,228,0.28);
      background: rgba(255,255,255,0.78);
      color: var(--accent);
      text-decoration: none;
      font-size: 14px;
      font-weight: 700;
    }
    .checkout-links {
      display: grid;
      gap: 10px;
    }
    .checkout-more {
      margin-top: 6px;
      border-top: 1px solid var(--border);
      padding-top: 12px;
    }
    .checkout-more.is-standalone {
      margin-top: 0;
      border-top: 0;
      padding-top: 0;
    }
    .checkout-more summary {
      cursor: pointer;
      color: var(--text);
      font-size: 14px;
      font-weight: 800;
      list-style: none;
      min-height: 48px;
      padding: 12px 14px;
      border-radius: 16px;
      border: 1px solid var(--border);
      background: linear-gradient(180deg, #ffffff, #f7faff);
      display: flex;
      align-items: center;
      justify-content: space-between;
    }
    .checkout-more summary::-webkit-details-marker {
      display: none;
    }
    .checkout-more-body {
      display: grid;
      gap: 10px;
      margin-top: 12px;
    }
    .checkout-debug {
      margin-top: 10px;
      padding: 12px 14px;
      border-radius: 16px;
      background: linear-gradient(180deg, #ffffff, #f7faff);
      border: 1px solid var(--border);
      color: var(--muted);
      font-size: 13px;
      line-height: 1.45;
      white-space: pre-line;
    }
    .checkout-debug.is-error {
      color: var(--danger);
      border-color: rgba(181, 84, 84, 0.22);
      background: linear-gradient(180deg, #fffdfd, #fff5f5);
    }
    .checkout-empty {
      padding: 40px 22px 46px;
      text-align: center;
      color: var(--muted);
    }
    @media (max-width: 860px) {
      .checkout-layout { grid-template-columns: 1fr; }
      .checkout-shell { padding: 10px 10px 22px; }
      .checkout-top-pay { padding: 12px 12px 0; }
      .checkout-card-head { padding: 12px 12px 6px; }
      .checkout-items { padding: 2px 10px 10px; }
      .checkout-summary { padding: 12px; }
      #checkoutTopPayBtn {
        padding: 11px 12px;
        font-size: 13px;
      }
      #checkoutTopPrimaryLabel {
        font-size: 13px;
      }
    }
  </style>
</head>
<body>
  <div class="checkout-shell">
    <div class="checkout-topbar">
      <a class="checkout-back" href="javascript:history.back()">← Back to menu</a>
      <div class="checkout-brand">TapTray Checkout</div>
    </div>

    <div class="checkout-layout">
      <section class="checkout-card">
        <div class="checkout-top-pay">
          <div class="checkout-amount">
            <div class="checkout-amount-label">Confirm amount</div>
            <div class="checkout-amount-value" id="checkoutAmountValue">0</div>
          </div>
          <button class="wallet-btn primary" type="button" id="checkoutTopPayBtn">
            <span id="checkoutTopPrimaryLabel" class="checkout-primary-label">Confirm and pay</span>
          </button>
        </div>
        <div class="checkout-card-head">
          <div class="checkout-kicker">Shop</div>
          <h1 id="checkoutOrderHeading">Selected shop</h1>
        </div>
        <div class="checkout-order-name">
          <label for="checkoutOrderName">Order name</label>
          <input id="checkoutOrderName" type="text" maxlength="120" placeholder="Your name, table, or pickup name">
        </div>
        <div id="checkoutItems" class="checkout-items"></div>
      </section>

      <section class="checkout-card">
          <details class="checkout-more is-standalone">
            <summary>Payment details</summary>
            <div class="checkout-more-body">
              <div class="checkout-provider-badge">Provider: <?= htmlspecialchars(strtoupper($paymentProvider), ENT_QUOTES, 'UTF-8') ?></div>
              <p class="checkout-sub"><?= htmlspecialchars($paymentProvider === 'rapyd'
                ? 'Google Pay runs directly when available. Apple Pay still opens the Rapyd hosted checkout.'
                : 'Phone wallet comes first. If the device has no default wallet path, TapTray will fall back to other payment options.', ENT_QUOTES, 'UTF-8') ?></p>
              <div class="checkout-row"><span>Items</span><strong id="checkoutQty">0</strong></div>
              <div class="checkout-row"><span>Subtotal</span><strong id="checkoutSubtotal">0</strong></div>
              <div class="checkout-row checkout-total"><span>Total</span><strong id="checkoutTotal">0</strong></div>
              <div id="checkoutPrimaryNote" class="checkout-primary-note">Checking for your phone wallet…</div>
              <button class="wallet-btn apple" type="button" id="checkoutTestApplePayBtn"><?= htmlspecialchars($paymentProvider === 'rapyd' ? 'Open Hosted Wallet Test' : 'Test Apple Pay Route', ENT_QUOTES, 'UTF-8') ?></button>
              <div class="checkout-links">
                <a href="/taptray_payment_success.php?test=1" id="checkoutViewSuccessBtn" class="checkout-test-link">View post-purchase screen</a>
                <a href="<?= htmlspecialchars($paymentProvider === 'rapyd' ? '/test_rapyd_api.php' : '/taptray_payment_diagnostics.php', ENT_QUOTES, 'UTF-8') ?>" class="checkout-test-link"><?= htmlspecialchars($paymentProvider === 'rapyd' ? 'Open Rapyd sandbox test' : 'Open payment diagnostics', ENT_QUOTES, 'UTF-8') ?></a>
              </div>
              <div id="checkoutDebug" class="checkout-debug">Wallet detection info will appear here when you press pay.</div>
              <p class="checkout-note">TapTray keeps one clear pay step. Current provider: <strong><?= htmlspecialchars(strtoupper($paymentProvider), ENT_QUOTES, 'UTF-8') ?></strong>.</p>
            </div>
          </details>
      </section>
    </div>
  </div>

  <script async src="https://pay.google.com/gp/p/js/pay.js"></script>
  <script>
    window.tapTrayCheckoutDraftOrder = null;

    function getTapTrayCheckoutCart() {
      const items = Array.isArray(window.tapTrayCheckoutDraftOrder?.items) ? window.tapTrayCheckoutDraftOrder.items : [];
      const cart = {};
      items.forEach((item) => {
        const key = String(item?.surrogate || "").trim();
        if (!key) return;
        cart[key] = item;
      });
      return cart;
    }

    function getTapTrayCheckoutOrderReference() {
      return String(window.tapTrayCheckoutDraftOrder?.order_reference || "").trim();
    }

    function getRequestedCheckoutOrderReference() {
      try {
        return String(new URLSearchParams(window.location.search || "").get("order_reference") || "").trim();
      } catch {
        return "";
      }
    }

    async function loadTapTrayCheckoutDraftOrder() {
      const requestedOrderReference = getRequestedCheckoutOrderReference();
      const statusUrl = requestedOrderReference
        ? `/taptray_order_status.php?order_reference=${encodeURIComponent(requestedOrderReference)}`
        : "/taptray_order_status.php";
      const response = await fetch(statusUrl, {
        credentials: "same-origin",
        headers: { Accept: "application/json" }
      });
      const data = await response.json().catch(() => null);
      if (!response.ok || !data || !data.ok) {
        throw new Error(data && data.error ? data.error : "Could not load the TapTray order.");
      }
      window.tapTrayCheckoutDraftOrder = data.draft_order && typeof data.draft_order === "object" ? data.draft_order : null;
      return window.tapTrayCheckoutDraftOrder;
    }

    function loadCheckoutOrderName() {
      try {
        return String(localStorage.getItem("taptray:order-name") || "").trim();
      } catch {
        return "";
      }
    }

    function persistCheckoutOrderName(value) {
      try {
        const trimmed = String(value || "").trim();
        if (trimmed) {
          localStorage.setItem("taptray:order-name", trimmed);
        } else {
          localStorage.removeItem("taptray:order-name");
        }
      } catch {}
    }

    function getCheckoutOrderName() {
      return String(document.getElementById("checkoutOrderName")?.value || "").trim();
    }

    function parsePrice(label) {
      const raw = String(label || "").trim();
      const match = raw.match(/(\d+(?:[.,]\d{1,2})?)/);
      if (!match) return 0;
      const value = Number.parseFloat(match[1].replace(",", "."));
      return Number.isFinite(value) ? value : 0;
    }

    function escapeHtml(value) {
      return String(value || "")
        .replace(/&/g, "&amp;")
        .replace(/</g, "&lt;")
        .replace(/>/g, "&gt;")
        .replace(/\"/g, "&quot;")
        .replace(/'/g, "&#039;");
    }

    const ZERO_DECIMAL_CURRENCIES = new Set(["BIF", "CLP", "DJF", "GNF", "ISK", "JPY", "KMF", "KRW", "MGA", "PYG", "RWF", "UGX", "VND", "VUV", "XAF", "XOF", "XPF"]);
    const TAPTRAY_PAYMENT_CONTEXT = <?= json_encode($ttMerchantConfig, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?>;
    const TAPTRAY_PROVIDER = String(TAPTRAY_PAYMENT_CONTEXT.paymentProvider || "worldline").toLowerCase();
    const TAPTRAY_GOOGLE_PAY = {
      enabled: !!TAPTRAY_PAYMENT_CONTEXT.walletEnabled,
      environment: String(TAPTRAY_PAYMENT_CONTEXT.googlePayEnvironment || "TEST").toUpperCase(),
      merchantId: String(TAPTRAY_PAYMENT_CONTEXT.googlePayMerchantId || "").trim(),
      gatewayMerchantId: String(TAPTRAY_PAYMENT_CONTEXT.worldlineGatewayMerchantId || "").trim(),
      product320Endpoint: "/taptray_worldline_get_product_320.php",
      createPaymentEndpoint: "/taptray_worldline_create_googlepay_payment.php",
      createCheckoutEndpoint: "/taptray_worldline_create_checkout.php"
    };
    const TAPTRAY_RAPYD = {
      configEndpoint: "/taptray_rapyd_get_googlepay_config.php",
      createCheckoutEndpoint: "/taptray_rapyd_create_checkout.php",
      createPaymentEndpoint: "/taptray_rapyd_create_googlepay_payment.php"
    };
    const tapTrayGooglePayState = {
      paymentsClient: null,
      paymentMethod: null,
      product320: null,
      ready: false,
      walletInfo: null,
      mode: "fallback"
    };

    function isZeroDecimalCurrency(currencyCode) {
      return ZERO_DECIMAL_CURRENCIES.has(String(currencyCode || "").toUpperCase());
    }

    function formatDisplayAmount(amount) {
      const numeric = Number(amount || 0);
      if (!Number.isFinite(numeric)) return "0";
      return isZeroDecimalCurrency(TAPTRAY_PAYMENT_CONTEXT.merchantCurrency)
        ? String(Math.round(numeric))
        : numeric.toFixed(2);
    }

    function toMinorAmount(amount) {
      const numeric = Number(amount || 0);
      if (!Number.isFinite(numeric) || numeric <= 0) return 0;
      return isZeroDecimalCurrency(TAPTRAY_PAYMENT_CONTEXT.merchantCurrency)
        ? Math.round(numeric)
        : Math.round(numeric * 100);
    }

    function getCheckoutEntries() {
      const cart = getTapTrayCheckoutCart();
      return Object.values(cart);
    }

    function getCheckoutOwnerLabel() {
      const draftOrder = window.tapTrayCheckoutDraftOrder;
      const fromItems = String(draftOrder?.items?.[0]?.owner_display_name || "").trim();
      if (fromItems) return fromItems;
      const fromOrder = String(draftOrder?.owner_username || "").trim();
      if (fromOrder) return fromOrder;
      return "Selected shop";
    }

    function getCheckoutTotals(entries) {
      const totalQty = entries.reduce((sum, item) => sum + Number(item?.quantity || 0), 0);
      const totalPrice = entries.reduce((sum, item) => sum + parsePrice(item?.price_label) * Number(item?.quantity || 0), 0);
      return {
        totalQty,
        totalPrice: Math.round(totalPrice)
      };
    }

    function renderCheckout() {
      const entries = getCheckoutEntries();
      const host = document.getElementById("checkoutItems");
      const headingEl = document.getElementById("checkoutOrderHeading");
      const qtyEl = document.getElementById("checkoutQty");
      const subtotalEl = document.getElementById("checkoutSubtotal");
      const totalEl = document.getElementById("checkoutTotal");
      const amountValueEl = document.getElementById("checkoutAmountValue");
      const totals = getCheckoutTotals(entries);

      if (headingEl) {
        headingEl.textContent = getCheckoutOwnerLabel();
      }

      qtyEl.textContent = String(totals.totalQty);
      subtotalEl.textContent = String(totals.totalPrice);
      totalEl.textContent = String(totals.totalPrice);
      if (amountValueEl) {
        amountValueEl.textContent = formatDisplayAmount(totals.totalPrice);
      }
      document.querySelectorAll("#checkoutTopPrimaryLabel").forEach((label) => {
        label.textContent = `Confirm and pay ${formatDisplayAmount(totals.totalPrice)}`;
      });

      if (!entries.length) {
        host.innerHTML = `<div class="checkout-empty">No items selected yet.</div>`;
        return;
      }

      host.innerHTML = entries.map((item) => {
        const title = String(item?.title || "Menu item").trim() || "Menu item";
        const description = String(item?.detailed_description || item?.short_description || item?.public_description || "").trim();
        const price = String(item?.price_label || "").trim() || "Set price";
        const imageUrl = String(item?.image_url || "").trim();
        const qty = Number(item?.quantity || 0);
        const thumb = imageUrl
          ? `<img src="${escapeHtml(imageUrl)}" alt="${escapeHtml(title)}">`
          : `<div class="checkout-thumb-fallback">IMG</div>`;
        return `
          <article class="checkout-item">
            <div class="checkout-thumb">
              ${thumb}
              <div class="checkout-qty">${qty}</div>
            </div>
            <div>
              <div class="checkout-item-title">${escapeHtml(title)}</div>
              <div class="checkout-item-description">${escapeHtml(description || "Selected menu item")}</div>
            </div>
            <div class="checkout-item-price">${escapeHtml(price)}</div>
          </article>
        `;
      }).join("");
    }

    async function detectPreferredWallet() {
      const hasGooglePaySdk = !!(window.google && google.payments && google.payments.api);
      const hasApplePay = !!(window.ApplePaySession && typeof window.ApplePaySession.canMakePayments === "function" && window.ApplePaySession.canMakePayments());
      let hasPaymentRequest = false;
      if (window.PaymentRequest) {
        try {
          const request = new PaymentRequest(
            [{ supportedMethods: "basic-card" }],
            { total: { label: "TapTray", amount: { currency: TAPTRAY_PAYMENT_CONTEXT.merchantCurrency || "EUR", value: "1" } } }
          );
          hasPaymentRequest = typeof request.canMakePayment === "function"
            ? await request.canMakePayment().catch(() => false)
            : false;
        } catch {
          hasPaymentRequest = false;
        }
      }

      return {
        type: hasApplePay ? "apple_pay" : (hasGooglePaySdk ? "google_pay" : (hasPaymentRequest ? "wallet" : "fallback")),
        hasApplePay,
        hasPaymentRequest,
        hasGooglePaySdk,
        platform: navigator.platform || "",
        language: navigator.language || "",
        userAgent: navigator.userAgent || ""
      };
    }

    function mapGoogleCardNetworks(networks) {
      const mapping = {
        mastercard: "MASTERCARD",
        visa: "VISA",
        amex: "AMEX",
        discover: "DISCOVER",
        diners: "DISCOVER",
        jcb: "JCB",
        maestro: "MASTERCARD"
      };
      const normalized = Array.isArray(networks) ? networks : [];
      const mapped = normalized
        .map((entry) => mapping[String(entry || "").trim().toLowerCase()] || "")
        .filter(Boolean);
      return mapped.length ? Array.from(new Set(mapped)) : ["MASTERCARD", "VISA"];
    }

    async function fetchWorldlineGooglePayConfig(amountMinor) {
      const url = `${TAPTRAY_GOOGLE_PAY.product320Endpoint}?country=${encodeURIComponent(TAPTRAY_PAYMENT_CONTEXT.merchantCountry)}&currency=${encodeURIComponent(TAPTRAY_PAYMENT_CONTEXT.merchantCurrency)}&amount_minor=${encodeURIComponent(String(Math.max(1, amountMinor || 1)))}`;
      const response = await fetch(url, {
        credentials: "same-origin",
        headers: { Accept: "application/json" }
      });
      const data = await response.json().catch(() => null);
      if (!response.ok || !data || !data.ok) {
        throw new Error(data && data.error ? data.error : "Could not load the Worldline Google Pay configuration.");
      }
      return data;
    }

    function buildGooglePayPaymentMethod(config) {
      if (!config.gateway) {
        throw new Error("Worldline did not return the Google Pay gateway value.");
      }
      if (!TAPTRAY_GOOGLE_PAY.gatewayMerchantId) {
        throw new Error("TapTray is missing the Worldline Google Pay gateway merchant ID.");
      }

      return {
        type: "CARD",
        parameters: {
          allowedAuthMethods: ["PAN_ONLY", "CRYPTOGRAM_3DS"],
          allowedCardNetworks: mapGoogleCardNetworks(config.networks)
        },
        tokenizationSpecification: {
          type: "PAYMENT_GATEWAY",
          parameters: {
            gateway: config.gateway,
            gatewayMerchantId: TAPTRAY_GOOGLE_PAY.gatewayMerchantId
          }
        }
      };
    }

    function createGooglePaymentsClient() {
      if (!window.google || !google.payments || !google.payments.api) {
        throw new Error("Google Pay is not available in this browser.");
      }
      return new google.payments.api.PaymentsClient({
        environment: TAPTRAY_GOOGLE_PAY.environment === "PRODUCTION" ? "PRODUCTION" : "TEST"
      });
    }

    async function waitForGooglePaySdk(timeoutMs = 5000) {
      const start = Date.now();
      while (Date.now() - start < timeoutMs) {
        if (window.google && google.payments && google.payments.api) {
          return true;
        }
        await new Promise((resolve) => window.setTimeout(resolve, 120));
      }
      return !!(window.google && google.payments && google.payments.api);
    }

    async function initializeWalletPath() {
      await waitForGooglePaySdk();
      const walletInfo = await detectPreferredWallet();
      tapTrayGooglePayState.walletInfo = walletInfo;
      tapTrayGooglePayState.mode = "fallback";

      if (!TAPTRAY_GOOGLE_PAY.enabled) {
        return { ready: false, walletInfo, reason: "Wallet-first payment is disabled in TapTray settings." };
      }
      if (TAPTRAY_PROVIDER === "rapyd") {
        if (walletInfo.hasApplePay) {
          tapTrayGooglePayState.ready = true;
          tapTrayGooglePayState.mode = "apple_pay_hosted_checkout";
          return { ready: true, walletInfo, walletLabel: "Apple Pay" };
        }
        if (!walletInfo.hasGooglePaySdk) {
          return { ready: false, walletInfo, reason: "Google Pay is not available in this browser yet." };
        }
        if (!String(TAPTRAY_PAYMENT_CONTEXT.googlePayMerchantId || "").trim()) {
          return { ready: false, walletInfo, reason: "TapTray is missing the Google Pay merchant ID." };
        }

        const totals = getCheckoutTotals(getCheckoutEntries());
        const url = `${TAPTRAY_RAPYD.configEndpoint}?country=${encodeURIComponent(TAPTRAY_PAYMENT_CONTEXT.merchantCountry)}&currency=${encodeURIComponent(TAPTRAY_PAYMENT_CONTEXT.merchantCurrency)}&amount_minor=${encodeURIComponent(String(Math.max(1, toMinorAmount(totals.totalPrice) || 1)))}`;
        const response = await fetch(url, {
          credentials: "same-origin",
          headers: { Accept: "application/json" }
        });
        const config = await response.json().catch(() => null);
        if (!response.ok || !config || !config.ok) {
          throw new Error(config && config.error ? config.error : "Could not load the Rapyd Google Pay configuration.");
        }

        const client = createGooglePaymentsClient();
        const paymentMethod = {
          type: "CARD",
          parameters: {
            allowedAuthMethods: ["PAN_ONLY", "CRYPTOGRAM_3DS"],
            allowedCardNetworks: Array.isArray(config.allowedCardNetworks) && config.allowedCardNetworks.length
              ? config.allowedCardNetworks
              : ["MASTERCARD", "VISA"]
          },
          tokenizationSpecification: {
            type: "PAYMENT_GATEWAY",
            parameters: {
              gateway: "rapyd",
              gatewayMerchantId: String(config.gatewayMerchantId || "").trim()
            }
          }
        };

        const readyResponse = await client.isReadyToPay({
          apiVersion: 2,
          apiVersionMinor: 0,
          allowedPaymentMethods: [paymentMethod]
        });

        tapTrayGooglePayState.paymentsClient = client;
        tapTrayGooglePayState.paymentMethod = paymentMethod;
        tapTrayGooglePayState.product320 = config;
        tapTrayGooglePayState.ready = !!(readyResponse && readyResponse.result);
        tapTrayGooglePayState.mode = tapTrayGooglePayState.ready ? "rapyd_google_pay_direct" : "fallback";

        return {
          ready: tapTrayGooglePayState.ready,
          walletInfo,
          product320: config,
          walletLabel: "Google Pay"
        };
      }
      if (walletInfo.hasApplePay) {
        tapTrayGooglePayState.ready = true;
        tapTrayGooglePayState.mode = "apple_pay_hosted_checkout";
        return { ready: true, walletInfo, walletLabel: "Apple Pay" };
      }
      if (!TAPTRAY_GOOGLE_PAY.merchantId) {
        return { ready: false, walletInfo, reason: "TapTray is missing the Google Pay merchant ID." };
      }
      if (!walletInfo.hasGooglePaySdk) {
        return { ready: false, walletInfo, reason: "Google Pay is not available in this browser yet." };
      }

      const totals = getCheckoutTotals(getCheckoutEntries());
      const product320 = await fetchWorldlineGooglePayConfig(toMinorAmount(totals.totalPrice));
      const paymentMethod = buildGooglePayPaymentMethod(product320);
      const client = createGooglePaymentsClient();
      const readyResponse = await client.isReadyToPay({
        apiVersion: 2,
        apiVersionMinor: 0,
        allowedPaymentMethods: [paymentMethod]
      });

      tapTrayGooglePayState.paymentsClient = client;
      tapTrayGooglePayState.paymentMethod = paymentMethod;
      tapTrayGooglePayState.product320 = product320;
      tapTrayGooglePayState.ready = !!(readyResponse && readyResponse.result);
      tapTrayGooglePayState.mode = tapTrayGooglePayState.ready ? "google_pay_direct" : "fallback";

      return {
        ready: tapTrayGooglePayState.ready,
        walletInfo,
        product320,
        walletLabel: "Google Pay"
      };
    }

    async function createHostedWalletCheckout(walletInfo, walletLabel) {
      const checkoutEndpoint = TAPTRAY_PROVIDER === "rapyd"
        ? TAPTRAY_RAPYD.createCheckoutEndpoint
        : TAPTRAY_GOOGLE_PAY.createCheckoutEndpoint;
      const response = await fetch(checkoutEndpoint, {
        method: "POST",
        headers: {
          "Content-Type": "application/json",
          "Accept": "application/json"
        },
        credentials: "same-origin",
        body: JSON.stringify(buildCheckoutPayload(walletInfo, walletLabel))
      });
      const data = await response.json().catch(() => null);
      if (!response.ok || !data || !data.ok || !data.redirect_url) {
        throw new Error(data && data.error ? data.error : "TapTray could not start hosted checkout.");
      }
      return data;
    }

    function buildGooglePayPaymentDataRequest(totals) {
      return {
        apiVersion: 2,
        apiVersionMinor: 0,
        allowedPaymentMethods: [tapTrayGooglePayState.paymentMethod],
        merchantInfo: {
          merchantId: TAPTRAY_GOOGLE_PAY.merchantId,
          merchantName: TAPTRAY_PAYMENT_CONTEXT.merchantName
        },
        transactionInfo: {
          totalPriceStatus: "FINAL",
          totalPriceLabel: TAPTRAY_PAYMENT_CONTEXT.merchantDescriptor || TAPTRAY_PAYMENT_CONTEXT.merchantName,
          totalPrice: formatDisplayAmount(totals.totalPrice),
          currencyCode: TAPTRAY_PAYMENT_CONTEXT.merchantCurrency,
          countryCode: TAPTRAY_PAYMENT_CONTEXT.merchantCountry,
          checkoutOption: "COMPLETE_IMMEDIATE_PURCHASE"
        }
      };
    }

    function showWalletDebug(walletInfo, label) {
      const debugEl = document.getElementById("checkoutDebug");
      if (!debugEl) return;
      debugEl.classList.remove("is-error");
      const summary = [
        `Primary path: ${label}`,
        `Provider: ${TAPTRAY_PROVIDER}`,
        `Merchant: ${TAPTRAY_PAYMENT_CONTEXT.merchantName}`,
        `Payment model: ${TAPTRAY_PAYMENT_CONTEXT.paymentModel}`,
        `Country / currency: ${TAPTRAY_PAYMENT_CONTEXT.merchantCountry} / ${TAPTRAY_PAYMENT_CONTEXT.merchantCurrency}`,
        `Wallet mode: ${TAPTRAY_PAYMENT_CONTEXT.walletMode}`,
        `Detected type: ${walletInfo?.type || "unknown"}`,
        `Wallet route: ${tapTrayGooglePayState.mode || "unknown"}`,
        `Apple Pay available: ${walletInfo?.hasApplePay ? "yes" : "no"}`,
        `Google Pay SDK available: ${walletInfo?.hasGooglePaySdk ? "yes" : "no"}`,
        `Payment Request available: ${walletInfo?.hasPaymentRequest ? "yes" : "no"}`,
        `Platform: ${walletInfo?.platform || "unknown"}`,
        `Language: ${walletInfo?.language || "unknown"}`
      ];
      if (TAPTRAY_PROVIDER !== "rapyd" && tapTrayGooglePayState.product320?.gateway) {
        summary.push(`Worldline gateway: ${tapTrayGooglePayState.product320.gateway}`);
      }
      if (TAPTRAY_PROVIDER === "rapyd" && tapTrayGooglePayState.product320?.gateway) {
        summary.push(`Rapyd gateway: ${tapTrayGooglePayState.product320.gateway}`);
      }
      debugEl.textContent = summary.join("\n");
    }

    function showCheckoutError(message) {
      const debugEl = document.getElementById("checkoutDebug");
      if (!debugEl) return;
      debugEl.classList.add("is-error");
      const raw = String(message || "Checkout failed.");
      const help = raw.includes("ACCESS_TO_MERCHANT_NOT_ALLOWED") || raw.includes("product 320 lookup failed")
        ? "\n\nCurrent blocker: Worldline preprod merchant access is not enabled yet. Use the payment diagnostics page while waiting for Worldline support."
        : "";
      debugEl.textContent = raw + help;
    }

    function setPrimaryButtonLoading(isLoading) {
      document.querySelectorAll("#checkoutTopPayBtn").forEach((button) => {
        button.disabled = isLoading;
        button.dataset.loading = isLoading ? "1" : "0";
      });
      document.querySelectorAll("#checkoutTopPrimaryLabel").forEach((label) => {
        if (isLoading) {
          label.dataset.originalLabel = label.textContent || "Confirm and pay";
          label.textContent = "Opening wallet…";
        } else if (label.dataset.originalLabel) {
          label.textContent = label.dataset.originalLabel;
        }
      });
    }

    function setButtonLoading(button, isLoading, loadingLabel) {
      if (!button) return;
      button.disabled = isLoading;
      if (isLoading) {
        button.dataset.originalLabel = button.textContent || "";
        button.textContent = loadingLabel;
      } else if (button.dataset.originalLabel) {
        button.textContent = button.dataset.originalLabel;
      }
    }

    function buildCheckoutPayload(walletInfo, walletLabel) {
      const entries = getCheckoutEntries();
      const totals = getCheckoutTotals(entries);
      return {
        order_reference: getTapTrayCheckoutOrderReference(),
        cart: entries,
        order_name: getCheckoutOrderName(),
        wallet: {
          requestedPath: walletLabel,
          detectedType: walletInfo?.type || "unknown",
          hasApplePay: !!walletInfo?.hasApplePay,
          hasPaymentRequest: !!walletInfo?.hasPaymentRequest,
          platform: walletInfo?.platform || "",
          language: walletInfo?.language || ""
        },
        merchant: TAPTRAY_PAYMENT_CONTEXT,
        totals
      };
    }

    function buildSuccessPreviewUrl() {
      const entries = getCheckoutEntries();
      const totals = getCheckoutTotals(entries);
      const payload = {
        reference: `preview_${Date.now()}`,
        order_name: getCheckoutOrderName(),
        owner_display_name: getCheckoutOwnerLabel(),
        currency: TAPTRAY_PAYMENT_CONTEXT.merchantCurrency,
        totals: {
          quantity: totals.totalQty,
          amount_minor: toMinorAmount(totals.totalPrice)
        },
        wallet: {
          requested_path: "Phone wallet"
        },
        items: entries
      };
      return `/taptray_payment_success.php?test=1&payload=${encodeURIComponent(JSON.stringify(payload))}`;
    }

    async function startWalletPayment() {
      const entries = getCheckoutEntries();
      if (!entries.length) {
        showCheckoutError("No items selected yet.");
        return;
      }
      const walletInfo = tapTrayGooglePayState.walletInfo || await detectPreferredWallet();
      if (!tapTrayGooglePayState.ready && tapTrayGooglePayState.mode !== "fallback" && tapTrayGooglePayState.mode !== "apple_pay_hosted_checkout") {
        showCheckoutError("No supported default wallet is ready on this phone yet.");
        return;
      }

      const walletLabel = tapTrayGooglePayState.mode === "apple_pay_hosted_checkout" ? "Apple Pay" : "Google Pay";
      showWalletDebug(walletInfo, walletLabel);
      setPrimaryButtonLoading(true);

      try {
        if (TAPTRAY_PROVIDER === "rapyd") {
          if (tapTrayGooglePayState.mode === "rapyd_google_pay_direct" && tapTrayGooglePayState.ready) {
            await startRapydGooglePayment(walletInfo, entries);
            return;
          }
          const data = await createHostedWalletCheckout(walletInfo, walletInfo.hasApplePay ? "Apple Pay" : "Phone wallet");
          window.location.href = data.redirect_url;
          return;
        }
        if (tapTrayGooglePayState.mode === "apple_pay_hosted_checkout") {
          const data = await createHostedWalletCheckout(walletInfo, "Apple Pay");
          window.location.href = data.redirect_url;
          return;
        }
        await startWorldlineGooglePayment(walletInfo, entries);
      } catch (error) {
        const statusCode = error && typeof error.statusCode === "string" ? error.statusCode : "";
        if (statusCode === "CANCELED") {
          showCheckoutError("Payment was canceled before authorization.");
        } else {
          showCheckoutError(error?.message || "TapTray could not complete wallet payment.");
        }
        setPrimaryButtonLoading(false);
      }
    }

    async function startWorldlineGooglePayment(walletInfo, entries) {
      const totals = getCheckoutTotals(entries);
      const paymentData = await tapTrayGooglePayState.paymentsClient.loadPaymentData(
        buildGooglePayPaymentDataRequest(totals)
      );

      const response = await fetch(TAPTRAY_GOOGLE_PAY.createPaymentEndpoint, {
        method: "POST",
        headers: {
          "Content-Type": "application/json",
          "Accept": "application/json"
        },
        credentials: "same-origin",
        body: JSON.stringify({
          ...buildCheckoutPayload(walletInfo, "Phone wallet"),
          googlePayPaymentData: paymentData,
          device: {
            language: navigator.language || "",
            userAgent: navigator.userAgent || "",
            platform: navigator.platform || "",
            acceptHeader: navigator.userAgent || "*/*"
          }
        })
      });

      const data = await response.json().catch(() => null);
      if (!response.ok || !data || !data.ok) {
        const message = data && data.error ? data.error : "TapTray could not complete wallet payment.";
        throw new Error(message);
      }

      const debugEl = document.getElementById("checkoutDebug");
      if (debugEl) {
        debugEl.classList.remove("is-error");
        debugEl.textContent = [
          `Primary path: Phone wallet`,
          `Provider: WORLDLINE`,
          `Merchant: ${TAPTRAY_PAYMENT_CONTEXT.merchantName}`,
          `Order reference: ${data.order_reference || "pending"}`,
          `Worldline payment: ${data.payment_id || "pending"}`,
          `Status: ${data.status_category || data.status || "received"}`,
          `Payment approved. Moving to confirmation…`
        ].join("\n");
      }

      window.location.href = data.success_url || "/taptray_payment_success.php";
    }

    async function startRapydGooglePayment(walletInfo, entries) {
      const totals = getCheckoutTotals(entries);
      const config = tapTrayGooglePayState.product320 || {};
      const paymentData = await tapTrayGooglePayState.paymentsClient.loadPaymentData({
        apiVersion: 2,
        apiVersionMinor: 0,
        allowedPaymentMethods: [tapTrayGooglePayState.paymentMethod],
        merchantInfo: {
          merchantId: String(TAPTRAY_PAYMENT_CONTEXT.googlePayMerchantId || "").trim(),
          merchantName: TAPTRAY_PAYMENT_CONTEXT.merchantName
        },
        transactionInfo: {
          totalPriceStatus: "FINAL",
          totalPriceLabel: TAPTRAY_PAYMENT_CONTEXT.merchantDescriptor || TAPTRAY_PAYMENT_CONTEXT.merchantName,
          totalPrice: formatDisplayAmount(totals.totalPrice),
          currencyCode: TAPTRAY_PAYMENT_CONTEXT.merchantCurrency,
          countryCode: TAPTRAY_PAYMENT_CONTEXT.merchantCountry,
          checkoutOption: "COMPLETE_IMMEDIATE_PURCHASE"
        }
      });

      const response = await fetch(TAPTRAY_RAPYD.createPaymentEndpoint, {
        method: "POST",
        headers: {
          "Content-Type": "application/json",
          "Accept": "application/json"
        },
        credentials: "same-origin",
        body: JSON.stringify({
          ...buildCheckoutPayload(walletInfo, "Google Pay"),
          googlePayPaymentData: paymentData,
          rapydPaymentTypeMap: config.paymentTypeMap || {}
        })
      });

      const data = await response.json().catch(() => null);
      if (!response.ok || !data || !data.ok) {
        const message = data && data.error ? data.error : "TapTray could not complete Rapyd Google Pay.";
        throw new Error(message);
      }

      const debugEl = document.getElementById("checkoutDebug");
      if (debugEl) {
        debugEl.classList.remove("is-error");
        debugEl.textContent = [
          `Primary path: Google Pay`,
          `Provider: RAPYD`,
          `Merchant: ${TAPTRAY_PAYMENT_CONTEXT.merchantName}`,
          `Order reference: ${data.order_reference || "pending"}`,
          `Rapyd payment: ${data.payment_id || "pending"}`,
          `Status: ${data.status || "received"}`,
          `Payment approved. Moving to confirmation…`
        ].join("\n");
      }

      window.location.href = data.redirect_url || data.success_url || "/taptray_payment_success.php";
    }

    async function startApplePayHostedCheckoutTest() {
      const button = document.getElementById("checkoutTestApplePayBtn");
      const entries = getCheckoutEntries();
      if (!entries.length) {
        showCheckoutError("No items selected yet.");
        return;
      }
      if (TAPTRAY_PROVIDER === "rapyd") {
        const walletInfo = tapTrayGooglePayState.walletInfo || await detectPreferredWallet();
        showWalletDebug({ ...walletInfo, type: "rapyd_hosted_checkout" }, "Rapyd hosted checkout");
        setButtonLoading(button, true, "Opening Rapyd…");
        try {
          const data = await createHostedWalletCheckout(walletInfo, walletInfo.hasApplePay ? "Apple Pay" : "Hosted wallet");
          window.location.href = data.redirect_url;
          return;
        } catch (error) {
          showCheckoutError(error?.message || "TapTray could not start Rapyd checkout.");
          setButtonLoading(button, false, "");
          return;
        }
      }

      const walletInfo = tapTrayGooglePayState.walletInfo || await detectPreferredWallet();
      showWalletDebug({ ...walletInfo, hasApplePay: true, type: "apple_pay_forced_test" }, "Apple Pay test route");
      setButtonLoading(button, true, "Opening Apple Pay test…");

      try {
        const data = await createHostedWalletCheckout(
          { ...walletInfo, hasApplePay: true, type: "apple_pay_forced_test" },
          "Apple Pay"
        );
        window.location.href = data.redirect_url;
      } catch (error) {
        showCheckoutError(error?.message || "TapTray could not start the Apple Pay hosted checkout test.");
        setButtonLoading(button, false, "");
      }
    }

    document.addEventListener("DOMContentLoaded", async () => {
      const orderNameInput = document.getElementById("checkoutOrderName");
      if (orderNameInput) {
        orderNameInput.value = loadCheckoutOrderName();
        orderNameInput.addEventListener("input", () => persistCheckoutOrderName(orderNameInput.value));
      }
      try {
        await loadTapTrayCheckoutDraftOrder();
        renderCheckout();
        const previewBtn = document.getElementById("checkoutViewSuccessBtn");
        if (previewBtn) {
          previewBtn.href = buildSuccessPreviewUrl();
        }
        const note = document.getElementById("checkoutPrimaryNote");
        const init = await initializeWalletPath();
        if (note) {
          note.textContent = init.ready
            ? `${init.walletLabel || "Phone wallet"} ready with ${TAPTRAY_PAYMENT_CONTEXT.merchantName} as merchant`
            : (init.reason || "No supported default wallet was detected on this phone.");
        }
        showWalletDebug(init.walletInfo, init.ready ? `${init.walletLabel || "Phone wallet"} ready` : "Wallet unavailable");
      } catch (error) {
        renderCheckout();
        const note = document.getElementById("checkoutPrimaryNote");
        if (note) {
          note.textContent = error?.message || "TapTray could not prepare wallet payment.";
        }
        showCheckoutError(error?.message || "TapTray could not prepare wallet payment.");
      }

      const primaryBtn = document.getElementById("checkoutTopPayBtn");
      primaryBtn?.addEventListener("click", startWalletPayment);
      document.getElementById("checkoutTestApplePayBtn")?.addEventListener("click", startApplePayHostedCheckoutTest);
    });
  </script>
</body>
</html>
