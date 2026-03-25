<?php
require_once __DIR__ . '/includes/functions.php';
require_once __DIR__ . '/includes/db_connect.php';
require_once __DIR__ . '/includes/sub_plans.php';
require_once __DIR__ . "/includes/sub_functions.php";

header('Content-Type: text/html; charset=utf-8');
sec_session_start();

define('PAYMENT_GATEWAY', 'worldline');

$isLoggedIn = isset($_SESSION['user_id']);
$userId     = $_SESSION['user_id'] ?? null;

$currentPlanKey    = 'free';
$currentStorageAdd = 0;
$currentUserAdd    = 0;

$storageStats = ['gb' => 0, 'files' => 0];
$userCount    = 0;
$listCount    = 0;
$itemCount    = 0;

if ($isLoggedIn && $userId) {
    $storageStats = getUserStorageStats($mysqli, (int)$userId);
    $userCount    = getTeamMemberCount($mysqli, (int)$userId);
    $listCount    = getUserListsCount($mysqli, (int)$userId);
    $itemCount    = getUserItemsCount($mysqli, (int)$userId);
}

if ($isLoggedIn && $userId) {
    $stmt = $mysqli->prepare("SELECT plan, storage_addon, user_addon FROM members WHERE id = ?");
    $stmt->bind_param("i", $userId);
    $stmt->execute();
    $stmt->bind_result($planFromDb, $currentStorageAdd, $currentUserAdd);
    if ($stmt->fetch()) {
        $currentPlanKey = $planFromDb ?: 'free';
    }
    $stmt->close();
}

$promoCodes = [];
$promoQuery = "
    SELECT code, label, discount_type, discount_value, valid_from, valid_until
    FROM mmt_price_promo
    WHERE (valid_from IS NULL OR valid_from <= CURDATE())
      AND (valid_until IS NULL OR valid_until >= CURDATE())
";
if ($promoResult = $mysqli->query($promoQuery)) {
    while ($row = $promoResult->fetch_assoc()) {
        $codeKey = strtoupper(trim((string)($row['code'] ?? '')));
        if ($codeKey === '') {
            continue;
        }
        $promoCodes[$codeKey] = [
            'code' => $codeKey,
            'label' => (string)($row['label'] ?? ''),
            'discount_type' => strtolower(trim((string)($row['discount_type'] ?? 'percent'))),
            'discount_value' => (float)($row['discount_value'] ?? 0),
        ];
    }
    $promoResult->close();
}

$pricingCards = [
    'free' => [
        'label' => 'Free',
        'gb' => 0.1,
        'user' => 1,
        'price' => 0,
        'mode' => 'basic',
    ],
    'team_standard' => [
        'label' => 'Team Standard',
        'gb' => 2,
        'user' => 20,
        'price' => 0,
        'mode' => 'team',
    ],
    'team_plus' => [
        'label' => 'Team Plus',
        'gb' => 5,
        'user' => 50,
        'price' => 0,
        'mode' => 'team',
    ],
];

$pricingUserOptions = [20, 30, 50, 70, 150, 300];
$pricingStorageOptions = [1, 2, 3, 5, 7, 10, 15, 30];

$planMeta = [
    'free' => [
        'eyebrow' => 'Personal access',
        'tag' => 'Free user',
        'summary' => 'Every person can use TextWhisper for free, join groups, and prepare privately around the material their groups unlock.',
        'accent' => 'mint',
        'features' => [
            'Private annotation on group scores',
            'Text memorizing tools',
            'Private or shared rehearsal lists',
            'Join paid groups for free',
            'Share and open TW links',
            'Private 1:1 and group chats',
            'Integrated piano',
            'Offline mode',
        ],
    ],
    'team_standard' => [
        'eyebrow' => 'Group plan',
        'tag' => 'Standard',
        'summary' => 'For groups that want shared repertoire workflow, visible group collaboration, and communication without playable XML, Event Planner, or reporting.',
        'accent' => 'coral',
        'features' => [
            'Everything free users already have, plus...',
            'Create and share performance and rehearsal lists',
            'Combine PDF, text, audio, and more inside each item',
            'Move seamlessly through items in a list',
            'Reorder and nest lists to match your program',
            'Shared annotations and drawing tools',
            'Group chat and list chat',
            'File manager with Google Drive, Dropbox, and more',
            'Audio player and looping',
            'MIDI player with voice channels',
        ],
    ],
    'team_plus' => [
        'eyebrow' => 'Premium group',
        'tag' => 'Recommended',
        'summary' => 'For groups that want the full TW offer with advanced playback, planning, insight, and ownership control.',
        'accent' => 'violet',
        'features' => [
            'Everything in Team Standard, plus...',
            'Playable scores with voice filtering',
            'Transpose scores',
            'Integrated piano with playable scores',
            'Clickable scores to hear the pitch',
            'Live Mode for real-time group guidance',
            'Cross-choir borrowing',
            'Event Planner',
            'Event and chat polls',
            'Reporting and My Work tracking',
        ],
    ],
];

function format_price_amount($value) {
    if ((float)$value <= 0) {
        return 'Free';
    }
    return 'EUR ' . number_format((float)$value, 0);
}

function format_storage_label($gbValue) {
    $gb = (float)$gbValue;
    $mb = (int)round($gb * 1000);
    return rtrim(rtrim(number_format($gb, 1, '.', ''), '0'), '.') . ' GB (~' . number_format($mb) . ' MB)';
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title>New Pricing Concept - TextWhisper</title>
  <style>
    :root {
      --bg: #f7f2e8;
      --bg-2: #fffaf4;
      --ink: #172033;
      --muted: #5f687c;
      --line: rgba(23, 32, 51, 0.12);
      --panel: rgba(255, 251, 245, 0.74);
      --panel-strong: rgba(255, 255, 255, 0.92);
      --shadow: 0 28px 70px rgba(95, 76, 44, 0.16);
      --hero-grad: radial-gradient(circle at top left, rgba(255, 183, 77, 0.32), transparent 34%), radial-gradient(circle at top right, rgba(255, 116, 87, 0.18), transparent 28%), linear-gradient(180deg, #fffaf3 0%, #f6efe2 46%, #f3eadc 100%);
      --mint: #1d7b61;
      --amber: #a86600;
      --sky: #006b8f;
      --coral: #b24a2d;
      --violet: #6e4bb6;
      --slate: #324055;
      --accent: #b24a2d;
      --accent-soft: rgba(178, 74, 45, 0.12);
      --button-grad: linear-gradient(135deg, #d55f36 0%, #b24a2d 100%);
      --font-display: Georgia, "Times New Roman", serif;
      --font-body: "Trebuchet MS", "Segoe UI", sans-serif;
    }

    * {
      box-sizing: border-box;
    }

    html {
      scroll-behavior: smooth;
    }

    body {
      margin: 0;
      min-height: 100vh;
      color: var(--ink);
      background:
        radial-gradient(circle at 15% 15%, rgba(255,255,255,0.7), transparent 18%),
        radial-gradient(circle at 82% 12%, rgba(255,191,125,0.35), transparent 18%),
        linear-gradient(180deg, #f9f3e7 0%, #f1e7d7 100%);
      font-family: var(--font-body);
    }

    a {
      color: inherit;
    }

    .page-shell {
      position: relative;
      overflow: hidden;
    }

    .page-shell::before,
    .page-shell::after {
      content: "";
      position: absolute;
      inset: auto;
      border-radius: 999px;
      filter: blur(12px);
      opacity: 0.45;
      pointer-events: none;
    }

    .page-shell::before {
      width: 26rem;
      height: 26rem;
      top: -7rem;
      right: -10rem;
      background: rgba(255, 181, 110, 0.4);
    }

    .page-shell::after {
      width: 22rem;
      height: 22rem;
      bottom: 16rem;
      left: -8rem;
      background: rgba(107, 182, 169, 0.22);
    }

    .content {
      position: relative;
      z-index: 1;
      width: min(1240px, calc(100% - 32px));
      margin: 0 auto;
      padding: 28px 0 72px;
    }

    .hero {
      position: relative;
      padding: 24px;
      border: 1px solid rgba(255, 255, 255, 0.55);
      border-radius: 34px;
      background: var(--hero-grad);
      box-shadow: var(--shadow);
      overflow: hidden;
    }

    .hero::after {
      content: "";
      position: absolute;
      inset: 0;
      background: linear-gradient(135deg, rgba(255,255,255,0.24), transparent 45%, rgba(255,255,255,0.1) 100%);
      pointer-events: none;
    }

    .topbar {
      display: flex;
      align-items: center;
      justify-content: space-between;
      gap: 18px;
      margin-bottom: 24px;
      position: relative;
      z-index: 1;
    }

    .back-link,
    .ghost-link {
      display: inline-flex;
      align-items: center;
      gap: 8px;
      padding: 12px 16px;
      border-radius: 999px;
      text-decoration: none;
      font-size: 14px;
      font-weight: 700;
      letter-spacing: 0.02em;
      border: 1px solid rgba(23, 32, 51, 0.12);
      background: rgba(255, 255, 255, 0.55);
      backdrop-filter: blur(8px);
    }

    .hero-grid {
      display: grid;
      grid-template-columns: minmax(0, 1fr);
      gap: 18px;
      align-items: start;
      position: relative;
      z-index: 1;
    }

    .eyebrow {
      display: inline-flex;
      align-items: center;
      gap: 8px;
      padding: 8px 12px;
      border-radius: 999px;
      background: rgba(255,255,255,0.58);
      border: 1px solid rgba(23, 32, 51, 0.08);
      font-size: 13px;
      font-weight: 700;
      text-transform: uppercase;
      letter-spacing: 0.08em;
    }

    .hero-copy h1 {
      margin: 14px 0 12px;
      max-width: 11ch;
      font-family: var(--font-display);
      font-size: clamp(2.7rem, 5.2vw, 4.2rem);
      line-height: 0.98;
      letter-spacing: -0.04em;
    }

    .hero-copy p {
      max-width: 760px;
      margin: 0;
      font-size: 17px;
      line-height: 1.62;
      color: var(--muted);
    }

    .hero-actions {
      display: flex;
      flex-wrap: wrap;
      gap: 12px;
      margin-top: 22px;
    }

    .hero-button,
    .hero-button-secondary {
      display: inline-flex;
      align-items: center;
      justify-content: center;
      gap: 8px;
      min-height: 50px;
      padding: 0 20px;
      border-radius: 999px;
      text-decoration: none;
      font-size: 15px;
      font-weight: 800;
      letter-spacing: 0.02em;
      transition: transform 180ms ease, box-shadow 180ms ease, background 180ms ease;
    }

    .hero-button {
      color: #fff;
      background: var(--button-grad);
      box-shadow: 0 18px 32px rgba(178, 74, 45, 0.24);
    }

    .hero-button-secondary {
      color: var(--ink);
      border: 1px solid rgba(23, 32, 51, 0.12);
      background: rgba(255,255,255,0.56);
    }

    .hero-button:hover,
    .hero-button-secondary:hover,
    .plan-submit:hover,
    .info-button:hover {
      transform: translateY(-1px);
    }

    .usage-card,
    .current-plan-banner,
    .info-panel,
    .feature-columns,
    .plan-card {
      border: 1px solid rgba(23, 32, 51, 0.1);
      background: var(--panel);
      backdrop-filter: blur(10px);
      box-shadow: var(--shadow);
    }

    .usage-card,
    .current-plan-banner,
    .info-panel {
      border-radius: 24px;
      padding: 22px;
    }

    .section-title {
      margin: 52px 0 18px;
      font-family: var(--font-display);
      font-size: clamp(2.2rem, 4vw, 3.2rem);
      line-height: 1;
      letter-spacing: -0.04em;
    }

    .section-copy {
      max-width: 720px;
      margin: 0 0 24px;
      color: var(--muted);
      font-size: 17px;
      line-height: 1.65;
    }

    .usage-card {
      display: grid;
      gap: 16px;
      background: rgba(255, 255, 255, 0.74);
    }

    .account-section {
      margin-top: 56px;
    }

    .usage-head {
      display: flex;
      align-items: center;
      justify-content: space-between;
      gap: 12px;
    }

    .usage-kicker {
      display: inline-flex;
      align-items: center;
      gap: 8px;
      padding: 8px 12px;
      border-radius: 999px;
      background: rgba(23, 32, 51, 0.06);
      font-size: 12px;
      font-weight: 800;
      letter-spacing: 0.08em;
      text-transform: uppercase;
    }

    .usage-grid {
      display: grid;
      grid-template-columns: repeat(4, minmax(0, 1fr));
      gap: 14px;
    }

    .usage-stat {
      padding: 18px;
      border-radius: 18px;
      background: rgba(255,255,255,0.76);
      border: 1px solid rgba(23, 32, 51, 0.07);
    }

    .usage-stat strong {
      display: block;
      font-size: 13px;
      text-transform: uppercase;
      letter-spacing: 0.07em;
      color: var(--muted);
    }

    .usage-value {
      margin-top: 8px;
      font-family: var(--font-display);
      font-size: 2rem;
      line-height: 1;
      letter-spacing: -0.04em;
    }

    .usage-detail {
      margin-top: 8px;
      font-size: 14px;
      color: var(--muted);
    }

    .current-plan-banner {
      margin-top: 18px;
      display: flex;
      align-items: center;
      justify-content: space-between;
      gap: 18px;
      background: linear-gradient(135deg, rgba(255,255,255,0.78), rgba(255,243,231,0.88));
    }

    .current-plan-banner h3,
    .usage-card h3,
    .info-panel h3,
    .feature-columns h3 {
      margin: 0;
      font-size: 1rem;
      letter-spacing: 0.03em;
      text-transform: uppercase;
      color: var(--muted);
    }

    .current-plan-name {
      margin: 6px 0 0;
      font-family: var(--font-display);
      font-size: 2rem;
      line-height: 1;
      letter-spacing: -0.05em;
    }

    .plan-addon-row {
      margin-top: 8px;
      display: flex;
      flex-wrap: wrap;
      gap: 10px;
      color: var(--muted);
      font-size: 14px;
      font-weight: 700;
    }

    .payments-link,
    .info-button {
      display: inline-flex;
      align-items: center;
      justify-content: center;
      min-height: 46px;
      padding: 0 18px;
      border-radius: 999px;
      text-decoration: none;
      font-weight: 800;
      letter-spacing: 0.02em;
      border: 1px solid rgba(23, 32, 51, 0.12);
      background: rgba(255,255,255,0.75);
    }

    .pricing-grid {
      display: grid;
      grid-template-columns: repeat(3, minmax(0, 1fr));
      gap: 18px;
      align-items: stretch;
    }

    .plan-card {
      position: relative;
      display: flex;
      flex-direction: column;
      padding: 22px;
      border-radius: 28px;
      overflow: hidden;
      transition: transform 220ms ease, box-shadow 220ms ease, border-color 220ms ease;
    }

    .plan-card:hover {
      transform: translateY(-4px);
      border-color: rgba(23, 32, 51, 0.18);
    }

    .plan-card.current {
      border-color: rgba(178, 74, 45, 0.42);
      box-shadow: 0 28px 70px rgba(178, 74, 45, 0.15);
    }

    .plan-card.popular {
      background: linear-gradient(180deg, rgba(255,248,240,0.95), rgba(255,255,255,0.86));
    }

    .plan-card::before {
      content: "";
      position: absolute;
      inset: -20% 50% auto -12%;
      height: 160px;
      border-radius: 999px;
      opacity: 0.18;
      pointer-events: none;
      transform: rotate(-12deg);
    }

    .plan-card[data-accent="mint"]::before { background: var(--mint); }
    .plan-card[data-accent="amber"]::before { background: var(--amber); }
    .plan-card[data-accent="sky"]::before { background: var(--sky); }
    .plan-card[data-accent="coral"]::before { background: var(--coral); }
    .plan-card[data-accent="violet"]::before { background: var(--violet); }
    .plan-card[data-accent="slate"]::before { background: var(--slate); }

    .plan-top,
    .plan-body,
    .plan-form {
      position: relative;
      z-index: 1;
    }

    .plan-top {
      display: flex;
      align-items: flex-start;
      justify-content: space-between;
      gap: 14px;
    }

    .plan-kicker {
      margin-bottom: 12px;
      font-size: 12px;
      font-weight: 800;
      letter-spacing: 0.08em;
      text-transform: uppercase;
      color: var(--muted);
    }

    .plan-title {
      margin: 0;
      font-family: var(--font-display);
      font-size: 2rem;
      line-height: 0.96;
      letter-spacing: -0.05em;
    }

    .plan-tag {
      display: inline-flex;
      align-items: center;
      justify-content: center;
      min-width: 84px;
      min-height: 34px;
      padding: 0 12px;
      border-radius: 999px;
      font-size: 11px;
      font-weight: 900;
      letter-spacing: 0.08em;
      text-transform: uppercase;
      color: #fff;
      background: rgba(23, 32, 51, 0.82);
    }

    .plan-summary {
      min-height: 72px;
      margin: 14px 0 0;
      color: var(--muted);
      line-height: 1.65;
      font-size: 15px;
    }

    .price-display {
      margin: 20px 0 16px;
      padding: 18px 18px 16px;
      border-radius: 22px;
      background: rgba(255,255,255,0.7);
      border: 1px solid rgba(23, 32, 51, 0.08);
    }

    .price-main {
      font-family: var(--font-display);
      font-size: 2.4rem;
      line-height: 0.95;
      letter-spacing: -0.06em;
    }

    .price-sub {
      margin-top: 10px;
      font-size: 14px;
      color: var(--muted);
      line-height: 1.5;
    }

    .price-original {
      text-decoration: line-through;
      opacity: 0.72;
    }

    .plan-points {
      display: grid;
      gap: 10px;
      margin: 0;
      padding: 0;
      list-style: none;
    }

    .plan-points li {
      display: flex;
      align-items: flex-start;
      gap: 10px;
      font-size: 14px;
      line-height: 1.45;
    }

    .plan-points li::before {
      content: "";
      width: 10px;
      height: 10px;
      margin-top: 6px;
      border-radius: 999px;
      background: var(--accent);
      box-shadow: 0 0 0 4px var(--accent-soft);
      flex: 0 0 auto;
    }

    .plan-form {
      margin-top: auto;
      padding-top: 18px;
    }

    .plan-select-group {
      margin-top: 12px;
    }

    .plan-select-group label {
      display: block;
      margin-bottom: 8px;
      font-size: 12px;
      font-weight: 800;
      letter-spacing: 0.08em;
      text-transform: uppercase;
      color: var(--muted);
    }

    .plan-select,
    .plan-submit {
      width: 100%;
      border-radius: 16px;
      min-height: 48px;
      font-size: 15px;
      font-family: inherit;
    }

    .plan-select {
      padding: 0 14px;
      border: 1px solid rgba(23, 32, 51, 0.12);
      background: rgba(255,255,255,0.78);
      color: var(--ink);
    }

    .plan-submit {
      margin-top: 16px;
      border: 0;
      font-weight: 800;
      letter-spacing: 0.02em;
      color: #fff;
      cursor: pointer;
      background: var(--button-grad);
      box-shadow: 0 18px 28px rgba(178, 74, 45, 0.22);
    }

    .plan-card.current .plan-submit {
      background: linear-gradient(135deg, #8f3c26 0%, #6e2e1d 100%);
    }

    .plan-note {
      margin-top: 10px;
      font-size: 13px;
      line-height: 1.55;
      color: var(--muted);
    }

    .promo-row {
      margin-top: 14px;
    }

    .promo-inline {
      display: grid;
      grid-template-columns: minmax(0, 1fr) auto;
      gap: 10px;
      align-items: center;
    }

    .promo-input {
      width: 100%;
      min-height: 48px;
      padding: 0 14px;
      border-radius: 16px;
      border: 1px solid rgba(23, 32, 51, 0.12);
      background: rgba(255,255,255,0.78);
      color: var(--ink);
      font-size: 15px;
      font-family: inherit;
      text-transform: uppercase;
    }

    .promo-button {
      min-height: 48px;
      padding: 0 16px;
      border: 1px solid rgba(23, 32, 51, 0.12);
      border-radius: 16px;
      background: rgba(255,255,255,0.78);
      color: var(--ink);
      font-size: 14px;
      font-weight: 800;
      cursor: pointer;
    }

    .promo-status {
      margin-top: 8px;
      min-height: 20px;
      font-size: 13px;
      line-height: 1.45;
      color: var(--muted);
    }

    .promo-status.valid {
      color: #1d7b61;
    }

    .promo-status.invalid {
      color: #9d2f24;
    }

    .info-layout {
      display: grid;
      grid-template-columns: minmax(0, 1.1fr) minmax(280px, 0.9fr);
      gap: 18px;
      margin-top: 26px;
    }

    .info-panel {
      background: rgba(255,255,255,0.78);
    }

    .info-list {
      margin: 16px 0 0;
      padding: 0;
      list-style: none;
      display: grid;
      gap: 12px;
    }

    .info-list li {
      font-size: 15px;
      line-height: 1.6;
      color: var(--ink);
    }

    .info-list strong {
      color: var(--accent);
    }

    .feature-columns {
      margin-top: 20px;
      padding: 22px;
      border-radius: 28px;
      background: rgba(255,255,255,0.72);
    }

    .feature-grid {
      display: grid;
      grid-template-columns: repeat(2, minmax(0, 1fr));
      gap: 14px;
      margin-top: 18px;
    }

    .feature-block {
      padding: 18px;
      border-radius: 20px;
      background: rgba(255,255,255,0.7);
      border: 1px solid rgba(23, 32, 51, 0.08);
    }

    .feature-block h4 {
      margin: 0 0 10px;
      font-family: var(--font-display);
      font-size: 1.4rem;
      letter-spacing: -0.03em;
    }

    .feature-block p {
      margin: 0;
      color: var(--muted);
      line-height: 1.65;
      font-size: 15px;
    }

    .flash-message {
      position: fixed;
      top: 20px;
      left: 50%;
      transform: translateX(-50%);
      padding: 14px 20px;
      border-radius: 16px;
      background: #8f2f22;
      color: #fff;
      font-weight: 800;
      box-shadow: 0 20px 30px rgba(143, 47, 34, 0.25);
      z-index: 1005;
    }

    .modal-shell {
      display: none;
      position: fixed;
      inset: 0;
      background: rgba(20, 18, 16, 0.52);
      backdrop-filter: blur(6px);
      z-index: 1000;
      padding: 20px;
    }

    .modal-card {
      max-width: 640px;
      margin: 7vh auto 0;
      padding: 26px;
      border-radius: 24px;
      background: #fffaf3;
      box-shadow: 0 30px 70px rgba(0, 0, 0, 0.2);
      position: relative;
    }

    .modal-close {
      position: absolute;
      top: 14px;
      right: 14px;
      width: 40px;
      height: 40px;
      border: 0;
      border-radius: 999px;
      background: rgba(23, 32, 51, 0.08);
      font-size: 18px;
      cursor: pointer;
    }

    .modal-card h3 {
      margin: 0 0 10px;
      font-family: var(--font-display);
      font-size: 2rem;
      letter-spacing: -0.04em;
    }

    .modal-card p,
    .modal-card li {
      color: var(--muted);
      line-height: 1.65;
      font-size: 15px;
    }

    .modal-card ul {
      margin: 16px 0 0;
      padding-left: 20px;
    }

    @media (max-width: 1100px) {
      .hero-grid,
      .info-layout,
      .pricing-grid {
        grid-template-columns: 1fr;
      }

      .hero-copy h1 {
        max-width: none;
      }
    }

    @media (max-width: 760px) {
      .content {
        width: min(100% - 20px, 1240px);
        padding: 16px 0 48px;
      }

      .hero {
        padding: 20px;
        border-radius: 24px;
      }

      .topbar,
      .current-plan-banner,
      .usage-head {
        flex-direction: column;
        align-items: flex-start;
      }

      .hero-copy h1 {
        font-size: 3.2rem;
      }

      .usage-grid,
      .feature-grid,
      .hero-stat-grid {
        grid-template-columns: 1fr;
      }

      .plan-card,
      .signal-card,
      .usage-card,
      .current-plan-banner,
      .info-panel,
      .feature-columns {
        border-radius: 22px;
      }
    }
  </style>
  <script>
    const IS_LOGGED_IN = <?= $isLoggedIn ? 'true' : 'false' ?>;
    const PLUS_MULTIPLIER = 1.3;
    const PROMO_CODES = <?= json_encode($promoCodes) ?>;
    const usage = <?= json_encode([
      'gb'    => $storageStats['gb'],
      'files' => $storageStats['files'],
      'users' => $userCount,
      'lists' => $listCount,
      'items' => $itemCount
    ]); ?>;

    function formatMoney(value) {
      return "EUR " + value.toFixed(0);
    }

    function formatStorageLabel(gbValue) {
      const numericGb = parseFloat(gbValue || "0");
      const roundedMb = Math.round(numericGb * 1000);
      return numericGb + " GB (~" + roundedMb.toLocaleString() + " MB)";
    }

    function getPromo(planKey) {
      const promoInput = document.getElementById("promo_" + planKey);
      if (!promoInput) {
        return { code: "", data: null };
      }

      const code = promoInput.value.trim().toUpperCase();
      return {
        code,
        data: code && PROMO_CODES[code] ? PROMO_CODES[code] : null
      };
    }

    function applyPromo(baseValue, promoData) {
      if (!promoData || baseValue <= 0) {
        return {
          finalValue: baseValue,
          discountAmount: 0
        };
      }

      let discountAmount = 0;
      if (promoData.discount_type === "fixed") {
        discountAmount = promoData.discount_value;
      } else {
        discountAmount = baseValue * (promoData.discount_value / 100);
      }

      discountAmount = Math.min(baseValue, discountAmount);
      return {
        finalValue: Math.max(0, baseValue - discountAmount),
        discountAmount
      };
    }

    function updatePromoStatus(planKey, promoCode, promoData, discountAmount) {
      const statusEl = document.getElementById("promo_status_" + planKey);
      if (!statusEl) {
        return;
      }

      if (!promoCode) {
        statusEl.textContent = "Enter a promo code to preview the discounted price.";
        statusEl.className = "promo-status";
        return;
      }

      if (!promoData) {
        statusEl.textContent = "Promo code not found or not currently valid.";
        statusEl.className = "promo-status invalid";
        return;
      }

      const labelText = promoData.label ? " " + promoData.label : "";
      statusEl.textContent = "Applied " + promoData.code + labelText + " (-" + formatMoney(discountAmount) + ")";
      statusEl.className = "promo-status valid";
    }

    function formatPrice(baseValue, finalValue, discountAmount) {
      if (finalValue <= 0) {
        return "<div class='price-main'>Free</div><div class='price-sub'>Personal access. Groups pay separately.</div>";
      }

      const monthly = finalValue / 12;
      let output = "<div class='price-main'>" + formatMoney(monthly) + "<span style='font-size:0.45em;'> / month</span></div>"
        + "<div class='price-sub'>Billed annually at " + formatMoney(finalValue) + "</div>";
      if (discountAmount > 0 && baseValue > finalValue) {
        output += "<div class='price-sub price-original'>Regular price " + formatMoney(baseValue) + "</div>";
      }
      return output;
    }

    function updatePrice(planKey) {
      const priceEl = document.getElementById("price_" + planKey);
      const base = parseFloat(priceEl.dataset.base || "0");
      const storageEl = document.getElementById("storage_" + planKey);
      const usersEl = document.getElementById("users_" + planKey);

      let total = base;

      if (planKey === "team_standard" || planKey === "team_plus") {
        const selectedStorage = storageEl ? parseFloat(storageEl.value || "0") : 0;
        const selectedUsers = usersEl ? parseFloat(usersEl.value || "0") : 0;
        const unitMemberPrice = 9;
        const unitGbPrice = 2;
        const discountBaseMembers = 20;
        const discountLogFactor = 0.06;
        const discountFloor = 0.82;

        const totalMemberPrice = selectedUsers * unitMemberPrice;
        const totalGbPrice = selectedStorage * unitGbPrice;
        const totalTeamPrice = totalMemberPrice + totalGbPrice;
        const rawFactor = 1 - Math.log((selectedUsers / discountBaseMembers) + 1) * discountLogFactor;
        const sizeFactor = Math.max(discountFloor, rawFactor);
        total = totalTeamPrice * sizeFactor;
        if (planKey === "team_plus") {
          total *= PLUS_MULTIPLIER;
        }
      } else {
        const storageExtra = storageEl ? parseFloat(storageEl.selectedOptions[0].dataset.price || "0") : 0;
        const userExtra = usersEl ? parseFloat(usersEl.selectedOptions[0].dataset.price || "0") : 0;
        total = base + storageExtra + userExtra;
      }

      const promo = getPromo(planKey);
      const promoResult = applyPromo(total, promo.data);
      priceEl.innerHTML = formatPrice(total, promoResult.finalValue, promoResult.discountAmount);
      updatePromoStatus(planKey, promo.code, promo.data, promoResult.discountAmount);

      if (storageEl) {
        const baseGb = parseFloat(storageEl.dataset.baseGb || "0");
        const addonGb = parseFloat(storageEl.value || "0");
        const storageLabel = document.getElementById("storageLabel_" + planKey);
        if (storageLabel) {
          if (planKey === "team_standard" || planKey === "team_plus") {
            storageLabel.textContent = formatStorageLabel(addonGb) + " storage";
          } else {
            storageLabel.textContent = formatStorageLabel(baseGb + addonGb) + " storage";
          }
        }
      }

      if ((planKey === "team_standard" || planKey === "team_plus") && usersEl) {
        const userLabel = document.getElementById("userLabel_" + planKey);
        const selectedText = usersEl.selectedOptions[0].textContent.trim();
        if (userLabel) {
          userLabel.textContent = selectedText;
        }
      }
    }

    function showFlashMessage(message) {
      const flash = document.createElement("div");
      flash.className = "flash-message";
      flash.textContent = message;
      document.body.appendChild(flash);
      setTimeout(() => flash.remove(), 3200);
    }

    function handleSubmit(event) {
      event.preventDefault();
      showFlashMessage("Subscription services are temporarily unavailable.");
      return false;
    }

    function openModal(id) {
      const modal = document.getElementById(id);
      if (modal) {
        modal.style.display = "block";
      }
    }

    function closeModal(id) {
      const modal = document.getElementById(id);
      if (modal) {
        modal.style.display = "none";
      }
    }

    window.addEventListener("DOMContentLoaded", () => {
      <?php foreach ($pricingCards as $key => $defaults): ?>
      updatePrice(<?= json_encode($key) ?>);
      <?php endforeach; ?>
    });
  </script>
</head>
<body>
  <div class="page-shell">
    <div class="content">
      <section class="hero">
        <div class="topbar">
          <a href="javascript:history.back()" class="back-link">← Back</a>
        </div>

        <div class="hero-grid">
          <div class="hero-copy">
            <span class="eyebrow">New pricing concept for TW</span>
            <h1>Group pricing. Free access for everyone else.</h1>
            <p>TextWhisper is free for individual users. Groups pay for the shared workspace, with Team Standard for the core workflow and Team Plus for playable XML, Event Planner, reporting, and My Work tracking.</p>
            <div class="hero-actions">
              <a href="#plans" class="hero-button">Explore plans</a>
              <button type="button" class="hero-button-secondary" onclick="openModal('subInfoModal')">How billing works</button>
            </div>
          </div>
        </div>
      </section>

      <h2 class="section-title" id="plans">Choose a plan</h2>
      <p class="section-copy">The model below treats personal access and group subscriptions differently. Free is for users. Team Standard and Team Plus are for groups, with team size and storage chosen directly inside each paid plan.</p>

      <section class="pricing-grid">
        <?php foreach ($pricingCards as $key => $defaults): ?>
          <?php
            $meta = $planMeta[$key] ?? [
              'eyebrow' => 'Plan',
              'tag' => 'TextWhisper',
              'summary' => 'Flexible pricing for your workflow.',
              'accent' => 'slate',
              'features' => ['All features included'],
            ];
            $isCurrent = $key === $currentPlanKey;
            $basePrice = (float)$defaults['price'];
            $baseGb = (float)$defaults['gb'];
            $baseUsers = (int)$defaults['user'];
            $isPopular = $key === 'team_standard';
          ?>
          <article class="plan-card<?= $isCurrent ? ' current' : '' ?><?= $isPopular ? ' popular' : '' ?>" data-accent="<?= htmlspecialchars($meta['accent']) ?>">
            <div class="plan-top">
              <div>
                <div class="plan-kicker"><?= htmlspecialchars($meta['eyebrow']) ?></div>
                <h3 class="plan-title"><?= htmlspecialchars($defaults['label']) ?></h3>
              </div>
              <div class="plan-tag"><?= htmlspecialchars($isCurrent ? 'Current' : $meta['tag']) ?></div>
            </div>

            <div class="plan-body">
              <p class="plan-summary"><?= htmlspecialchars($meta['summary']) ?></p>

              <div id="price_<?= htmlspecialchars($key) ?>" class="price-display" data-base="<?= htmlspecialchars((string)$basePrice) ?>">
                <?php if ($defaults['mode'] === 'team'): ?>
                  <?php
                    $calcMemberPrice = $baseUsers * 9;
                    $calcGbPrice = $baseGb * 2;
                    $calcTeamPrice = $calcMemberPrice + $calcGbPrice;
                    $calcRawFactor = 1 - log(($baseUsers / 20) + 1) * 0.06;
                    $calcSizeFactor = max(0.82, $calcRawFactor);
                    $calcAnnual = $calcTeamPrice * $calcSizeFactor;
                    if ($key === 'team_plus') {
                        $calcAnnual *= 1.3;
                    }
                  ?>
                  <div class="price-main">EUR <?= number_format($calcAnnual / 12, 0) ?><span style="font-size:0.45em;"> / month</span></div>
                  <div class="price-sub">Billed annually at EUR <?= number_format($calcAnnual, 0) ?></div>
                <?php elseif ($basePrice > 0): ?>
                  <div class="price-main">EUR <?= number_format($basePrice / 12, 0) ?><span style="font-size:0.45em;"> / month</span></div>
                  <div class="price-sub">Billed annually at <?= htmlspecialchars(format_price_amount($basePrice)) ?></div>
                <?php else: ?>
                  <div class="price-main">Free</div>
                  <div class="price-sub">Personal access. Groups pay separately.</div>
                <?php endif; ?>
              </div>

              <ul class="plan-points">
                <li id="storageLabel_<?= htmlspecialchars($key) ?>"><?= $key === 'free' ? 'Limited private storage' : htmlspecialchars(format_storage_label($baseGb)) . ' storage' ?></li>
                <?php if ($defaults['mode'] === 'team'): ?>
                  <li id="userLabel_<?= htmlspecialchars($key) ?>">Up to <?= $baseUsers ?> users</li>
                <?php else: ?>
                  <li>Up to <?= $baseUsers ?> users</li>
                <?php endif; ?>
                <?php foreach ($meta['features'] as $feature): ?>
                  <li><?= htmlspecialchars($feature) ?></li>
                <?php endforeach; ?>
              </ul>
            </div>

            <form method="POST"
                  action="sub_subscribe_<?= PAYMENT_GATEWAY ?>.php"
                  onsubmit="return handleSubmit(event)">
              <input type="hidden" name="plan" value="<?= htmlspecialchars($key) ?>">

              <div class="plan-form">
                <?php if ($defaults['mode'] === 'team'): ?>
                  <div class="plan-select-group">
                    <label for="users_<?= htmlspecialchars($key) ?>">Users</label>
                    <select class="plan-select" name="user_addon" id="users_<?= htmlspecialchars($key) ?>" onchange="updatePrice('<?= htmlspecialchars($key) ?>')">
                      <?php foreach ($pricingUserOptions as $users): ?>
                        <?php $selectedUsers = $isCurrent ? (int)$currentUserAdd : $baseUsers; ?>
                        <option value="<?= (int)$users ?>"<?= ((int)$selectedUsers === (int)$users) ? ' selected' : '' ?>>
                          Up to <?= (int)$users ?> users
                        </option>
                      <?php endforeach; ?>
                    </select>
                  </div>
                <?php endif; ?>

                <?php if ($key !== 'free'): ?>
                  <div class="plan-select-group">
                    <label for="storage_<?= htmlspecialchars($key) ?>">Storage</label>
                    <select class="plan-select" name="storage_addon" id="storage_<?= htmlspecialchars($key) ?>" data-base-gb="<?= htmlspecialchars((string)$baseGb) ?>" onchange="updatePrice('<?= htmlspecialchars($key) ?>')">
                      <?php foreach ($pricingStorageOptions as $storageGb): ?>
                        <?php $selectedStorage = $isCurrent ? (float)$currentStorageAdd : $baseGb; ?>
                        <option value="<?= htmlspecialchars((string)$storageGb) ?>"<?= ((float)$selectedStorage === (float)$storageGb) ? ' selected' : '' ?>>
                          <?= htmlspecialchars(format_storage_label($storageGb)) ?>
                        </option>
                      <?php endforeach; ?>
                    </select>
                  </div>

                  <div class="promo-row">
                    <label for="promo_<?= htmlspecialchars($key) ?>">Promo code</label>
                    <div class="promo-inline">
                      <input class="promo-input" type="text" name="promo_code" id="promo_<?= htmlspecialchars($key) ?>" placeholder="Enter code" oninput="updatePrice('<?= htmlspecialchars($key) ?>')">
                      <button class="promo-button" type="button" onclick="updatePrice('<?= htmlspecialchars($key) ?>')">Apply</button>
                    </div>
                    <div id="promo_status_<?= htmlspecialchars($key) ?>" class="promo-status">Enter a promo code to preview the discounted price.</div>
                  </div>
                <?php endif; ?>

                <?php if ($key === 'free'): ?>
                  <div class="plan-note">Free users can join paid groups and build personal rehearsal structure around shared material.</div>
                <?php else: ?>
                  <div class="plan-note">These are group plans. Adjust team size and storage directly in the card.</div>
                <?php endif; ?>

                <button class="plan-submit" type="submit"><?= $isCurrent ? 'Current plan selected' : 'Select plan' ?></button>
                <div class="plan-note">Subscriptions are currently paused, so this button shows the UI state without completing checkout.</div>
              </div>
            </form>
          </article>
        <?php endforeach; ?>
      </section>

      <section class="info-layout">
        <div class="info-panel">
          <h3>How subscriptions work</h3>
          <ul class="info-list">
            <li><strong>People are free.</strong> Anyone can have a TW account, join groups, and use what their groups unlock.</li>
            <li><strong>Paid plans are for shared use.</strong> Team Standard and Team Plus are for shared spaces, not just personal access.</li>
            <li><strong>Flexible paid plans.</strong> Team Standard and Team Plus scale with the team size and storage you pick in the card.</li>
            <li><strong>Team Plus adds more score and planning power.</strong> It adds playable scores, Live Mode, Event Planner, and group insight tools.</li>
            <li><strong>Annual billing.</strong> Plans renew yearly unless cancelled before the renewal date.</li>
          </ul>
          <p class="plan-note" style="margin-top:16px;">Need help: <a href="mailto:customersupport@textwhisper.com">customersupport@textwhisper.com</a></p>
        </div>

        <div class="info-panel">
          <h3>What we are selling</h3>
          <ul class="info-list">
            <li><strong>Team Standard sells the core TW experience.</strong> Create and share lists, combine PDF, text, audio, and more in each item, and move through your program smoothly.</li>
            <li><strong>Team Plus adds deeper score and group tools.</strong> Add playable scores, Live Mode, Event Planner, borrowing, and stronger group insight.</li>
            <li><strong>Free removes friction.</strong> Users can join groups and keep their own notes, chats, and rehearsal lists without needing a paid personal plan.</li>
            <li><strong>The plan model stays simple.</strong> One free user layer, two group plans, configurable users and GB.</li>
          </ul>
          <button type="button" class="info-button" onclick="openModal('subInfoModal')">Open billing explainer</button>
        </div>
      </section>

      <section class="feature-columns">
        <h3>What Team Plus adds</h3>
        <div class="feature-grid">
          <div class="feature-block">
            <h4>Playable scores</h4>
            <p>Use playable scores with voice filtering, transposition, an integrated piano, and clickable notes to hear pitch directly from the score.</p>
          </div>
          <div class="feature-block">
            <h4>Event Planner</h4>
            <p>Keep planning and coordination inside TW with Event Planner plus event and chat polls for group decisions.</p>
          </div>
          <div class="feature-block">
            <h4>Live Mode</h4>
            <p>Guide the group in real time by showing everyone the score or item selected by the leader.</p>
          </div>
          <div class="feature-block">
            <h4>Borrowing and insight</h4>
            <p>Support cross-choir borrowing and get clearer reporting and My Work tracking for ownership and activity visibility.</p>
          </div>
        </div>
      </section>

      <?php if ($isLoggedIn && isset($PLANS[$currentPlanKey])): ?>
        <section class="account-section">
          <h2 class="section-title">Your account</h2>
          <p class="section-copy">Account-specific usage stays below the pricing offer so the page remains focused, while still giving you the context needed to compare your current plan with the group options above.</p>

          <section class="current-plan-banner">
            <div>
              <h3>Current plan</h3>
              <div class="current-plan-name"><?= htmlspecialchars($pricingCards[$currentPlanKey]['label'] ?? $PLANS[$currentPlanKey]['label']) ?></div>
              <?php if ($currentStorageAdd > 0 || $currentUserAdd > 0): ?>
                <div class="plan-addon-row">
                  <?php if ($currentStorageAdd > 0): ?><span>Storage add-on: +<?= (int)$currentStorageAdd ?> GB</span><?php endif; ?>
                  <?php if ($currentUserAdd > 0): ?><span>User add-on: <?= (int)$currentUserAdd ?> total users</span><?php endif; ?>
                </div>
              <?php endif; ?>
            </div>
            <a href="/sub_payments.php" class="payments-link">View payments and history</a>
          </section>

          <section class="usage-card">
            <div class="usage-head">
              <div>
                <h3>Current usage</h3>
                <div class="usage-kicker">Live account snapshot</div>
              </div>
            </div>
            <div class="usage-grid">
              <div class="usage-stat">
                <strong>Storage</strong>
                <div class="usage-value"><?= htmlspecialchars((string)$storageStats['gb']) ?> GB</div>
                <div class="usage-detail"><?= (int)$storageStats['files'] ?> files</div>
              </div>
              <div class="usage-stat">
                <strong>Team</strong>
                <div class="usage-value"><?= (int)$userCount ?></div>
                <div class="usage-detail">active members</div>
              </div>
              <div class="usage-stat">
                <strong>Lists</strong>
                <div class="usage-value"><?= (int)$listCount ?></div>
                <div class="usage-detail">organized spaces</div>
              </div>
              <div class="usage-stat">
                <strong>Items</strong>
                <div class="usage-value"><?= (int)$itemCount ?></div>
                <div class="usage-detail">tracked records</div>
              </div>
            </div>
          </section>
        </section>
      <?php endif; ?>
    </div>
  </div>

  <div id="subInfoModal" class="modal-shell" onclick="closeModal('subInfoModal')">
    <div class="modal-card" onclick="event.stopPropagation()">
      <button class="modal-close" type="button" onclick="closeModal('subInfoModal')">✕</button>
      <h3>Billing notes</h3>
      <p>This concept reflects the group-first pricing model: people use TW for free, and groups pay for shared access and added group features.</p>
      <ul>
        <li>Free is a user-level access model.</li>
        <li>Plans can be changed at any time.</li>
        <li>Group plans scale by chosen users and storage.</li>
        <li>Team Plus includes playable scores, Live Mode, Event Planner, borrowing, reporting, and My Work tracking.</li>
        <li>Payments are handled through the configured payment provider.</li>
        <li>Subscriptions renew yearly unless cancelled.</li>
      </ul>
    </div>
  </div>
</body>
</html>
