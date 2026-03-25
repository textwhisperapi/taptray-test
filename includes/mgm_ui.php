<?php
require_once __DIR__ . '/functions.php';
require_once __DIR__ . '/db_connect.php';

function mgm_bootstrap(string $activeNav, string $pageTitle): array {
    sec_session_start();

    if (!isset($_SESSION['user_id'], $_SESSION['username'])) {
        header('Location: /login.php');
        exit;
    }

    $currentUsername = trim((string)($_SESSION['username'] ?? ''));
    if ($currentUsername !== 'grimmi') {
        http_response_code(403);
        echo '<!DOCTYPE html><html lang="en"><head><meta charset="UTF-8"><meta name="viewport" content="width=device-width, initial-scale=1"><title>Access Denied</title></head><body style="font-family:system-ui,sans-serif;padding:24px;"><h1>Access Denied</h1><p>This page is restricted.</p></body></html>';
        exit;
    }

    return [
        'active_nav' => $activeNav,
        'page_title' => $pageTitle,
        'username' => $currentUsername,
    ];
}

function mgm_h($value): string {
    return htmlspecialchars((string)$value, ENT_QUOTES, 'UTF-8');
}

function mgm_format_datetime(?string $value, string $fallback = 'n/a'): string {
    $value = trim((string)$value);
    if ($value === '' || $value === '0000-00-00 00:00:00') {
        return $fallback;
    }

    try {
        $dt = new DateTimeImmutable($value, new DateTimeZone('UTC'));
    } catch (Throwable $e) {
        return $fallback;
    }

    return $dt->setTimezone(new DateTimeZone('UTC'))->format('d.m.Y H:i');
}

function mgm_nav_items(): array {
    return [
        'overview' => ['label' => 'Overview', 'href' => '/mgm_overview.php'],
        'traffic' => ['label' => 'Traffic', 'href' => '/mgm_traffic_overview.php'],
        'pricing' => ['label' => 'Pricing', 'href' => '/mgm_price_settings.php'],
        'payments' => ['label' => 'Payments', 'href' => '/mgm_payment_settings.php'],
        'contracts' => ['label' => 'Contracts', 'href' => '#'],
        'users' => ['label' => 'Users', 'href' => '/mgm_users.php'],
        'insights' => ['label' => 'Insights', 'href' => '#'],
        'logs' => ['label' => 'Logs', 'href' => '#'],
    ];
}

function mgm_render_shell_start(array $ctx, string $headline, string $intro): void {
    $navItems = mgm_nav_items();
    ?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title><?= mgm_h($ctx['page_title']) ?></title>
  <style>
    :root {
      --mgm-bg: #f2f4f8;
      --mgm-panel: #ffffff;
      --mgm-panel-soft: #eef3f8;
      --mgm-border: #d9e1ec;
      --mgm-text: #18212f;
      --mgm-muted: #5d6b80;
      --mgm-accent: #0f766e;
      --mgm-accent-soft: #dff6f2;
      --mgm-warn: #b45309;
      --mgm-danger: #b42318;
      --mgm-shadow: 0 20px 45px rgba(15, 23, 42, 0.08);
    }
    * { box-sizing: border-box; }
    body {
      margin: 0;
      font-family: Georgia, "Times New Roman", serif;
      color: var(--mgm-text);
      background:
        radial-gradient(circle at top left, rgba(15, 118, 110, 0.08), transparent 28%),
        linear-gradient(180deg, #f7f8fb 0%, var(--mgm-bg) 100%);
    }
    a { color: inherit; }
    .mgm-shell {
      min-height: 100vh;
      display: grid;
      grid-template-columns: 272px minmax(0, 1fr);
    }
    .mgm-sidebar {
      background: #152033;
      color: #f8fafc;
      padding: 28px 22px;
      position: sticky;
      top: 0;
      height: 100vh;
    }
    .mgm-brand {
      margin-bottom: 28px;
      padding-bottom: 20px;
      border-bottom: 1px solid rgba(255,255,255,0.12);
    }
    .mgm-brand-kicker {
      font: 700 11px/1.2 "Trebuchet MS", sans-serif;
      letter-spacing: 0.12em;
      text-transform: uppercase;
      color: #8ad6cc;
      margin-bottom: 10px;
    }
    .mgm-brand-title {
      font-size: 28px;
      line-height: 1.05;
      margin: 0;
    }
    .mgm-brand-copy {
      margin: 10px 0 0;
      font-size: 14px;
      line-height: 1.45;
      color: rgba(255,255,255,0.76);
    }
    .mgm-nav {
      display: grid;
      gap: 8px;
    }
    .mgm-nav a {
      text-decoration: none;
      padding: 12px 14px;
      border-radius: 12px;
      font: 600 14px/1.2 "Trebuchet MS", sans-serif;
      color: rgba(255,255,255,0.8);
      background: rgba(255,255,255,0.03);
      border: 1px solid transparent;
    }
    .mgm-nav a.is-active {
      background: rgba(138, 214, 204, 0.12);
      color: #ffffff;
      border-color: rgba(138, 214, 204, 0.22);
    }
    .mgm-nav a.is-disabled {
      pointer-events: none;
      opacity: 0.56;
    }
    .mgm-sidebar-foot {
      position: absolute;
      left: 22px;
      right: 22px;
      bottom: 22px;
      font: 500 13px/1.4 "Trebuchet MS", sans-serif;
      color: rgba(255,255,255,0.68);
      padding-top: 16px;
      border-top: 1px solid rgba(255,255,255,0.12);
    }
    .mgm-main {
      padding: 28px;
    }
    .mgm-hero {
      background: linear-gradient(135deg, rgba(15, 118, 110, 0.1), rgba(255,255,255,0.9));
      border: 1px solid var(--mgm-border);
      border-radius: 24px;
      box-shadow: var(--mgm-shadow);
      padding: 28px 30px;
      margin-bottom: 24px;
    }
    .mgm-hero-kicker {
      font: 700 12px/1.2 "Trebuchet MS", sans-serif;
      text-transform: uppercase;
      letter-spacing: 0.12em;
      color: var(--mgm-accent);
      margin: 0 0 10px;
    }
    .mgm-hero h1 {
      margin: 0;
      font-size: 38px;
      line-height: 1;
    }
    .mgm-hero p {
      margin: 14px 0 0;
      max-width: 720px;
      color: var(--mgm-muted);
      font-size: 17px;
      line-height: 1.5;
    }
    .mgm-grid {
      display: grid;
      gap: 18px;
    }
    .mgm-grid.cols-4 { grid-template-columns: repeat(4, minmax(0, 1fr)); }
    .mgm-grid.cols-3 { grid-template-columns: repeat(3, minmax(0, 1fr)); }
    .mgm-grid.cols-2 { grid-template-columns: repeat(2, minmax(0, 1fr)); }
    .mgm-panel {
      background: var(--mgm-panel);
      border: 1px solid var(--mgm-border);
      border-radius: 20px;
      box-shadow: var(--mgm-shadow);
      padding: 22px;
    }
    .mgm-panel h2,
    .mgm-panel h3 {
      margin: 0 0 12px;
      font-size: 21px;
      line-height: 1.2;
    }
    .mgm-panel-intro {
      margin: 0 0 16px;
      color: var(--mgm-muted);
      font-size: 15px;
      line-height: 1.45;
    }
    .mgm-stat-label {
      margin: 0 0 10px;
      color: var(--mgm-muted);
      font: 700 12px/1.2 "Trebuchet MS", sans-serif;
      text-transform: uppercase;
      letter-spacing: 0.08em;
    }
    .mgm-stat-value {
      margin: 0;
      font-size: 34px;
      line-height: 1;
    }
    .mgm-stat-note {
      margin: 10px 0 0;
      color: var(--mgm-muted);
      font-size: 14px;
      line-height: 1.45;
    }
    .mgm-table-wrap {
      overflow-x: auto;
    }
    table {
      width: 100%;
      border-collapse: collapse;
      font-size: 14px;
    }
    th, td {
      padding: 10px 12px;
      border-bottom: 1px solid #e7edf5;
      text-align: left;
      vertical-align: top;
    }
    th {
      color: var(--mgm-muted);
      font: 700 12px/1.2 "Trebuchet MS", sans-serif;
      text-transform: uppercase;
      letter-spacing: 0.08em;
    }
    .mgm-list {
      display: grid;
      gap: 10px;
      margin: 0;
      padding: 0;
      list-style: none;
    }
    .mgm-list li {
      padding: 12px 14px;
      border-radius: 14px;
      background: var(--mgm-panel-soft);
      border: 1px solid #dde6f1;
      font-size: 14px;
      line-height: 1.45;
    }
    .mgm-pill {
      display: inline-block;
      padding: 5px 9px;
      border-radius: 999px;
      font: 700 11px/1.1 "Trebuchet MS", sans-serif;
      text-transform: uppercase;
      letter-spacing: 0.06em;
      background: var(--mgm-accent-soft);
      color: var(--mgm-accent);
    }
    .mgm-pill.warn {
      background: #fff1de;
      color: var(--mgm-warn);
    }
    .mgm-pill.danger {
      background: #ffe4e7;
      color: var(--mgm-danger);
    }
    .mgm-link-card {
      display: block;
      text-decoration: none;
      color: inherit;
      height: 100%;
    }
    .mgm-link-card:hover .mgm-panel {
      border-color: #b5c4d8;
      transform: translateY(-1px);
    }
    .mgm-panel,
    .mgm-link-card .mgm-panel {
      transition: border-color 0.18s ease, transform 0.18s ease;
    }
    .mgm-kv {
      display: grid;
      grid-template-columns: minmax(150px, 220px) minmax(0, 1fr);
      gap: 10px 16px;
      margin: 0;
    }
    .mgm-kv dt {
      color: var(--mgm-muted);
      font: 700 12px/1.2 "Trebuchet MS", sans-serif;
      text-transform: uppercase;
      letter-spacing: 0.08em;
    }
    .mgm-kv dd {
      margin: 0;
    }
    .mgm-settings-form {
      display: grid;
      gap: 12px;
    }
    .mgm-settings-grid {
      display: grid;
      grid-template-columns: repeat(2, minmax(0, 1fr));
      gap: 12px;
    }
    .mgm-settings-form label {
      display: grid;
      gap: 6px;
      font: 600 14px/1.3 "Trebuchet MS", sans-serif;
      color: var(--mgm-text);
    }
    .mgm-settings-form span {
      color: var(--mgm-muted);
      font: 700 12px/1.2 "Trebuchet MS", sans-serif;
      text-transform: uppercase;
      letter-spacing: 0.05em;
    }
    .mgm-settings-form input {
      width: 100%;
      border: 1px solid var(--mgm-border);
      border-radius: 12px;
      padding: 11px 12px;
      font: 500 14px/1.2 "Trebuchet MS", sans-serif;
      color: var(--mgm-text);
      background: #fff;
    }
    .mgm-save-btn {
      border: 1px solid rgba(15, 118, 110, 0.18);
      border-radius: 999px;
      padding: 10px 16px;
      font: 700 14px/1 "Trebuchet MS", sans-serif;
      background: var(--mgm-accent);
      color: #fff;
      cursor: pointer;
    }
    @media (max-width: 1100px) {
      .mgm-shell { grid-template-columns: 1fr; }
      .mgm-sidebar {
        position: static;
        height: auto;
      }
      .mgm-sidebar-foot {
        position: static;
        margin-top: 22px;
      }
      .mgm-grid.cols-4,
      .mgm-grid.cols-3,
      .mgm-grid.cols-2 {
        grid-template-columns: repeat(2, minmax(0, 1fr));
      }
    }
    @media (max-width: 720px) {
      .mgm-main { padding: 16px; }
      .mgm-sidebar { padding: 18px 16px; }
      .mgm-hero { padding: 22px 18px; }
      .mgm-hero h1 { font-size: 30px; }
      .mgm-grid.cols-4,
      .mgm-grid.cols-3,
      .mgm-grid.cols-2 {
        grid-template-columns: 1fr;
      }
      .mgm-kv {
        grid-template-columns: 1fr;
        gap: 6px;
      }
      .mgm-settings-grid {
        grid-template-columns: 1fr;
      }
    }
  </style>
</head>
<body>
  <div class="mgm-shell">
    <aside class="mgm-sidebar">
      <div class="mgm-brand">
        <div class="mgm-brand-kicker">TextWhisper Management</div>
        <h1 class="mgm-brand-title">Backoffice</h1>
        <p class="mgm-brand-copy">Internal tools for pricing, traffic, payments, account state, and operational follow-up.</p>
      </div>
      <nav class="mgm-nav">
        <?php foreach ($navItems as $key => $item): ?>
          <?php $classNames = []; ?>
          <?php if ($ctx['active_nav'] === $key) { $classNames[] = 'is-active'; } ?>
          <?php if ($item['href'] === '#') { $classNames[] = 'is-disabled'; } ?>
          <a href="<?= mgm_h($item['href']) ?>" class="<?= mgm_h(implode(' ', $classNames)) ?>"><?= mgm_h($item['label']) ?></a>
        <?php endforeach; ?>
      </nav>
      <div class="mgm-sidebar-foot">
        Signed in as <strong><?= mgm_h($ctx['username']) ?></strong><br>
        Access is still hardcoded and should move to role-based auth.
      </div>
    </aside>
    <main class="mgm-main">
      <section class="mgm-hero">
        <p class="mgm-hero-kicker">Management Workspace</p>
        <h1><?= mgm_h($headline) ?></h1>
        <p><?= mgm_h($intro) ?></p>
      </section>
<?php
}

function mgm_render_shell_end(): void {
    ?>
    </main>
  </div>
</body>
</html>
<?php
}
