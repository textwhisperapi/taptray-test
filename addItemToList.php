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

$user_id = $_SESSION['user_id'];
$username = $_SESSION['username'] ?? '';
$token = $_POST['token'] ?? '';
$surrogateRaw = $_POST['surrogate'] ?? '';
$surrogate = (int)$surrogateRaw;
$orderRaw = $_POST['order'] ?? null;
$orderProvided = ($orderRaw !== null && $orderRaw !== '');
$order = $orderProvided ? (int)$orderRaw : null;

if (!$token || $surrogate <= 0) {
    http_response_code(400);
    echo json_encode(["error" => "Missing or invalid token/surrogate"]);
    exit;
}

// 🔍 Try to find list by token (+ owner username)
$stmt = $mysqli->prepare("
    SELECT cl.id, cl.owner_id, m.username
    FROM content_lists cl
    LEFT JOIN members m ON m.id = cl.owner_id
    WHERE cl.token = ?
");
$stmt->bind_param("s", $token);
$stmt->execute();
$stmt->bind_result($list_id, $owner_id, $owner_username);
$stmt->fetch();
$stmt->close();

if (!$list_id) {
    http_response_code(404);
    echo json_encode(["error" => "List not found"]);
    exit;
}

// 🔒 Check permission: owner OR invited admin/editor
$hasPermission = ($owner_id === $user_id);

if (!$hasPermission && $username) {
    // Check if user is an invited admin/editor
    $stmt = $mysqli->prepare("
        SELECT 1 FROM invitations i
        JOIN members m ON m.email = i.email
        WHERE i.listToken = ? AND m.username = ? AND i.role IN ('admin', 'editor')
        LIMIT 1
    ");
    $stmt->bind_param("ss", $token, $username);
    $stmt->execute();
    $stmt->store_result();
    $hasPermission = $stmt->num_rows > 0;
    $stmt->close();
}

// ✅ Allow if user is admin/editor on owner's root list (list owner profile)
if (!$hasPermission && $username && $owner_username) {
    $hasPermission = get_user_list_role_rank($mysqli, $owner_username, $username) >= 80;
}

if (!$hasPermission) {
    http_response_code(403);
    echo json_encode(["error" => "Permission denied"]);
    exit;
}

// ✅ Optional order support (backward compatible):
// - no order param: legacy behavior
// - order = 0: append to end
// - order >= 1: insert at position N (shift existing down)
$success = false;
$mysqli->begin_transaction();
try {
    if (!$orderProvided) {
        $stmt = $mysqli->prepare("
            INSERT IGNORE INTO content_list_items (content_list_id, surrogate)
            SELECT ?, t.Surrogate
            FROM text t
            WHERE t.Surrogate = ?
        ");
        $stmt->bind_param("ii", $list_id, $surrogate);
        $success = $stmt->execute();
        $stmt->close();
    } else {
        // Ensure surrogate exists
        $stmt = $mysqli->prepare("SELECT 1 FROM text WHERE Surrogate = ? LIMIT 1");
        $stmt->bind_param("i", $surrogate);
        $stmt->execute();
        $stmt->store_result();
        $exists = $stmt->num_rows > 0;
        $stmt->close();
        if (!$exists) {
            throw new RuntimeException("Surrogate not found");
        }

        // Skip if already in list
        $stmt = $mysqli->prepare("SELECT 1 FROM content_list_items WHERE content_list_id = ? AND surrogate = ? LIMIT 1");
        $stmt->bind_param("ii", $list_id, $surrogate);
        $stmt->execute();
        $stmt->store_result();
        $already = $stmt->num_rows > 0;
        $stmt->close();
        if ($already) {
            $success = true;
        } else {
            if ($order <= 0) {
                // append to end
                $nextOrder = 1;
                $stmt = $mysqli->prepare("
                    SELECT COALESCE(MAX(CASE WHEN sort_order IS NULL OR sort_order < 1 THEN 0 ELSE sort_order END), 0) + 1
                    FROM content_list_items
                    WHERE content_list_id = ?
                ");
                $stmt->bind_param("i", $list_id);
                $stmt->execute();
                $stmt->bind_result($nextOrder);
                $stmt->fetch();
                $stmt->close();

                $stmt = $mysqli->prepare("
                    INSERT INTO content_list_items (content_list_id, surrogate, sort_order)
                    VALUES (?, ?, ?)
                ");
                $stmt->bind_param("iii", $list_id, $surrogate, $nextOrder);
                $success = $stmt->execute();
                $stmt->close();
            } else {
                // insert at explicit position N
                $position = max(1, (int)$order);
                $fallbackAfter = $position + 1;

                $stmt = $mysqli->prepare("
                    UPDATE content_list_items
                    SET sort_order = CASE
                        WHEN sort_order IS NULL OR sort_order < 1 THEN ?
                        WHEN sort_order >= ? THEN sort_order + 1
                        ELSE sort_order
                    END
                    WHERE content_list_id = ?
                ");
                $stmt->bind_param("iii", $fallbackAfter, $position, $list_id);
                $stmt->execute();
                $stmt->close();

                $stmt = $mysqli->prepare("
                    INSERT INTO content_list_items (content_list_id, surrogate, sort_order)
                    VALUES (?, ?, ?)
                ");
                $stmt->bind_param("iii", $list_id, $surrogate, $position);
                $success = $stmt->execute();
                $stmt->close();
            }
        }
    }
    $mysqli->commit();
} catch (Throwable $e) {
    $mysqli->rollback();
    $success = false;
}

echo json_encode(["status" => $success ? "OK" : "Failed"]);

$mysqli->close();
?>
