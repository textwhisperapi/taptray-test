<?php
require_once __DIR__ . '/includes/db_connect.php';
require_once __DIR__ . '/includes/functions.php';
sec_session_start();

$ttMerchantConfig = [
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
  'worldlineGatewayMerchantId' => defined('TT_WORLDLINE_GOOGLEPAY_GATEWAY_MERCHANT_ID') ? TT_WORLDLINE_GOOGLEPAY_GATEWAY_MERCHANT_ID : '',
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
      grid-template-columns: minmax(0, 1.08fr) minmax(320px, 0.92fr);
      gap: 14px;
    }
    .checkout-card {
      background: var(--surface);
      border: 1px solid var(--border);
      border-radius: var(--radius);
      box-shadow: var(--shadow);
      backdrop-filter: blur(10px);
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
      .checkout-card-head { padding: 12px 12px 6px; }
      .checkout-items { padding: 2px 10px 10px; }
      .checkout-summary { padding: 12px; }
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
        <div class="checkout-card-head">
          <div class="checkout-kicker">Your order</div>
          <h1>Review before payment</h1>
          <p class="checkout-sub">Phone wallet comes first. If the device has no default wallet path, TapTray will fall back to other payment options.</p>
        </div>
        <div id="checkoutItems" class="checkout-items"></div>
      </section>

      <aside class="checkout-card">
        <div class="checkout-summary">
          <div class="checkout-kicker">Payment</div>
          <div class="checkout-row"><span>Items</span><strong id="checkoutQty">0</strong></div>
          <div class="checkout-row"><span>Subtotal</span><strong id="checkoutSubtotal">0</strong></div>
          <div class="checkout-row checkout-total"><span>Total</span><strong id="checkoutTotal">0</strong></div>
          <div class="wallet-stack">
            <button class="wallet-btn primary" type="button" id="checkoutPayNowBtn">
              <span id="checkoutPrimaryLabel" class="checkout-primary-label">Confirm and pay</span>
            </button>
            <div id="checkoutPrimaryNote" class="checkout-primary-note">Checking for your phone wallet…</div>
          </div>
          <div class="checkout-links">
            <a href="/taptray_success_worldline.php?test=1" id="checkoutViewSuccessBtn" class="checkout-test-link">View post-purchase screen</a>
            <a href="/taptray_payment_diagnostics.php" class="checkout-test-link">Open payment diagnostics</a>
          </div>
          <div id="checkoutDebug" class="checkout-debug">Wallet detection info will appear here when you press pay.</div>
          <p class="checkout-note">TapTray keeps one clear pay step. The phone wallet authorizes, and Worldline handles processing in the background. Right now the remaining blocker is Worldline merchant access for the preprod Direct API.</p>
        </div>
      </aside>
    </div>
  </div>

  <script async src="https://pay.google.com/gp/p/js/pay.js"></script>
  <script>
    function getTapTrayCheckoutCart() {
      try {
        const parsed = JSON.parse(localStorage.getItem("taptray:cart") || "{}");
        return parsed && typeof parsed === "object" ? parsed : {};
      } catch {
        return {};
      }
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
    const TAPTRAY_GOOGLE_PAY = {
      enabled: !!TAPTRAY_PAYMENT_CONTEXT.walletEnabled,
      environment: String(TAPTRAY_PAYMENT_CONTEXT.googlePayEnvironment || "TEST").toUpperCase(),
      merchantId: String(TAPTRAY_PAYMENT_CONTEXT.googlePayMerchantId || "").trim(),
      gatewayMerchantId: String(TAPTRAY_PAYMENT_CONTEXT.worldlineGatewayMerchantId || "").trim(),
      product320Endpoint: "/taptray_get_worldline_product_320.php",
      createPaymentEndpoint: "/taptray_create_worldline_googlepay_payment.php"
    };
    const tapTrayGooglePayState = {
      paymentsClient: null,
      paymentMethod: null,
      product320: null,
      ready: false,
      walletInfo: null
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
      const qtyEl = document.getElementById("checkoutQty");
      const subtotalEl = document.getElementById("checkoutSubtotal");
      const totalEl = document.getElementById("checkoutTotal");
      const totals = getCheckoutTotals(entries);

      qtyEl.textContent = String(totals.totalQty);
      subtotalEl.textContent = String(totals.totalPrice);
      totalEl.textContent = String(totals.totalPrice);
      const primaryLabel = document.getElementById("checkoutPrimaryLabel");
      if (primaryLabel) {
        primaryLabel.textContent = `Confirm and pay ${formatDisplayAmount(totals.totalPrice)}`;
      }

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
        type: hasGooglePaySdk ? "wallet" : (hasPaymentRequest ? "wallet" : "fallback"),
        hasApplePay: false,
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

      if (!TAPTRAY_GOOGLE_PAY.enabled) {
        return { ready: false, walletInfo, reason: "Wallet-first payment is disabled in TapTray settings." };
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

      return {
        ready: tapTrayGooglePayState.ready,
        walletInfo,
        product320
      };
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
        `Merchant: ${TAPTRAY_PAYMENT_CONTEXT.merchantName}`,
        `Payment model: ${TAPTRAY_PAYMENT_CONTEXT.paymentModel}`,
        `Country / currency: ${TAPTRAY_PAYMENT_CONTEXT.merchantCountry} / ${TAPTRAY_PAYMENT_CONTEXT.merchantCurrency}`,
        `Wallet mode: ${TAPTRAY_PAYMENT_CONTEXT.walletMode}`,
        `Detected type: ${walletInfo?.type || "unknown"}`,
        `Google Pay SDK available: ${walletInfo?.hasGooglePaySdk ? "yes" : "no"}`,
        `Payment Request available: ${walletInfo?.hasPaymentRequest ? "yes" : "no"}`,
        `Platform: ${walletInfo?.platform || "unknown"}`,
        `Language: ${walletInfo?.language || "unknown"}`
      ];
      if (tapTrayGooglePayState.product320?.gateway) {
        summary.push(`Worldline gateway: ${tapTrayGooglePayState.product320.gateway}`);
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

    function setPrimaryButtonLoading(button, isLoading) {
      if (!button) return;
      button.disabled = isLoading;
      button.dataset.loading = isLoading ? "1" : "0";
      const label = document.getElementById("checkoutPrimaryLabel");
      if (!label) return;
      if (isLoading) {
        label.dataset.originalLabel = label.textContent || "Confirm and pay";
        label.textContent = "Opening wallet…";
      } else if (label.dataset.originalLabel) {
        label.textContent = label.dataset.originalLabel;
      }
    }

    function buildCheckoutPayload(walletInfo, walletLabel) {
      const entries = getCheckoutEntries();
      const totals = getCheckoutTotals(entries);
      return {
        cart: entries,
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
        merchant_name: TAPTRAY_PAYMENT_CONTEXT.merchantName,
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
      return `/taptray_success_worldline.php?test=1&payload=${encodeURIComponent(JSON.stringify(payload))}`;
    }

    async function startWalletPayment() {
      const primaryBtn = document.getElementById("checkoutPayNowBtn");
      const entries = getCheckoutEntries();
      if (!entries.length) {
        showCheckoutError("No items selected yet.");
        return;
      }
      if (!tapTrayGooglePayState.ready || !tapTrayGooglePayState.paymentsClient || !tapTrayGooglePayState.paymentMethod) {
        showCheckoutError("No supported default wallet is ready on this phone yet.");
        return;
      }

      const walletInfo = tapTrayGooglePayState.walletInfo || await detectPreferredWallet();
      showWalletDebug(walletInfo, "Phone wallet");
      setPrimaryButtonLoading(primaryBtn, true);

      try {
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
            `Merchant: ${TAPTRAY_PAYMENT_CONTEXT.merchantName}`,
            `Order reference: ${data.order_reference || "pending"}`,
            `Worldline payment: ${data.payment_id || "pending"}`,
            `Status: ${data.status_category || data.status || "received"}`,
            `Payment approved. Moving to confirmation…`
          ].join("\n");
        }

        window.location.href = data.success_url || "/taptray_success_worldline.php";
      } catch (error) {
        const statusCode = error && typeof error.statusCode === "string" ? error.statusCode : "";
        if (statusCode === "CANCELED") {
          showCheckoutError("Payment was canceled before authorization.");
        } else {
          showCheckoutError(error?.message || "TapTray could not complete wallet payment.");
        }
        setPrimaryButtonLoading(primaryBtn, false);
      }
    }

    document.addEventListener("DOMContentLoaded", async () => {
      renderCheckout();
      const note = document.getElementById("checkoutPrimaryNote");
      const primaryBtn = document.getElementById("checkoutPayNowBtn");
      const previewBtn = document.getElementById("checkoutViewSuccessBtn");

      if (previewBtn) {
        previewBtn.href = buildSuccessPreviewUrl();
      }

      try {
        const init = await initializeWalletPath();
        if (note) {
          note.textContent = init.ready
            ? `Uses your default payment wallet with ${TAPTRAY_PAYMENT_CONTEXT.merchantName} as merchant`
            : (init.reason || "No supported default wallet was detected on this phone.");
        }
        showWalletDebug(init.walletInfo, init.ready ? "Phone wallet ready" : "Wallet unavailable");
      } catch (error) {
        if (note) {
          note.textContent = error?.message || "TapTray could not prepare wallet payment.";
        }
        showCheckoutError(error?.message || "TapTray could not prepare wallet payment.");
      }

      primaryBtn?.addEventListener("click", startWalletPayment);
    });
  </script>
</body>
</html>
