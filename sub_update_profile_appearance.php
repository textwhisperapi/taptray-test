<?php
require_once __DIR__ . '/includes/db_connect.php';
require_once __DIR__ . '/includes/functions.php';

sec_session_start();
header('Content-Type: application/json; charset=utf-8');

function tw_ensure_member_appearance_columns(mysqli $mysqli): void {
    static $done = false;
    if ($done) return;
    $done = true;

    $required = [
        'menu_skin_preset' => "ALTER TABLE members ADD COLUMN menu_skin_preset VARCHAR(32) NULL AFTER home_page",
        'menu_pattern_base' => "ALTER TABLE members ADD COLUMN menu_pattern_base VARCHAR(32) NULL AFTER menu_skin_preset",
        'menu_pattern_size' => "ALTER TABLE members ADD COLUMN menu_pattern_size INT NULL AFTER menu_pattern_base",
        'menu_top_banner_url' => "ALTER TABLE members ADD COLUMN menu_top_banner_url TEXT NULL AFTER menu_pattern_size",
        'menu_greeting_text' => "ALTER TABLE members ADD COLUMN menu_greeting_text VARCHAR(120) NULL AFTER menu_top_banner_url",
        'menu_greeting_icon' => "ALTER TABLE members ADD COLUMN menu_greeting_icon VARCHAR(16) NULL AFTER menu_greeting_text",
        'menu_greeting_icon_url' => "ALTER TABLE members ADD COLUMN menu_greeting_icon_url TEXT NULL AFTER menu_greeting_icon",
    ];

    $existing = [];
    if ($result = $mysqli->query("SHOW COLUMNS FROM members")) {
        while ($row = $result->fetch_assoc()) {
            $existing[$row['Field']] = true;
        }
        $result->close();
    }

    foreach ($required as $column => $sql) {
        if (!isset($existing[$column])) {
            @$mysqli->query($sql);
        }
    }
}

function tw_normalize_menu_skin(string $value): string {
    $allowed = ['legacy-dark', 'silver', 'gold', 'blue', 'rose', 'green', 'red', 'purple'];
    return in_array($value, $allowed, true) ? $value : 'legacy-dark';
}

function tw_normalize_pattern_base(string $value): string {
    $allowed = ['default', 'none', 'dots', 'lines', 'grid', 'waves', 'hearts', 'flowers', 'music', 'melody'];
    return in_array($value, $allowed, true) ? $value : 'melody';
}

function tw_normalize_banner_url(string $value): string {
    $value = trim($value);
    if ($value === '') return '';
    if (strlen($value) > 2048) return '';

    if (filter_var($value, FILTER_VALIDATE_URL)) {
        return $value;
    }

    if ($value[0] === '/') {
        return $value;
    }

    return '';
}

function tw_normalize_greeting_text(string $value): string {
    $value = trim($value);
    if ($value === '') return '';
    if (mb_strlen($value, 'UTF-8') > 80) {
        $value = mb_substr($value, 0, 80, 'UTF-8');
    }
    return $value;
}

function tw_normalize_greeting_icon_url(string $value): string {
    $value = trim($value);
    if ($value === '') return '';
    if (strlen($value) > 2048) return '';
    if (filter_var($value, FILTER_VALIDATE_URL)) {
        return $value;
    }
    if ($value[0] === '/') {
        return $value;
    }
    return '';
}

if (!login_check($mysqli) || empty($_SESSION['user_id'])) {
    http_response_code(403);
    echo json_encode(['ok' => false, 'error' => 'Not logged in']);
    exit;
}

$payload = json_decode(file_get_contents('php://input'), true);
if (!is_array($payload)) {
    http_response_code(400);
    echo json_encode(['ok' => false, 'error' => 'Invalid payload']);
    exit;
}

$skinPreset = tw_normalize_menu_skin((string)($payload['skin_preset'] ?? 'legacy-dark'));
$patternBase = tw_normalize_pattern_base((string)($payload['pattern_base'] ?? 'melody'));
$patternSize = max(10, min(100, (int)($payload['pattern_size'] ?? 40)));
$topBannerUrl = tw_normalize_banner_url((string)($payload['top_banner_url'] ?? ''));
$greetingText = tw_normalize_greeting_text((string)($payload['greeting_text'] ?? ''));
$greetingIconUrl = tw_normalize_greeting_icon_url((string)($payload['greeting_icon_url'] ?? ''));

tw_ensure_member_appearance_columns($mysqli);

$userId = (int)$_SESSION['user_id'];
$bannerDb = $topBannerUrl === '' ? null : $topBannerUrl;

$stmt = $mysqli->prepare("
    UPDATE members
    SET menu_skin_preset = ?, menu_pattern_base = ?, menu_pattern_size = ?, menu_top_banner_url = ?,
        menu_greeting_text = ?, menu_greeting_icon = NULL, menu_greeting_icon_url = ?
    WHERE id = ?
");
$greetingTextDb = $greetingText === '' ? null : $greetingText;
$greetingIconUrlDb = $greetingIconUrl === '' ? null : $greetingIconUrl;
$stmt->bind_param('ssisssi', $skinPreset, $patternBase, $patternSize, $bannerDb, $greetingTextDb, $greetingIconUrlDb, $userId);
$ok = $stmt->execute();
$stmt->close();

if (!$ok) {
    http_response_code(500);
    echo json_encode(['ok' => false, 'error' => 'Failed to save appearance']);
    exit;
}

echo json_encode([
    'ok' => true,
    'appearance' => [
        'skin_preset' => $skinPreset,
        'pattern_base' => $patternBase,
        'pattern_size' => $patternSize,
        'top_banner_url' => $topBannerUrl,
        'greeting_text' => $greetingText,
        'greeting_icon_url' => $greetingIconUrl
    ]
], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
