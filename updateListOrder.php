<?php
// updateListOrder.php
header('Content-Type: application/json');
require_once __DIR__ . "/includes/functions.php";
require_once __DIR__ . "/includes/db_connect.php";
sec_session_start();

$username = $_SESSION['username'] ?? null;
if (!$username) {
    echo json_encode(["status" => "error", "message" => "Not logged in"]);
    exit;
}

// Decode input first so we know $token
$data = json_decode(file_get_contents("php://input"), true);
$token = $data['token'] ?? null;
$orderList = $data['order'] ?? [];

if (!$token || !is_array($orderList)) {
    echo json_encode(["status" => "error", "message" => "Invalid input"]);
    exit;
}

// ✅ Check rights
// $roleRank = get_user_list_role_rank($mysqli, $token, $username);
// if ($roleRank < 60) {
//     echo json_encode(["status" => "error", "message" => "Permission denied"]);
//     exit;
// }


// Now resolve list_id
$list_id = null;

// Handle virtual list like "virtual-45"
if (strpos($token, "virtual-") === 0) {
    $user_id = intval(substr($token, 8));

    $stmt = $mysqli->prepare("SELECT id FROM content_lists WHERE name = 'All Content (System)' AND owner_id = ?");
    $stmt->bind_param("i", $user_id);
    $stmt->execute();
    $stmt->bind_result($list_id);
    $stmt->fetch();
    $stmt->close();

    if (!$list_id) {
        $newToken = bin2hex(random_bytes(8));
        $stmt = $mysqli->prepare("INSERT INTO content_lists (name, token, owner_id, created_by_id) VALUES ('All Content (System)', ?, ?, ?)");
        $stmt->bind_param("sii", $newToken, $user_id, $user_id);
        $stmt->execute();
        $list_id = $stmt->insert_id;
        $stmt->close();
    }
} else {
    $stmt = $mysqli->prepare("SELECT id FROM content_lists WHERE token = ?");
    $stmt->bind_param("s", $token);
    $stmt->execute();
    $stmt->bind_result($list_id);
    $stmt->fetch();
    $stmt->close();

    if (!$list_id) {
        echo json_encode(["status" => "error", "message" => "List not found"]);
        exit;
    }
}

// ✅ Update sort order
$stmt = $mysqli->prepare("
    INSERT INTO content_list_items (content_list_id, surrogate, sort_order)
    VALUES (?, ?, ?)
    ON DUPLICATE KEY UPDATE sort_order = VALUES(sort_order)
");

foreach ($orderList as $item) {
    $position = (int)$item['position'];
    $surrogate = (int)$item['surrogate'];
    if ($surrogate <= 0 || $position <= 0) {
        continue;
    }
    $stmt->bind_param("iii", $list_id, $surrogate, $position);
    $stmt->execute();
}

$stmt->close();
$mysqli->close();

echo json_encode(["status" => "OK"]);
