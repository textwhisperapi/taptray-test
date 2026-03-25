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

function tw_pdf_marker_decode(?string $raw): array
{
    if (!$raw) return [];
    $decoded = json_decode($raw, true);
    return is_array($decoded) ? $decoded : [];
}

if (!login_check($mysqli) || empty($_SESSION['username'])) {
    tw_pdf_marker_json(['status' => 'error', 'error' => 'Not logged in'], 403);
}

if (!tw_pdf_marker_table_exists($mysqli)) {
    tw_pdf_marker_json([
        'status' => 'success',
        'owner' => '',
        'annotator' => (string)($_SESSION['username'] ?? ''),
        'ownerMarkers' => [],
        'userMarkers' => []
    ]);
}

$surrogate = (int)($_GET['surrogate'] ?? 0);
if ($surrogate <= 0) {
    tw_pdf_marker_json(['status' => 'error', 'error' => 'Missing surrogate'], 400);
}

$owner = trim((string)($_GET['owner'] ?? ''));
if ($owner === '') {
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
}

$sessionUser = trim((string)($_SESSION['username'] ?? ''));

$loadMarkers = function (string $annotator) use ($mysqli, $surrogate): array {
    if ($annotator === '') return [];
    $stmt = $mysqli->prepare("
        SELECT markers
        FROM pdf_markers
        WHERE surrogate = ? AND annotator = ?
        ORDER BY updated_at DESC, id DESC
        LIMIT 1
    ");
    if (!$stmt) return [];
    $stmt->bind_param('is', $surrogate, $annotator);
    $stmt->execute();
    $stmt->bind_result($markersJson);
    $found = $stmt->fetch();
    $stmt->close();
    return $found ? tw_pdf_marker_decode($markersJson) : [];
};

tw_pdf_marker_json([
    'status' => 'success',
    'owner' => $owner,
    'annotator' => $sessionUser,
    'ownerMarkers' => $loadMarkers($owner),
    'userMarkers' => $loadMarkers($sessionUser)
]);
