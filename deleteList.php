<?php
// delete_list.php
require_once __DIR__ . "/includes/db_connect.php";
require_once __DIR__ . "/includes/functions.php";

sec_session_start();
header('Content-Type: text/plain');

if (!login_check($mysqli)) {
    http_response_code(403);
    echo "Not logged in";
    exit;
}

$user_id = $_SESSION['user_id'] ?? null;
$username = $_SESSION['username'] ?? '';
$isSiteAdmin = !empty($_SESSION['is_admin']);

$token = $_GET['token'] ?? '';
if (!$token) {
    http_response_code(400);
    echo "Missing token";
    exit;
}

// 🔒 Check if the user owns the list or is an admin for it
$check = $mysqli->prepare("
    SELECT cl.owner_id, m.username
    FROM content_lists cl
    JOIN members m ON m.id = cl.owner_id
    WHERE cl.token = ?
    LIMIT 1
");
$check->bind_param("s", $token);
$check->execute();
$check->bind_result($owner_id, $owner_username);
$check->fetch();
$check->close();

$roleRankOwner = get_user_list_role_rank($mysqli, $owner_username, $username);
$roleRankList = get_user_list_role_rank($mysqli, $token, $username);

// Fallback: some invites store role without role_rank
$inviteRank = 0;
$userEmail = '';
$emailStmt = $mysqli->prepare("SELECT email FROM members WHERE id = ? LIMIT 1");
$emailStmt->bind_param("i", $user_id);
$emailStmt->execute();
$emailStmt->bind_result($userEmail);
$emailStmt->fetch();
$emailStmt->close();

if ($userEmail) {
    $invStmt = $mysqli->prepare("
        SELECT COALESCE(i.role_rank, 0) AS role_rank, COALESCE(i.role, '') AS role
        FROM invitations i
        WHERE i.listToken IN (?, ?) AND (i.email = ? OR i.member_id = ?)
        LIMIT 1
    ");
    $invStmt->bind_param("sssi", $owner_username, $token, $userEmail, $user_id);
    $invStmt->execute();
    $invStmt->bind_result($roleRankRaw, $roleRaw);
    if ($invStmt->fetch()) {
        $inviteRank = (int)$roleRankRaw;
        if ($inviteRank <= 0 && $roleRaw) {
            $roleRaw = strtolower(trim($roleRaw));
            if ($roleRaw === 'admin' || $roleRaw === 'owner') {
                $inviteRank = 90;
            } elseif ($roleRaw === 'editor') {
                $inviteRank = 80;
            } elseif ($roleRaw === 'viewer' || $roleRaw === 'commenter') {
                $inviteRank = 60;
            }
        }
    }
    $invStmt->close();
}

$maxRoleRank = max($roleRankOwner, $roleRankList, $inviteRank);
if ($owner_id !== $user_id && !$isSiteAdmin && $maxRoleRank < 80) {
    http_response_code(403);
    echo "Permission denied";
    exit;
}

// ✅ Proceed with delete
$stmt = $mysqli->prepare("DELETE FROM content_lists WHERE token = ?");
$stmt->bind_param("s", $token);
if ($stmt->execute()) {
    echo "List deleted";
} else {
    http_response_code(500);
    echo "Failed to delete list";
}
$stmt->close();
?>
