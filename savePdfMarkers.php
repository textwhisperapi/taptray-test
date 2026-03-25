<?php
declare(strict_types=1);

require_once __DIR__ . '/includes/functions.php';
require_once __DIR__ . '/includes/db_connect.php';

sec_session_start();
header('Content-Type: application/json; charset=utf-8');

function tw_pdf_marker_json(array $payload, int $code = 200): void
{
    http_response_code($code);
    echo json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

function tw_pdf_marker_table_exists(mysqli $mysqli): bool
{
    $res = $mysqli->query("SHOW TABLES LIKE 'pdf_markers'");
    if (!$res) return false;
    $exists = $res->num_rows > 0;
    $res->close();
    return $exists;
}

function tw_pdf_marker_is_site_admin(mysqli $mysqli, string $username): bool
{
    if ($username === '') return false;
    $res = $mysqli->query("SHOW COLUMNS FROM members LIKE 'is_admin'");
    $hasCol = $res && $res->num_rows > 0;
    if ($res) $res->close();
    if (!$hasCol) return false;
    $stmt = $mysqli->prepare("SELECT COALESCE(is_admin, 0) FROM members WHERE username = ? LIMIT 1");
    if (!$stmt) return false;
    $stmt->bind_param('s', $username);
    $stmt->execute();
    $stmt->bind_result($isAdmin);
    $ok = $stmt->fetch();
    $stmt->close();
    return $ok && (int)$isAdmin === 1;
}

function tw_pdf_marker_normalize_markers($markers): array
{
    if (!is_array($markers)) return [];
    $normalized = [];
    foreach ($markers as $entry) {
        if (!is_array($entry)) continue;
        $page = max(1, (int)($entry['page'] ?? 1));
        $xPct = max(0, min(1, (float)($entry['xPct'] ?? 0.5)));
        $yPct = max(0, min(1, (float)($entry['yPct'] ?? 0)));
        $id = trim((string)($entry['id'] ?? ''));
        if ($id === '') {
            $id = 'm-' . time() . '-' . substr(md5((string)mt_rand()), 0, 6);
        }
        $normalized[] = [
            'id' => $id,
            'page' => $page,
            'xPct' => $xPct,
            'yPct' => $yPct,
            'createdAt' => (int)($entry['createdAt'] ?? round(microtime(true) * 1000))
        ];
    }
    return $normalized;
}

if (!login_check($mysqli) || empty($_SESSION['username'])) {
    tw_pdf_marker_json(['status' => 'error', 'error' => 'Not logged in'], 403);
}

if (!tw_pdf_marker_table_exists($mysqli)) {
    tw_pdf_marker_json(['status' => 'error', 'error' => 'pdf_markers table missing'], 500);
}

$payload = json_decode((string)file_get_contents('php://input'), true);
if (!is_array($payload)) {
    tw_pdf_marker_json(['status' => 'error', 'error' => 'Invalid JSON'], 400);
}

$surrogate = (int)($payload['surrogate'] ?? 0);
$requestedLayer = trim((string)($payload['layer'] ?? 'self'));
$layer = $requestedLayer === 'owner' ? 'owner' : 'self';
$markers = tw_pdf_marker_normalize_markers($payload['markers'] ?? []);
if ($surrogate <= 0) {
    tw_pdf_marker_json(['status' => 'error', 'error' => 'Missing surrogate'], 400);
}

$stmtOwner = $mysqli->prepare("SELECT owner FROM text WHERE surrogate = ? LIMIT 1");
if (!$stmtOwner) {
    tw_pdf_marker_json(['status' => 'error', 'error' => 'DB prepare failed', 'details' => $mysqli->error], 500);
}
$stmtOwner->bind_param('i', $surrogate);
$stmtOwner->execute();
$stmtOwner->bind_result($ownerDb);
$stmtOwner->fetch();
$stmtOwner->close();
$owner = trim((string)$ownerDb);

if ($owner === '') {
    tw_pdf_marker_json(['status' => 'error', 'error' => 'Item not found'], 404);
}

$sessionUser = trim((string)($_SESSION['username'] ?? ''));
$canEditOwner = $sessionUser === $owner
    || !empty($_SESSION['is_admin'])
    || tw_pdf_marker_is_site_admin($mysqli, $sessionUser)
    || can_user_edit_list($mysqli, $owner, $sessionUser)
    || (int)get_user_list_role_rank($mysqli, $owner, $sessionUser) >= 80;

$annotator = $layer === 'owner' ? $owner : $sessionUser;
if ($layer === 'owner' && !$canEditOwner) {
    tw_pdf_marker_json(['status' => 'error', 'error' => 'Owner marker permission denied'], 403);
}

$markersJson = json_encode($markers, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
if ($markersJson === false) {
    tw_pdf_marker_json(['status' => 'error', 'error' => 'Could not encode markers'], 500);
}

$existingId = 0;
$stmtExisting = $mysqli->prepare("
    SELECT id
    FROM pdf_markers
    WHERE surrogate = ? AND annotator = ?
    ORDER BY updated_at DESC, id DESC
    LIMIT 1
");
if ($stmtExisting) {
    $stmtExisting->bind_param('is', $surrogate, $annotator);
    $stmtExisting->execute();
    $stmtExisting->bind_result($existingIdRow);
    if ($stmtExisting->fetch()) {
        $existingId = (int)$existingIdRow;
    }
    $stmtExisting->close();
}

if ($existingId > 0) {
    $stmtUpdate = $mysqli->prepare("
        UPDATE pdf_markers
        SET owner = ?, markers = ?, updated_at = CURRENT_TIMESTAMP
        WHERE id = ?
        LIMIT 1
    ");
    if (!$stmtUpdate) {
        tw_pdf_marker_json(['status' => 'error', 'error' => 'DB prepare failed', 'details' => $mysqli->error], 500);
    }
    $stmtUpdate->bind_param('ssi', $owner, $markersJson, $existingId);
    $ok = $stmtUpdate->execute();
    $stmtUpdate->close();
} else {
    $stmtInsert = $mysqli->prepare("
        INSERT INTO pdf_markers (surrogate, owner, annotator, markers)
        VALUES (?, ?, ?, ?)
    ");
    if (!$stmtInsert) {
        tw_pdf_marker_json(['status' => 'error', 'error' => 'DB prepare failed', 'details' => $mysqli->error], 500);
    }
    $stmtInsert->bind_param('isss', $surrogate, $owner, $annotator, $markersJson);
    $ok = $stmtInsert->execute();
    $stmtInsert->close();
}

if (!$ok) {
    tw_pdf_marker_json(['status' => 'error', 'error' => 'DB write failed', 'details' => $mysqli->error], 500);
}

tw_pdf_marker_json([
    'status' => 'success',
    'surrogate' => $surrogate,
    'owner' => $owner,
    'annotator' => $annotator,
    'layer' => $layer,
    'saved' => count($markers)
]);
