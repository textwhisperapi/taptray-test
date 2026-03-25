<?php
header('Content-Type: application/json');
require_once __DIR__ . "/includes/functions.php";
require_once __DIR__ . "/includes/db_connect.php";

sec_session_start();


$con = $mysqli;

$token = $_POST['token'] ?? '';
$access = $_POST['access_level'] ?? '';
$validAccess = ['public', 'private', 'secret'];

if (!$token || !in_array($access, $validAccess)) {
    echo json_encode(['status' => 'error', 'error' => 'Invalid input']);
    exit;
}

$user_id = (int)($_SESSION['user_id'] ?? 0);
if ($user_id <= 0) {
    echo json_encode(['status' => 'error', 'error' => 'Not logged in']);
    exit;
}

// Rights to manage list privacy come from the owner's default/root profile.
$stmt = $con->prepare("
    SELECT id
    FROM content_lists
    WHERE token = ?
    LIMIT 1
");
$stmt->bind_param("s", $token);
$stmt->execute();
$stmt->bind_result($list_id);
if (!$stmt->fetch()) {
    $stmt->close();
    echo json_encode(['status' => 'error', 'error' => 'List not found']);
    exit;
}
$stmt->close();

$roleRank = get_profile_role_rank_for_list($con, $token, $user_id);
if ($roleRank < 80) {
    echo json_encode(['status' => 'error', 'error' => 'Permission denied']);
    exit;
}

// ✅ Update access level
$stmt = $con->prepare("UPDATE content_lists SET access_level = ? WHERE id = ?");
$stmt->bind_param("si", $access, $list_id);
$updated = $stmt->execute();
$stmt->close();

if (!$updated) {
    echo json_encode(['status' => 'error', 'error' => 'Database update failed']);
    exit;
}

// If parent is private, force all descendants to private too.
if ($access === 'private') {
    $descendantIds = [];
    $queue = [$list_id];
    $visited = [$list_id => true];

    while (!empty($queue)) {
        $currentId = array_shift($queue);
        $childStmt = $con->prepare("SELECT id FROM content_lists WHERE parent_id = ?");
        $childStmt->bind_param("i", $currentId);
        $childStmt->execute();
        $res = $childStmt->get_result();
        while ($row = $res->fetch_assoc()) {
            $childId = (int)$row['id'];
            if (isset($visited[$childId])) {
                continue;
            }
            $visited[$childId] = true;
            $descendantIds[] = $childId;
            $queue[] = $childId;
        }
        $childStmt->close();
    }

    if (!empty($descendantIds)) {
        $placeholders = implode(',', array_fill(0, count($descendantIds), '?'));
        $types = str_repeat('i', count($descendantIds));
        $sql = "UPDATE content_lists SET access_level = 'private' WHERE id IN ($placeholders)";
        $updateChildrenStmt = $con->prepare($sql);
        $updateChildrenStmt->bind_param($types, ...$descendantIds);
        $updateChildrenStmt->execute();
        $updateChildrenStmt->close();
    }
}

echo json_encode(['status' => 'success']);
