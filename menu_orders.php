<?php
declare(strict_types=1);

require_once __DIR__ . '/includes/taptray_orders.php';

sec_session_start();
if (!login_check($mysqli) || empty($_SESSION['username'])) {
    http_response_code(403);
    echo 'Not logged in.';
    exit;
}

$sessionUsername = trim((string) $_SESSION['username']);
$requestedOwner = trim((string) ($_GET['owner'] ?? $sessionUsername));
$orders = [];
$requestedOwnerLabel = '';

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
  <title>TapTray Orders · <?= htmlspecialchars($requestedOwnerLabel, ENT_QUOTES) ?></title>
  <style>
    :root {
      --bg: #eef2f7;
      --panel: #ffffff;
      --panel-border: #d8dfef;
      --ink: #18212f;
      --muted: #637188;
      --soft: #f6f9fd;
      --shadow: 0 18px 40px rgba(31,42,70,.08);
    }
    body { margin: 0; font-family: system-ui, sans-serif; background: linear-gradient(180deg, #f4f7fb 0%, #edf2f8 100%); color: var(--ink); }
    .shell { max-width: 1320px; margin: 0 auto; padding: 24px 16px 40px; }
    .top { display: flex; justify-content: space-between; gap: 12px; align-items: center; margin-bottom: 18px; }
    .top-actions { display: flex; gap: 10px; flex-wrap: wrap; align-items: center; }
    .brand { font-size: 14px; text-transform: uppercase; letter-spacing: .08em; color: #637188; font-weight: 800; }
    .orders-grid { display: grid; grid-template-columns: 1fr; gap: 16px; align-items: start; }
    .card { background: var(--panel); border: 1px solid var(--panel-border); border-radius: 24px; box-shadow: var(--shadow); padding: 14px; margin-bottom: 0; display: grid; gap: 10px; align-self: start; }
    .section-heading { display: flex; align-items: center; justify-content: space-between; gap: 10px; margin: 10px 0 12px; color: #4d5b6f; font-size: 13px; font-weight: 800; letter-spacing: .04em; text-transform: uppercase; }
    .order-head { display: grid; grid-template-columns: minmax(0, 1fr) auto; gap: 12px; align-items: center; }
    .order-title { display: flex; gap: 10px; align-items: baseline; min-width: 0; flex-wrap: wrap; }
    .order-title strong { font-size: 20px; line-height: 1.15; display: block; }
    .order-number { color: var(--ink); font-size: 18px; line-height: 1.15; font-weight: 800; }
    .pill { display: inline-flex; padding: 8px 12px; border-radius: 999px; font-size: 13px; font-weight: 800; border: 1px solid transparent; }
    #ordersRoot .card .order-head > .pill.queued { background: #fff6cc !important; color: #967200 !important; border-color: #ecd98a !important; }
    #ordersRoot .card .order-head > .pill.making { background: #fff0bf !important; color: #9a6a00 !important; border-color: #ebd27b !important; }
    #ordersRoot .card .order-head > .pill.ready { background: #5b8f79 !important; color: #ffffff !important; border-color: #497664 !important; }
    #ordersRoot .card .order-head > .pill.closed { background: #eceff4 !important; color: #536172 !important; border-color: #cfd7e1 !important; }
    .items { margin-top: 12px; display: grid; gap: 8px; }
    .item { padding: 10px 12px; border-radius: 14px; background: #f8fbff; border: 1px solid #e0e6f2; }
    .item-label { min-width: 0; }
    .item-detail-link { display: inline-flex; align-items: center; justify-content: center; min-height: 40px; padding: 0 16px; border-radius: 999px; border: 1px solid #d8dfef; background: #ffffff; color: #42516b; font-size: 12px; font-weight: 700; text-decoration: none; white-space: nowrap; }
    .order-body { display: grid; grid-template-columns: 88px minmax(0, 1fr); gap: 12px; align-items: start; }
    .actions-rail { display: grid; gap: 8px; align-content: start; justify-items: stretch; padding-top: 6px; }
    .item-area { display: grid; gap: 6px; min-width: 0; }
    .inline-tools { display: flex; gap: 8px; flex-wrap: wrap; align-items: center; justify-content: flex-end; align-self: center; }
    button { min-height: 34px; border-radius: 999px; border: 1px solid #bcd3f5; padding: 0 11px; cursor: pointer; font-weight: 700; background: #e9f2ff; color: #1f4b8f; display: inline-flex; align-items: center; justify-content: center; line-height: 1; box-sizing: border-box; }
    .actions-rail button[data-action] { width: 100%; min-width: 0; justify-content: center; text-align: center; }
    .actions-rail button[data-action] { min-height: 31px; padding-left: 7px; padding-right: 7px; }
    .actions-rail .item-detail-link,
    .inline-tools .item-detail-link { min-height: 30px; padding: 0 12px; font-size: 12px; border-color: #d7deeb; background: #ffffff; color: #42516b; }
    #ordersRoot .card button[data-action] { box-shadow: none !important; }
    #ordersRoot .card button[data-action="making"]:not(.is-active):not(.is-muted) {
      background: #ffe7e1 !important;
      color: #a14a38 !important;
      border-color: #efb5a8 !important;
    }
    #ordersRoot .card button[data-action="making"].is-active {
      background: #fff0bf !important;
      color: #9a6a00 !important;
      border-color: #ebd27b !important;
    }
    #ordersRoot .card button[data-action="making"].is-muted {
      background: #f4f6fa !important;
      color: #6a7789 !important;
      border-color: #dbe2ec !important;
    }
    #ordersRoot .card button[data-action="ready"].is-active { appearance: none !important; -webkit-appearance: none !important; background-image: none !important; background-color: #5b8f79 !important; color: #ffffff !important; border-color: #497664 !important; }
    #ordersRoot .card button[data-action="closed"].is-active { background: #eceff4 !important; color: #536172 !important; border-color: #cfd7e1 !important; }
    .empty { color: #667389; }
    .order-items { display: grid; grid-template-columns: repeat(auto-fit, minmax(240px, 1fr)); gap: 6px; }
    .item-line { display: grid; grid-template-columns: 42px minmax(0, 1fr) auto; gap: 8px; align-items: center; padding: 6px 8px; border-radius: 12px; background: #f8fbff; border: 1px solid #e1e8f2; }
    .item-thumb { width: 42px; height: 42px; border-radius: 10px; overflow: hidden; border: 1px solid #d7dfec; background: #ffffff; display: flex; align-items: center; justify-content: center; }
    .item-thumb img { width: 100%; height: 100%; object-fit: cover; display: block; }
    .item-thumb-placeholder { color: #8a95a8; font-size: 11px; font-weight: 800; text-transform: uppercase; letter-spacing: .05em; }
    .item-copy { min-width: 0; display: grid; gap: 2px; align-content: start; }
    .item-name { font-size: 14px; font-weight: 800; line-height: 1.1; white-space: nowrap; overflow: hidden; text-overflow: ellipsis; }
    .item-notes { color: var(--muted); font-size: 12px; line-height: 1.2; white-space: nowrap; overflow: hidden; text-overflow: ellipsis; }
    .item-qty { min-width: 40px; text-align: center; padding: 6px 8px; border-radius: 999px; background: #ffffff; border: 1px solid #d5deea; font-size: 14px; font-weight: 800; color: #314056; align-self: center; }
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
      .orders-grid { grid-template-columns: 1fr; }
      .order-head { grid-template-columns: 1fr; }
      .order-title { gap: 8px; }
      .order-body { grid-template-columns: 1fr; }
      .actions-rail { grid-template-columns: repeat(3, minmax(0, 1fr)); }
      .order-items { grid-template-columns: 1fr; }
      .item-line { grid-template-columns: 42px minmax(0, 1fr) auto; }
      .item-qty { justify-self: start; }
      .item-thumb { width: 42px; height: 42px; border-radius: 10px; }
      .inline-tools { justify-content: flex-start; }
      .detail-panel { grid-template-columns: 1fr; }
      .detail-panel-media { max-width: 240px; }
    }
  </style>
<?php
function tt_menu_orders_state_buttons(array $order): string {
    $status = (string) ($order['status'] ?? '');
    $isMaking = in_array($status, ['making', 'in_process'], true);
    $isReady = $status === 'ready';
    $isClosed = $status === 'closed';
    $isFinished = $isReady || $isClosed;
    $startLabel = $isMaking ? 'Making' : 'Start';
    $readyLabel = 'Ready';
    $closeLabel = $isClosed ? 'Closed' : 'Close';
    $startClass = $isMaking ? 'making-btn is-active' : ($isFinished ? 'making-btn is-muted' : 'making-btn');
    $readyClass = $isReady ? 'ready-btn is-active' : 'ready-btn';
    $closeClass = $isClosed ? 'close-btn is-active' : 'close-btn';
    $items = is_array($order['items'] ?? null) ? $order['items'] : [];
    $firstItem = $items[0] ?? null;
    return sprintf(
        '<div class="actions-rail"><button class="%s" type="button" data-action="making">%s</button><button class="%s" type="button" data-action="ready">%s</button><button class="%s" type="button" data-action="closed">%s</button></div>',
        htmlspecialchars($startClass, ENT_QUOTES),
        htmlspecialchars($startLabel, ENT_QUOTES),
        htmlspecialchars($readyClass, ENT_QUOTES),
        htmlspecialchars($readyLabel, ENT_QUOTES),
        htmlspecialchars($closeClass, ENT_QUOTES),
        htmlspecialchars($closeLabel, ENT_QUOTES)
    );
}

function tt_menu_orders_inline_tools(array $order): string {
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
        '<div class="inline-tools"><button class="item-detail-link" type="button" data-panel-kind="details" data-detail-surrogate="%d" data-detail-item-id="%s" data-detail-title="%s" data-detail-short="%s" data-detail-body="%s" data-detail-image="%s">Details</button><button class="item-detail-link" type="button" data-panel-kind="recipe" data-detail-surrogate="%d" data-detail-item-id="%s" data-detail-title="%s" data-detail-short="%s" data-detail-body="%s" data-detail-image="%s">Recipe</button></div>',
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

function tt_menu_orders_item_rows(array $order): string {
    $items = is_array($order['items'] ?? null) ? $order['items'] : [];
    if (!$items) {
        return '<div class="item-line"><div class="item-copy"><div class="item-name">No items</div></div></div>';
    }

    $rows = [];
    foreach ($items as $item) {
        $title = trim((string) ($item['title'] ?? 'Item')) ?: 'Item';
        $qty = max(0, (int) ($item['quantity'] ?? 0));
        $imageUrl = trim((string) ($item['image_url'] ?? ''));
        $short = trim((string) ($item['short_description'] ?? ''));
        $details = trim((string) ($item['detailed_description'] ?? ''));
        $notes = $short !== '' ? $short : $details;
        $thumb = $imageUrl !== ''
            ? '<img src="' . htmlspecialchars($imageUrl, ENT_QUOTES) . '" alt="' . htmlspecialchars($title, ENT_QUOTES) . '">'
            : '<div class="item-thumb-placeholder">No image</div>';
        $rows[] = sprintf(
            '<div class="item-line"><div class="item-thumb">%s</div><div class="item-copy"><div class="item-name">%s</div>%s</div><div class="item-qty">× %d</div></div>',
            $thumb,
            htmlspecialchars($title, ENT_QUOTES),
            $notes !== '' ? '<div class="item-notes">' . htmlspecialchars($notes, ENT_QUOTES) . '</div>' : '',
            $qty
        );
    }

    return implode('', $rows);
}
?>
</head>
<body>
  <div class="shell">
    <div class="top">
      <div>
        <div class="brand">TapTray Kitchen</div>
        <div id="kitchenOwnerLabel" class="brand" style="margin-top:4px; letter-spacing:.03em; text-transform:none; font-size:16px;"><?= htmlspecialchars($requestedOwnerLabel, ENT_QUOTES) ?></div>
        <h1>Menu Orders</h1>
      </div>
      <div class="top-actions">
        <button type="button" id="togglePastOrders">Show past orders</button>
      </div>
    </div>
    <div id="ordersRoot" class="orders-grid" data-initial-orders='<?= htmlspecialchars(json_encode($orders, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES), ENT_QUOTES) ?>'>
      <?php if (!$orders): ?>
        <div class="card empty" id="ordersEmptyState">No active TapTray orders.</div>
      <?php endif; ?>
      <?php foreach ($orders as $order): ?>
        <article class="card" data-order-reference="<?= htmlspecialchars((string) $order['order_reference']) ?>">
          <div class="order-body">
            <?= tt_menu_orders_state_buttons($order) ?>
            <div class="item-area">
              <div class="order-head">
                <div class="order-title">
                  <span class="order-number"><?= htmlspecialchars(tt_menu_orders_short_number($order)) ?></span>
                  <strong><?= htmlspecialchars(tt_menu_orders_display_name($order)) ?></strong>
                </div>
                <?= tt_menu_orders_inline_tools($order) ?>
              </div>
              <div class="order-items">
                <?= tt_menu_orders_item_rows($order) ?>
              </div>
            </div>
          </div>
        </article>
      <?php endforeach; ?>
    </div>
    <div id="pastOrdersSection" hidden>
      <div class="section-heading">
        <span>Past orders</span>
      </div>
      <div id="pastOrdersRoot"></div>
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
    const pastOrdersSection = document.getElementById("pastOrdersSection");
    const pastOrdersRoot = document.getElementById("pastOrdersRoot");
    const togglePastOrdersButton = document.getElementById("togglePastOrders");
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
    let showPastOrders = false;

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

    function getParentSelectedProfileUsername() {
      const parentWin = window.parent && window.parent !== window ? window.parent : null;
      return String(parentWin?.currentProfileUsername || "").trim();
    }

    function getParentSelectedProfileLabel() {
      const parentWin = window.parent && window.parent !== window ? window.parent : null;
      const labelNode = parentWin?.document?.getElementById("homeCurrentProfileLabel");
      const fromHeader = String(labelNode?.textContent || "").trim();
      if (fromHeader) return fromHeader;
      return getParentSelectedProfileUsername();
    }

    function resolveRestaurantLabel(card) {
      return getParentSelectedProfileLabel();
    }

    function resolveOwnerDisplayName() {
      return getParentSelectedProfileLabel();
    }

    function resolveOwnerUsername() {
      return getParentSelectedProfileUsername();
    }

    function renderStateButtons(status) {
      const value = String(status || "").trim();
      const isMaking = value === "making" || value === "in_process";
      const isReady = value === "ready";
      const isClosed = value === "closed";
      const isFinished = isReady || isClosed;
      return `
        <div class="actions-rail">
          <button class="${isMaking ? "making-btn is-active" : (isFinished ? "making-btn is-muted" : "making-btn")}" type="button" data-action="making">${isMaking ? "Making" : "Start"}</button>
          <button class="${isReady ? "ready-btn is-active" : "ready-btn"}" type="button" data-action="ready">Ready</button>
          <button class="${isClosed ? "close-btn is-active" : "close-btn"}" type="button" data-action="closed">${isClosed ? "Closed" : "Close"}</button>
        </div>
      `;
    }

    function renderInlineTools(items = []) {
      const detailItem = Array.isArray(items) && items.length ? items[0] : null;
      const detailSurrogate = Number(detailItem?.surrogate || 0);
      const detailId = String(detailItem?.id || "");
      const detailTitle = String(detailItem?.title || "Recipe");
      const recipeBody = String(detailItem?.recipe_text || "");
      const detailsBody = String(detailItem?.detailed_description || detailItem?.short_description || "").trim();
      const shortDescription = String(detailItem?.short_description || "").trim();
      const imageUrl = String(detailItem?.image_url || "").trim();
      return `
        <div class="inline-tools">
          <button class="item-detail-link" type="button" data-panel-kind="details" data-detail-surrogate="${detailSurrogate}" data-detail-item-id="${escapeHtml(detailId)}" data-detail-title="${escapeHtml(detailTitle)}" data-detail-short="${escapeHtml(shortDescription)}" data-detail-body="${escapeHtml(detailsBody)}" data-detail-image="${escapeHtml(imageUrl)}">Details</button>
          <button class="item-detail-link" type="button" data-panel-kind="recipe" data-detail-surrogate="${detailSurrogate}" data-detail-item-id="${escapeHtml(detailId)}" data-detail-title="${escapeHtml(detailTitle)}" data-detail-short="${escapeHtml(shortDescription)}" data-detail-body="${escapeHtml(recipeBody)}" data-detail-image="${escapeHtml(imageUrl)}">Recipe</button>
        </div>
      `;
    }

    function renderOrderCard(order) {
      const items = Array.isArray(order?.items) ? order.items : [];
      const orderReference = String(order?.order_reference || "");
      const orderSignature = createOrderSignature(order);
      return `
        <article class="card" data-order-reference="${escapeHtml(orderReference)}" data-order-signature="${escapeHtml(orderSignature)}">
          <div class="order-body">
            ${renderStateButtons(order?.status || "")}
            <div class="item-area">
              <div class="order-head">
                <div class="order-title">
                  <span class="order-number">${escapeHtml(getOrderShortNumber(order))}</span>
                  <strong>${escapeHtml(getOrderDisplayName(order))}</strong>
                </div>
                ${renderInlineTools(items)}
              </div>
              <div class="order-items">
            ${items.length ? items.map((item) => {
              const title = String(item?.title || "Item");
              const quantity = Number(item?.quantity || 0);
              const imageUrl = String(item?.image_url || "").trim();
              const shortText = String(item?.short_description || "").trim();
              const detailText = String(item?.detailed_description || "").trim();
              const notes = shortText || detailText;
              return `
                <div class="item-line">
                  <div class="item-thumb">
                    ${imageUrl ? `<img src="${escapeHtml(imageUrl)}" alt="${escapeHtml(title)}">` : `<div class="item-thumb-placeholder">No image</div>`}
                  </div>
                  <div class="item-copy">
                    <div class="item-name">${escapeHtml(title)}</div>
                    ${notes ? `<div class="item-notes">${escapeHtml(notes)}</div>` : ""}
                  </div>
                  <div class="item-qty">× ${quantity}</div>
                </div>
              `;
            }).join("") : `<div class="item-line"><div class="item-copy"><div class="item-name">No items</div></div></div>`}
              </div>
            </div>
          </div>
        </article>
      `;
    }

    function createOrderSignature(order) {
      const items = Array.isArray(order?.items) ? order.items.map((item) => ({
        title: String(item?.title || ""),
        quantity: Number(item?.quantity || 0),
        image_url: String(item?.image_url || ""),
        short_description: String(item?.short_description || ""),
        detailed_description: String(item?.detailed_description || "")
      })) : [];
      return JSON.stringify({
        order_reference: String(order?.order_reference || ""),
        order_name: String(order?.order_name || ""),
        customer_username: String(order?.customer_username || ""),
        total_quantity: Number(order?.total_quantity || 0),
        status: String(order?.status || ""),
        items
      });
    }

    function updateOrdersRoot(root, orders, emptyMessage) {
      const nextOrders = Array.isArray(orders) ? orders : [];
      if (!nextOrders.length) {
        root.innerHTML = `<div class="card empty">${emptyMessage}</div>`;
        return;
      }

      const emptyCards = Array.from(root.querySelectorAll(".card.empty"));
      emptyCards.forEach((card) => card.remove());

      const existingCards = new Map(
        Array.from(root.querySelectorAll("[data-order-reference]")).map((card) => [card.dataset.orderReference || "", card])
      );
      const nextRefs = new Set();

      nextOrders.forEach((order, index) => {
        const orderReference = String(order?.order_reference || "");
        if (!orderReference) {
          return;
        }
        nextRefs.add(orderReference);
        const nextSignature = createOrderSignature(order);
        const existingCard = existingCards.get(orderReference) || null;

        if (!existingCard) {
          const wrapper = document.createElement("div");
          wrapper.innerHTML = renderOrderCard(order).trim();
          const nextCard = wrapper.firstElementChild;
          const currentChild = root.children[index] || null;
          root.insertBefore(nextCard, currentChild);
          return;
        }

        if (existingCard.dataset.orderSignature !== nextSignature) {
          const wrapper = document.createElement("div");
          wrapper.innerHTML = renderOrderCard(order).trim();
          const nextCard = wrapper.firstElementChild;
          existingCard.replaceWith(nextCard);
          return;
        }

        const currentChild = root.children[index] || null;
        if (currentChild !== existingCard) {
          root.insertBefore(existingCard, currentChild);
        }
      });

      Array.from(root.querySelectorAll("[data-order-reference]")).forEach((card) => {
        const orderReference = card.dataset.orderReference || "";
        if (!nextRefs.has(orderReference)) {
          card.remove();
        }
      });

      if (!root.querySelector("[data-order-reference]")) {
        root.innerHTML = `<div class="card empty">${emptyMessage}</div>`;
      }
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
      const ownerUsername = resolveOwnerUsername();
      const response = await fetch(`/menu_orders_data.php`, {
        method: "POST",
        credentials: "same-origin",
        headers: { "Accept": "application/json", "Content-Type": "application/json" },
        body: JSON.stringify({
          owner: ownerUsername,
          include_past: showPastOrders,
          past_limit: 8
        })
      });
      const data = await response.json().catch(() => null);
      if (!response.ok || !data || !data.ok) {
        return;
      }
      const orders = Array.isArray(data.orders) ? data.orders : [];
      const pastOrders = Array.isArray(data.past_orders) ? data.past_orders : [];
      updateOrdersRoot(ordersRoot, orders, "No active TapTray orders.");
      bindOrderActions(ordersRoot);
      if (showPastOrders) {
        pastOrdersSection.hidden = false;
        updateOrdersRoot(pastOrdersRoot, pastOrders, "No past TapTray orders.");
        bindOrderActions(pastOrdersRoot);
      } else {
        pastOrdersSection.hidden = true;
        pastOrdersRoot.innerHTML = "";
      }
    }

    bindOrderActions(ordersRoot);
    const kitchenOwnerLabel = document.getElementById("kitchenOwnerLabel");
    if (kitchenOwnerLabel) {
      const ownerLabel = resolveOwnerDisplayName();
      if (ownerLabel) {
        kitchenOwnerLabel.textContent = ownerLabel;
        document.title = `TapTray Orders · ${ownerLabel}`;
      }
    }
    togglePastOrdersButton.addEventListener("click", async () => {
      showPastOrders = !showPastOrders;
      togglePastOrdersButton.textContent = showPastOrders ? "Hide past orders" : "Show past orders";
      await refreshOrders();
    });
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
    refreshOrders();
    window.setInterval(refreshOrders, 5000);
  </script>
</body>
</html>
