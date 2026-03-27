<?php
declare(strict_types=1);

require_once __DIR__ . '/includes/db_connect.php';
require_once __DIR__ . '/includes/functions.php';

sec_session_start();
header('Content-Type: text/html; charset=utf-8');

function rp_resolve_merchant_id(mysqli $mysqli): int {
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

    $memberId = (int)($_SESSION['user_id'] ?? 0);
    if ($memberId > 0) {
        return $memberId;
    }

    return max(0, (int)($_GET['merchant_id'] ?? 0));
}

function rp_fetch_merchant_label(mysqli $mysqli, int $merchantId): string {
    if ($merchantId <= 0) {
        return 'TapTray Reservations';
    }
    $stmt = $mysqli->prepare("SELECT COALESCE(NULLIF(display_name, ''), username) AS label FROM members WHERE id = ? LIMIT 1");
    if (!$stmt) {
        return 'TapTray Reservations';
    }
    $stmt->bind_param('i', $merchantId);
    $stmt->execute();
    $stmt->bind_result($label);
    $stmt->fetch();
    $stmt->close();
    $label = trim((string)$label);
    return $label !== '' ? $label : 'TapTray Reservations';
}

function rp_fetch_tables(mysqli $mysqli, int $merchantId): array {
    if ($merchantId <= 0) {
        return [];
    }
    $stmt = $mysqli->prepare("
        SELECT id, label, zone, capacity_min, capacity_max
        FROM rp_tables
        WHERE merchant_id = ? AND is_active = 1
        ORDER BY sort_order ASC, label ASC
    ");
    if (!$stmt) {
        return [];
    }
    $stmt->bind_param('i', $merchantId);
    $stmt->execute();
    $res = $stmt->get_result();
    $rows = [];
    while ($row = $res->fetch_assoc()) {
        $rows[] = [
            'id' => (int)$row['id'],
            'label' => trim((string)$row['label']),
            'zone' => trim((string)($row['zone'] ?? '')),
            'capacity_min' => (int)$row['capacity_min'],
            'capacity_max' => (int)$row['capacity_max'],
        ];
    }
    $stmt->close();
    return $rows;
}

function rp_fetch_day_rule(mysqli $mysqli, int $merchantId, string $dateYmd): ?array {
    if ($merchantId <= 0 || $dateYmd === '') {
        return null;
    }
    $dayOfWeek = (int)date('w', strtotime($dateYmd));
    $stmt = $mysqli->prepare("
        SELECT open_time, close_time, slot_minutes, max_party_size, is_closed
        FROM rp_availability_rules
        WHERE merchant_id = ? AND day_of_week = ?
        ORDER BY location_id IS NULL DESC, id ASC
        LIMIT 1
    ");
    if (!$stmt) {
        return null;
    }
    $stmt->bind_param('ii', $merchantId, $dayOfWeek);
    $stmt->execute();
    $res = $stmt->get_result();
    $row = $res ? $res->fetch_assoc() : null;
    $stmt->close();
    if (!$row) {
        return null;
    }
    return [
        'open_time' => (string)$row['open_time'],
        'close_time' => (string)$row['close_time'],
        'slot_minutes' => max(5, (int)$row['slot_minutes']),
        'max_party_size' => $row['max_party_size'] !== null ? (int)$row['max_party_size'] : null,
        'is_closed' => (int)$row['is_closed'] === 1,
    ];
}

function rp_fetch_day_blackouts(mysqli $mysqli, int $merchantId, string $dateYmd): array {
    if ($merchantId <= 0 || $dateYmd === '') {
        return [];
    }
    $start = $dateYmd . ' 00:00:00';
    $end = $dateYmd . ' 23:59:59';
    $stmt = $mysqli->prepare("
        SELECT start_at, end_at, reason
        FROM rp_blackouts
        WHERE merchant_id = ?
          AND start_at <= ?
          AND end_at >= ?
        ORDER BY start_at ASC
    ");
    if (!$stmt) {
        return [];
    }
    $stmt->bind_param('iss', $merchantId, $end, $start);
    $stmt->execute();
    $res = $stmt->get_result();
    $rows = [];
    while ($row = $res->fetch_assoc()) {
        $rows[] = [
            'start_at' => (string)$row['start_at'],
            'end_at' => (string)$row['end_at'],
            'reason' => trim((string)($row['reason'] ?? 'Unavailable')),
        ];
    }
    $stmt->close();
    return $rows;
}

function rp_fetch_reservations(mysqli $mysqli, int $merchantId, string $dateYmd): array {
    if ($merchantId <= 0 || $dateYmd === '') {
        return [];
    }
    $stmt = $mysqli->prepare("
        SELECT
            r.id,
            r.reservation_ref,
            r.customer_name,
            r.party_size,
            r.start_time,
            r.end_time,
            r.status,
            r.notes,
            GROUP_CONCAT(t.label ORDER BY t.label SEPARATOR ', ') AS table_labels
        FROM rp_reservations r
        LEFT JOIN rp_reservation_tables rt ON rt.reservation_id = r.id
        LEFT JOIN rp_tables t ON t.id = rt.table_id
        WHERE r.merchant_id = ?
          AND r.reservation_date = ?
          AND r.status IN ('new','confirmed','arrived','seated')
        GROUP BY r.id
        ORDER BY r.start_time ASC, r.id ASC
    ");
    if (!$stmt) {
        return [];
    }
    $stmt->bind_param('is', $merchantId, $dateYmd);
    $stmt->execute();
    $res = $stmt->get_result();
    $rows = [];
    while ($row = $res->fetch_assoc()) {
        $rows[] = [
            'id' => (int)$row['id'],
            'reservation_ref' => (string)$row['reservation_ref'],
            'customer_name' => (string)$row['customer_name'],
            'party_size' => (int)$row['party_size'],
            'start_time' => (string)$row['start_time'],
            'end_time' => (string)($row['end_time'] ?? ''),
            'status' => (string)$row['status'],
            'notes' => trim((string)($row['notes'] ?? '')),
            'table_labels' => trim((string)($row['table_labels'] ?? '')),
        ];
    }
    $stmt->close();
    return $rows;
}

function rp_time_label(string $time): string {
    $ts = strtotime($time);
    return $ts ? date('H:i', $ts) : $time;
}

function rp_build_slots(string $dateYmd, ?array $rule, array $reservations, array $blackouts, int $tableCount): array {
    $openTime = $rule['open_time'] ?? '17:00:00';
    $closeTime = $rule['close_time'] ?? '22:00:00';
    $slotMinutes = max(15, (int)($rule['slot_minutes'] ?? 30));
    $openTs = strtotime($dateYmd . ' ' . $openTime);
    $closeTs = strtotime($dateYmd . ' ' . $closeTime);
    if (!$openTs || !$closeTs || $closeTs <= $openTs) {
        $openTs = strtotime($dateYmd . ' 17:00:00');
        $closeTs = strtotime($dateYmd . ' 22:00:00');
    }

    $slots = [];
    for ($ts = $openTs; $ts < $closeTs; $ts += $slotMinutes * 60) {
        $slotEnd = $ts + $slotMinutes * 60;
        $busyCount = 0;
        foreach ($reservations as $reservation) {
            $resStart = strtotime($dateYmd . ' ' . $reservation['start_time']);
            $resEnd = $reservation['end_time'] !== ''
                ? strtotime($dateYmd . ' ' . $reservation['end_time'])
                : ($resStart + 90 * 60);
            if ($resStart < $slotEnd && $resEnd > $ts) {
                $busyCount++;
            }
        }

        $blackoutReason = '';
        foreach ($blackouts as $blackout) {
            $blackoutStart = strtotime((string)$blackout['start_at']);
            $blackoutEnd = strtotime((string)$blackout['end_at']);
            if ($blackoutStart < $slotEnd && $blackoutEnd > $ts) {
                $blackoutReason = (string)$blackout['reason'];
                break;
            }
        }

        $availableCount = max(0, $tableCount - $busyCount);
        $status = $blackoutReason !== '' ? 'blocked' : ($availableCount > 0 ? 'open' : 'busy');
        $slots[] = [
            'label' => date('H:i', $ts),
            'status' => $status,
            'caption' => $blackoutReason !== ''
                ? $blackoutReason
                : ($status === 'open' ? ($availableCount . ' tables open') : 'Peak time'),
        ];
    }

    return $slots;
}

$merchantId = rp_resolve_merchant_id($mysqli);
$merchantLabel = rp_fetch_merchant_label($mysqli, $merchantId);
$selectedDate = trim((string)($_GET['date'] ?? date('Y-m-d')));
if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $selectedDate)) {
    $selectedDate = date('Y-m-d');
}
$partySize = max(1, min(12, (int)($_GET['party_size'] ?? 2)));
$selectedTime = trim((string)($_GET['time'] ?? ''));
if (!preg_match('/^\d{2}:\d{2}$/', $selectedTime)) {
    $selectedTime = '';
}
$tables = rp_fetch_tables($mysqli, $merchantId);
$reservations = rp_fetch_reservations($mysqli, $merchantId, $selectedDate);
$dayRule = rp_fetch_day_rule($mysqli, $merchantId, $selectedDate);
$blackouts = rp_fetch_day_blackouts($mysqli, $merchantId, $selectedDate);
$slotGrid = rp_build_slots($selectedDate, $dayRule, $reservations, $blackouts, count($tables));
$maxParty = (int)($dayRule['max_party_size'] ?? 12);
$partySize = min($partySize, max(1, $maxParty > 0 ? $maxParty : 12));
$openLabel = rp_time_label((string)($dayRule['open_time'] ?? '17:00:00'));
$closeLabel = rp_time_label((string)($dayRule['close_time'] ?? '22:00:00'));
$isClosedDay = (bool)($dayRule['is_closed'] ?? false);
$dateOptions = [];
for ($i = 0; $i < 10; $i++) {
    $date = date('Y-m-d', strtotime('+' . $i . ' day', strtotime($selectedDate === date('Y-m-d') ? 'today' : $selectedDate)));
    $dateOptions[] = [
        'value' => $date,
        'weekday' => date('D', strtotime($date)),
        'day' => date('j', strtotime($date)),
        'month' => date('M', strtotime($date)),
    ];
}
$heroStats = [
    'tables' => count($tables),
    'bookings' => count($reservations),
    'window' => $openLabel . ' - ' . $closeLabel,
];
$ownerQuery = '';
if (isset($_GET['owner']) && trim((string)$_GET['owner']) !== '') {
    $ownerQuery = '?owner=' . rawurlencode(trim((string)$_GET['owner']));
}
?>
<!doctype html>
<html lang="en">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title><?= htmlspecialchars($merchantLabel, ENT_QUOTES, 'UTF-8') ?> Reservations</title>
  <style>
    :root {
      --bg: #f7f1e7;
      --bg-2: #efe6d6;
      --ink: #241810;
      --muted: #6f6254;
      --line: rgba(45, 27, 15, 0.12);
      --panel: rgba(255, 252, 247, 0.82);
      --panel-strong: rgba(255, 250, 242, 0.94);
      --accent: #a15a2b;
      --accent-2: #2d6f60;
      --warm: #d7a96b;
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
        radial-gradient(circle at 100% 20%, rgba(45, 111, 96, 0.16), transparent 24%),
        linear-gradient(180deg, #f9f4ec 0%, var(--bg) 46%, var(--bg-2) 100%);
    }
    a { color: inherit; }
    .rp-shell {
      width: min(1180px, calc(100vw - 28px));
      margin: 0 auto;
      padding: 22px 0 44px;
    }
    .rp-topbar {
      display: flex;
      align-items: center;
      justify-content: space-between;
      gap: 16px;
      margin-bottom: 18px;
    }
    .rp-brand {
      display: inline-flex;
      align-items: center;
      gap: 12px;
      color: var(--muted);
      font-size: 13px;
      letter-spacing: 0.16em;
      text-transform: uppercase;
      font-weight: 800;
    }
    .rp-brand-mark {
      width: 42px;
      height: 42px;
      border-radius: 16px;
      background: linear-gradient(135deg, #2d6f60, #a15a2b);
      color: #fff;
      display: inline-flex;
      align-items: center;
      justify-content: center;
      font-weight: 900;
      box-shadow: 0 10px 26px rgba(45, 111, 96, 0.24);
    }
    .rp-link {
      display: inline-flex;
      align-items: center;
      gap: 8px;
      text-decoration: none;
      padding: 10px 14px;
      border-radius: 999px;
      background: rgba(255,255,255,0.66);
      border: 1px solid rgba(45, 27, 15, 0.08);
      box-shadow: 0 8px 24px rgba(36, 24, 16, 0.08);
      font-weight: 700;
    }
    .rp-hero {
      position: relative;
      overflow: hidden;
      border-radius: 34px;
      padding: 28px;
      background:
        linear-gradient(130deg, rgba(37, 22, 15, 0.9), rgba(61, 37, 24, 0.76)),
        linear-gradient(140deg, rgba(161, 90, 43, 0.36), rgba(45, 111, 96, 0.28));
      color: #fff8f2;
      box-shadow: var(--shadow);
    }
    .rp-hero::before {
      content: "";
      position: absolute;
      inset: auto -60px -80px auto;
      width: 320px;
      height: 320px;
      border-radius: 50%;
      background: radial-gradient(circle, rgba(215,169,107,0.42), transparent 64%);
      pointer-events: none;
    }
    .rp-hero-grid {
      position: relative;
      display: grid;
      grid-template-columns: minmax(0, 1.2fr) minmax(300px, 0.8fr);
      gap: 22px;
      align-items: stretch;
    }
    .rp-kicker {
      font-size: 12px;
      letter-spacing: 0.16em;
      text-transform: uppercase;
      color: rgba(255, 244, 228, 0.78);
      font-weight: 800;
    }
    .rp-hero h1 {
      margin: 10px 0 12px;
      font-family: var(--font-display);
      font-size: clamp(34px, 5vw, 62px);
      line-height: 0.95;
      letter-spacing: -0.04em;
    }
    .rp-hero-copy {
      max-width: 56ch;
      color: rgba(255, 244, 228, 0.82);
      font-size: 16px;
      line-height: 1.55;
      margin: 0;
    }
    .rp-hero-stats {
      margin-top: 22px;
      display: grid;
      grid-template-columns: repeat(3, minmax(0, 1fr));
      gap: 12px;
    }
    .rp-stat {
      padding: 14px 16px;
      border-radius: 20px;
      background: rgba(255, 249, 241, 0.09);
      border: 1px solid rgba(255, 244, 228, 0.12);
      backdrop-filter: blur(8px);
    }
    .rp-stat-label {
      font-size: 11px;
      letter-spacing: 0.12em;
      text-transform: uppercase;
      color: rgba(255, 244, 228, 0.68);
      font-weight: 700;
    }
    .rp-stat-value {
      margin-top: 8px;
      font-size: 24px;
      font-weight: 800;
      line-height: 1;
    }
    .rp-intake {
      align-self: end;
      padding: 18px;
      border-radius: 26px;
      background: rgba(255, 250, 244, 0.94);
      color: var(--ink);
      border: 1px solid rgba(255,255,255,0.18);
      box-shadow: 0 20px 42px rgba(28, 18, 11, 0.18);
    }
    .rp-intake-title {
      font-size: 14px;
      letter-spacing: 0.12em;
      text-transform: uppercase;
      color: var(--muted);
      font-weight: 800;
    }
    .rp-intake strong {
      display: block;
      margin-top: 4px;
      font-size: 24px;
      line-height: 1.05;
      font-family: var(--font-display);
    }
    .rp-intake-grid {
      margin-top: 14px;
      display: grid;
      grid-template-columns: repeat(2, minmax(0, 1fr));
      gap: 12px;
    }
    .rp-field {
      display: grid;
      gap: 6px;
    }
    .rp-field span {
      font-size: 11px;
      letter-spacing: 0.12em;
      text-transform: uppercase;
      color: var(--muted);
      font-weight: 800;
    }
    .rp-field input,
    .rp-field select {
      width: 100%;
      border: 1px solid var(--line);
      background: #fffaf5;
      color: var(--ink);
      border-radius: 16px;
      min-height: 48px;
      padding: 0 14px;
      font: inherit;
      box-shadow: inset 0 1px 0 rgba(255,255,255,0.8);
    }
    .rp-intake-note {
      margin: 12px 0 0;
      color: var(--muted);
      font-size: 13px;
      line-height: 1.45;
    }
    .rp-layout {
      margin-top: 20px;
      display: grid;
      grid-template-columns: minmax(0, 1.15fr) minmax(320px, 0.85fr);
      gap: 18px;
      align-items: start;
    }
    .rp-panel {
      background: var(--panel);
      border: 1px solid rgba(45, 27, 15, 0.08);
      border-radius: var(--radius);
      box-shadow: 0 14px 36px rgba(38, 24, 16, 0.08);
      backdrop-filter: blur(10px);
    }
    .rp-panel-head {
      display: flex;
      align-items: center;
      justify-content: space-between;
      gap: 12px;
      padding: 20px 22px 14px;
    }
    .rp-panel-title {
      font-family: var(--font-display);
      font-size: 28px;
      line-height: 1;
      letter-spacing: -0.03em;
    }
    .rp-panel-sub {
      color: var(--muted);
      font-size: 14px;
      line-height: 1.45;
    }
    .rp-date-row {
      display: grid;
      grid-template-columns: repeat(5, minmax(0, 1fr));
      gap: 10px;
      padding: 0 22px 20px;
    }
    .rp-date-chip {
      appearance: none;
      border: 1px solid rgba(45, 27, 15, 0.08);
      border-radius: 20px;
      background: rgba(255,255,255,0.86);
      text-decoration: none;
      color: inherit;
      padding: 12px 10px;
      display: grid;
      gap: 5px;
      text-align: center;
      box-shadow: inset 0 1px 0 rgba(255,255,255,0.9);
    }
    .rp-date-chip.is-active {
      background: linear-gradient(180deg, #2d6f60, #24574c);
      color: #fff;
      border-color: rgba(45,111,96,0.4);
    }
    .rp-date-chip,
    .rp-slot {
      transition: transform 140ms ease, box-shadow 140ms ease, border-color 140ms ease, background 140ms ease, opacity 140ms ease;
    }
    .rp-date-chip:hover,
    .rp-slot:hover {
      transform: translateY(-1px);
    }
    .rp-date-chip-weekday {
      font-size: 11px;
      text-transform: uppercase;
      letter-spacing: 0.1em;
      font-weight: 800;
      opacity: 0.78;
    }
    .rp-date-chip-day {
      font-size: 28px;
      line-height: 1;
      font-family: var(--font-display);
    }
    .rp-date-chip-month {
      font-size: 12px;
      font-weight: 700;
      opacity: 0.88;
    }
    .rp-slots {
      display: grid;
      grid-template-columns: repeat(3, minmax(0, 1fr));
      gap: 12px;
      padding: 0 22px 22px;
    }
    .rp-slot {
      appearance: none;
      border-radius: 22px;
      padding: 15px 16px;
      border: 1px solid rgba(45, 27, 15, 0.08);
      background: rgba(255, 252, 248, 0.94);
      display: grid;
      gap: 7px;
      text-align: left;
      box-shadow: inset 0 1px 0 rgba(255,255,255,0.92);
      color: inherit;
      text-decoration: none;
    }
    .rp-slot.is-open {
      border-color: rgba(45,111,96,0.2);
      background: linear-gradient(180deg, rgba(233,247,242,0.96), rgba(255,252,248,0.98));
    }
    .rp-slot.is-busy {
      border-color: rgba(161, 90, 43, 0.18);
      background: linear-gradient(180deg, rgba(255,244,236,0.96), rgba(255,252,248,0.98));
    }
    .rp-slot.is-blocked {
      border-color: rgba(147, 58, 50, 0.16);
      background: linear-gradient(180deg, rgba(251,239,236,0.96), rgba(255,252,248,0.98));
    }
    .rp-slot.is-selected {
      border-color: rgba(45,111,96,0.42);
      background: linear-gradient(180deg, rgba(45,111,96,0.16), rgba(255,252,248,0.98));
      box-shadow: 0 14px 28px rgba(45,111,96,0.18), inset 0 1px 0 rgba(255,255,255,0.92);
      transform: translateY(-1px);
    }
    .rp-slot.is-disabled {
      pointer-events: none;
      opacity: 0.78;
    }
    .rp-slot-time {
      font-size: 24px;
      line-height: 1;
      font-weight: 800;
      letter-spacing: -0.03em;
    }
    .rp-slot-caption {
      color: var(--muted);
      font-size: 13px;
      line-height: 1.35;
    }
    .rp-slot-badge {
      justify-self: start;
      display: inline-flex;
      align-items: center;
      min-height: 28px;
      padding: 0 10px;
      border-radius: 999px;
      font-size: 11px;
      text-transform: uppercase;
      letter-spacing: 0.1em;
      font-weight: 800;
      background: rgba(45, 111, 96, 0.12);
      color: var(--accent-2);
    }
    .rp-slot.is-busy .rp-slot-badge {
      background: rgba(161, 90, 43, 0.12);
      color: var(--accent);
    }
    .rp-slot.is-blocked .rp-slot-badge {
      background: rgba(147, 58, 50, 0.12);
      color: var(--danger);
    }
    .rp-right-col {
      display: grid;
      gap: 18px;
    }
    .rp-summary {
      padding: 22px;
    }
    .rp-summary-card {
      padding: 16px 16px 18px;
      border-radius: 22px;
      background: linear-gradient(180deg, #fffaf4, #fffdf9);
      border: 1px solid rgba(45, 27, 15, 0.08);
    }
    .rp-summary-card + .rp-summary-card {
      margin-top: 12px;
    }
    .rp-summary-label {
      font-size: 11px;
      letter-spacing: 0.1em;
      text-transform: uppercase;
      color: var(--muted);
      font-weight: 800;
    }
    .rp-summary-value {
      margin-top: 7px;
      font-size: 26px;
      line-height: 1.05;
      letter-spacing: -0.03em;
      font-weight: 800;
      font-family: var(--font-display);
    }
    .rp-summary-copy {
      margin-top: 8px;
      color: var(--muted);
      font-size: 14px;
      line-height: 1.45;
    }
    .rp-cta {
      margin-top: 16px;
      display: grid;
      gap: 10px;
    }
    .rp-btn {
      appearance: none;
      border: 0;
      min-height: 52px;
      border-radius: 18px;
      padding: 0 18px;
      display: inline-flex;
      align-items: center;
      justify-content: center;
      text-decoration: none;
      font-size: 15px;
      font-weight: 800;
      letter-spacing: 0.01em;
      cursor: pointer;
    }
    .rp-btn.primary {
      background: linear-gradient(135deg, #a15a2b, #8a4b21);
      color: #fff;
      box-shadow: 0 14px 28px rgba(161, 90, 43, 0.24);
    }
    .rp-btn.secondary {
      background: rgba(45,111,96,0.12);
      color: var(--accent-2);
      border: 1px solid rgba(45,111,96,0.14);
    }
    .rp-today {
      padding: 22px;
    }
    .rp-booking-list {
      display: grid;
      gap: 10px;
      margin-top: 14px;
    }
    .rp-booking {
      display: grid;
      gap: 10px;
      padding: 14px 15px;
      border-radius: 20px;
      background: rgba(255,252,248,0.92);
      border: 1px solid rgba(45, 27, 15, 0.08);
    }
    .rp-booking-head {
      display: flex;
      align-items: start;
      justify-content: space-between;
      gap: 12px;
    }
    .rp-booking-time {
      font-size: 22px;
      line-height: 1;
      font-weight: 800;
      letter-spacing: -0.03em;
    }
    .rp-booking-status {
      display: inline-flex;
      align-items: center;
      min-height: 30px;
      padding: 0 12px;
      border-radius: 999px;
      background: rgba(45,111,96,0.12);
      color: var(--accent-2);
      font-size: 11px;
      text-transform: uppercase;
      letter-spacing: 0.1em;
      font-weight: 800;
    }
    .rp-booking-name {
      font-size: 17px;
      font-weight: 800;
      line-height: 1.15;
    }
    .rp-booking-meta {
      color: var(--muted);
      font-size: 13px;
      line-height: 1.45;
    }
    .rp-empty {
      margin-top: 14px;
      padding: 16px;
      border-radius: 18px;
      background: rgba(255,255,255,0.76);
      border: 1px dashed rgba(45, 27, 15, 0.16);
      color: var(--muted);
      font-size: 14px;
      line-height: 1.5;
    }
    .rp-shell.is-loading {
      opacity: 0.72;
    }
    .rp-shell.is-loading .rp-date-chip,
    .rp-shell.is-loading .rp-slot {
      pointer-events: none;
    }
    @media (max-width: 980px) {
      .rp-hero-grid,
      .rp-layout {
        grid-template-columns: 1fr;
      }
      .rp-date-row {
        grid-template-columns: repeat(5, minmax(88px, 1fr));
        overflow-x: auto;
        padding-bottom: 18px;
      }
      .rp-slots {
        grid-template-columns: repeat(2, minmax(0, 1fr));
      }
    }
    @media (max-width: 640px) {
      .rp-shell {
        width: min(100vw - 18px, 100%);
        padding-top: 14px;
      }
      .rp-hero,
      .rp-panel,
      .rp-intake {
        border-radius: 24px;
      }
      .rp-hero {
        padding: 20px;
      }
      .rp-hero-stats,
      .rp-intake-grid,
      .rp-slots {
        grid-template-columns: 1fr;
      }
      .rp-date-row {
        grid-template-columns: repeat(5, minmax(80px, 1fr));
      }
      .rp-panel-head {
        padding: 18px 18px 12px;
      }
      .rp-date-row,
      .rp-slots {
        padding-left: 18px;
        padding-right: 18px;
      }
      .rp-summary,
      .rp-today {
        padding: 18px;
      }
    }
  </style>
</head>
<body>
  <main class="rp-shell" id="rpShell">
    <div class="rp-topbar">
      <div class="rp-brand">
        <span class="rp-brand-mark">TT</span>
        <span><?= htmlspecialchars($merchantLabel, ENT_QUOTES, 'UTF-8') ?></span>
      </div>
      <div style="display:inline-flex;gap:10px;flex-wrap:wrap;">
        <a class="rp-link" href="/rp_settings.php<?= htmlspecialchars($ownerQuery, ENT_QUOTES, 'UTF-8') ?>">RP settings</a>
        <a class="rp-link" href="/">Back to menu</a>
      </div>
    </div>

    <section class="rp-hero">
      <div class="rp-hero-grid">
        <div>
          <div class="rp-kicker">Reservations Planner</div>
          <h1>Book a table with the same ease as ordering.</h1>
          <p class="rp-hero-copy">Pick a day, choose your party size, and move straight into the moments that still feel open. The page is tuned for quick customer booking, not a heavy admin workflow.</p>
          <div class="rp-hero-stats">
            <div class="rp-stat">
              <div class="rp-stat-label">Tables</div>
              <div class="rp-stat-value"><?= htmlspecialchars((string)$heroStats['tables'], ENT_QUOTES, 'UTF-8') ?></div>
            </div>
            <div class="rp-stat">
              <div class="rp-stat-label">Bookings today</div>
              <div class="rp-stat-value"><?= htmlspecialchars((string)$heroStats['bookings'], ENT_QUOTES, 'UTF-8') ?></div>
            </div>
            <div class="rp-stat">
              <div class="rp-stat-label">Serving window</div>
              <div class="rp-stat-value"><?= htmlspecialchars((string)$heroStats['window'], ENT_QUOTES, 'UTF-8') ?></div>
            </div>
          </div>
        </div>

        <aside class="rp-intake">
          <div class="rp-intake-title">Quick setup</div>
          <strong>Your reservation details</strong>
          <form method="get" action="/rp_reservations.php" id="rpIntakeForm">
            <?php if (!empty($_GET['owner'])): ?>
              <input type="hidden" name="owner" value="<?= htmlspecialchars((string)$_GET['owner'], ENT_QUOTES, 'UTF-8') ?>">
            <?php endif; ?>
            <?php if ($merchantId > 0): ?>
              <input type="hidden" name="merchant_id" value="<?= htmlspecialchars((string)$merchantId, ENT_QUOTES, 'UTF-8') ?>">
            <?php endif; ?>
            <div class="rp-intake-grid">
              <label class="rp-field">
                <span>Date</span>
                <input type="date" name="date" value="<?= htmlspecialchars($selectedDate, ENT_QUOTES, 'UTF-8') ?>">
              </label>
              <label class="rp-field">
                <span>Party size</span>
                <select name="party_size">
                  <?php for ($size = 1; $size <= max(2, $maxParty); $size++): ?>
                    <option value="<?= $size ?>"<?= $partySize === $size ? ' selected' : '' ?>><?= $size ?> guest<?= $size === 1 ? '' : 's' ?></option>
                  <?php endfor; ?>
                </select>
              </label>
            </div>
            <div class="rp-cta">
              <button class="rp-btn primary" type="submit">Refresh availability</button>
            </div>
          </form>
          <p class="rp-intake-note"><?= $isClosedDay ? 'This day is currently marked closed in your availability rules.' : 'This first version reads your reservation tables, opening rules, and existing bookings.' ?></p>
        </aside>
      </div>
    </section>

    <section class="rp-layout">
      <section class="rp-panel">
        <div class="rp-panel-head">
          <div>
            <div class="rp-panel-title">Choose a time</div>
            <div class="rp-panel-sub">A customer-first layout: dates first, then the strongest open slots.</div>
          </div>
        </div>

        <div class="rp-date-row" id="rpDateRow">
          <?php foreach ($dateOptions as $option): ?>
            <?php
              $isActive = $option['value'] === $selectedDate;
              $href = '/rp_reservations.php?date=' . rawurlencode($option['value']) . '&party_size=' . rawurlencode((string)$partySize);
              if (!empty($_GET['owner'])) {
                  $href .= '&owner=' . rawurlencode((string)$_GET['owner']);
              }
              if ($merchantId > 0) {
                  $href .= '&merchant_id=' . rawurlencode((string)$merchantId);
              }
            ?>
            <a class="rp-date-chip<?= $isActive ? ' is-active' : '' ?>" href="<?= htmlspecialchars($href, ENT_QUOTES, 'UTF-8') ?>">
              <span class="rp-date-chip-weekday"><?= htmlspecialchars($option['weekday'], ENT_QUOTES, 'UTF-8') ?></span>
              <span class="rp-date-chip-day"><?= htmlspecialchars((string)$option['day'], ENT_QUOTES, 'UTF-8') ?></span>
              <span class="rp-date-chip-month"><?= htmlspecialchars($option['month'], ENT_QUOTES, 'UTF-8') ?></span>
            </a>
          <?php endforeach; ?>
        </div>

        <div class="rp-slots" id="rpSlots">
          <?php foreach ($slotGrid as $slot): ?>
            <?php
              $slotLabel = (string)$slot['label'];
              $isOpenSlot = (string)$slot['status'] === 'open';
              $isSelectedSlot = $isOpenSlot && $selectedTime === $slotLabel;
              $slotHref = '/rp_reservations.php?date=' . rawurlencode($selectedDate) . '&party_size=' . rawurlencode((string)$partySize) . '&time=' . rawurlencode($slotLabel);
              if (!empty($_GET['owner'])) {
                  $slotHref .= '&owner=' . rawurlencode((string)$_GET['owner']);
              }
              if ($merchantId > 0) {
                  $slotHref .= '&merchant_id=' . rawurlencode((string)$merchantId);
              }
            ?>
            <a class="rp-slot is-<?= htmlspecialchars($slot['status'], ENT_QUOTES, 'UTF-8') ?><?= $isSelectedSlot ? ' is-selected' : '' ?><?= !$isOpenSlot ? ' is-disabled' : '' ?>" href="<?= $isOpenSlot ? htmlspecialchars($slotHref, ENT_QUOTES, 'UTF-8') : '#' ?>"<?= $isSelectedSlot ? ' aria-current="true"' : '' ?>>
              <div class="rp-slot-time"><?= htmlspecialchars($slotLabel, ENT_QUOTES, 'UTF-8') ?></div>
              <div class="rp-slot-badge"><?= htmlspecialchars($slot['status'], ENT_QUOTES, 'UTF-8') ?></div>
              <div class="rp-slot-caption"><?= htmlspecialchars($slot['caption'], ENT_QUOTES, 'UTF-8') ?></div>
            </a>
          <?php endforeach; ?>
        </div>
      </section>

      <aside class="rp-right-col">
        <section class="rp-panel rp-summary" id="rpSummaryPanel">
          <div class="rp-panel-title">Reservation feel</div>
          <div class="rp-panel-sub">This side is meant to reassure the guest and keep the booking choice obvious.</div>

          <div class="rp-summary-card">
            <div class="rp-summary-label">Selected reservation</div>
            <div class="rp-summary-value"><?= htmlspecialchars(date('l, j M', strtotime($selectedDate)), ENT_QUOTES, 'UTF-8') ?></div>
            <div class="rp-summary-copy">
              Party of <?= htmlspecialchars((string)$partySize, ENT_QUOTES, 'UTF-8') ?>.
              <?= $selectedTime !== '' ? 'Selected time ' . htmlspecialchars($selectedTime, ENT_QUOTES, 'UTF-8') . '.' : 'Choose a time below to continue.' ?>
              Serving window <?= htmlspecialchars($openLabel, ENT_QUOTES, 'UTF-8') ?> to <?= htmlspecialchars($closeLabel, ENT_QUOTES, 'UTF-8') ?>.
            </div>
          </div>

          <div class="rp-summary-card">
            <div class="rp-summary-label">Guest experience</div>
            <div class="rp-summary-value">Fast, clear, elegant.</div>
            <div class="rp-summary-copy">No cramped admin controls on the customer side. The page should feel closer to a boutique booking experience than a spreadsheet.</div>
          </div>

          <div class="rp-cta">
            <a class="rp-btn primary" href="#availability"><?= $selectedTime !== '' ? 'Continue with ' . htmlspecialchars($selectedTime, ENT_QUOTES, 'UTF-8') : 'Choose a time first' ?></a>
            <a class="rp-btn secondary" href="/">Back to menu</a>
          </div>
        </section>

        <section class="rp-panel rp-today" id="availability">
          <div id="rpFloorPanel">
          <div class="rp-panel-title">Today’s floor</div>
          <div class="rp-panel-sub">Live reservations already on the books for the selected day.</div>

          <?php if ($reservations): ?>
            <div class="rp-booking-list">
              <?php foreach ($reservations as $reservation): ?>
                <article class="rp-booking">
                  <div class="rp-booking-head">
                    <div class="rp-booking-time"><?= htmlspecialchars(rp_time_label($reservation['start_time']), ENT_QUOTES, 'UTF-8') ?></div>
                    <div class="rp-booking-status"><?= htmlspecialchars($reservation['status'], ENT_QUOTES, 'UTF-8') ?></div>
                  </div>
                  <div class="rp-booking-name"><?= htmlspecialchars($reservation['customer_name'], ENT_QUOTES, 'UTF-8') ?></div>
                  <div class="rp-booking-meta">
                    Party of <?= htmlspecialchars((string)$reservation['party_size'], ENT_QUOTES, 'UTF-8') ?>
                    <?php if ($reservation['table_labels'] !== ''): ?>
                      · <?= htmlspecialchars($reservation['table_labels'], ENT_QUOTES, 'UTF-8') ?>
                    <?php endif; ?>
                    <?php if ($reservation['notes'] !== ''): ?>
                      · <?= htmlspecialchars($reservation['notes'], ENT_QUOTES, 'UTF-8') ?>
                    <?php endif; ?>
                  </div>
                </article>
              <?php endforeach; ?>
            </div>
          <?php else: ?>
            <div class="rp-empty">No active reservations are stored yet for <?= htmlspecialchars(date('j M Y', strtotime($selectedDate)), ENT_QUOTES, 'UTF-8') ?>. That makes this a good first day to shape the customer flow and copy.</div>
          <?php endif; ?>
          </div>
        </section>
      </aside>
    </section>
  </main>
  <script>
    (function () {
      const shell = document.getElementById("rpShell");
      const form = document.getElementById("rpIntakeForm");
      if (!shell || !form) return;

      const SECTION_IDS = ["rpDateRow", "rpSlots", "rpSummaryPanel", "rpFloorPanel"];

      async function updatePlanner(url, options = {}) {
        if (shell.dataset.loading === "1") return;
        shell.dataset.loading = "1";
        shell.classList.add("is-loading");

        try {
          const res = await fetch(url, {
            method: "GET",
            headers: { "X-Requested-With": "fetch" },
            cache: "no-store"
          });
          if (!res.ok) throw new Error(`HTTP ${res.status}`);

          const html = await res.text();
          const doc = new DOMParser().parseFromString(html, "text/html");
          SECTION_IDS.forEach((id) => {
            const next = doc.getElementById(id);
            const current = document.getElementById(id);
            if (next && current) current.replaceWith(next);
          });

          const nextTitle = doc.querySelector("title");
          if (nextTitle) document.title = nextTitle.textContent || document.title;
          if (!options.skipHistory) {
            window.history.pushState({}, "", url);
          }
        } catch (err) {
          console.error("RP partial update failed:", err);
          window.location.href = url;
          return;
        } finally {
          shell.dataset.loading = "0";
          shell.classList.remove("is-loading");
        }
      }

      document.addEventListener("click", (event) => {
        const link = event.target.closest("#rpDateRow a.rp-date-chip, #rpSlots a.rp-slot");
        if (!link) return;
        const href = link.getAttribute("href");
        if (!href || href === "#") return;
        event.preventDefault();
        updatePlanner(href);
      });

      form.addEventListener("submit", (event) => {
        event.preventDefault();
        const params = new URLSearchParams(new FormData(form));
        const url = form.action + "?" + params.toString();
        updatePlanner(url);
      });

      window.addEventListener("popstate", () => {
        updatePlanner(window.location.href, { skipHistory: true });
      });
    })();
  </script>
</body>
</html>
