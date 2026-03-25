<?php
header('Content-Type: application/json; charset=utf-8');

require_once __DIR__ . "/includes/functions.php";
require_once __DIR__ . "/includes/db_connect.php";

sec_session_start();

if (!login_check($mysqli)) {
    http_response_code(403);
    echo json_encode([
        "status" => "error",
        "message" => "Not logged in",
        "profiles" => []
    ]);
    exit;
}

$currentUserId = (int)($_SESSION['user_id'] ?? 0);
$currentUser = trim((string)($_SESSION['username'] ?? ''));
$currentEmail = trim((string)($_SESSION['email'] ?? ''));

if ($currentUserId <= 0 || $currentUser === '') {
    http_response_code(403);
    echo json_encode([
        "status" => "error",
        "message" => "Invalid session",
        "profiles" => []
    ]);
    exit;
}

$profiles = [];

// Always include own profile.
$stmt = $mysqli->prepare("
    SELECT username, COALESCE(display_name, username) AS display_name, COALESCE(avatar_url, '/default-avatar.png') AS avatar_url
    FROM members
    WHERE id = ?
    LIMIT 1
");
$stmt->bind_param("i", $currentUserId);
$stmt->execute();
$res = $stmt->get_result();
if ($row = $res->fetch_assoc()) {
    $token = trim((string)$row['username']);
    if ($token !== '') {
        $profiles[$token] = [
            "username" => $token,
            "display_name" => $row['display_name'] ?: $token,
            "avatar_url" => $row['avatar_url'] ?: "/default-avatar.png",
            "role_rank" => 90
        ];
    }
}
$stmt->close();

// Add profiles where current user has admin/owner rights on the owner's root list.
$stmt = $mysqli->prepare("
    SELECT
        owner.username AS username,
        COALESCE(owner.display_name, owner.username) AS display_name,
        COALESCE(owner.avatar_url, '/default-avatar.png') AS avatar_url,
        MAX(i.role_rank) AS role_rank
    FROM invitations i
    JOIN content_lists cl ON cl.token = i.listToken
    JOIN members owner ON owner.id = cl.owner_id
    WHERE cl.token = owner.username
      AND i.role_rank >= 80
      AND (
          (i.member_id IS NOT NULL AND i.member_id = ?)
          OR (? <> '' AND i.email = ?)
      )
    GROUP BY owner.id, owner.username, owner.display_name, owner.avatar_url
    ORDER BY display_name ASC
");
$stmt->bind_param("iss", $currentUserId, $currentEmail, $currentEmail);
$stmt->execute();
$res = $stmt->get_result();
while ($row = $res->fetch_assoc()) {
    $token = trim((string)$row['username']);
    if ($token === '') continue;
    $rank = (int)($row['role_rank'] ?? 0);
    if ($rank < 80) continue;

    if (!isset($profiles[$token]) || $rank > (int)$profiles[$token]['role_rank']) {
        $profiles[$token] = [
            "username" => $token,
            "display_name" => $row['display_name'] ?: $token,
            "avatar_url" => $row['avatar_url'] ?: "/default-avatar.png",
            "role_rank" => $rank
        ];
    }
}
$stmt->close();

$out = array_values($profiles);
usort($out, static function ($a, $b) {
    return strcasecmp((string)$a['display_name'], (string)$b['display_name']);
});

echo json_encode([
    "status" => "success",
    "profiles" => $out
], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

