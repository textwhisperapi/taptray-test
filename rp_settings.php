<?php
declare(strict_types=1);

require_once __DIR__ . '/includes/db_connect.php';
require_once __DIR__ . '/includes/functions.php';

sec_session_start();
if (!login_check($mysqli) || empty($_SESSION['user_id'])) {
    http_response_code(403);
    exit('Not authorized');
}

header('Content-Type: text/html; charset=utf-8');

function rp_settings_resolve_merchant_id(mysqli $mysqli): int {
    $ownerToken = trim((string)($_GET['owner'] ?? ''));
    if ($ownerToken !== '') {
        $normalized = str_starts_with($ownerToken, 'invited-')
            ? substr($ownerToken, strlen('invited-'))
            : $ownerToken;
        $stmt = $mysqli->prepare("SELECT owner_id FROM content_lists WHERE token = ? LIMIT 1");
        if ($stmt) {
            $stmt->bind_param('s', $normalized);
            $stmt->execute();
            $stmt->bind_result($ownerId);
            $stmt->fetch();
            $stmt->close();
            if (!empty($ownerId)) {
                return (int)$ownerId;
            }
        }
        $stmt = $mysqli->prepare("SELECT id FROM members WHERE username = ? LIMIT 1");
        if ($stmt) {
            $stmt->bind_param('s', $normalized);
            $stmt->execute();
            $stmt->bind_result($memberId);
            $stmt->fetch();
            $stmt->close();
            if (!empty($memberId)) {
                return (int)$memberId;
            }
        }
    }

    return (int)($_SESSION['user_id'] ?? 0);
}

function rp_settings_owner_query(mysqli $mysqli, int $merchantId): string {
    if ($merchantId <= 0) {
        return '';
    }
    $stmt = $mysqli->prepare("SELECT username FROM members WHERE id = ? LIMIT 1");
    if (!$stmt) {
        return '';
    }
    $stmt->bind_param('i', $merchantId);
    $stmt->execute();
    $stmt->bind_result($username);
    $stmt->fetch();
    $stmt->close();
    $username = trim((string)$username);
    return $username !== '' ? ('?owner=' . rawurlencode($username)) : '';
}

function rp_settings_fetch_label(mysqli $mysqli, int $merchantId): string {
    $stmt = $mysqli->prepare("SELECT COALESCE(NULLIF(display_name, ''), username) FROM members WHERE id = ? LIMIT 1");
    if (!$stmt) {
        return 'TapTray';
    }
    $stmt->bind_param('i', $merchantId);
    $stmt->execute();
    $stmt->bind_result($label);
    $stmt->fetch();
    $stmt->close();
    $label = trim((string)$label);
    return $label !== '' ? $label : 'TapTray';
}

function rp_settings_redirect(string $ownerQuery, string $flash): never {
    $sep = $ownerQuery === '' ? '?' : '&';
    header('Location: /rp_settings.php' . $ownerQuery . $sep . 'flash=' . rawurlencode($flash));
    exit;
}

$merchantId = rp_settings_resolve_merchant_id($mysqli);
if ($merchantId <= 0) {
    http_response_code(400);
    exit('Missing merchant');
}

$ownerQuery = rp_settings_owner_query($mysqli, $merchantId);

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = trim((string)($_POST['action'] ?? ''));

    if ($action === 'add_table') {
        $tableCode = substr(trim((string)($_POST['table_code'] ?? '')), 0, 40);
        $label = substr(trim((string)($_POST['label'] ?? '')), 0, 80);
        $zone = substr(trim((string)($_POST['zone'] ?? '')), 0, 80);
        $capacityMin = max(1, (int)($_POST['capacity_min'] ?? 1));
        $capacityMax = max($capacityMin, (int)($_POST['capacity_max'] ?? $capacityMin));
        $sortOrder = (int)($_POST['sort_order'] ?? 0);
        if ($tableCode === '' || $label === '') {
            rp_settings_redirect($ownerQuery, 'missing_table_fields');
        }
        $stmt = $mysqli->prepare("
            INSERT INTO rp_tables
                (merchant_id, location_id, table_code, label, zone, capacity_min, capacity_max, is_active, sort_order)
            VALUES (?, NULL, ?, ?, ?, ?, ?, 1, ?)
            ON DUPLICATE KEY UPDATE
                label = VALUES(label),
                zone = VALUES(zone),
                capacity_min = VALUES(capacity_min),
                capacity_max = VALUES(capacity_max),
                sort_order = VALUES(sort_order),
                is_active = 1
        ");
        if ($stmt) {
            $stmt->bind_param('isssiii', $merchantId, $tableCode, $label, $zone, $capacityMin, $capacityMax, $sortOrder);
            $stmt->execute();
            $stmt->close();
        }
        rp_settings_redirect($ownerQuery, 'table_saved');
    }

    if ($action === 'toggle_table') {
        $tableId = (int)($_POST['table_id'] ?? 0);
        $nextActive = (int)($_POST['next_active'] ?? 0) === 1 ? 1 : 0;
        $stmt = $mysqli->prepare("UPDATE rp_tables SET is_active = ? WHERE id = ? AND merchant_id = ?");
        if ($stmt) {
            $stmt->bind_param('iii', $nextActive, $tableId, $merchantId);
            $stmt->execute();
            $stmt->close();
        }
        rp_settings_redirect($ownerQuery, 'table_updated');
    }

    if ($action === 'save_rule') {
        $dayOfWeek = max(0, min(6, (int)($_POST['day_of_week'] ?? 0)));
        $openTime = preg_match('/^\d{2}:\d{2}$/', (string)($_POST['open_time'] ?? '')) ? (string)$_POST['open_time'] . ':00' : '17:00:00';
        $closeTime = preg_match('/^\d{2}:\d{2}$/', (string)($_POST['close_time'] ?? '')) ? (string)$_POST['close_time'] . ':00' : '22:00:00';
        $slotMinutes = max(5, min(180, (int)($_POST['slot_minutes'] ?? 30)));
        $maxPartySize = max(1, min(50, (int)($_POST['max_party_size'] ?? 8)));
        $isClosed = isset($_POST['is_closed']) ? 1 : 0;

        $existingId = 0;
        $stmt = $mysqli->prepare("SELECT id FROM rp_availability_rules WHERE merchant_id = ? AND day_of_week = ? AND location_id IS NULL ORDER BY id ASC LIMIT 1");
        if ($stmt) {
            $stmt->bind_param('ii', $merchantId, $dayOfWeek);
            $stmt->execute();
            $stmt->bind_result($ruleId);
            if ($stmt->fetch()) {
                $existingId = (int)$ruleId;
            }
            $stmt->close();
        }

        if ($existingId > 0) {
            $stmt = $mysqli->prepare("
                UPDATE rp_availability_rules
                SET open_time = ?, close_time = ?, slot_minutes = ?, max_party_size = ?, is_closed = ?
                WHERE id = ? AND merchant_id = ?
            ");
            if ($stmt) {
                $stmt->bind_param('ssiiiii', $openTime, $closeTime, $slotMinutes, $maxPartySize, $isClosed, $existingId, $merchantId);
                $stmt->execute();
                $stmt->close();
            }
        } else {
            $stmt = $mysqli->prepare("
                INSERT INTO rp_availability_rules
                    (merchant_id, location_id, day_of_week, open_time, close_time, slot_minutes, max_party_size, is_closed)
                VALUES (?, NULL, ?, ?, ?, ?, ?, ?)
            ");
            if ($stmt) {
                $stmt->bind_param('iissiii', $merchantId, $dayOfWeek, $openTime, $closeTime, $slotMinutes, $maxPartySize, $isClosed);
                $stmt->execute();
                $stmt->close();
            }
        }
        rp_settings_redirect($ownerQuery, 'rule_saved');
    }

    if ($action === 'add_blackout') {
        $startAt = trim((string)($_POST['start_at'] ?? ''));
        $endAt = trim((string)($_POST['end_at'] ?? ''));
        $reason = substr(trim((string)($_POST['reason'] ?? 'Unavailable')), 0, 255);
        if ($startAt === '' || $endAt === '') {
            rp_settings_redirect($ownerQuery, 'missing_blackout_fields');
        }
        $startAt = str_replace('T', ' ', $startAt) . ':00';
        $endAt = str_replace('T', ' ', $endAt) . ':00';
        $stmt = $mysqli->prepare("
            INSERT INTO rp_blackouts (merchant_id, location_id, start_at, end_at, reason)
            VALUES (?, NULL, ?, ?, ?)
        ");
        if ($stmt) {
            $stmt->bind_param('isss', $merchantId, $startAt, $endAt, $reason);
            $stmt->execute();
            $stmt->close();
        }
        rp_settings_redirect($ownerQuery, 'blackout_saved');
    }

    if ($action === 'delete_blackout') {
        $blackoutId = (int)($_POST['blackout_id'] ?? 0);
        $stmt = $mysqli->prepare("DELETE FROM rp_blackouts WHERE id = ? AND merchant_id = ?");
        if ($stmt) {
            $stmt->bind_param('ii', $blackoutId, $merchantId);
            $stmt->execute();
            $stmt->close();
        }
        rp_settings_redirect($ownerQuery, 'blackout_deleted');
    }
}

$merchantLabel = rp_settings_fetch_label($mysqli, $merchantId);
$flash = trim((string)($_GET['flash'] ?? ''));
$tables = [];
$res = $mysqli->query("
    SELECT id, table_code, label, zone, capacity_min, capacity_max, sort_order, is_active
    FROM rp_tables
    WHERE merchant_id = " . (int)$merchantId . "
    ORDER BY is_active DESC, sort_order ASC, label ASC
");
if ($res) {
    while ($row = $res->fetch_assoc()) {
        $tables[] = $row;
    }
    $res->close();
}

$rules = [];
$res = $mysqli->query("
    SELECT id, day_of_week, open_time, close_time, slot_minutes, max_party_size, is_closed
    FROM rp_availability_rules
    WHERE merchant_id = " . (int)$merchantId . " AND location_id IS NULL
    ORDER BY day_of_week ASC, id ASC
");
if ($res) {
    while ($row = $res->fetch_assoc()) {
        $day = (int)$row['day_of_week'];
        if (!isset($rules[$day])) {
            $rules[$day] = $row;
        }
    }
    $res->close();
}

$blackouts = [];
$res = $mysqli->query("
    SELECT id, start_at, end_at, reason
    FROM rp_blackouts
    WHERE merchant_id = " . (int)$merchantId . "
    ORDER BY start_at ASC
    LIMIT 30
");
if ($res) {
    while ($row = $res->fetch_assoc()) {
        $blackouts[] = $row;
    }
    $res->close();
}

$days = [
    0 => 'Sunday',
    1 => 'Monday',
    2 => 'Tuesday',
    3 => 'Wednesday',
    4 => 'Thursday',
    5 => 'Friday',
    6 => 'Saturday',
];

$activeTables = array_values(array_filter($tables, static fn(array $row): bool => (int)$row['is_active'] === 1));
$totalSeats = array_sum(array_map(static fn(array $row): int => (int)$row['capacity_max'], $activeTables));
?>
<!doctype html>
<html lang="en">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title><?= htmlspecialchars($merchantLabel, ENT_QUOTES, 'UTF-8') ?> RP Settings</title>
  <style>
    :root {
      --bg: #f7f1e7;
      --bg-2: #efe6d6;
      --ink: #241810;
      --muted: #6f6254;
      --line: rgba(45, 27, 15, 0.12);
      --panel: rgba(255, 252, 247, 0.82);
      --accent: #a15a2b;
      --accent-2: #2d6f60;
      --danger: #933a32;
      --shadow: 0 18px 44px rgba(42, 24, 12, 0.12);
      --radius: 28px;
      --font-display: "Georgia", "Iowan Old Style", "Times New Roman", serif;
    }
    * { box-sizing: border-box; }
    body {
      margin: 0;
      min-height: 100vh;
      color: var(--ink);
      font-family: "Segoe UI", system-ui, sans-serif;
      background:
        radial-gradient(circle at 0% 0%, rgba(161, 90, 43, 0.16), transparent 28%),
        radial-gradient(circle at 100% 18%, rgba(45, 111, 96, 0.16), transparent 24%),
        linear-gradient(180deg, #f9f4ec 0%, var(--bg) 46%, var(--bg-2) 100%);
    }
    .rp-shell { width: min(1220px, calc(100vw - 28px)); margin: 0 auto; padding: 22px 0 44px; }
    .rp-topbar { display: flex; align-items: center; justify-content: space-between; gap: 16px; margin-bottom: 18px; }
    .rp-brand { display: inline-flex; align-items: center; gap: 12px; color: var(--muted); font-size: 13px; letter-spacing: 0.16em; text-transform: uppercase; font-weight: 800; }
    .rp-brand-mark { width: 42px; height: 42px; border-radius: 16px; background: linear-gradient(135deg, #2d6f60, #a15a2b); color: #fff; display: inline-flex; align-items: center; justify-content: center; font-weight: 900; box-shadow: 0 10px 26px rgba(45, 111, 96, 0.24); }
    .rp-link-row { display: inline-flex; flex-wrap: wrap; gap: 10px; }
    .rp-link { display: inline-flex; align-items: center; gap: 8px; text-decoration: none; padding: 10px 14px; border-radius: 999px; background: rgba(255,255,255,0.66); border: 1px solid rgba(45, 27, 15, 0.08); box-shadow: 0 8px 24px rgba(36, 24, 16, 0.08); font-weight: 700; color: inherit; }
    .rp-hero { border-radius: 34px; padding: 28px; background: linear-gradient(130deg, rgba(37, 22, 15, 0.9), rgba(61, 37, 24, 0.76)); color: #fff8f2; box-shadow: var(--shadow); }
    .rp-hero-grid { display: grid; grid-template-columns: minmax(0, 1.2fr) minmax(280px, 0.8fr); gap: 22px; }
    .rp-kicker { font-size: 12px; letter-spacing: 0.16em; text-transform: uppercase; color: rgba(255, 244, 228, 0.78); font-weight: 800; }
    .rp-hero h1 { margin: 10px 0 12px; font-family: var(--font-display); font-size: clamp(34px, 5vw, 58px); line-height: 0.95; letter-spacing: -0.04em; }
    .rp-hero-copy { max-width: 54ch; color: rgba(255, 244, 228, 0.82); font-size: 16px; line-height: 1.55; margin: 0; }
    .rp-stats { margin-top: 22px; display: grid; grid-template-columns: repeat(3, minmax(0, 1fr)); gap: 12px; }
    .rp-stat { padding: 14px 16px; border-radius: 20px; background: rgba(255, 249, 241, 0.09); border: 1px solid rgba(255, 244, 228, 0.12); }
    .rp-stat-label { font-size: 11px; letter-spacing: 0.12em; text-transform: uppercase; color: rgba(255, 244, 228, 0.68); font-weight: 700; }
    .rp-stat-value { margin-top: 8px; font-size: 24px; font-weight: 800; line-height: 1; }
    .rp-note { align-self: end; padding: 18px; border-radius: 26px; background: rgba(255, 250, 244, 0.94); color: var(--ink); box-shadow: 0 20px 42px rgba(28, 18, 11, 0.18); }
    .rp-note strong { display: block; font-size: 22px; line-height: 1.1; font-family: var(--font-display); }
    .rp-layout { margin-top: 20px; display: grid; grid-template-columns: minmax(0, 1.1fr) minmax(340px, 0.9fr); gap: 18px; align-items: start; }
    .rp-stack { display: grid; gap: 18px; }
    .rp-panel { background: var(--panel); border: 1px solid rgba(45, 27, 15, 0.08); border-radius: var(--radius); box-shadow: 0 14px 36px rgba(38, 24, 16, 0.08); backdrop-filter: blur(10px); }
    .rp-panel-head { display: flex; align-items: start; justify-content: space-between; gap: 12px; padding: 20px 22px 12px; }
    .rp-panel-title { font-family: var(--font-display); font-size: 28px; line-height: 1; letter-spacing: -0.03em; }
    .rp-panel-sub { color: var(--muted); font-size: 14px; line-height: 1.45; }
    .rp-panel-body { padding: 0 22px 22px; }
    .rp-grid-2 { display: grid; grid-template-columns: repeat(2, minmax(0, 1fr)); gap: 12px; }
    .rp-grid-3 { display: grid; grid-template-columns: repeat(3, minmax(0, 1fr)); gap: 12px; }
    .rp-field { display: grid; gap: 6px; }
    .rp-field label { font-size: 11px; letter-spacing: 0.12em; text-transform: uppercase; color: var(--muted); font-weight: 800; }
    .rp-field input, .rp-field select { width: 100%; min-height: 48px; border: 1px solid var(--line); background: #fffaf5; color: var(--ink); border-radius: 16px; padding: 0 14px; font: inherit; }
    .rp-inline { display: inline-flex; align-items: center; gap: 10px; }
    .rp-inline input[type="checkbox"] { width: 18px; height: 18px; }
    .rp-btn { appearance: none; border: 0; min-height: 48px; border-radius: 18px; padding: 0 18px; display: inline-flex; align-items: center; justify-content: center; text-decoration: none; font-size: 14px; font-weight: 800; letter-spacing: 0.01em; cursor: pointer; }
    .rp-btn.primary { background: linear-gradient(135deg, #a15a2b, #8a4b21); color: #fff; box-shadow: 0 14px 28px rgba(161, 90, 43, 0.24); }
    .rp-btn.secondary { background: rgba(45,111,96,0.12); color: var(--accent-2); border: 1px solid rgba(45,111,96,0.14); }
    .rp-btn.ghost { background: rgba(0,0,0,0); color: var(--muted); border: 1px solid rgba(45,27,15,0.12); }
    .rp-btn.danger { background: rgba(147,58,50,0.12); color: var(--danger); border: 1px solid rgba(147,58,50,0.14); }
    .rp-table-list, .rp-rule-list, .rp-blackout-list { display: grid; gap: 12px; }
    .rp-row { display: grid; gap: 12px; padding: 14px 15px; border-radius: 20px; background: rgba(255,252,248,0.92); border: 1px solid rgba(45, 27, 15, 0.08); }
    .rp-row-head { display: flex; align-items: start; justify-content: space-between; gap: 12px; }
    .rp-row-title { font-size: 17px; font-weight: 800; line-height: 1.15; }
    .rp-row-meta { color: var(--muted); font-size: 13px; line-height: 1.45; }
    .rp-chip { display: inline-flex; align-items: center; min-height: 30px; padding: 0 12px; border-radius: 999px; background: rgba(45,111,96,0.12); color: var(--accent-2); font-size: 11px; text-transform: uppercase; letter-spacing: 0.1em; font-weight: 800; }
    .rp-chip.muted { background: rgba(45,27,15,0.08); color: var(--muted); }
    .rp-actions { display: flex; flex-wrap: wrap; gap: 8px; }
    .rp-flash { margin: 16px 0 0; padding: 12px 14px; border-radius: 16px; background: rgba(45,111,96,0.12); color: var(--accent-2); font-weight: 700; }
    @media (max-width: 980px) {
      .rp-hero-grid, .rp-layout { grid-template-columns: 1fr; }
      .rp-grid-2, .rp-grid-3, .rp-stats { grid-template-columns: 1fr; }
    }
  </style>
</head>
<body>
  <main class="rp-shell">
    <div class="rp-topbar">
      <div class="rp-brand">
        <span class="rp-brand-mark">RP</span>
        <span><?= htmlspecialchars($merchantLabel, ENT_QUOTES, 'UTF-8') ?></span>
      </div>
      <div class="rp-link-row">
        <a class="rp-link" href="/rp_reservations.php<?= htmlspecialchars($ownerQuery, ENT_QUOTES, 'UTF-8') ?>">Reservation planner</a>
        <a class="rp-link" href="/index.php">Back to app</a>
      </div>
    </div>

    <section class="rp-hero">
      <div class="rp-hero-grid">
        <div>
          <div class="rp-kicker">TapTray RP admin</div>
          <h1>Settings and inventory.</h1>
          <p class="rp-hero-copy">This is the operational layer behind the customer reservation planner. Define the tables, set weekly hours, and block out unavailable periods so availability stops defaulting to generic busy states.</p>
          <div class="rp-stats">
            <div class="rp-stat">
              <div class="rp-stat-label">Active tables</div>
              <div class="rp-stat-value"><?= htmlspecialchars((string)count($activeTables), ENT_QUOTES, 'UTF-8') ?></div>
            </div>
            <div class="rp-stat">
              <div class="rp-stat-label">Seat capacity</div>
              <div class="rp-stat-value"><?= htmlspecialchars((string)$totalSeats, ENT_QUOTES, 'UTF-8') ?></div>
            </div>
            <div class="rp-stat">
              <div class="rp-stat-label">Blackouts</div>
              <div class="rp-stat-value"><?= htmlspecialchars((string)count($blackouts), ENT_QUOTES, 'UTF-8') ?></div>
            </div>
          </div>
        </div>
        <div class="rp-note">
          <div class="rp-kicker" style="color:var(--muted)">Most important first</div>
          <strong>Add tables and capacities.</strong>
          <div style="margin-top:10px;color:var(--muted);line-height:1.5;">If RP has no usable table inventory or no weekly rules, customer slots will stay too blunt. Start with real table counts, then opening hours, then blackouts.</div>
          <?php if ($flash !== ''): ?>
            <div class="rp-flash"><?= htmlspecialchars(str_replace('_', ' ', $flash), ENT_QUOTES, 'UTF-8') ?></div>
          <?php endif; ?>
        </div>
      </div>
    </section>

    <section class="rp-layout">
      <div class="rp-stack">
        <section class="rp-panel">
          <div class="rp-panel-head">
            <div>
              <div class="rp-panel-title">Table inventory</div>
              <div class="rp-panel-sub">Define the tables the reservation planner can actually book against.</div>
            </div>
          </div>
          <div class="rp-panel-body">
            <form method="post" class="rp-grid-3" style="margin-bottom:18px;">
              <input type="hidden" name="action" value="add_table">
              <div class="rp-field">
                <label for="table_code">Code</label>
                <input id="table_code" name="table_code" placeholder="T1" required>
              </div>
              <div class="rp-field">
                <label for="label">Label</label>
                <input id="label" name="label" placeholder="Window 1" required>
              </div>
              <div class="rp-field">
                <label for="zone">Zone</label>
                <input id="zone" name="zone" placeholder="Main room">
              </div>
              <div class="rp-field">
                <label for="capacity_min">Min party</label>
                <input id="capacity_min" name="capacity_min" type="number" min="1" max="30" value="1" required>
              </div>
              <div class="rp-field">
                <label for="capacity_max">Max party</label>
                <input id="capacity_max" name="capacity_max" type="number" min="1" max="30" value="4" required>
              </div>
              <div class="rp-field">
                <label for="sort_order">Sort order</label>
                <input id="sort_order" name="sort_order" type="number" value="0">
              </div>
              <div>
                <button class="rp-btn primary" type="submit">Save table</button>
              </div>
            </form>

            <div class="rp-table-list">
              <?php if (!$tables): ?>
                <div class="rp-row">
                  <div class="rp-row-title">No tables yet</div>
                  <div class="rp-row-meta">Add your first table above. RP needs table capacity to calculate real availability.</div>
                </div>
              <?php else: ?>
                <?php foreach ($tables as $table): ?>
                  <div class="rp-row">
                    <div class="rp-row-head">
                      <div>
                        <div class="rp-row-title"><?= htmlspecialchars((string)$table['label'], ENT_QUOTES, 'UTF-8') ?></div>
                        <div class="rp-row-meta">
                          <?= htmlspecialchars((string)$table['table_code'], ENT_QUOTES, 'UTF-8') ?>
                          · <?= htmlspecialchars((string)($table['zone'] ?: 'No zone'), ENT_QUOTES, 'UTF-8') ?>
                          · <?= (int)$table['capacity_min'] ?>-<?= (int)$table['capacity_max'] ?> guests
                          · sort <?= (int)$table['sort_order'] ?>
                        </div>
                      </div>
                      <span class="rp-chip <?= (int)$table['is_active'] === 1 ? '' : 'muted' ?>">
                        <?= (int)$table['is_active'] === 1 ? 'active' : 'inactive' ?>
                      </span>
                    </div>
                    <div class="rp-actions">
                      <form method="post">
                        <input type="hidden" name="action" value="toggle_table">
                        <input type="hidden" name="table_id" value="<?= (int)$table['id'] ?>">
                        <input type="hidden" name="next_active" value="<?= (int)$table['is_active'] === 1 ? '0' : '1' ?>">
                        <button class="rp-btn ghost" type="submit"><?= (int)$table['is_active'] === 1 ? 'Archive' : 'Reactivate' ?></button>
                      </form>
                    </div>
                  </div>
                <?php endforeach; ?>
              <?php endif; ?>
            </div>
          </div>
        </section>

        <section class="rp-panel">
          <div class="rp-panel-head">
            <div>
              <div class="rp-panel-title">Weekly opening rules</div>
              <div class="rp-panel-sub">One row per weekday. Slot length and max party size live here too.</div>
            </div>
          </div>
          <div class="rp-panel-body">
            <div class="rp-rule-list">
              <?php foreach ($days as $dayNumber => $dayLabel): ?>
                <?php
                  $rule = $rules[$dayNumber] ?? null;
                  $open = substr((string)($rule['open_time'] ?? '17:00:00'), 0, 5);
                  $close = substr((string)($rule['close_time'] ?? '22:00:00'), 0, 5);
                  $slotMinutes = (int)($rule['slot_minutes'] ?? 30);
                  $maxPartySize = (int)($rule['max_party_size'] ?? 8);
                  $isClosed = (int)($rule['is_closed'] ?? 0) === 1;
                ?>
                <form method="post" class="rp-row">
                  <input type="hidden" name="action" value="save_rule">
                  <input type="hidden" name="day_of_week" value="<?= $dayNumber ?>">
                  <div class="rp-row-head">
                    <div>
                      <div class="rp-row-title"><?= htmlspecialchars($dayLabel, ENT_QUOTES, 'UTF-8') ?></div>
                      <div class="rp-row-meta">Default schedule for this weekday.</div>
                    </div>
                    <label class="rp-inline">
                      <input type="checkbox" name="is_closed" value="1" <?= $isClosed ? 'checked' : '' ?>>
                      <span>Closed</span>
                    </label>
                  </div>
                  <div class="rp-grid-3">
                    <div class="rp-field">
                      <label>Open</label>
                      <input type="time" name="open_time" value="<?= htmlspecialchars($open, ENT_QUOTES, 'UTF-8') ?>">
                    </div>
                    <div class="rp-field">
                      <label>Close</label>
                      <input type="time" name="close_time" value="<?= htmlspecialchars($close, ENT_QUOTES, 'UTF-8') ?>">
                    </div>
                    <div class="rp-field">
                      <label>Slot minutes</label>
                      <input type="number" name="slot_minutes" min="5" max="180" value="<?= $slotMinutes ?>">
                    </div>
                    <div class="rp-field">
                      <label>Max party size</label>
                      <input type="number" name="max_party_size" min="1" max="50" value="<?= $maxPartySize ?>">
                    </div>
                  </div>
                  <div><button class="rp-btn secondary" type="submit">Save day</button></div>
                </form>
              <?php endforeach; ?>
            </div>
          </div>
        </section>
      </div>

      <div class="rp-stack">
        <section class="rp-panel">
          <div class="rp-panel-head">
            <div>
              <div class="rp-panel-title">Blackouts</div>
              <div class="rp-panel-sub">Block holidays, private events, or short closures.</div>
            </div>
          </div>
          <div class="rp-panel-body">
            <form method="post" class="rp-grid-2" style="margin-bottom:18px;">
              <input type="hidden" name="action" value="add_blackout">
              <div class="rp-field">
                <label for="start_at">Start</label>
                <input id="start_at" name="start_at" type="datetime-local" required>
              </div>
              <div class="rp-field">
                <label for="end_at">End</label>
                <input id="end_at" name="end_at" type="datetime-local" required>
              </div>
              <div class="rp-field" style="grid-column: 1 / -1;">
                <label for="reason">Reason</label>
                <input id="reason" name="reason" placeholder="Private event, holiday, maintenance">
              </div>
              <div>
                <button class="rp-btn primary" type="submit">Add blackout</button>
              </div>
            </form>

            <div class="rp-blackout-list">
              <?php if (!$blackouts): ?>
                <div class="rp-row">
                  <div class="rp-row-title">No blackouts</div>
                  <div class="rp-row-meta">This is fine. Add them only when you need to close all or part of service.</div>
                </div>
              <?php else: ?>
                <?php foreach ($blackouts as $blackout): ?>
                  <div class="rp-row">
                    <div class="rp-row-head">
                      <div>
                        <div class="rp-row-title"><?= htmlspecialchars((string)($blackout['reason'] ?: 'Unavailable'), ENT_QUOTES, 'UTF-8') ?></div>
                        <div class="rp-row-meta">
                          <?= htmlspecialchars(date('Y-m-d H:i', strtotime((string)$blackout['start_at'])), ENT_QUOTES, 'UTF-8') ?>
                          →
                          <?= htmlspecialchars(date('Y-m-d H:i', strtotime((string)$blackout['end_at'])), ENT_QUOTES, 'UTF-8') ?>
                        </div>
                      </div>
                    </div>
                    <div class="rp-actions">
                      <form method="post">
                        <input type="hidden" name="action" value="delete_blackout">
                        <input type="hidden" name="blackout_id" value="<?= (int)$blackout['id'] ?>">
                        <button class="rp-btn danger" type="submit">Remove</button>
                      </form>
                    </div>
                  </div>
                <?php endforeach; ?>
              <?php endif; ?>
            </div>
          </div>
        </section>

        <section class="rp-panel">
          <div class="rp-panel-head">
            <div>
              <div class="rp-panel-title">What to configure first</div>
              <div class="rp-panel-sub">Suggested order so the customer planner starts behaving sensibly.</div>
            </div>
          </div>
          <div class="rp-panel-body">
            <div class="rp-row">
              <div class="rp-row-title">1. Tables and capacities</div>
              <div class="rp-row-meta">Without active tables, RP has no inventory to offer.</div>
            </div>
            <div class="rp-row">
              <div class="rp-row-title">2. Weekly opening rules</div>
              <div class="rp-row-meta">This defines the booking window, slot grid, and max party size per weekday.</div>
            </div>
            <div class="rp-row">
              <div class="rp-row-title">3. Blackouts</div>
              <div class="rp-row-meta">Use these for holidays, buyouts, short closures, or special events.</div>
            </div>
          </div>
        </section>
      </div>
    </section>
  </main>
</body>
</html>
