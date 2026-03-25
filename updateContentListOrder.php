<?php
// updateContentListOrder.php
header('Content-Type: application/json');
require_once __DIR__ . "/includes/functions.php";
require_once __DIR__ . "/includes/db_connect.php";
sec_session_start();


$username = $_SESSION['username'] ?? null;
if (!$username) {
    echo json_encode(["status" => "error", "message" => "Not logged in"]);
    exit;
}

// Get current user ID + email
$stmt = $mysqli->prepare("SELECT id, email FROM members WHERE username = ?");
$stmt->bind_param("s", $username);
$stmt->execute();
$stmt->bind_result($user_id, $user_email);
$stmt->fetch();
$stmt->close();

if (!$user_id) {
    echo json_encode(["status" => "error", "message" => "User not found"]);
    exit;
}

// Decode input payload
$data        = json_decode(file_get_contents("php://input"), true);
$section     = $data['section'] ?? 'owned'; // default to owned
$orderList   = $data['order']   ?? [];
$parentToken = $data['parentToken']  ?? null;
$movedToken  = $data['movedToken']   ?? null;

if (!is_array($orderList)) {
    echo json_encode(["status" => "error", "message" => "Invalid input"]);
    exit;
}






if ($section === "owned") {
    // Check ownership or admin/editor rights
    $stmt = $mysqli->prepare("
        SELECT cl.owner_id, m.username
        FROM content_lists cl
        JOIN members m ON m.id = cl.owner_id
        WHERE cl.token = ?
        LIMIT 1
    ");
    $stmt->bind_param("s", $movedToken);
    $stmt->execute();
    $stmt->bind_result($owner_id, $ownername);
    if (!$stmt->fetch()) {
        echo json_encode(["status" => "error", "message" => "List not found"]);
        exit;
    }
    $stmt->close();

    // ✅ Check rights using the list owner username (for global access)
    $roleRank = get_user_list_role_rank($mysqli, $ownername, $username);
    if ($roleRank < 60) {
        echo json_encode([
            "status" => "error",
            "message" => "Permission denied for $username on list owned by $ownername"
        ]);
        exit;
    }
}




if ($section === "owned") {
    // Resolve parent_id
    $parent_id = null;
    if ($parentToken) {
        $stmt = $mysqli->prepare("SELECT id FROM content_lists WHERE token = ? AND owner_id = ?");
        $stmt->bind_param("si", $parentToken, $owner_id);
        $stmt->execute();
        $stmt->bind_result($parent_id);
        $stmt->fetch();
        $stmt->close();
    }

    // Update moved item's parent_id
    if ($movedToken) {
        if ($parent_id === null) {
            $stmt = $mysqli->prepare("UPDATE content_lists SET parent_id = NULL WHERE token = ? AND owner_id = ?");
            $stmt->bind_param("si", $movedToken, $owner_id);
        } else {
            $stmt = $mysqli->prepare("UPDATE content_lists SET parent_id = ? WHERE token = ? AND owner_id = ?");
            $stmt->bind_param("isi", $parent_id, $movedToken, $owner_id);
        }
        $stmt->execute();
        $stmt->close();
    }

    // Update sibling order
    $stmt = $mysqli->prepare("UPDATE content_lists SET order_index = ? WHERE token = ? AND owner_id = ?");
    foreach ($orderList as $pos => $token) {
        $index = $pos + 1;
        $stmt->bind_param("isi", $index, $token, $owner_id);
        $stmt->execute();
    }
    $stmt->close();
}




elseif ($section === "followed") {
    // -------------------------------
    // FOLLOWED LISTS (flat order)
    // -------------------------------
    $stmt = $mysqli->prepare("UPDATE followed_lists SET order_index = ? WHERE user_id = ? AND list_token = ?");
    foreach ($orderList as $pos => $token) {
        $index = $pos + 1;
        $stmt->bind_param("iis", $index, $user_id, $token);
        $stmt->execute();
    }
    $stmt->close();

} elseif ($section === "invited") {
    // -------------------------------
    // INVITED LISTS (flat order)
    // -------------------------------
    $stmt = $mysqli->prepare("UPDATE invitations SET order_index = ? WHERE email = ? AND listToken = ?");
    foreach ($orderList as $pos => $token) {
        $index = $pos + 1;
        $stmt->bind_param("iss", $index, $user_email, $token);
        $stmt->execute();
    }
    $stmt->close();
}

$mysqli->close();
echo json_encode(["status" => "success"]);
