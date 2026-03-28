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

function tt_menu_orders_status_label(string $status): string {
    return match ($status) {
        'queued' => 'Queued',
        'making', 'in_process' => 'Making',
        'ready' => 'Ready',
        'closed' => 'Closed',
        default => ucfirst($status),
    };
}

function tt_menu_orders_short_number(array $order): string {
    $reference = strtolower(trim((string) ($order['order_reference'] ?? '')));
    if (preg_match('/_([a-f0-9]{4,8})$/', $reference, $m) === 1) {
        return '#' . strtoupper($m[1]);
    }
    return '#' . (string) ((int) ($order['id'] ?? 0));
}

function tt_menu_orders_display_name(array $order): string {
    $raw = trim((string) ($order['order_name'] ?? ''));
    if ($raw !== '') {
        return $raw;
    }
    $items = is_array($order['items'] ?? null) ? $order['items'] : [];
    if ($items) {
        $firstTitle = trim((string) ($items[0]['title'] ?? 'Order'));
        $extra = max(0, count($items) - 1);
        return $extra > 0 ? ($firstTitle . ' +' . $extra) : $firstTitle;
    }
    return 'Order';
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
    .pill.queued { background: #f7efd8; color: #9c6b11; }
    .pill.making { background: #fff0bf; color: #9a6a00; }
    .pill.ready { background: #e8fbf4; color: #187a59; }
    .pill.closed { background: #eceff4; color: #536172; }
    .meta { color: #667389; margin-top: 10px; line-height: 1.5; }
    .items { margin-top: 12px; display: grid; gap: 8px; }
    .item { padding: 10px 12px; border-radius: 14px; background: #f8fbff; border: 1px solid #e0e6f2; }
    .actions { margin-top: 14px; display: flex; gap: 10px; flex-wrap: wrap; }
    button { min-height: 40px; border-radius: 999px; border: 1px solid #d8dfef; padding: 0 16px; cursor: pointer; font-weight: 700; background: #f4f7fb; color: #42516b; }
    .start-btn { background: #e6ebff; color: #4d62c9; }
    .making-btn { background: #fff0bf; color: #9a6a00; }
    .ready-btn { background: #dff5e8; color: #1f8a70; }
    .close-btn { background: #eceff4; color: #536172; }
    .is-active { box-shadow: 0 0 0 4px rgba(31,42,70,.08); }
    .empty { color: #667389; }
  </style>
<?php
function tt_menu_orders_action_buttons(array $order): string {
    $status = (string) ($order['status'] ?? '');
    $isMaking = in_array($status, ['making', 'in_process'], true);
    $isReady = $status === 'ready';
    $isClosed = $status === 'closed';
    $startLabel = $isMaking ? 'Making' : 'Start';
    $readyLabel = $isReady ? 'Ready' : 'Mark ready';
    $closeLabel = $isClosed ? 'Closed' : 'Close';
    $startClass = $isMaking ? 'making-btn is-active' : 'start-btn';
    $readyClass = $isReady ? 'ready-btn is-active' : 'ready-btn';
    $closeClass = $isClosed ? 'close-btn is-active' : 'close-btn';
    return sprintf(
        '<button class="%s" type="button" data-action="making">%s</button><button class="%s" type="button" data-action="ready">%s</button><button class="%s" type="button" data-action="closed">%s</button>',
        htmlspecialchars($startClass, ENT_QUOTES),
        htmlspecialchars($startLabel, ENT_QUOTES),
        htmlspecialchars($readyClass, ENT_QUOTES),
        htmlspecialchars($readyLabel, ENT_QUOTES),
        htmlspecialchars($closeClass, ENT_QUOTES),
        htmlspecialchars($closeLabel, ENT_QUOTES)
    );
}
?>
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
              <strong><?= htmlspecialchars(tt_menu_orders_short_number($order) . ' · ' . tt_menu_orders_display_name($order)) ?></strong>
              <div class="meta"><?= htmlspecialchars(tt_menu_orders_amount_display($order)) ?></div>
            </div>
            <?php $statusClass = tt_menu_orders_status_label((string) ($order['status'] ?? '')); ?>
            <div class="pill <?= htmlspecialchars(strtolower(str_replace(' ', '-', $statusClass))) ?>"><?= htmlspecialchars($statusClass) ?></div>
          </div>
          <div class="meta">Wallet: <?= htmlspecialchars((string) $order['wallet_path']) ?> · Qty: <?= (int) ($order['total_quantity'] ?? 0) ?> · Created: <?= htmlspecialchars((string) $order['created_at']) ?></div>
          <div class="items">
            <?php foreach (($order['items'] ?? []) as $item): ?>
              <div class="item"><?= htmlspecialchars((string) ($item['title'] ?? 'Item')) ?> × <?= (int) ($item['quantity'] ?? 0) ?></div>
            <?php endforeach; ?>
          </div>
          <div class="actions"><?= tt_menu_orders_action_buttons($order) ?></div>
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

    function getOrderShortNumber(order) {
      const reference = String(order?.order_reference || "").trim().toLowerCase();
      const match = reference.match(/_([a-f0-9]{4,8})$/);
      if (match) return `#${match[1].toUpperCase()}`;
      return `#${Number(order?.id || 0)}`;
    }

    function getOrderDisplayName(order) {
      const explicitName = String(order?.order_name || "").trim();
      if (explicitName) return explicitName;
      const items = Array.isArray(order?.items) ? order.items : [];
      if (!items.length) return "Order";
      const firstTitle = String(items[0]?.title || "Order").trim() || "Order";
      const extra = Math.max(0, items.length - 1);
      return extra > 0 ? `${firstTitle} +${extra}` : firstTitle;
    }

    function getStatusLabel(status) {
      const value = String(status || "").trim();
      if (value === "queued") return "Queued";
      if (value === "making" || value === "in_process") return "Making";
      if (value === "ready") return "Ready";
      if (value === "closed") return "Closed";
      return value || "Making";
    }

    function renderActions(status) {
      const value = String(status || "").trim();
      const isMaking = value === "making" || value === "in_process";
      const isReady = value === "ready";
      const isClosed = value === "closed";
      return `
        <button class="${isMaking ? "making-btn is-active" : "start-btn"}" type="button" data-action="making">${isMaking ? "Making" : "Start"}</button>
        <button class="${isReady ? "ready-btn is-active" : "ready-btn"}" type="button" data-action="ready">${isReady ? "Ready" : "Mark ready"}</button>
        <button class="${isClosed ? "close-btn is-active" : "close-btn"}" type="button" data-action="closed">${isClosed ? "Closed" : "Close"}</button>
      `;
    }

    function renderOrderCard(order) {
      const items = Array.isArray(order?.items) ? order.items : [];
      const statusLabel = getStatusLabel(order?.status || "");
      const statusClass = statusLabel.toLowerCase().replace(/\s+/g, "-");
      return `
        <article class="card" data-order-reference="${String(order?.order_reference || "")}">
          <div class="order-head">
            <div>
              <strong>${getOrderShortNumber(order)} · ${getOrderDisplayName(order)}</strong>
              <div class="meta">${formatAmount(order)}</div>
            </div>
            <div class="pill ${statusClass}">${statusLabel}</div>
          </div>
          <div class="meta">Wallet: ${String(order?.wallet_path || "")} · Qty: ${Number(order?.total_quantity || 0)} · Created: ${String(order?.created_at || "")}</div>
          <div class="items">
            ${items.map((item) => `<div class="item">${String(item?.title || "Item")} × ${Number(item?.quantity || 0)}</div>`).join("")}
          </div>
          <div class="actions">${renderActions(order?.status || "")}</div>
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
