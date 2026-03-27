<?php
declare(strict_types=1);

require_once __DIR__ . '/includes/taptray_orders.php';

sec_session_start();
if (!login_check($mysqli) || empty($_SESSION['username'])) {
    http_response_code(403);
    echo 'Not logged in.';
    exit;
}

$orders = tt_orders_list_open($mysqli);

function tt_menu_orders_amount_display(array $order): string {
    $currency = (string) ($order['currency'] ?? 'EUR');
    $amountMinor = (int) ($order['amount_minor'] ?? 0);
    return $currency . ' ' . number_format($amountMinor / 100, 2);
}
?>
<!doctype html>
<html lang="en">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title>TapTray Orders</title>
  <style>
    body { margin: 0; font-family: system-ui, sans-serif; background: #f4f7fb; color: #18212f; }
    .shell { max-width: 1100px; margin: 0 auto; padding: 24px 16px 40px; }
    .top { display: flex; justify-content: space-between; gap: 12px; align-items: center; margin-bottom: 18px; }
    .brand { font-size: 14px; text-transform: uppercase; letter-spacing: .08em; color: #637188; font-weight: 800; }
    .card { background: #fff; border: 1px solid #d8dfef; border-radius: 22px; box-shadow: 0 18px 40px rgba(31,42,70,.08); padding: 18px; margin-bottom: 14px; }
    .order-head { display: flex; justify-content: space-between; gap: 12px; flex-wrap: wrap; }
    .pill { display: inline-flex; padding: 8px 12px; border-radius: 999px; font-size: 13px; font-weight: 800; background: #eef1ff; color: #4256d9; }
    .pill.ready { background: #e8fbf4; color: #187a59; }
    .meta { color: #667389; margin-top: 10px; line-height: 1.5; }
    .items { margin-top: 12px; display: grid; gap: 8px; }
    .item { padding: 10px 12px; border-radius: 14px; background: #f8fbff; border: 1px solid #e0e6f2; }
    .actions { margin-top: 14px; display: flex; gap: 10px; flex-wrap: wrap; }
    button { min-height: 40px; border-radius: 999px; border: 0; padding: 0 16px; cursor: pointer; font-weight: 700; }
    .ready-btn { background: #1f8a70; color: #fff; }
    .close-btn { background: #1a2230; color: #fff; }
    .empty { color: #667389; }
  </style>
</head>
<body>
  <div class="shell">
    <div class="top">
      <div>
        <div class="brand">TapTray Kitchen</div>
        <h1>Menu Orders</h1>
      </div>
      <a href="/index.php">Back to menu</a>
    </div>
    <div id="ordersRoot" data-initial-orders='<?= htmlspecialchars(json_encode($orders, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES), ENT_QUOTES) ?>'>
      <?php if (!$orders): ?>
        <div class="card empty" id="ordersEmptyState">No active TapTray orders.</div>
      <?php endif; ?>
      <?php foreach ($orders as $order): ?>
        <article class="card" data-order-reference="<?= htmlspecialchars((string) $order['order_reference']) ?>">
          <div class="order-head">
            <div>
              <strong><?= htmlspecialchars((string) $order['order_reference']) ?></strong>
              <div class="meta"><?= htmlspecialchars((string) $order['merchant_name']) ?> · <?= htmlspecialchars(tt_menu_orders_amount_display($order)) ?></div>
            </div>
            <div class="pill<?= ($order['status'] ?? '') === 'ready' ? ' ready' : '' ?>"><?= htmlspecialchars((string) $order['status']) ?></div>
          </div>
          <div class="meta">Wallet: <?= htmlspecialchars((string) $order['wallet_path']) ?> · Qty: <?= (int) ($order['total_quantity'] ?? 0) ?> · Created: <?= htmlspecialchars((string) $order['created_at']) ?></div>
          <div class="items">
            <?php foreach (($order['items'] ?? []) as $item): ?>
              <div class="item"><?= htmlspecialchars((string) ($item['title'] ?? 'Item')) ?> × <?= (int) ($item['quantity'] ?? 0) ?></div>
            <?php endforeach; ?>
          </div>
          <div class="actions">
            <?php if (($order['status'] ?? '') !== 'ready'): ?>
              <button class="ready-btn" type="button" data-action="ready">Mark ready</button>
            <?php endif; ?>
            <button class="close-btn" type="button" data-action="closed">Close</button>
          </div>
        </article>
      <?php endforeach; ?>
    </div>
  </div>
  <script>
    const ordersRoot = document.getElementById("ordersRoot");

    function formatAmount(order) {
      const currency = String(order?.currency || "EUR");
      const amount = Number(order?.amount_minor || 0) / 100;
      return `${currency} ${amount.toFixed(2)}`;
    }

    function renderOrderCard(order) {
      const items = Array.isArray(order?.items) ? order.items : [];
      const readyClass = String(order?.status || "") === "ready" ? " ready" : "";
      const readyAction = String(order?.status || "") !== "ready"
        ? `<button class="ready-btn" type="button" data-action="ready">Mark ready</button>`
        : "";
      return `
        <article class="card" data-order-reference="${String(order?.order_reference || "")}">
          <div class="order-head">
            <div>
              <strong>${String(order?.order_reference || "")}</strong>
              <div class="meta">${String(order?.merchant_name || "TapTray")} · ${formatAmount(order)}</div>
            </div>
            <div class="pill${readyClass}">${String(order?.status || "")}</div>
          </div>
          <div class="meta">Wallet: ${String(order?.wallet_path || "")} · Qty: ${Number(order?.total_quantity || 0)} · Created: ${String(order?.created_at || "")}</div>
          <div class="items">
            ${items.map((item) => `<div class="item">${String(item?.title || "Item")} × ${Number(item?.quantity || 0)}</div>`).join("")}
          </div>
          <div class="actions">
            ${readyAction}
            <button class="close-btn" type="button" data-action="closed">Close</button>
          </div>
        </article>
      `;
    }

    function bindOrderActions(scope = document) {
      scope.querySelectorAll("[data-action]").forEach((button) => {
        if (button.dataset.bound === "1") return;
        button.dataset.bound = "1";
        button.addEventListener("click", async () => {
          const card = button.closest("[data-order-reference]");
          const orderReference = card?.dataset.orderReference || "";
          if (!orderReference) return;
          button.disabled = true;
          const response = await fetch("/taptray_order_update.php", {
            method: "POST",
            headers: { "Content-Type": "application/json", "Accept": "application/json" },
            credentials: "same-origin",
            body: JSON.stringify({
              order_reference: orderReference,
              status: button.dataset.action || "ready"
            })
          });
          const data = await response.json().catch(() => null);
          if (!response.ok || !data || !data.ok) {
            button.disabled = false;
            alert(data && data.error ? data.error : "Could not update order.");
            return;
          }
          await refreshOrders();
        });
      });
    }

    async function refreshOrders() {
      const response = await fetch("/menu_orders_data.php", {
        credentials: "same-origin",
        headers: { "Accept": "application/json" }
      });
      const data = await response.json().catch(() => null);
      if (!response.ok || !data || !data.ok) {
        return;
      }
      const orders = Array.isArray(data.orders) ? data.orders : [];
      if (!orders.length) {
        ordersRoot.innerHTML = `<div class="card empty" id="ordersEmptyState">No active TapTray orders.</div>`;
        return;
      }
      ordersRoot.innerHTML = orders.map(renderOrderCard).join("");
      bindOrderActions(ordersRoot);
    }

    bindOrderActions(ordersRoot);
    window.setInterval(refreshOrders, 5000);
  </script>
</body>
</html>
