<?php
require_once __DIR__ . "/includes/functions.php";
require_once __DIR__ . "/includes/db_connect.php";

sec_session_start();

header('Content-Type: application/json');

if (!login_check($mysqli)) {
    http_response_code(403);
    echo json_encode(["error" => "Not logged in"]);
    exit;
}

$requestedOwner = trim($_GET['owner'] ?? '');
$sessionUser = $_SESSION['username'] ?? '';
$topLevelOnly = isset($_GET['topLevel']) && (string)$_GET['topLevel'] === '1';

$ownerUsername = $sessionUser;
if ($requestedOwner && $requestedOwner !== $sessionUser) {
    $roleRank = get_user_list_role_rank($mysqli, $requestedOwner, $sessionUser);
    if ($roleRank < 80) {
        http_response_code(403);
        echo json_encode(["error" => "Permission denied"]);
        exit;
    }
    $ownerUsername = $requestedOwner;
}

$sql = "
    SELECT l.id, l.name, l.token
    FROM content_lists l
    JOIN members owner ON owner.id = l.owner_id
    WHERE owner.username = ?
      AND l.token <> owner.username
";

if ($topLevelOnly) {
    $sql .= " AND l.parent_id IS NULL";
}

$sql .= " ORDER BY l.id DESC";

$stmt = $mysqli->prepare($sql);
$stmt->bind_param("s", $ownerUsername);  // ✅ bind as string
$stmt->execute();
$result = $stmt->get_result();

$lists = [];
while ($row = $result->fetch_assoc()) {
    $lists[] = [
        "id" => (int)$row['id'],
        "name" => $row['name'],
        "token" => $row['token']
    ];
}

echo json_encode($lists);
?>
