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

function ep_normalize_owner_token($tokenOrUser) {
    if (!$tokenOrUser) return $tokenOrUser;
    if (str_starts_with($tokenOrUser, 'invited-')) {
        return substr($tokenOrUser, strlen('invited-'));
    }
    return $tokenOrUser;
}

function ep_resolve_owner_id($mysqli, $tokenOrUser, $fallback) {
    $tokenOrUser = ep_normalize_owner_token($tokenOrUser);
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

function ep_parse_date_or_null($value) {
    $value = trim((string)$value);
    if ($value === '' || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $value)) {
        return null;
    }
    return $value;
}

function ep_event_filter_fragment($alias, $groupId, $category, $includeAllMembersForGroup = false) {
    $sql = '';
    $types = '';
    $params = [];
    if ($groupId > 0) {
        $sql .= " AND (" . ($includeAllMembersForGroup ? "{$alias}.all_members = 1 OR " : "") . "EXISTS (
            SELECT 1
            FROM ep_event_groups egf
            WHERE egf.event_id = {$alias}.id
              AND egf.group_id = ?
        ))";
        $types .= 'i';
        $params[] = $groupId;
    }
    if ($category !== '') {
        $sql .= " AND LOWER(TRIM({$alias}.category)) = LOWER(TRIM(?))";
        $types .= 's';
        $params[] = $category;
    }
    return [$sql, $types, $params];
}

function ep_build_in_clause_params($ids) {
    $ids = array_values(array_unique(array_map('intval', $ids)));
    $ids = array_values(array_filter($ids, function ($v) {
        return $v > 0;
    }));
    if (!$ids) return [null, '', []];
    $placeholders = implode(',', array_fill(0, count($ids), '?'));
    $types = str_repeat('i', count($ids));
    return [$placeholders, $types, $ids];
}

$rawOwner = $_GET['owner'] ?? null;
$ownerId = ep_resolve_owner_id($mysqli, $rawOwner, (int)$memberId);

$username = $_SESSION['username'] ?? '';
$listTokenForRole = $rawOwner ?: $username;
$roleRank = $username ? get_user_list_role_rank($mysqli, $listTokenForRole, $username) : 0;
if ($roleRank < 80 && $listTokenForRole && str_starts_with($listTokenForRole, 'invited-')) {
    $fallbackToken = substr($listTokenForRole, strlen('invited-'));
    if ($fallbackToken !== '') {
        $roleRank = max($roleRank, (int)get_user_list_role_rank($mysqli, $fallbackToken, $username));
    }
}

$hasAccess = ((int)$ownerId === (int)$memberId) || ($roleRank >= 80);
if (!$hasAccess) {
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
    $hasAccess = $stmt->num_rows > 0;
    $stmt->close();
}

if (!$hasAccess) {
    ep_json(["status" => "OK", "attendants" => [], "total_events" => 0]);
}

$year = (int)($_GET['year'] ?? 0);
$from = ep_parse_date_or_null($_GET['from'] ?? '');
$to = ep_parse_date_or_null($_GET['to'] ?? '');
$groupId = (int)($_GET['group_id'] ?? 0);
$category = trim((string)($_GET['category'] ?? ''));
$includeAllMembersForGroup = false;
if ($groupId > 0) {
    $stmt = $mysqli->prepare("
        SELECT is_all_members
        FROM ep_groups
        WHERE id = ? AND owner_id = ?
        LIMIT 1
    ");
    $stmt->bind_param("ii", $groupId, $ownerId);
    $stmt->execute();
    $stmt->bind_result($isAllMembersGroupFlag);
    if ($stmt->fetch()) {
        $includeAllMembersForGroup = ((int)$isAllMembersGroupFlag === 1);
    }
    $stmt->close();
}

if ($from && $to) {
    $periodStart = $from . ' 00:00:00';
    $periodEnd = $to . ' 23:59:59';
} else {
    if ($year < 1970 || $year > 2200) {
        $year = (int)date('Y');
    }
    $periodStart = sprintf('%04d-01-01 00:00:00', $year);
    $periodEnd = sprintf('%04d-12-31 23:59:59', $year);
}

if (strcmp($periodStart, $periodEnd) > 0) {
    ep_json(["status" => "error", "message" => "Invalid period"]);
}

$ownerUsername = '';
$stmt = $mysqli->prepare("SELECT username FROM members WHERE id = ? LIMIT 1");
$stmt->bind_param("i", $ownerId);
$stmt->execute();
$stmt->bind_result($ownerUsername);
$stmt->fetch();
$stmt->close();

[$eventFilterSqlE, $eventFilterTypesE, $eventFilterParamsE] = ep_event_filter_fragment('e', $groupId, $category, $includeAllMembersForGroup);
$types = "iss" . $eventFilterTypesE;
$params = array_merge([$ownerId, $periodStart, $periodEnd], $eventFilterParamsE);

$stmt = $mysqli->prepare("
    SELECT e.id, e.title, e.starts_at
    FROM ep_events e
    WHERE e.owner_id = ?
      AND e.starts_at BETWEEN ? AND ?
      {$eventFilterSqlE}
    ORDER BY e.starts_at ASC
");
$stmt->bind_param($types, ...$params);
$stmt->execute();
$res = $stmt->get_result();
$events = [];
while ($row = $res->fetch_assoc()) {
    $events[] = [
        "id" => (int)$row["id"],
        "title" => $row["title"] ?: "Event",
        "starts_at" => $row["starts_at"]
    ];
}
$stmt->close();
$totalEvents = count($events);
$eventIds = array_map(function ($event) {
    return (int)($event['id'] ?? 0);
}, $events);
[$eventInPlaceholders, $eventInTypes, $eventInParams] = ep_build_in_clause_params($eventIds);

if (!$eventInPlaceholders) {
    ep_json([
        "status" => "OK",
        "period_start" => $periodStart,
        "period_end" => $periodEnd,
        "total_events" => 0,
        "attendants" => [],
        "members" => [],
        "events" => [],
        "checkins" => [],
        "in_scope" => []
    ]);
}

$stmt = $mysqli->prepare("
    SELECT c.event_id, c.member_id, c.status
    FROM ep_checkins c
    WHERE c.event_id IN ($eventInPlaceholders)
");
$stmt->bind_param($eventInTypes, ...$eventInParams);
$stmt->execute();
$res = $stmt->get_result();
$checkins = [];
while ($row = $res->fetch_assoc()) {
    $checkins[] = [
        "event_id" => (int)$row["event_id"],
        "member_id" => (int)$row["member_id"],
        "status" => $row["status"] ?: "in"
    ];
}
$stmt->close();

$stmt = $mysqli->prepare("
    SELECT DISTINCT e.id AS event_id, e.owner_id AS member_id
    FROM ep_events e
    WHERE e.id IN ($eventInPlaceholders)
    UNION
    SELECT DISTINCT e.id AS event_id, m.id AS member_id
    FROM ep_events e
    JOIN content_lists cl ON cl.owner_id = e.owner_id AND cl.token = ?
    JOIN invitations i ON i.listToken = cl.token
    JOIN members m ON m.email = i.email
    WHERE e.id IN ($eventInPlaceholders)
      AND e.all_members = 1
    UNION
    SELECT DISTINCT eg.event_id, gm.member_id
    FROM ep_event_groups eg
    JOIN ep_group_members gm ON gm.group_id = eg.group_id
    WHERE eg.event_id IN ($eventInPlaceholders)
");
$inScopeTypes = $eventInTypes . "s" . $eventInTypes . $eventInTypes;
$inScopeParams = array_merge(
    $eventInParams,
    [$ownerUsername],
    $eventInParams,
    $eventInParams
);
$stmt->bind_param($inScopeTypes, ...$inScopeParams);
$stmt->execute();
$res = $stmt->get_result();
$inScope = [];
while ($row = $res->fetch_assoc()) {
    $inScope[] = [
        "event_id" => (int)$row["event_id"],
        "member_id" => (int)$row["member_id"]
    ];
}
$stmt->close();

$memberIdMap = [$ownerId => true];
foreach ($checkins as $entry) {
    $id = (int)($entry['member_id'] ?? 0);
    if ($id > 0) $memberIdMap[$id] = true;
}
foreach ($inScope as $entry) {
    $id = (int)($entry['member_id'] ?? 0);
    if ($id > 0) $memberIdMap[$id] = true;
}
$memberIds = array_keys($memberIdMap);
$members = [];
if ($memberIds) {
    $placeholders = implode(',', array_fill(0, count($memberIds), '?'));
    $stmt = $mysqli->prepare("
        SELECT
          m.id AS member_id,
          m.username,
          m.display_name,
          m.avatar_url,
          (
            SELECT gm.role
            FROM ep_group_members gm
            JOIN ep_groups g ON g.id = gm.group_id
            WHERE g.owner_id = ?
              AND gm.member_id = m.id
              AND gm.role IS NOT NULL
              AND gm.role <> ''
            ORDER BY gm.joined_at DESC
            LIMIT 1
          ) AS role
        FROM members m
        WHERE m.id IN ($placeholders)
        ORDER BY m.display_name ASC, m.username ASC
    ");
    $memberTypes = "i" . str_repeat("i", count($memberIds));
    $memberParams = array_merge([$ownerId], array_map('intval', $memberIds));
    $stmt->bind_param($memberTypes, ...$memberParams);
    $stmt->execute();
    $res = $stmt->get_result();
    while ($row = $res->fetch_assoc()) {
        $members[] = [
            "member_id" => (int)$row['member_id'],
            "username" => $row['username'],
            "display_name" => $row['display_name'],
            "avatar_url" => $row['avatar_url'] ?: '/default-avatar.png',
            "role" => $row['role'] ?: ''
        ];
    }
    $stmt->close();
}

$stmt = $mysqli->prepare("
    SELECT
      m.id AS member_id,
      m.username,
      m.display_name,
      m.avatar_url,
      (
        SELECT gm.role
        FROM ep_group_members gm
        JOIN ep_groups g ON g.id = gm.group_id
        WHERE g.owner_id = ?
          AND gm.member_id = m.id
          AND gm.role IS NOT NULL
          AND gm.role <> ''
        ORDER BY gm.joined_at DESC
        LIMIT 1
      ) AS role,
      COUNT(DISTINCT e.id) AS attended_events,
      MIN(e.starts_at) AS first_event_at,
      MAX(e.starts_at) AS last_event_at
    FROM ep_checkins c
    JOIN ep_events e ON e.id = c.event_id
    JOIN members m ON m.id = c.member_id
    WHERE e.id IN ($eventInPlaceholders)
      AND c.status = 'in'
    GROUP BY m.id, m.username, m.display_name, m.avatar_url
    ORDER BY attended_events DESC, m.display_name ASC, m.username ASC
");
$attendantTypes = "i" . $eventInTypes;
$attendantParams = array_merge([$ownerId], $eventInParams);
$stmt->bind_param($attendantTypes, ...$attendantParams);
$stmt->execute();
$res = $stmt->get_result();

$attendants = [];
while ($row = $res->fetch_assoc()) {
    $attendants[] = [
        "member_id" => (int)$row['member_id'],
        "username" => $row['username'],
        "display_name" => $row['display_name'],
        "avatar_url" => $row['avatar_url'] ?: '/default-avatar.png',
        "role" => $row['role'] ?: '',
        "attended_events" => (int)$row['attended_events'],
        "first_event_at" => $row['first_event_at'],
        "last_event_at" => $row['last_event_at']
    ];
}
$stmt->close();

ep_json([
    "status" => "OK",
    "period_start" => $periodStart,
    "period_end" => $periodEnd,
    "total_events" => (int)$totalEvents,
    "attendants" => $attendants,
    "members" => $members,
    "events" => $events,
    "checkins" => $checkins,
    "in_scope" => $inScope
]);
