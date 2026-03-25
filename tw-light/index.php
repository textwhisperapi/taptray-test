<?php
header('Content-Type: text/html; charset=utf-8');

require_once __DIR__ . '/../includes/db_connect.php';
require_once __DIR__ . '/../includes/functions.php';

sec_session_start();
$lightVersion = 'lite-v14';

$rawPath = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH);
$segments = explode('/', trim((string)$rawPath, '/'));
$segments = array_values(array_filter($segments, static function ($s) {
    return $s !== '';
}));

if (!empty($segments) && ($segments[0] === 'l' || $segments[0] === 'tw-light')) {
    array_shift($segments);
}

$target = $segments[0] ?? '';
$surrogate = $segments[1] ?? '';
$queryToken = trim((string)($_GET['token'] ?? ''));

$loggedIn = login_check($mysqli);
$username = $_SESSION['username'] ?? '';
$displayName = $_SESSION['display_name'] ?? $username;

$resolvedTarget = $target !== '' ? $target : ($queryToken !== '' ? $queryToken : $username);
$homePath = '/';
if (!empty($username)) {
    $homePath = '/' . rawurlencode($username);
} elseif (!empty($resolvedTarget)) {
    $homePath = '/' . rawurlencode($resolvedTarget);
}
?>
<!doctype html>
<html lang="en">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title>TextWhisper Light</title>
  <link rel="stylesheet" href="/tw-light/tw-light.css?v=<?= urlencode($lightVersion) ?>">
</head>
<body>
  <div class="twl-shell">
    <aside class="twl-sidebar">
      <div class="twl-brand">TextWhisper Light</div>
      <div class="twl-user">
        <?php if ($loggedIn): ?>
          <span><?= htmlspecialchars($displayName, ENT_QUOTES, 'UTF-8') ?></span>
        <?php else: ?>
          <a href="/login.php">Log in</a>
        <?php endif; ?>
      </div>
      <div id="twlBuildTag" class="twl-build">Build <?= htmlspecialchars($lightVersion, ENT_QUOTES, 'UTF-8') ?></div>
      <form id="twlTokenForm" class="twl-token-form" action="" method="get">
        <input id="twlTokenInput" name="token" type="text" placeholder="Open profile/list token" value="<?= htmlspecialchars($resolvedTarget, ENT_QUOTES, 'UTF-8') ?>">
        <button type="submit">Open</button>
      </form>
      <div class="twl-links">
        <a href="<?= htmlspecialchars($homePath, ENT_QUOTES, 'UTF-8') ?>">Home</a>
        <a href="/sub_settings.php">Settings</a>
      </div>
      <div id="twlStatus" class="twl-status">Loading lists...</div>
      <div id="twlListTree" class="twl-list-tree"></div>
      <div id="twlItemList" class="twl-item-list"></div>
    </aside>

    <main class="twl-main">
      <div class="twl-header">
        <span class="twl-title">TextWhisper Light</span>
        <button id="twlToggleSidebar" class="twl-tab twl-menu-btn" type="button">Menu</button>
        <button id="twlTabText" class="twl-tab active" type="button">Text</button>
        <button id="twlTabPdf" class="twl-tab" type="button">PDF</button>
        <span id="twlCurrent" class="twl-current"></span>
      </div>

      <section id="twlPanelText" class="twl-panel active">
        <div id="twlTextMeta" class="twl-meta"></div>
        <div id="twlTextView" class="twl-text-view"></div>
      </section>

      <section id="twlPanelPdf" class="twl-panel">
        <div id="twlPdfMeta" class="twl-meta"></div>
        <div class="twl-pdf-nav">
          <button id="twlPdfPrev" type="button">Prev</button>
          <span id="twlPdfPageLabel">Page 1</span>
          <button id="twlPdfMode" type="button">Continuous</button>
          <button id="twlPdfNext" type="button">Next</button>
        </div>
        <div id="twlPdfCanvasWrap" class="twl-pdf-canvas-wrap"></div>
        <iframe id="twlPdfFrame" class="twl-pdf-frame" title="PDF preview"></iframe>
        <a id="twlPdfLink" class="twl-pdf-link" href="#" target="_blank" rel="noopener" style="display:none;">Open PDF in new tab</a>
      </section>
    </main>
  </div>

  <footer id="twlFooter" class="twl-footer">
    <button id="twlFooterSidebar" type="button">Menu</button>
    <button id="twlFooterText" type="button" class="active">Text</button>
    <button id="twlFooterPdf" type="button">PDF</button>
  </footer>

  <script>
    (function () {
      function showLiteBootFlash() {
        if (!document.body) return;
        var el = document.createElement("div");
        el.id = "twlBootFlash";
        el.appendChild(document.createTextNode("TextWhisper Light <?= htmlspecialchars($lightVersion, ENT_QUOTES, 'UTF-8') ?>"));
        el.style.position = "fixed";
        el.style.top = "50%";
        el.style.left = "50%";
        el.style.transform = "translate(-50%, -50%)";
        el.style.background = "#1f2630";
        el.style.color = "#fff";
        el.style.padding = "10px 14px";
        el.style.borderRadius = "8px";
        el.style.boxShadow = "0 4px 12px rgba(0,0,0,0.28)";
        el.style.zIndex = "99999";
        el.style.fontSize = "14px";
        el.style.opacity = "0";
        el.style.transition = "opacity 0.2s ease-in-out";
        document.body.appendChild(el);

        setTimeout(function () {
          el.style.opacity = "1";
        }, 20);

        setTimeout(function () {
          el.style.opacity = "0";
          setTimeout(function () {
            if (el && el.parentNode) el.parentNode.removeChild(el);
          }, 260);
        }, 1800);
      }

      if (document.readyState === "loading") {
        document.addEventListener("DOMContentLoaded", showLiteBootFlash);
      } else {
        showLiteBootFlash();
      }
    })();
  </script>

  <script>
    window.TWL_CONTEXT = {
      target: <?= json_encode($resolvedTarget) ?>,
      initialSurrogate: <?= json_encode($surrogate) ?>,
      username: <?= json_encode($username) ?>,
      loggedIn: <?= $loggedIn ? 'true' : 'false' ?>,
      lightVersion: <?= json_encode($lightVersion) ?>
    };
  </script>
  <script src="/assets/pdf.min.js"></script>
  <script src="/tw-light/tw-light.js?v=<?= urlencode($lightVersion) ?>"></script>
</body>
</html>
