<?php

include_once __DIR__ . '/includes/db_connect.php';
include_once __DIR__ . '/includes/functions.php';

sec_session_start();

header('Content-Type: application/json');

// 🔐 Enforce login
if (!isset($_SESSION['username'])) {
    http_response_code(403);
    die("Unauthorized: You must be logged in.");
}

$username = $_SESSION['username'];
$user_id = $_SESSION['user_id'] ?? null;

mysqli_set_charset($mysqli, 'utf8mb4');
mysqli_query($mysqli, "SET collation_connection = 'utf8mb4_unicode_ci'");

// ---------------------------------------------------------------------------
// ✔ SAFE: Extract raw user text with NO escaping here
// ---------------------------------------------------------------------------
$dataname  = $_POST['dataname'] ?? '';
$surrogate = (int) ($_POST['surrogate'] ?? 0);
$text      = $_POST['text'] ?? '';
$ownerInput = trim($_POST['owner'] ?? '');


// ---------------------------------------------------------------------------
// UPDATE EXISTING
// ---------------------------------------------------------------------------
if ($surrogate > 0) {

    // 🔒 Check edit rights
    if (!can_user_edit_surrogate($mysqli, $surrogate, $username)) {
        http_response_code(403);
        die('Unauthorized: You do not have permission to modify this item.');
    }

    // ✔ Use prepared statement
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


// ---------------------------------------------------------------------------
// INSERT NEW
// ---------------------------------------------------------------------------
$effectiveOwner = $username;
if ($ownerInput !== '' && $ownerInput !== $username) {
    $isAdmin = !empty($_SESSION['is_admin']);
    $hasListRights = get_user_list_role_rank($mysqli, $ownerInput, $username) >= 80;
    if (!$isAdmin && !$hasListRights) {
        http_response_code(403);
        die('Unauthorized: You do not have permission to create items for this owner.');
    }
    $effectiveOwner = $ownerInput;
}

// ✔ Insert with prepared statement
$stmt = $mysqli->prepare("
    INSERT INTO text 
    (Owner, Dataname, Text, Published, CreatedTime, CreatedUser, UpdatedTime, UpdatedUser, Category, Author)
    VALUES 
    (?, ?, ?, 1, NOW(), ?, NOW(), ?, 'Poem', ?)
");
$stmt->bind_param("ssssss", $effectiveOwner, $dataname, $text, $username, $username, $username);
$stmt->execute();

$newId = $stmt->insert_id;
$stmt->close();

// Lookup owner id for logging (best-effort)
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

// ✔ Add to user's default list
$stmt = $mysqli->prepare("
    INSERT IGNORE INTO content_list_items (content_list_id, surrogate)
    SELECT id, ? FROM content_lists WHERE token = ? LIMIT 1
");
$stmt->bind_param("is", $newId, $effectiveOwner);
$stmt->execute();
$stmt->close();

mysqli_close($mysqli);

echo $newId;
?>
