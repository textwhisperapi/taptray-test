<?php

include_once __DIR__ . '/includes/db_connect.php';
include_once __DIR__ . '/includes/functions.php';

sec_session_start();

header('Content-Type: application/json');

if (!isset($_SESSION['username'])) {
    http_response_code(403);
    die("Unauthorized: You must be logged in.");
}

$username = $_SESSION['username'];
$user_id = (int)($_SESSION['user_id'] ?? 0);
$isAdmin = !empty($_SESSION['is_admin']);

mysqli_set_charset($mysqli, 'utf8mb4');
mysqli_query($mysqli, "SET collation_connection = 'utf8mb4_unicode_ci'");

function tw_insert_item_with_order(mysqli $mysqli, int $listId, int $surrogate, int $order): void {
    if ($listId <= 0 || $surrogate <= 0) return;

    // order=0 => append to end; order>=1 => insert at that 1-based position.
    if ($order <= 0) {
        $nextOrder = 1;
        $stmt = $mysqli->prepare("
            SELECT COALESCE(MAX(CASE WHEN sort_order IS NULL OR sort_order < 1 THEN 0 ELSE sort_order END), 0) + 1
            FROM content_list_items
            WHERE content_list_id = ?
        ");
        $stmt->bind_param("i", $listId);
        $stmt->execute();
        $stmt->bind_result($nextOrder);
        $stmt->fetch();
        $stmt->close();

        $stmt = $mysqli->prepare("
            INSERT IGNORE INTO content_list_items (content_list_id, surrogate, sort_order)
            VALUES (?, ?, ?)
        ");
        $stmt->bind_param("iii", $listId, $surrogate, $nextOrder);
        $stmt->execute();
        $stmt->close();
        return;
    }

    $position = max(1, $order);
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
    $stmt->bind_param("iii", $fallbackAfter, $position, $listId);
    $stmt->execute();
    $stmt->close();

    $stmt = $mysqli->prepare("
        INSERT IGNORE INTO content_list_items (content_list_id, surrogate, sort_order)
        VALUES (?, ?, ?)
    ");
    $stmt->bind_param("iii", $listId, $surrogate, $position);
    $stmt->execute();
    $stmt->close();
}

$dataname = $_POST['dataname'] ?? '';
$surrogate = (int)($_POST['surrogate'] ?? 0);
$text = $_POST['text'] ?? '';
$ownerInput = trim($_POST['owner'] ?? '');
$listToken = trim($_POST['token'] ?? '');
$order = (int)($_POST['order'] ?? 1);

if ($surrogate > 0) {
    if (!can_user_edit_surrogate($mysqli, $surrogate, $username)) {
        http_response_code(403);
        die('Unauthorized: You do not have permission to modify this item.');
    }

    $stmt = $mysqli->prepare("
        UPDATE text
        SET Text = ?, Dataname = ?, UpdatedTime = NOW(), UpdatedUser = ?
        WHERE surrogate = ?
    ");
    $stmt->bind_param("sssi", $text, $dataname, $username, $surrogate);
    $stmt->execute();
    $stmt->close();

    echo $surrogate;
    mysqli_close($mysqli);
    exit;
}

$targetListId = 0;
$targetOwnerId = 0;
$targetOwnerUsername = '';

if ($listToken !== '') {
    $stmt = $mysqli->prepare("
        SELECT cl.id, cl.owner_id, m.username
        FROM content_lists cl
        LEFT JOIN members m ON m.id = cl.owner_id
        WHERE cl.token = ?
        LIMIT 1
    ");
    $stmt->bind_param("s", $listToken);
    $stmt->execute();
    $stmt->bind_result($targetListId, $targetOwnerId, $targetOwnerUsername);
    $stmt->fetch();
    $stmt->close();

    if (!$targetListId || !$targetOwnerUsername) {
        http_response_code(404);
        die("List not found.");
    }

    $hasListRights = get_user_list_role_rank($mysqli, $targetOwnerUsername, $username) >= 80;
    if (!$isAdmin && !$hasListRights && ((int)$targetOwnerId !== (int)$user_id)) {
        http_response_code(403);
        die("Unauthorized: You do not have permission to add items to this list.");
    }
}

$effectiveOwner = $username;
if ($targetOwnerUsername !== '') {
    $effectiveOwner = $targetOwnerUsername;
} elseif ($ownerInput !== '' && $ownerInput !== $username) {
    $hasListRights = get_user_list_role_rank($mysqli, $ownerInput, $username) >= 80;
    if (!$isAdmin && !$hasListRights) {
        http_response_code(403);
        die('Unauthorized: You do not have permission to create items for this owner.');
    }
    $effectiveOwner = $ownerInput;
}

mysqli_begin_transaction($mysqli);

try {
    $stmt = $mysqli->prepare("
        INSERT INTO text
        (Owner, Dataname, Text, Published, CreatedTime, CreatedUser, UpdatedTime, UpdatedUser, Category, Author)
        VALUES
        (?, ?, ?, 1, NOW(), ?, NOW(), ?, 'Poem', ?)
    ");
    $stmt->bind_param("ssssss", $effectiveOwner, $dataname, $text, $username, $username, $username);
    $stmt->execute();
    $newId = (int)$stmt->insert_id;
    $stmt->close();

    $ownerId = null;
    if ($effectiveOwner !== '') {
        $stmt = $mysqli->prepare("SELECT id FROM members WHERE username = ? LIMIT 1");
        if ($stmt) {
            $stmt->bind_param("s", $effectiveOwner);
            $stmt->execute();
            $stmt->bind_result($ownerId);
            $stmt->fetch();
            $stmt->close();
        }
    }

    log_change_general(
        $mysqli,
        'create',
        'item',
        $newId,
        null,
        $ownerId,
        $effectiveOwner,
        $user_id,
        $username,
        json_encode(['title' => $dataname], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)
    );

    $ownerRootListId = 0;
    $stmt = $mysqli->prepare("SELECT id FROM content_lists WHERE token = ? LIMIT 1");
    $stmt->bind_param("s", $effectiveOwner);
    $stmt->execute();
    $stmt->bind_result($ownerRootListId);
    $stmt->fetch();
    $stmt->close();
    tw_insert_item_with_order($mysqli, (int)$ownerRootListId, (int)$newId, $order);

    if ($targetListId > 0 && (int)$targetListId !== (int)$ownerRootListId) {
        tw_insert_item_with_order($mysqli, (int)$targetListId, (int)$newId, $order);
    }

    mysqli_commit($mysqli);
    echo $newId;
} catch (Throwable $e) {
    mysqli_rollback($mysqli);
    http_response_code(500);
    echo "0";
}

mysqli_close($mysqli);

?>
