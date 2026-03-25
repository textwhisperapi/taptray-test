<?php
header('Content-Type: application/json');
require_once __DIR__ . '/includes/db_connect.php';
require_once __DIR__ . '/includes/functions.php';

sec_session_start();

if (!login_check($mysqli)) {
    http_response_code(403);
    echo json_encode(['status' => 'error', 'message' => 'Not logged in']);
    exit;
}

$surrogate = $_POST['surrogate'] ?? '';
$type      = $_POST['type'] ?? '';
$url       = $_POST['url'] ?? '';
$source    = $_POST['source'] ?? 'cloudflare';

if (!is_numeric($surrogate) || $type !== 'pdf') {
    http_response_code(400);
    echo json_encode(['status' => 'error', 'message' => 'Invalid input']);
    exit;
}

$username = $_SESSION['username'] ?? '';
$userId   = $_SESSION['user_id'] ?? null;

if (!can_user_edit_surrogate($mysqli, $surrogate, $username)) {
    http_response_code(403);
    echo json_encode(['status' => 'error', 'message' => 'Permission denied']);
    exit;
}

// Owner lookup
$surrogateSafe = $mysqli->real_escape_string($surrogate);
$query  = "SELECT owner FROM `text` WHERE Surrogate = '$surrogateSafe' LIMIT 1";
$result = $mysqli->query($query);
$item   = $result ? $result->fetch_assoc() : null;
if (!$item || empty($item['owner'])) {
    http_response_code(404);
    echo json_encode(['status' => 'error', 'message' => 'Item not found']);
    exit;
}
$owner = $item['owner'];

// Owner id lookup (best-effort)
$ownerId = null;
$stmtOwner = $mysqli->prepare("SELECT id FROM members WHERE username = ? LIMIT 1");
if ($stmtOwner) {
    $stmtOwner->bind_param("s", $owner);
    $stmtOwner->execute();
    $stmtOwner->bind_result($ownerId);
    $stmtOwner->fetch();
    $stmtOwner->close();
}

log_change_general(
    $mysqli,
    'upload',
    'pdf',
    (int)$surrogate,
    null,
    $ownerId,
    $owner,
    $userId,
    $username,
    json_encode(['url' => $url, 'source' => $source], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)
);

echo json_encode(['status' => 'success']);
