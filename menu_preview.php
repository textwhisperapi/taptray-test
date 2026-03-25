<?php
require_once __DIR__ . '/includes/db_connect.php';
require_once __DIR__ . '/includes/functions.php';

sec_session_start();

header('Content-Type: text/html; charset=utf-8');

$token = trim((string)($_GET['token'] ?? ''));
$ownerToken = trim((string)($_GET['owner'] ?? ''));
$surrogate = (int)($_GET['surrogate'] ?? 0);
$username = $_SESSION['username'] ?? '';

if (!login_check($mysqli)) {
    http_response_code(403);
    echo '<!DOCTYPE html><html><body style="font-family:sans-serif;padding:24px;">Log in to view the menu preview.</body></html>';
    exit;
}

function tt_can_access_list(mysqli $mysqli, string $listToken, string $username): bool {
    $stmt = $mysqli->prepare("
        SELECT cl.owner_id, cl.access_level, m.username
        FROM content_lists cl
        JOIN members m ON m.id = cl.owner_id
        WHERE cl.token = ?
        LIMIT 1
    ");
    $stmt->bind_param('s', $listToken);
    $stmt->execute();
    $stmt->bind_result($ownerId, $accessLevel, $ownerUsername);
    $found = $stmt->fetch();
    $stmt->close();

    if (!$found) {
        return false;
    }

    if ($accessLevel === 'public') {
        return true;
    }

    if ($username !== '' && $ownerUsername === $username) {
        return true;
    }

    return get_user_list_role_rank($mysqli, $ownerUsername, $username) > 0;
}

function tt_fetch_item_rows(mysqli $mysqli, int $listId): array {
    $stmt = $mysqli->prepare("
        SELECT
            t.Surrogate AS surrogate,
            t.dataname AS title,
            COALESCE(NULLIF(TRIM(t.Text), ''), '') AS body,
            COALESCE(NULLIF(TRIM(s.public_description), ''), '') AS public_description,
            COALESCE(NULLIF(TRIM(s.price_label), ''), '') AS price_label,
            COALESCE(NULLIF(TRIM(s.image_url), ''), '') AS image_url,
            COALESCE(NULLIF(TRIM(s.allergens), ''), '') AS allergens,
            COALESCE(s.is_available, 1) AS is_available
        FROM content_list_items cli
        JOIN text t ON t.Surrogate = cli.surrogate
        LEFT JOIN item_settings s ON s.surrogate = t.Surrogate
        WHERE cli.content_list_id = ?
          AND (t.deleted IS NULL OR t.deleted != 'D')
        ORDER BY cli.sort_order ASC, t.dataname ASC
    ");
    $stmt->bind_param('i', $listId);
    $stmt->execute();
    $res = $stmt->get_result();
    $items = [];
    while ($row = $res->fetch_assoc()) {
        $body = trim((string)$row['body']);
        $price = trim((string)($row['price_label'] ?? ''));
        $description = trim((string)($row['public_description'] ?? ''));
        $imageUrl = trim((string)($row['image_url'] ?? ''));
        $allergens = trim((string)($row['allergens'] ?? ''));
        $detailLines = [];

        if ($body !== '') {
            $lines = preg_split('/\R+/', $body);
            foreach ($lines as $line) {
                $line = trim((string)$line);
                if ($line === '') {
                    continue;
                }
                if ($imageUrl === '' && preg_match('~https?://\S+\.(?:png|jpe?g|webp|gif)(?:\?\S*)?$~i', $line, $m)) {
                    $imageUrl = $m[0];
                    continue;
                }
                if ($price === '' && preg_match('/(?:^|[^0-9])(\d{1,4}(?:[.,]\d{2})?)\s?(?:kr|isk|eur|\$|€)\b/i', $line, $m)) {
                    $price = $m[1];
                }
                if ($description === '') {
                    $description = $line;
                } else {
                    $detailLines[] = $line;
                }
            }
        }

        $items[] = [
            'surrogate' => (int)$row['surrogate'],
            'title' => (string)$row['title'],
            'description' => $description,
            'price' => $price,
            'image_url' => $imageUrl,
            'allergens' => $allergens,
            'is_available' => (int)$row['is_available'],
            'details' => $detailLines,
        ];
    }
    $stmt->close();

    return $items;
}

function tt_fetch_single_item(mysqli $mysqli, int $surrogate): ?array {
    $stmt = $mysqli->prepare("
        SELECT
            t.Surrogate AS surrogate,
            t.dataname AS title,
            COALESCE(NULLIF(TRIM(t.Text), ''), '') AS body,
            COALESCE(NULLIF(TRIM(s.public_description), ''), '') AS public_description,
            COALESCE(NULLIF(TRIM(s.price_label), ''), '') AS price_label,
            COALESCE(NULLIF(TRIM(s.image_url), ''), '') AS image_url,
            COALESCE(NULLIF(TRIM(s.allergens), ''), '') AS allergens,
            COALESCE(s.is_available, 1) AS is_available
        FROM text t
        LEFT JOIN item_settings s ON s.surrogate = t.Surrogate
        WHERE t.Surrogate = ?
          AND (t.deleted IS NULL OR t.deleted != 'D')
        LIMIT 1
    ");
    $stmt->bind_param('i', $surrogate);
    $stmt->execute();
    $res = $stmt->get_result();
    $row = $res->fetch_assoc();
    $stmt->close();

    if (!$row) {
        return null;
    }

    $body = trim((string)$row['body']);
    $price = trim((string)($row['price_label'] ?? ''));
    $description = trim((string)($row['public_description'] ?? ''));
    $imageUrl = trim((string)($row['image_url'] ?? ''));
    $allergens = trim((string)($row['allergens'] ?? ''));
    $detailLines = [];
    if ($body !== '') {
        $lines = preg_split('/\R+/', $body);
        foreach ($lines as $line) {
            $line = trim((string)$line);
            if ($line === '') {
                continue;
            }
            if ($imageUrl === '' && preg_match('~https?://\S+\.(?:png|jpe?g|webp|gif)(?:\?\S*)?$~i', $line, $m)) {
                $imageUrl = $m[0];
                continue;
            }
            if ($price === '' && preg_match('/(?:^|[^0-9])(\d{1,4}(?:[.,]\d{2})?)\s?(?:kr|isk|eur|\$|€)\b/i', $line, $m)) {
                $price = $m[1];
            }
            if ($description === '') {
                $description = $line;
            } else {
                $detailLines[] = $line;
            }
        }
    }

    return [
        'surrogate' => (int)$row['surrogate'],
        'title' => (string)$row['title'],
        'description' => $description,
        'price' => $price,
        'image_url' => $imageUrl,
        'allergens' => $allergens,
        'is_available' => (int)$row['is_available'],
        'details' => $detailLines,
    ];
}

function tt_fetch_child_sections(mysqli $mysqli, int $parentId): array {
    $stmt = $mysqli->prepare("
        SELECT id, token, name
        FROM content_lists
        WHERE parent_id = ?
        ORDER BY order_index ASC, created_at ASC
    ");
    $stmt->bind_param('i', $parentId);
    $stmt->execute();
    $res = $stmt->get_result();
    $sections = [];
    while ($row = $res->fetch_assoc()) {
        $sections[] = [
            'id' => (int)$row['id'],
            'token' => (string)$row['token'],
            'name' => (string)$row['name'],
        ];
    }
    $stmt->close();
    return $sections;
}

$pageTitle = 'TapTray Menu Preview';
$heroTitle = 'Menu Preview';
$heroSubtitle = 'Customer-facing mobile preview generated from the current management data.';
$menus = [];
$focusedItem = null;

if ($surrogate > 0) {
    $focusedItem = tt_fetch_single_item($mysqli, $surrogate);
    if ($focusedItem) {
        $heroTitle = $focusedItem['title'];
        $heroSubtitle = 'Preview of the currently selected menu item.';
    }
}

if (!$focusedItem && $token !== '') {
    $stmt = $mysqli->prepare("
        SELECT cl.id, cl.name, cl.token, m.display_name, m.username
        FROM content_lists cl
        JOIN members m ON m.id = cl.owner_id
        WHERE cl.token = ?
        LIMIT 1
    ");
    $stmt->bind_param('s', $token);
    $stmt->execute();
    $res = $stmt->get_result();
    $menuRow = $res->fetch_assoc();
    $stmt->close();

    if ($menuRow && tt_can_access_list($mysqli, $token, $username)) {
        $menuId = (int)$menuRow['id'];
        $sections = [];
        $directItems = tt_fetch_item_rows($mysqli, $menuId);
        if ($directItems) {
            $sections[] = [
                'name' => 'Featured',
                'items' => $directItems,
            ];
        }
        foreach (tt_fetch_child_sections($mysqli, $menuId) as $sectionRow) {
            $sectionItems = tt_fetch_item_rows($mysqli, (int)$sectionRow['id']);
            if (!$sectionItems) {
                continue;
            }
            $sections[] = [
                'name' => $sectionRow['name'],
                'items' => $sectionItems,
            ];
        }

        $menus[] = [
            'name' => $menuRow['name'],
            'owner' => $menuRow['display_name'] ?: $menuRow['username'],
            'sections' => $sections,
        ];
        $heroTitle = $menuRow['name'];
        $heroSubtitle = 'Preview of the selected menu rendered for phone-sized guest browsing.';
    }
}

if (!$focusedItem && !$menus && $ownerToken !== '') {
    $stmt = $mysqli->prepare("
        SELECT id, display_name, username
        FROM members
        WHERE username = ?
        LIMIT 1
    ");
    $stmt->bind_param('s', $ownerToken);
    $stmt->execute();
    $res = $stmt->get_result();
    $ownerRow = $res->fetch_assoc();
    $stmt->close();

    if ($ownerRow) {
        $ownerId = (int)$ownerRow['id'];
        $stmt = $mysqli->prepare("
            SELECT id, token, name
            FROM content_lists
            WHERE owner_id = ?
              AND parent_id IS NULL
              AND name <> 'All Content'
            ORDER BY order_index ASC, created_at ASC
        ");
        $stmt->bind_param('i', $ownerId);
        $stmt->execute();
        $res = $stmt->get_result();
        while ($row = $res->fetch_assoc()) {
            $listToken = (string)$row['token'];
            if (!tt_can_access_list($mysqli, $listToken, $username)) {
                continue;
            }
            $menuId = (int)$row['id'];
            $sections = [];
            $directItems = tt_fetch_item_rows($mysqli, $menuId);
            if ($directItems) {
                $sections[] = [
                    'name' => 'Featured',
                    'items' => $directItems,
                ];
            }
            foreach (tt_fetch_child_sections($mysqli, $menuId) as $sectionRow) {
                $sectionItems = tt_fetch_item_rows($mysqli, (int)$sectionRow['id']);
                if (!$sectionItems) {
                    continue;
                }
                $sections[] = [
                    'name' => $sectionRow['name'],
                    'items' => $sectionItems,
                ];
            }
            if (!$sections) {
                continue;
            }
            $menus[] = [
                'name' => (string)$row['name'],
                'owner' => $ownerRow['display_name'] ?: $ownerRow['username'],
                'sections' => $sections,
            ];
        }
        $stmt->close();
        $heroTitle = ($ownerRow['display_name'] ?: $ownerRow['username']) . ' menus';
        $heroSubtitle = 'Preview of top-level lists rendered as customer-facing menus.';
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title><?= htmlspecialchars($pageTitle, ENT_QUOTES, 'UTF-8') ?></title>
  <style>
    :root {
      --tt-ink: #17120d;
      --tt-muted: #6d645b;
      --tt-line: rgba(23, 18, 13, 0.12);
      --tt-bg: linear-gradient(180deg, #f6f0e7 0%, #fffaf4 36%, #f0f6f2 100%);
      --tt-card: rgba(255, 255, 255, 0.92);
      --tt-accent: #bc4c2a;
      --tt-accent-soft: #f3dfd4;
      --tt-shadow: 0 18px 40px rgba(31, 24, 17, 0.12);
    }
    * { box-sizing: border-box; }
    body {
      margin: 0;
      font-family: "Trebuchet MS", "Gill Sans", sans-serif;
      color: var(--tt-ink);
      background: var(--tt-bg);
    }
    .wrap {
      max-width: 460px;
      margin: 0 auto;
      min-height: 100vh;
      padding: 18px 14px 40px;
    }
    .hero {
      padding: 18px 18px 14px;
      border-radius: 24px;
      background: rgba(255,255,255,0.72);
      border: 1px solid rgba(255,255,255,0.8);
      box-shadow: var(--tt-shadow);
      backdrop-filter: blur(12px);
    }
    .eyebrow {
      display: inline-block;
      padding: 4px 10px;
      border-radius: 999px;
      background: var(--tt-accent-soft);
      color: var(--tt-accent);
      font-size: 12px;
      font-weight: 700;
      letter-spacing: 0.05em;
      text-transform: uppercase;
    }
    h1 {
      margin: 12px 0 8px;
      font-size: 32px;
      line-height: 1.05;
      font-family: Georgia, serif;
    }
    .hero p {
      margin: 0;
      color: var(--tt-muted);
      line-height: 1.5;
      font-size: 14px;
    }
    .menu-card {
      margin-top: 18px;
      padding: 18px 16px;
      border-radius: 22px;
      background: var(--tt-card);
      border: 1px solid var(--tt-line);
      box-shadow: var(--tt-shadow);
    }
    .menu-card h2 {
      margin: 0;
      font-size: 24px;
      font-family: Georgia, serif;
    }
    .menu-owner {
      margin-top: 4px;
      color: var(--tt-muted);
      font-size: 13px;
    }
    .section {
      margin-top: 18px;
    }
    .section h3 {
      margin: 0 0 10px;
      font-size: 13px;
      letter-spacing: 0.08em;
      text-transform: uppercase;
      color: var(--tt-accent);
    }
    .dish {
      padding: 12px 0;
      border-top: 1px solid var(--tt-line);
      cursor: pointer;
    }
    .dish:first-child {
      border-top: 0;
      padding-top: 0;
    }
    .dish-head {
      display: flex;
      gap: 10px;
      justify-content: space-between;
      align-items: baseline;
    }
    .dish-title {
      font-size: 16px;
      font-weight: 700;
    }
    .dish-price {
      flex: 0 0 auto;
      color: var(--tt-accent);
      font-weight: 700;
      font-size: 14px;
    }
    .dish-desc {
      margin-top: 5px;
      color: var(--tt-muted);
      font-size: 14px;
      line-height: 1.45;
    }
    .dish-thumb {
      margin-top: 10px;
      width: 100%;
      aspect-ratio: 16 / 9;
      border-radius: 16px;
      overflow: hidden;
      background: linear-gradient(135deg, #f4d8bc, #f0eee8);
      border: 1px solid var(--tt-line);
      display: flex;
      align-items: center;
      justify-content: center;
      color: #8a5c43;
      font-size: 13px;
      font-weight: 700;
      letter-spacing: 0.06em;
      text-transform: uppercase;
    }
    .dish-thumb img {
      width: 100%;
      height: 100%;
      object-fit: cover;
      display: block;
    }
    .detail-overlay {
      position: fixed;
      inset: 0;
      background: rgba(23, 18, 13, 0.52);
      backdrop-filter: blur(4px);
      display: none;
      align-items: flex-end;
      justify-content: center;
      padding: 16px;
      z-index: 9999;
    }
    .detail-overlay.is-open {
      display: flex;
    }
    .detail-sheet {
      width: min(460px, 100%);
      max-height: min(88vh, 760px);
      overflow: auto;
      border-radius: 26px;
      background: #fff;
      box-shadow: 0 28px 60px rgba(23, 18, 13, 0.25);
    }
    .detail-handle {
      width: 54px;
      height: 6px;
      border-radius: 999px;
      background: rgba(23, 18, 13, 0.14);
      margin: 10px auto 0;
    }
    .detail-media {
      width: 100%;
      aspect-ratio: 16 / 10;
      background: linear-gradient(135deg, #f4d8bc, #f0eee8);
      overflow: hidden;
      display: flex;
      align-items: center;
      justify-content: center;
      color: #8a5c43;
      font-weight: 700;
      letter-spacing: 0.08em;
      text-transform: uppercase;
    }
    .detail-media img {
      width: 100%;
      height: 100%;
      object-fit: cover;
      display: block;
    }
    .detail-body {
      padding: 18px 18px 26px;
    }
    .detail-top {
      display: flex;
      gap: 12px;
      justify-content: space-between;
      align-items: flex-start;
    }
    .detail-title {
      font-size: 26px;
      line-height: 1.1;
      font-family: Georgia, serif;
      margin: 0;
    }
    .detail-price {
      color: var(--tt-accent);
      font-weight: 700;
      font-size: 15px;
      white-space: nowrap;
      margin-top: 4px;
    }
    .detail-desc {
      margin-top: 12px;
      color: var(--tt-muted);
      font-size: 15px;
      line-height: 1.55;
    }
    .detail-list {
      margin: 16px 0 0;
      padding-left: 18px;
      color: var(--tt-muted);
    }
    .detail-list li {
      margin-bottom: 8px;
      line-height: 1.45;
    }
    .detail-actions {
      margin-top: 18px;
      display: flex;
      gap: 10px;
    }
    .detail-btn {
      flex: 1 1 auto;
      height: 46px;
      border-radius: 999px;
      border: 0;
      font-size: 14px;
      font-weight: 700;
      cursor: pointer;
    }
    .detail-btn.primary {
      background: var(--tt-accent);
      color: #fff;
    }
    .detail-btn.secondary {
      background: #f4efe9;
      color: var(--tt-ink);
    }
    .empty {
      margin-top: 18px;
      padding: 18px;
      border-radius: 18px;
      background: rgba(255,255,255,0.8);
      color: var(--tt-muted);
      border: 1px dashed var(--tt-line);
      text-align: center;
    }
  </style>
</head>
<body>
  <div class="wrap">
    <section class="hero">
      <span class="eyebrow">TapTray Preview</span>
      <h1><?= htmlspecialchars($heroTitle, ENT_QUOTES, 'UTF-8') ?></h1>
      <p><?= htmlspecialchars($heroSubtitle, ENT_QUOTES, 'UTF-8') ?></p>
    </section>

    <?php if (!$focusedItem && !$menus): ?>
      <div class="empty">
        No menu content is available for preview yet. Select a list in the manager or add items and sections first.
      </div>
    <?php endif; ?>

    <?php if ($focusedItem): ?>
      <section class="menu-card">
        <div class="detail-media" style="border-radius:20px;">
          <?php if ($focusedItem['image_url'] !== ''): ?>
            <img src="<?= htmlspecialchars($focusedItem['image_url'], ENT_QUOTES, 'UTF-8') ?>" alt="<?= htmlspecialchars($focusedItem['title'], ENT_QUOTES, 'UTF-8') ?>">
          <?php else: ?>
            Preview image
          <?php endif; ?>
        </div>
        <div class="detail-body" style="padding-left:0;padding-right:0;padding-bottom:0;">
          <div class="detail-top">
            <h2 class="detail-title" id="previewFocusedTitle"><?= htmlspecialchars($focusedItem['title'], ENT_QUOTES, 'UTF-8') ?></h2>
            <div class="detail-price"><?= htmlspecialchars($focusedItem['price'], ENT_QUOTES, 'UTF-8') ?></div>
          </div>
          <div class="detail-desc"><?= htmlspecialchars($focusedItem['description'], ENT_QUOTES, 'UTF-8') ?></div>
          <?php if (!empty($focusedItem['details'])): ?>
            <ul class="detail-list" style="display:block;">
              <?php foreach ($focusedItem['details'] as $line): ?>
                <li><?= htmlspecialchars($line, ENT_QUOTES, 'UTF-8') ?></li>
              <?php endforeach; ?>
            </ul>
          <?php endif; ?>
          <div class="detail-actions">
            <button class="detail-btn secondary" type="button">Customize</button>
            <button class="detail-btn primary" type="button">Add to order</button>
          </div>
        </div>
      </section>
    <?php endif; ?>

    <?php foreach ($menus as $menu): ?>
      <section class="menu-card">
        <h2><?= htmlspecialchars($menu['name'], ENT_QUOTES, 'UTF-8') ?></h2>
        <div class="menu-owner"><?= htmlspecialchars($menu['owner'], ENT_QUOTES, 'UTF-8') ?></div>
        <?php foreach ($menu['sections'] as $section): ?>
          <div class="section">
            <h3><?= htmlspecialchars($section['name'], ENT_QUOTES, 'UTF-8') ?></h3>
            <?php foreach ($section['items'] as $item): ?>
              <article
                class="dish"
                data-title="<?= htmlspecialchars($item['title'], ENT_QUOTES, 'UTF-8') ?>"
                data-price="<?= htmlspecialchars($item['price'], ENT_QUOTES, 'UTF-8') ?>"
                data-description="<?= htmlspecialchars($item['description'], ENT_QUOTES, 'UTF-8') ?>"
                data-image="<?= htmlspecialchars($item['image_url'], ENT_QUOTES, 'UTF-8') ?>"
                data-details="<?= htmlspecialchars(json_encode($item['details'], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES), ENT_QUOTES, 'UTF-8') ?>"
              >
                <div class="dish-head">
                  <div class="dish-title"><?= htmlspecialchars($item['title'], ENT_QUOTES, 'UTF-8') ?></div>
                  <?php if ($item['price'] !== ''): ?>
                    <div class="dish-price"><?= htmlspecialchars($item['price'], ENT_QUOTES, 'UTF-8') ?></div>
                  <?php endif; ?>
                </div>
                <?php if ($item['description'] !== ''): ?>
                  <div class="dish-desc"><?= htmlspecialchars($item['description'], ENT_QUOTES, 'UTF-8') ?></div>
                <?php endif; ?>
                <div class="dish-thumb">
                  <?php if ($item['image_url'] !== ''): ?>
                    <img src="<?= htmlspecialchars($item['image_url'], ENT_QUOTES, 'UTF-8') ?>" alt="<?= htmlspecialchars($item['title'], ENT_QUOTES, 'UTF-8') ?>">
                  <?php else: ?>
                    Preview image
                  <?php endif; ?>
                </div>
              </article>
            <?php endforeach; ?>
          </div>
        <?php endforeach; ?>
      </section>
    <?php endforeach; ?>
  </div>
  <div class="detail-overlay" id="menuDetailOverlay" aria-hidden="true">
    <div class="detail-sheet" role="dialog" aria-modal="true" aria-label="Menu item details">
      <div class="detail-handle"></div>
      <div class="detail-media" id="menuDetailMedia">Preview image</div>
      <div class="detail-body">
        <div class="detail-top">
          <h2 class="detail-title" id="menuDetailTitle">Menu item</h2>
          <div class="detail-price" id="menuDetailPrice"></div>
        </div>
        <div class="detail-desc" id="menuDetailDescription"></div>
        <ul class="detail-list" id="menuDetailList"></ul>
        <div class="detail-actions">
          <button class="detail-btn secondary" type="button" id="menuDetailClose">Close</button>
          <button class="detail-btn primary" type="button">Add to order</button>
        </div>
      </div>
    </div>
  </div>
  <script>
    (function () {
      const overlay = document.getElementById("menuDetailOverlay");
      const media = document.getElementById("menuDetailMedia");
      const title = document.getElementById("menuDetailTitle");
      const price = document.getElementById("menuDetailPrice");
      const description = document.getElementById("menuDetailDescription");
      const list = document.getElementById("menuDetailList");
      const closeBtn = document.getElementById("menuDetailClose");
      if (!overlay || !media || !title || !price || !description || !list || !closeBtn) return;

      function closeOverlay() {
        overlay.classList.remove("is-open");
        overlay.setAttribute("aria-hidden", "true");
      }

      function openOverlay(card) {
        title.textContent = card.dataset.title || "Menu item";
        price.textContent = card.dataset.price || "";
        description.textContent = card.dataset.description || "";
        list.innerHTML = "";

        let details = [];
        try {
          details = JSON.parse(card.dataset.details || "[]") || [];
        } catch (_) {
          details = [];
        }
        details.forEach((line) => {
          const li = document.createElement("li");
          li.textContent = String(line || "");
          list.appendChild(li);
        });
        list.style.display = details.length ? "block" : "none";

        const image = String(card.dataset.image || "").trim();
        if (image) {
          media.innerHTML = `<img src="${image}" alt="${title.textContent.replace(/"/g, '&quot;')}">`;
        } else {
          media.textContent = "Preview image";
        }

        overlay.classList.add("is-open");
        overlay.setAttribute("aria-hidden", "false");
      }

      document.querySelectorAll(".dish").forEach((card) => {
        card.addEventListener("click", () => openOverlay(card));
      });

      closeBtn.addEventListener("click", closeOverlay);
      overlay.addEventListener("click", (event) => {
        if (event.target === overlay) closeOverlay();
      });
      document.addEventListener("keydown", (event) => {
        if (event.key === "Escape") closeOverlay();
      });
    })();
  </script>
</body>
</html>
