<?php
// renameItem.php
require_once __DIR__ . "/includes/db_connect.php";
require_once __DIR__ . "/includes/functions.php";

sec_session_start();
header('Content-Type: application/json');

// 🛡 Check login
if (!login_check($mysqli)) {
    http_response_code(403);
    echo json_encode(["status" => "error", "message" => "Not logged in"]);
    exit;
}

$username = $_SESSION['username'] ?? '';
$data = json_decode(file_get_contents("php://input"), true);

$surrogate = (int)($data['surrogate'] ?? 0);
$newName   = trim($data['name'] ?? '');

if (!$surrogate || $newName === '') {
    http_response_code(400);
    echo json_encode(["status" => "error", "message" => "Missing surrogate or name"]);
    exit;
}

// 🔐 Permission check
if (!can_user_edit_surrogate($mysqli, $surrogate, $username)) {
    http_response_code(403);
    echo json_encode(["status" => "error", "message" => "Permission denied"]);
    exit;
}

// 📥 Fetch current full text (HTML)
$stmt = $mysqli->prepare("SELECT text FROM `text` WHERE Surrogate = ?");
$stmt->bind_param("i", $surrogate);
$stmt->execute();
$stmt->bind_result($fullText);
$stmt->fetch();
$stmt->close();

$fullText = $fullText ?: "";

// --- 🪄 Replace FIRST LINE of HTML safely ---

// Split only once on <br> of any style (<br>, <br/>, <br />)
$parts = preg_split("/<br\s*\/?>/i", $fullText, 2);

// Escape new subject for HTML
$escaped = htmlspecialchars($newName, ENT_QUOTES, 'UTF-8');

// Two cases: multi-line or single-line
if (count($parts) > 1) {
    // first line replaced, rest stays untouched
    $newFullText = $escaped . "<br>" . $parts[1];
} else {
    // only one line existed
    $newFullText = $escaped;
}

// 📝 Update both subject AND updated HTML text
$stmt = $mysqli->prepare("UPDATE `text` SET dataname = ?, text = ? WHERE Surrogate = ?");
$stmt->bind_param("ssi", $newName, $newFullText, $surrogate);

if ($stmt->execute()) {
    echo json_encode([
        "status" => "success",
        "newName" => $newName,
        "newText" => $newFullText
    ]);
} else {
    http_response_code(500);
    echo json_encode(["status" => "error", "message" => $stmt->error]);
}
$stmt->close();
?>
