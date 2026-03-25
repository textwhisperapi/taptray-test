<?php
header('Content-Type: application/json');
require_once __DIR__ . '/includes/functions.php';
require_once __DIR__ . '/includes/db_connect.php';

sec_session_start();
$mysqli->set_charset("utf8mb4");

$memberId = $_SESSION['user_id'] ?? null;
if (!$memberId) {
    echo json_encode(["status" => "error", "message" => "Not logged in"]);
    exit;
}

function ep_json($payload) {
    echo json_encode($payload);
    exit;
}

$rawOwner = $_GET['owner'] ?? null;
$data = json_decode(file_get_contents("php://input"), true);
if (!is_array($data)) {
    $data = $_POST;
}
$rawOwner = $rawOwner ?? ($data['owner'] ?? null);

function ep_resolve_owner_id($mysqli, $tokenOrUser, $fallback) {
    if (!$tokenOrUser) return $fallback;
    $ownerId = null;
    $stmt = $mysqli->prepare("SELECT owner_id FROM content_lists WHERE token = ? LIMIT 1");
    $stmt->bind_param("s", $tokenOrUser);
    $stmt->execute();
    $stmt->bind_result($ownerId);
    $stmt->fetch();
    $stmt->close();
    if ($ownerId) return (int)$ownerId;
    $stmt = $mysqli->prepare("SELECT id FROM members WHERE username = ? LIMIT 1");
    $stmt->bind_param("s", $tokenOrUser);
    $stmt->execute();
    $stmt->bind_result($ownerId);
    $stmt->fetch();
    $stmt->close();
    return $ownerId ? (int)$ownerId : $fallback;
}

function ep_event_owner($mysqli, $eventId) {
    $stmt = $mysqli->prepare("SELECT owner_id FROM ep_events WHERE id = ?");
    $stmt->bind_param("i", $eventId);
    $stmt->execute();
    $stmt->bind_result($ownerId);
    $stmt->fetch();
    $stmt->close();
    return $ownerId ?: null;
}

$ownerId = ep_resolve_owner_id($mysqli, $rawOwner, (int)$memberId);
$username = $_SESSION['username'] ?? '';
$listTokenForRole = $rawOwner ?: $username;
$roleRank = $username ? get_user_list_role_rank($mysqli, $listTokenForRole, $username) : 0;
$canManage = ($ownerId === (int)$memberId) || ($roleRank >= 80);

$method = $_SERVER['REQUEST_METHOD'] ?? 'GET';

if ($method === 'GET') {
    $eventId = (int)($_GET['event_id'] ?? 0);
    if ($eventId <= 0) {
        ep_json(["status" => "error", "message" => "event_id required"]);
    }
    if ((int)ep_event_owner($mysqli, $eventId) !== (int)$ownerId) {
        ep_json(["status" => "error", "message" => "Permission denied"]);
    }

    $stmt = $mysqli->prepare("
        SELECT c.member_id, c.status, c.updated_at,
               m.username, m.display_name, m.avatar_url
        FROM ep_checkins c
        JOIN members m ON m.id = c.member_id
        WHERE c.event_id = ?
        ORDER BY c.updated_at DESC
    ");
    $stmt->bind_param("i", $eventId);
    $stmt->execute();
    $res = $stmt->get_result();
    $checkins = [];
    while ($row = $res->fetch_assoc()) {
        $checkins[] = $row;
    }
    $stmt->close();

    ep_json(["status" => "OK", "checkins" => $checkins]);
}

$eventId = (int)($data['event_id'] ?? 0);
$status = $data['status'] ?? 'in';
if ($eventId <= 0) {
    ep_json(["status" => "error", "message" => "event_id required"]);
}
if (!in_array($status, ['in', 'out'], true)) {
    ep_json(["status" => "error", "message" => "Invalid status"]);
}

$targetMemberId = (int)($data['member_id'] ?? $memberId);
if ($canManage && (int)($data['member_id'] ?? 0) > 0) {
    $targetMemberId = (int)$data['member_id'];
}

$stmt = $mysqli->prepare("
    SELECT id FROM ep_checkins
    WHERE event_id = ? AND member_id = ?
    LIMIT 1
");
$stmt->bind_param("ii", $eventId, $targetMemberId);
$stmt->execute();
$stmt->bind_result($checkinId);
$stmt->fetch();
$stmt->close();

if ($checkinId) {
    $stmt = $mysqli->prepare("UPDATE ep_checkins SET status = ?, updated_at = NOW() WHERE id = ?");
    $stmt->bind_param("si", $status, $checkinId);
    $stmt->execute();
    $stmt->close();
} else {
    $stmt = $mysqli->prepare("
        INSERT INTO ep_checkins (event_id, member_id, status)
        VALUES (?, ?, ?)
    ");
    $stmt->bind_param("iis", $eventId, $targetMemberId, $status);
    $stmt->execute();
    $stmt->close();
}

ep_json(["status" => "OK"]);
