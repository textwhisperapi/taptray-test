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

function ep_owner_username($mysqli, $ownerId) {
    $stmt = $mysqli->prepare("SELECT username FROM members WHERE id = ? LIMIT 1");
    $stmt->bind_param("i", $ownerId);
    $stmt->execute();
    $stmt->bind_result($username);
    $stmt->fetch();
    $stmt->close();
    return $username ?: "";
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
$canManage = ($ownerId === (int)$memberId) || ($roleRank >= 90);

if (!$canManage) {
    echo json_encode(["status" => "OK", "members" => []]);
    exit;
}

$ownerUsername = ep_owner_username($mysqli, $ownerId);
$listToken = $rawOwner ?: $ownerUsername;
$listToken = ep_normalize_owner_token($listToken);
if ($listToken === "") {
    echo json_encode(["status" => "OK", "members" => []]);
    exit;
}

$stmt = $mysqli->prepare("
    SELECT DISTINCT m.id AS member_id, m.username, m.display_name, m.avatar_url, m.email,
           (
             SELECT gm.role
             FROM ep_group_members gm
             JOIN ep_groups g ON g.id = gm.group_id
             WHERE gm.member_id = m.id AND g.owner_id = ?
             AND gm.role IS NOT NULL AND gm.role <> ''
             ORDER BY gm.joined_at DESC
             LIMIT 1
           ) AS role
    FROM content_lists cl
    JOIN invitations i ON i.listToken = cl.token
    JOIN members m ON m.email = i.email
    WHERE cl.owner_id = ? AND cl.token = ?
    ORDER BY m.display_name ASC, m.username ASC
");
$stmt->bind_param("iis", $ownerId, $ownerId, $listToken);
$stmt->execute();
$res = $stmt->get_result();

$members = [];
while ($row = $res->fetch_assoc()) {
    $members[] = [
        "member_id" => (int)$row["member_id"],
        "username" => $row["username"],
        "display_name" => $row["display_name"],
        "avatar_url" => $row["avatar_url"] ?: "/default-avatar.png",
        "email" => $row["email"],
        "role" => $row["role"] ?: ""
    ];
}
$stmt->close();

echo json_encode(["status" => "OK", "members" => $members]);
