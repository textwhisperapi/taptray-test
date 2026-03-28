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
    .order-head { display: flex; justify-content: space-between; gap: 12px; flex-wrap: wrap; align-items: flex-start; }
    .pill { display: inline-flex; padding: 8px 12px; border-radius: 999px; font-size: 13px; font-weight: 800; border: 1px solid transparent; }
    #ordersRoot .card .order-head > .pill.queued { background: #fff6cc !important; color: #967200 !important; border-color: #ecd98a !important; }
    #ordersRoot .card .order-head > .pill.making { background: #fff0bf !important; color: #9a6a00 !important; border-color: #ebd27b !important; }
    #ordersRoot .card .order-head > .pill.ready { background: #5b8f79 !important; color: #ffffff !important; border-color: #497664 !important; }
    #ordersRoot .card .order-head > .pill.closed { background: #eceff4 !important; color: #536172 !important; border-color: #cfd7e1 !important; }
    .items { margin-top: 12px; display: grid; gap: 8px; }
    .item { padding: 10px 12px; border-radius: 14px; background: #f8fbff; border: 1px solid #e0e6f2; }
    .item-label { min-width: 0; }
    .item-detail-link { display: inline-flex; align-items: center; justify-content: center; min-height: 40px; padding: 0 16px; border-radius: 999px; border: 1px solid #d8dfef; background: #ffffff; color: #42516b; font-size: 12px; font-weight: 700; text-decoration: none; white-space: nowrap; }
    .actions { margin-top: 14px; display: flex; gap: 10px; flex-wrap: wrap; align-items: center; justify-content: space-between; }
    .actions-main { display: flex; gap: 10px; flex-wrap: wrap; align-items: center; }
    .actions-side { display: flex; gap: 10px; flex-wrap: wrap; align-items: center; }
    button { min-height: 40px; border-radius: 999px; border: 1px solid #bcd3f5; padding: 0 16px; cursor: pointer; font-weight: 700; background: #e9f2ff; color: #1f4b8f; }
    #ordersRoot .card .actions button[data-action] { box-shadow: none !important; }
    #ordersRoot .card .actions button[data-action="making"]:not(.is-active):not(.is-muted) {
      background: #ffe7e1 !important;
      color: #a14a38 !important;
      border-color: #efb5a8 !important;
    }
    #ordersRoot .card .actions button[data-action="making"].is-active {
      background: #fff0bf !important;
      color: #9a6a00 !important;
      border-color: #ebd27b !important;
    }
    #ordersRoot .card .actions button[data-action="making"].is-muted {
      background: #f4f6fa !important;
      color: #6a7789 !important;
      border-color: #dbe2ec !important;
    }
    #ordersRoot .card .actions button[data-action="ready"].is-active { appearance: none !important; -webkit-appearance: none !important; background-image: none !important; background-color: #5b8f79 !important; color: #ffffff !important; border-color: #497664 !important; }
    #ordersRoot .card .actions button[data-action="closed"].is-active { background: #eceff4 !important; color: #536172 !important; border-color: #cfd7e1 !important; }
    .empty { color: #667389; }
    .recipe-panel-backdrop { position: fixed; inset: 0; background: rgba(17, 25, 40, .38); display: none; align-items: center; justify-content: center; padding: 20px; z-index: 1000; }
    .recipe-panel-backdrop.is-open { display: flex; }
    .recipe-panel { width: min(760px, 100%); max-height: min(80vh, 760px); overflow: hidden; background: #ffffff; border: 1px solid #d8dfef; border-radius: 18px; box-shadow: 0 18px 40px rgba(31,42,70,.14); display: flex; flex-direction: column; }
    .recipe-panel-head { display: flex; justify-content: space-between; gap: 12px; align-items: center; padding: 14px 16px; border-bottom: 1px solid #e4eaf3; }
    .recipe-panel-title { font-size: 16px; font-weight: 700; }
    .recipe-panel-subtitle { margin-top: 2px; color: #667389; font-size: 12px; }
    .recipe-panel-close { width: 32px; min-width: 32px; min-height: 32px; height: 32px; padding: 0; border-radius: 8px; background: #ffffff; color: #42516b; border: 1px solid #d8dfef; box-shadow: none; line-height: 1; display: inline-flex; align-items: center; justify-content: center; }
    .recipe-panel-close svg { width: 16px; height: 16px; stroke-width: 2.25; }
    .recipe-panel-body { padding: 16px; overflow: auto; }
    .detail-panel { display: grid; grid-template-columns: 220px minmax(0, 1fr); gap: 18px; align-items: start; }
    .detail-panel[hidden] { display: none !important; }
    .detail-panel-media { width: 100%; aspect-ratio: 1 / 1; border-radius: 14px; overflow: hidden; border: 1px solid #e0e6f2; background: #f8fbff; display: flex; align-items: center; justify-content: center; }
    .detail-panel-media img { width: 100%; height: 100%; object-fit: cover; display: block; }
    .detail-panel-media-placeholder { color: #7d889b; font-size: 12px; font-weight: 700; letter-spacing: .04em; text-transform: uppercase; }
    .detail-panel-copy { min-width: 0; display: grid; gap: 12px; }
    .detail-panel-group { display: grid; gap: 6px; }
    .detail-panel-label { font-size: 12px; font-weight: 800; color: #667389; text-transform: uppercase; letter-spacing: .05em; }
    .detail-panel-value { color: #18212f; line-height: 1.5; white-space: pre-wrap; word-break: break-word; }
    .detail-panel-meta { display: grid; gap: 4px; color: #667389; font-size: 13px; line-height: 1.45; }
    @media (max-width: 720px) {
      .detail-panel { grid-template-columns: 1fr; }
      .detail-panel-media { max-width: 240px; }
    }
  </style>
<?php
function tt_menu_orders_action_buttons(array $order): string {
    $status = (string) ($order['status'] ?? '');
    $isMaking = in_array($status, ['making', 'in_process'], true);
    $isReady = $status === 'ready';
    $isClosed = $status === 'closed';
    $isFinished = $isReady || $isClosed;
    $startLabel = $isMaking ? 'Making' : 'Start';
    $readyLabel = $isReady ? 'Ready' : 'Mark ready';
    $closeLabel = $isClosed ? 'Closed' : 'Close';
    $startClass = $isMaking ? 'making-btn is-active' : ($isFinished ? 'making-btn is-muted' : 'making-btn');
    $readyClass = $isReady ? 'ready-btn is-active' : 'ready-btn';
    $closeClass = $isClosed ? 'close-btn is-active' : 'close-btn';
    $items = is_array($order['items'] ?? null) ? $order['items'] : [];
    $firstItem = $items[0] ?? null;
    $itemSurrogate = (int) (($firstItem['surrogate'] ?? 0));
    $itemId = trim((string) ($firstItem['id'] ?? ''));
    $detailTitle = trim((string) ($firstItem['title'] ?? 'Recipe'));
    $recipeBody = (string) ($firstItem['recipe_text'] ?? '');
    $detailsBody = trim((string) ($firstItem['detailed_description'] ?? $firstItem['short_description'] ?? ''));
    $shortDescription = trim((string) ($firstItem['short_description'] ?? ''));
    $imageUrl = trim((string) ($firstItem['image_url'] ?? ''));
    return sprintf(
        '<div class="actions-main"><button class="%s" type="button" data-action="making">%s</button><button class="%s" type="button" data-action="ready">%s</button><button class="%s" type="button" data-action="closed">%s</button></div><div class="actions-side"><button class="item-detail-link" type="button" data-panel-kind="details" data-detail-surrogate="%d" data-detail-item-id="%s" data-detail-title="%s" data-detail-short="%s" data-detail-body="%s" data-detail-image="%s">Details</button><button class="item-detail-link" type="button" data-panel-kind="recipe" data-detail-surrogate="%d" data-detail-item-id="%s" data-detail-title="%s" data-detail-short="%s" data-detail-body="%s" data-detail-image="%s">Recipe</button></div>',
        htmlspecialchars($startClass, ENT_QUOTES),
        htmlspecialchars($startLabel, ENT_QUOTES),
        htmlspecialchars($readyClass, ENT_QUOTES),
        htmlspecialchars($readyLabel, ENT_QUOTES),
        htmlspecialchars($closeClass, ENT_QUOTES),
        htmlspecialchars($closeLabel, ENT_QUOTES),
        $itemSurrogate,
        htmlspecialchars($itemId, ENT_QUOTES),
        htmlspecialchars($detailTitle, ENT_QUOTES),
        htmlspecialchars($shortDescription, ENT_QUOTES),
        htmlspecialchars($detailsBody, ENT_QUOTES),
        htmlspecialchars($imageUrl, ENT_QUOTES),
        $itemSurrogate,
        htmlspecialchars($itemId, ENT_QUOTES),
        htmlspecialchars($detailTitle, ENT_QUOTES),
        htmlspecialchars($shortDescription, ENT_QUOTES),
        htmlspecialchars($recipeBody, ENT_QUOTES),
        htmlspecialchars($imageUrl, ENT_QUOTES)
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
            </div>
            <?php $statusClass = tt_menu_orders_status_label((string) ($order['status'] ?? '')); ?>
            <div class="pill <?= htmlspecialchars(strtolower(str_replace(' ', '-', $statusClass))) ?>"><?= htmlspecialchars($statusClass) ?></div>
          </div>
          <div class="items">
            <?php foreach (($order['items'] ?? []) as $item): ?>
              <div class="item">
                <div class="item-label"><?= htmlspecialchars((string) ($item['title'] ?? 'Item')) ?> × <?= (int) ($item['quantity'] ?? 0) ?></div>
              </div>
            <?php endforeach; ?>
          </div>
          <div class="actions"><?= tt_menu_orders_action_buttons($order) ?></div>
        </article>
      <?php endforeach; ?>
    </div>
  </div>
  <div class="recipe-panel-backdrop" id="recipePanelBackdrop" aria-hidden="true">
    <div class="recipe-panel" role="dialog" aria-modal="true" aria-labelledby="recipePanelTitle">
      <div class="recipe-panel-head">
        <div>
          <div class="recipe-panel-title" id="recipePanelTitle">Recipe</div>
          <div class="recipe-panel-subtitle" id="recipePanelSubtitle"></div>
        </div>
        <button class="recipe-panel-close" id="recipePanelClose" type="button" aria-label="Close panel"><i data-lucide="x"></i></button>
      </div>
      <div class="recipe-panel-body">
        <div class="detail-panel" id="detailPanel" hidden>
          <div class="detail-panel-media" id="detailPanelMedia">
            <div class="detail-panel-media-placeholder">No image</div>
          </div>
          <div class="detail-panel-copy">
            <div class="detail-panel-group">
              <div class="detail-panel-label">Short description</div>
              <div class="detail-panel-value" id="detailPanelShort">Not available.</div>
            </div>
            <div class="detail-panel-group">
              <div class="detail-panel-label" id="detailPanelBodyLabel">Detailed description</div>
              <div class="detail-panel-value" id="detailPanelBody">Not available.</div>
            </div>
            <div class="detail-panel-group" id="detailPanelMetaGroup">
              <div class="detail-panel-label">IDs</div>
              <div class="detail-panel-meta" id="detailPanelMeta"></div>
            </div>
          </div>
        </div>
      </div>
    </div>
  </div>
  <script src="/assets/lucide.min.js"></script>
  <script>
    const ordersRoot = document.getElementById("ordersRoot");
    const recipePanelBackdrop = document.getElementById("recipePanelBackdrop");
    const recipePanelClose = document.getElementById("recipePanelClose");
    const recipePanelTitle = document.getElementById("recipePanelTitle");
    const recipePanelSubtitle = document.getElementById("recipePanelSubtitle");
    const detailPanel = document.getElementById("detailPanel");
    const detailPanelMedia = document.getElementById("detailPanelMedia");
    const detailPanelMeta = document.getElementById("detailPanelMeta");
    const detailPanelMetaGroup = document.getElementById("detailPanelMetaGroup");
    const detailPanelShort = document.getElementById("detailPanelShort");
    const detailPanelBodyLabel = document.getElementById("detailPanelBodyLabel");
    const detailPanelBody = document.getElementById("detailPanelBody");

    function getOrderShortNumber(order) {
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

    function escapeHtml(value) {
      return String(value || "")
        .replace(/&/g, "&amp;")
        .replace(/</g, "&lt;")
        .replace(/>/g, "&gt;")
        .replace(/"/g, "&quot;")
        .replace(/'/g, "&#39;");
    }

    function resolveRestaurantLabel(card) {
      const parentWin = window.parent && window.parent !== window ? window.parent : null;
      return String(parentWin?.currentOwner?.display_name || "").trim();
    }

    function renderActions(status, items = []) {
      const value = String(status || "").trim();
      const isMaking = value === "making" || value === "in_process";
      const isReady = value === "ready";
      const isClosed = value === "closed";
      const isFinished = isReady || isClosed;
      const detailItem = Array.isArray(items) && items.length ? items[0] : null;
      const detailSurrogate = Number(detailItem?.surrogate || 0);
      const detailId = String(detailItem?.id || "");
      const detailTitle = String(detailItem?.title || "Recipe");
      const recipeBody = String(detailItem?.recipe_text || "");
      const detailsBody = String(detailItem?.detailed_description || detailItem?.short_description || "").trim();
      const shortDescription = String(detailItem?.short_description || "").trim();
      const imageUrl = String(detailItem?.image_url || "").trim();
      return `
        <div class="actions-main">
          <button class="${isMaking ? "making-btn is-active" : (isFinished ? "making-btn is-muted" : "making-btn")}" type="button" data-action="making">${isMaking ? "Making" : "Start"}</button>
          <button class="${isReady ? "ready-btn is-active" : "ready-btn"}" type="button" data-action="ready">${isReady ? "Ready" : "Mark ready"}</button>
          <button class="${isClosed ? "close-btn is-active" : "close-btn"}" type="button" data-action="closed">${isClosed ? "Closed" : "Close"}</button>
        </div>
        <div class="actions-side">
          <button class="item-detail-link" type="button" data-panel-kind="details" data-detail-surrogate="${detailSurrogate}" data-detail-item-id="${escapeHtml(detailId)}" data-detail-title="${escapeHtml(detailTitle)}" data-detail-short="${escapeHtml(shortDescription)}" data-detail-body="${escapeHtml(detailsBody)}" data-detail-image="${escapeHtml(imageUrl)}">Details</button>
          <button class="item-detail-link" type="button" data-panel-kind="recipe" data-detail-surrogate="${detailSurrogate}" data-detail-item-id="${escapeHtml(detailId)}" data-detail-title="${escapeHtml(detailTitle)}" data-detail-short="${escapeHtml(shortDescription)}" data-detail-body="${escapeHtml(recipeBody)}" data-detail-image="${escapeHtml(imageUrl)}">Recipe</button>
        </div>
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
            </div>
            <div class="pill ${statusClass}">${statusLabel}</div>
          </div>
          <div class="items">
            ${items.map((item) => {
              return `<div class="item"><div class="item-label">${String(item?.title || "Item")} × ${Number(item?.quantity || 0)}</div></div>`;
            }).join("")}
          </div>
          <div class="actions">${renderActions(order?.status || "", items)}</div>
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
      scope.querySelectorAll("[data-panel-kind]").forEach((button) => {
        if (button.dataset.detailBound === "1") return;
        button.dataset.detailBound = "1";
        button.addEventListener("click", async () => {
          const panelKind = String(button.dataset.panelKind || "details").trim();
          const surrogate = Number(button.dataset.detailSurrogate || 0);
          const inlineDetail = String(button.dataset.detailBody || "");
          const itemId = String(button.dataset.detailItemId || "").trim();
          const card = button.closest("[data-order-reference]");
          const orderToken = String(card?.dataset.orderReference || "").trim();
          const merchantName = resolveRestaurantLabel(card);
          const orderTitle = card?.querySelector(".order-head strong")?.textContent?.trim() || "Order";
          const itemTitle = String(button.dataset.detailTitle || "Item").trim() || "Item";
          const shortText = String(button.dataset.detailShort || "").trim();
          const imageUrl = String(button.dataset.detailImage || "").trim();
          const fallbackText = panelKind === "recipe" ? "Recipe is not available for this item." : "Details are not available for this item.";
          recipePanelTitle.textContent = itemTitle;
          recipePanelSubtitle.textContent = `${panelKind === "recipe" ? "Recipe" : "Details"} · ${orderTitle}`;
          detailPanel.hidden = false;
          if (panelKind === "details") {
            detailPanelBodyLabel.textContent = "Detailed description";
            detailPanelMetaGroup.hidden = false;
            const chips = [];
            if (orderToken) chips.push(`<div>order ${escapeHtml(orderToken)}</div>`);
            if (surrogate > 0) chips.push(`<div>surrogate ${escapeHtml(String(surrogate))}</div>`);
            if (itemId) chips.push(`<div>id ${escapeHtml(itemId)}</div>`);
            detailPanelMeta.innerHTML = chips.join("");
            if (merchantName) {
              detailPanelMeta.innerHTML += `<div>${escapeHtml(merchantName)}</div>`;
            }
            detailPanelShort.textContent = shortText || "Not available.";
            detailPanelBody.textContent = inlineDetail || "Not available.";
            detailPanelMedia.innerHTML = imageUrl
              ? `<img src="${escapeHtml(imageUrl)}" alt="${escapeHtml(itemTitle)}">`
              : `<div class="detail-panel-media-placeholder">No image</div>`;
          } else {
            detailPanelBodyLabel.textContent = "Recipe";
            detailPanelMetaGroup.hidden = true;
            detailPanelMeta.innerHTML = "";
            detailPanelShort.textContent = shortText || "Not available.";
            detailPanelBody.textContent = inlineDetail || (surrogate > 0 ? "Loading recipe..." : fallbackText);
            detailPanelMedia.innerHTML = imageUrl
              ? `<img src="${escapeHtml(imageUrl)}" alt="${escapeHtml(itemTitle)}">`
              : `<div class="detail-panel-media-placeholder">No image</div>`;
          }
          recipePanelBackdrop.classList.add("is-open");
          recipePanelBackdrop.setAttribute("aria-hidden", "false");
          if (panelKind !== "recipe" || inlineDetail !== "" || surrogate <= 0) return;
          try {
            const response = await fetch(`/getText.php?q=${encodeURIComponent(String(surrogate))}`, {
              credentials: "same-origin",
              headers: { "Accept": "text/plain" }
            });
            const recipeText = await response.text();
            const normalized = response.ok ? recipeText.replace(/\r\n/g, "\n") : "";
            const recipeBody = normalized.includes("\n")
              ? normalized.split("\n").slice(1).join("\n").replace(/^\n+/, "")
              : "";
            const nextText = recipeBody ? recipeBody : fallbackText;
            detailPanelBody.textContent = nextText;
          } catch (error) {
            detailPanelBody.textContent = "Could not load recipe.";
          }
        });
      });
    }

    function closeRecipePanel() {
      recipePanelBackdrop.classList.remove("is-open");
      recipePanelBackdrop.setAttribute("aria-hidden", "true");
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
    window.lucide?.createIcons?.();
    recipePanelClose.addEventListener("click", closeRecipePanel);
    recipePanelBackdrop.addEventListener("click", (event) => {
      if (event.target === recipePanelBackdrop) {
        closeRecipePanel();
      }
    });
    document.addEventListener("keydown", (event) => {
      if (event.key === "Escape" && recipePanelBackdrop.classList.contains("is-open")) {
        closeRecipePanel();
      }
    });
    window.setInterval(refreshOrders, 5000);
  </script>
</body>
</html>
