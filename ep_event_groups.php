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

$rawOwner = $_GET['owner'] ?? null;
$data = json_decode(file_get_contents("php://input"), true);
if (!is_array($data)) {
    $data = $_POST;
}
$rawOwner = $rawOwner ?? ($data['owner'] ?? null);
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
        SELECT g.id, g.name, g.description
        FROM ep_event_groups eg
        JOIN ep_groups g ON g.id = eg.group_id
        WHERE eg.event_id = ?
        ORDER BY g.name ASC
    ");
    $stmt->bind_param("i", $eventId);
    $stmt->execute();
    $res = $stmt->get_result();
    $groups = [];
    while ($row = $res->fetch_assoc()) {
        $groups[] = $row;
    }
    $stmt->close();

    ep_json(["status" => "OK", "groups" => $groups]);
}

$action = $data['action'] ?? '';
$eventId = (int)($data['event_id'] ?? 0);
if ($eventId <= 0) {
    ep_json(["status" => "error", "message" => "event_id required"]);
}
if ((int)ep_event_owner($mysqli, $eventId) !== (int)$ownerId || !$canManage) {
    ep_json(["status" => "error", "message" => "Permission denied"]);
}

if ($action === 'add') {
    $groupId = (int)($data['group_id'] ?? 0);
    if ($groupId <= 0) {
        ep_json(["status" => "error", "message" => "group_id required"]);
    }
    $stmt = $mysqli->prepare("DELETE FROM ep_event_groups WHERE event_id = ? AND group_id = ?");
    $stmt->bind_param("ii", $eventId, $groupId);
    $stmt->execute();
    $stmt->close();

    $stmt = $mysqli->prepare("INSERT INTO ep_event_groups (event_id, group_id) VALUES (?, ?)");
    $stmt->bind_param("ii", $eventId, $groupId);
    $stmt->execute();
    $stmt->close();

    ep_json(["status" => "OK"]);
}

if ($action === 'remove') {
    $groupId = (int)($data['group_id'] ?? 0);
    if ($groupId <= 0) {
        ep_json(["status" => "error", "message" => "group_id required"]);
    }
    $stmt = $mysqli->prepare("DELETE FROM ep_event_groups WHERE event_id = ? AND group_id = ?");
    $stmt->bind_param("ii", $eventId, $groupId);
    $stmt->execute();
    $stmt->close();
    ep_json(["status" => "OK"]);
}

if ($action === 'set') {
    $groupIds = $data['group_ids'] ?? [];
    $allMembers = !empty($data['all_members']) ? 1 : 0;
    if (!is_array($groupIds)) {
        ep_json(["status" => "error", "message" => "group_ids must be array"]);
    }

    $stmt = $mysqli->prepare("UPDATE ep_events SET all_members = ? WHERE id = ?");
    $stmt->bind_param("ii", $allMembers, $eventId);
    $stmt->execute();
    $stmt->close();

    $stmt = $mysqli->prepare("DELETE FROM ep_event_groups WHERE event_id = ?");
    $stmt->bind_param("i", $eventId);
    $stmt->execute();
    $stmt->close();

    if (!$allMembers) {
        $stmt = $mysqli->prepare("INSERT INTO ep_event_groups (event_id, group_id) VALUES (?, ?)");
        foreach ($groupIds as $id) {
            $id = (int)$id;
            if ($id <= 0) continue;
            $stmt->bind_param("ii", $eventId, $id);
            $stmt->execute();
        }
        $stmt->close();
    }

    ep_json(["status" => "OK"]);
}

ep_json(["status" => "error", "message" => "Unsupported action"]);
