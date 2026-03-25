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

function ep_events_has_column(mysqli $mysqli, string $columnName): bool {
    $safeName = preg_replace('/[^a-zA-Z0-9_]/', '', $columnName);
    if ($safeName === '') return false;
    $res = $mysqli->query("SHOW COLUMNS FROM ep_events LIKE '{$safeName}'");
    if (!$res) return false;
    $has = $res->num_rows > 0;
    $res->close();
    return $has;
}

function ep_events_ensure_recurring_columns(mysqli $mysqli): array {
    $hasSeriesId = ep_events_has_column($mysqli, 'recurring_series_id');
    if (!$hasSeriesId) {
        @$mysqli->query("ALTER TABLE ep_events ADD COLUMN recurring_series_id VARCHAR(64) NULL AFTER created_by_member_id");
        $hasSeriesId = ep_events_has_column($mysqli, 'recurring_series_id');
        if ($hasSeriesId) {
            @$mysqli->query("ALTER TABLE ep_events ADD INDEX idx_ep_events_series_owner (owner_id, recurring_series_id)");
        }
    }
    return [
        'recurring_series_id' => $hasSeriesId
    ];
}

$rawOwner = $_GET['owner'] ?? null;

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

$ownerId = ep_resolve_owner_id($mysqli, $rawOwner, (int)$memberId);

$eventId = (int)($_GET['event_id'] ?? 0);
if ($eventId <= 0) {
    ep_json(["status" => "error", "message" => "event_id required"]);
}

$recurringCols = ep_events_ensure_recurring_columns($mysqli);
$hasSeriesIdColumn = !empty($recurringCols['recurring_series_id']);
$seriesSelect = $hasSeriesIdColumn ? "COALESCE(recurring_series_id, '') AS recurring_series_id" : "'' AS recurring_series_id";
$stmt = $mysqli->prepare("
    SELECT id, title, category, location, starts_at, ends_at, notes, owner_id, created_by_member_id, {$seriesSelect}, all_members, created_at
    FROM ep_events
    WHERE id = ?
");
$stmt->bind_param("i", $eventId);
$stmt->execute();
$event = $stmt->get_result()->fetch_assoc();
$stmt->close();

if (!$event) {
    ep_json(["status" => "error", "message" => "Event not found"]);
}

$eventOwnerId = (int)$event['owner_id'];
$contextOwnerId = $eventOwnerId;
$allMembersGroupId = null;
$stmt = $mysqli->prepare("
    SELECT id FROM ep_groups
    WHERE owner_id = ? AND is_all_members = 1
    LIMIT 1
");
$stmt->bind_param("i", $contextOwnerId);
$stmt->execute();
$stmt->bind_result($allMembersGroupId);
$stmt->fetch();
$stmt->close();
$hasAccess = ($eventOwnerId === (int)$ownerId);
if (!$hasAccess) {
    $stmt = $mysqli->prepare("
        SELECT 1
        FROM ep_event_groups eg
        JOIN ep_group_members gm ON gm.group_id = eg.group_id
        WHERE eg.event_id = ? AND gm.member_id = ?
        LIMIT 1
    ");
    $stmt->bind_param("ii", $eventId, $memberId);
    $stmt->execute();
    $stmt->store_result();
    if ($stmt->num_rows > 0) {
        $hasAccess = true;
    }
    $stmt->close();
}
if (!$hasAccess && !empty($event['all_members'])) {
    $stmt = $mysqli->prepare("SELECT username FROM members WHERE id = ? LIMIT 1");
    $stmt->bind_param("i", $contextOwnerId);
    $stmt->execute();
    $stmt->bind_result($ownerUsername);
    $stmt->fetch();
    $stmt->close();
    if (empty($ownerUsername)) {
        $ownerUsername = null;
    }
    $stmt = $mysqli->prepare("
        SELECT 1
        FROM content_lists cl
        JOIN invitations i ON i.listToken = cl.token
        JOIN members m ON m.email = i.email
        WHERE cl.owner_id = ? AND cl.token = ? AND m.id = ?
        LIMIT 1
    ");
    $stmt->bind_param("isi", $contextOwnerId, $ownerUsername, $memberId);
    $stmt->execute();
    $stmt->store_result();
    if ($stmt->num_rows > 0) {
        $hasAccess = true;
    }
    $stmt->close();
}
if (!$hasAccess) {
    $stmt = $mysqli->prepare("
        SELECT 1 FROM ep_checkins WHERE event_id = ? AND member_id = ? LIMIT 1
    ");
    $stmt->bind_param("ii", $eventId, $memberId);
    $stmt->execute();
    $stmt->store_result();
    if ($stmt->num_rows > 0) {
        $hasAccess = true;
    }
    $stmt->close();
}
if (!$hasAccess) {
    ep_json(["status" => "error", "message" => "Permission denied"]);
}

$ownerId = $contextOwnerId;

$isOwner = ((int)$event['owner_id'] === (int)$memberId);

$ownerName = "";
$creatorName = "";
if (!empty($event['owner_id'])) {
    $stmt = $mysqli->prepare("SELECT display_name, username FROM members WHERE id = ? LIMIT 1");
    $stmt->bind_param("i", $event['owner_id']);
    $stmt->execute();
    $stmt->bind_result($ownerDisplay, $ownerUsername);
    $stmt->fetch();
    $stmt->close();
    $ownerName = $ownerDisplay ?: $ownerUsername ?: "";
}
if (!empty($event['created_by_member_id'])) {
    $stmt = $mysqli->prepare("SELECT display_name, username FROM members WHERE id = ? LIMIT 1");
    $stmt->bind_param("i", $event['created_by_member_id']);
    $stmt->execute();
    $stmt->bind_result($creatorDisplay, $creatorUsername);
    $stmt->fetch();
    $stmt->close();
    $creatorName = $creatorDisplay ?: $creatorUsername ?: "";
}
$event['owner_display_name'] = $ownerName;
$event['creator_display_name'] = $creatorName;

$stmt = $mysqli->prepare("
    SELECT g.id, g.name, g.description, g.color
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

$stmt = $mysqli->prepare("
    SELECT gm.group_id,
           (
             SELECT gm2.role
             FROM ep_group_members gm2
             WHERE gm2.group_id = ? AND gm2.member_id = m.id
             AND gm2.role IS NOT NULL AND gm2.role <> ''
             LIMIT 1
           ) AS role,
           m.id AS member_id, m.username, m.display_name, m.avatar_url
    FROM ep_event_groups eg
    JOIN ep_group_members gm ON gm.group_id = eg.group_id
    JOIN members m ON m.id = gm.member_id
    WHERE eg.event_id = ?
    ORDER BY m.display_name ASC, m.username ASC
");
$stmt->bind_param("ii", $allMembersGroupId, $eventId);
$stmt->execute();
$res = $stmt->get_result();
$members = [];
while ($row = $res->fetch_assoc()) {
    $members[] = $row;
}
$stmt->close();

$memberIds = [];
foreach ($members as $row) {
    $memberIds[(int)$row['member_id']] = true;
}

$currentUser = null;
$invitedMembers = [];

if (!empty($event['all_members'])) {
    if (!isset($ownerUsername)) {
        $stmt = $mysqli->prepare("SELECT username FROM members WHERE id = ? LIMIT 1");
        $stmt->bind_param("i", $contextOwnerId);
        $stmt->execute();
        $stmt->bind_result($ownerUsername);
        $stmt->fetch();
        $stmt->close();
    }
    $stmt = $mysqli->prepare("
        SELECT DISTINCT m.id AS member_id, m.username, m.display_name, m.avatar_url,
               (
                 SELECT gm.role
                 FROM ep_group_members gm
                 WHERE gm.group_id = ? AND gm.member_id = m.id
                 AND gm.role IS NOT NULL AND gm.role <> ''
                 LIMIT 1
               ) AS role
        FROM content_lists cl
        JOIN invitations i ON i.listToken = cl.token
        JOIN members m ON m.email = i.email
        WHERE cl.owner_id = ? AND cl.token = ? AND m.id <> ?
        ORDER BY m.display_name ASC, m.username ASC
    ");
    $stmt->bind_param("iisi", $allMembersGroupId, $ownerId, $ownerUsername, $ownerId);
    $stmt->execute();
    $res = $stmt->get_result();
    while ($row = $res->fetch_assoc()) {
        $id = (int)$row['member_id'];
        if (isset($memberIds[$id])) {
            continue;
        }
        $members[] = [
            "group_id" => null,
            "role" => $row['role'],
            "member_id" => $row['member_id'],
            "username" => $row['username'],
            "display_name" => $row['display_name'],
            "avatar_url" => $row['avatar_url'] ?: "/default-avatar.png"
        ];
        $memberIds[$id] = true;
    }
    $stmt->close();
}

if (!isset($ownerUsername)) {
    $stmt = $mysqli->prepare("SELECT username FROM members WHERE id = ? LIMIT 1");
    $stmt->bind_param("i", $contextOwnerId);
    $stmt->execute();
    $stmt->bind_result($ownerUsername);
    $stmt->fetch();
    $stmt->close();
}
if (!empty($ownerUsername)) {
    $stmt = $mysqli->prepare("
        SELECT DISTINCT m.id AS member_id, m.username, m.display_name, m.avatar_url, m.email,
               (
                 SELECT gm.role
                 FROM ep_group_members gm
                 WHERE gm.group_id = ? AND gm.member_id = m.id
                 AND gm.role IS NOT NULL AND gm.role <> ''
                 LIMIT 1
               ) AS role
        FROM content_lists cl
        JOIN invitations i ON i.listToken = cl.token
        JOIN members m ON m.email = i.email
        WHERE cl.owner_id = ? AND cl.token = ?
        ORDER BY m.display_name ASC, m.username ASC
    ");
    $stmt->bind_param("iis", $allMembersGroupId, $contextOwnerId, $ownerUsername);
    $stmt->execute();
    $res = $stmt->get_result();
    while ($row = $res->fetch_assoc()) {
        $invitedMembers[] = [
            "member_id" => (int)$row["member_id"],
            "username" => $row["username"],
            "display_name" => $row["display_name"],
            "avatar_url" => $row["avatar_url"] ?: "/default-avatar.png",
            "email" => $row["email"],
            "role" => $row["role"] ?: ""
        ];
    }
    $stmt->close();
}

$stmt = $mysqli->prepare("
    SELECT c.member_id, m.username, m.display_name, m.avatar_url,
           (
             SELECT gm.role
             FROM ep_group_members gm
             WHERE gm.group_id = ? AND gm.member_id = m.id
             AND gm.role IS NOT NULL AND gm.role <> ''
             LIMIT 1
           ) AS role
    FROM ep_checkins c
    JOIN members m ON m.id = c.member_id
    WHERE c.event_id = ?
");
$stmt->bind_param("ii", $allMembersGroupId, $eventId);
$stmt->execute();
$res = $stmt->get_result();
while ($row = $res->fetch_assoc()) {
    $id = (int)$row['member_id'];
    if (isset($memberIds[$id])) {
        continue;
    }
    $members[] = [
        "group_id" => null,
        "role" => $row['role'],
        "member_id" => $row['member_id'],
        "username" => $row['username'],
        "display_name" => $row['display_name'],
        "avatar_url" => $row['avatar_url']
    ];
    $memberIds[$id] = true;
}
$stmt->close();

$isMemberInScope = false;
$stmt = $mysqli->prepare("
    SELECT 1
    FROM ep_event_groups eg
    JOIN ep_group_members gm ON gm.group_id = eg.group_id
    WHERE eg.event_id = ? AND gm.member_id = ?
    LIMIT 1
");
$stmt->bind_param("ii", $eventId, $memberId);
$stmt->execute();
$stmt->store_result();
if ($stmt->num_rows > 0) {
    $isMemberInScope = true;
}
$stmt->close();

if (!$isMemberInScope && !empty($event['all_members'])) {
    $stmt = $mysqli->prepare("
        SELECT 1
        FROM content_lists cl
        JOIN invitations i ON i.listToken = cl.token
        JOIN members m ON m.email = i.email
        WHERE cl.owner_id = ? AND m.id = ?
        LIMIT 1
    ");
    $stmt->bind_param("ii", $ownerId, $memberId);
    $stmt->execute();
    $stmt->store_result();
    if ($stmt->num_rows > 0) {
        $isMemberInScope = true;
    }
    $stmt->close();
}

if (!$isMemberInScope) {
    $stmt = $mysqli->prepare("
        SELECT 1 FROM ep_checkins WHERE event_id = ? AND member_id = ? LIMIT 1
    ");
    $stmt->bind_param("ii", $eventId, $memberId);
    $stmt->execute();
    $stmt->store_result();
    if ($stmt->num_rows > 0) {
        $isMemberInScope = true;
    }
    $stmt->close();
}

if (!$isMemberInScope && isset($memberIds[(int)$memberId])) {
    $isMemberInScope = true;
}

if ($isMemberInScope) {
    $stmt = $mysqli->prepare("
        SELECT m.id AS member_id, m.username, m.display_name, m.avatar_url,
               (
                 SELECT gm.role
                 FROM ep_group_members gm
                 WHERE gm.group_id = ? AND gm.member_id = m.id
                 AND gm.role IS NOT NULL AND gm.role <> ''
                 LIMIT 1
               ) AS role
        FROM members m
        WHERE m.id = ?
    ");
    $stmt->bind_param("ii", $allMembersGroupId, $memberId);
    $stmt->execute();
    $res = $stmt->get_result();
    if ($row = $res->fetch_assoc()) {
        $currentUser = [
            "member_id" => (int)$row['member_id'],
            "username" => $row['username'],
            "display_name" => $row['display_name'],
            "avatar_url" => $row['avatar_url'] ?: "/default-avatar.png",
            "role" => $row['role']
        ];
        if (!isset($memberIds[(int)$memberId])) {
            $members[] = [
                "group_id" => null,
                "role" => $row['role'],
                "member_id" => $row['member_id'],
                "username" => $row['username'],
                "display_name" => $row['display_name'],
                "avatar_url" => $row['avatar_url'] ?: "/default-avatar.png"
            ];
            $memberIds[(int)$memberId] = true;
        }
    }
    $stmt->close();
}

$stmt = $mysqli->prepare("
    SELECT member_id, status, updated_at
    FROM ep_checkins
    WHERE event_id = ?
");
$stmt->bind_param("i", $eventId);
$stmt->execute();
$res = $stmt->get_result();
$checkins = [];
while ($row = $res->fetch_assoc()) {
    $checkins[] = $row;
}
$stmt->close();

ep_json([
    "status" => "OK",
    "event" => $event,
    "groups" => $groups,
    "members" => $members,
    "invited_members" => $invitedMembers,
    "checkins" => $checkins,
    "is_member" => $isMemberInScope ? 1 : 0,
    "current_user" => $currentUser
]);
