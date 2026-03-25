<?php
include_once __DIR__ . '/includes/db_connect.php';
include_once __DIR__ . '/includes/functions.php';
sec_session_start();



if ($_SERVER["REQUEST_METHOD"] !== "POST") {
    http_response_code(405);
    echo json_encode(["error" => "Invalid request method"]);
    exit;
}

if (!isset($_POST['surrogate']) || !isset($_POST['dataname'])) {
    http_response_code(400);
    echo json_encode(["error" => "Missing required parameters"]);
    exit;
}

if (!isset($_SESSION['username'], $_SESSION['user_id'])) {
    http_response_code(403);
    echo json_encode(["error" => "Unauthorized. Please log in."]);
    exit;
}

$username  = $_SESSION['username'];
$user_id   = $_SESSION['user_id'];
$surrogate = intval($_POST['surrogate']);
$dataname  = $_POST['dataname'];

// $con = mysqli_connect("localhost", "wecanrec_text", "gotext", "wecanrec_text");
// if (mysqli_connect_errno()) {
//     http_response_code(500);
//     echo json_encode(["error" => "Database connection failed"]);
//     exit;
// }

mysqli_set_charset($mysqli, 'utf8');
mysqli_query($mysqli, "SET collation_connection = 'utf8_unicode_ci'");

// 1. Get the owner username of the item
$stmt = $mysqli->prepare("SELECT Owner FROM text WHERE surrogate = ? LIMIT 1");
$stmt->bind_param("i", $surrogate);
$stmt->execute();
$stmt->bind_result($ownerUsername);
$hasItem = $stmt->fetch();
$stmt->close();

if (!$hasItem) {
    http_response_code(404);
    echo json_encode(["error" => "Item not found"]);
    mysqli_close($mysqli);
    exit;
}

// 2. Determine the 'All Content' list token — owner username itself
$allContentToken = $ownerUsername;

// 3. Permission check — owner or admin/editor on owner's "All Content" list
$isOwner = ($username === $ownerUsername);
$isAdmin = false;
$role_rank = null;

if (!$isOwner) {
    $stmt = $mysqli->prepare("
        SELECT role_rank FROM invitations i
        JOIN members m ON i.email = m.email
        WHERE i.listToken = ? AND m.username = ?
        LIMIT 1
    ");
    $stmt->bind_param("ss", $allContentToken, $username);
    $stmt->execute();
    $stmt->bind_result($role_rank);
    if ($stmt->fetch() && $role_rank >= 60) {
        $isAdmin = true;
    }
    $stmt->close();
}

if (!$isOwner && !$isAdmin) {
    http_response_code(403);
    echo json_encode(["error" => "You do not have permission to delete this item"]);
    mysqli_close($mysqli);
    exit;
}

// 4. Soft delete the item
$stmt = $mysqli->prepare("UPDATE text SET deleted = 'D' WHERE surrogate = ?");
$stmt->bind_param("i", $surrogate);
if ($stmt->execute()) {
    echo json_encode(["status" => "OK", "message" => "Item marked as deleted"]);
} else {
    http_response_code(500);
    echo json_encode(["status" => "Failed", "error" => $stmt->error]);
}
$stmt->close();
mysqli_close($mysqli);
