<?php
// TEST MODE — dump raw output
if ($_GET['debug'] ?? false) {
    echo "<pre>";
    print_r($_POST);
    print_r($_SESSION);
    exit;
}

ob_clean(); // clear any accidental whitespace or BOM
header('Content-Type: application/json');

require_once __DIR__ . '/includes/db_connect.php';
require_once __DIR__ . '/includes/functions.php';

sec_session_start();

// ✅ Ensure user is logged in
if (!login_check($mysqli)) {
    http_response_code(403);
    echo json_encode(['error' => 'Not logged in']);
    exit;
}

$token = $_POST['token'] ?? '';
$message = $_POST['message'] ?? '';
$username = $_SESSION['username'] ?? '';

// ✅ Ensure token and session are valid
if (!$token || !$username) {
    http_response_code(400);
    echo json_encode(['error' => 'Missing token or session']);
    exit;
}

// ✅ Get user email
$stmt = $mysqli->prepare("SELECT email FROM members WHERE username = ?");
$stmt->bind_param("s", $username);
$stmt->execute();
$stmt->bind_result($email);
$stmt->fetch();
$stmt->close();

if (!$email) {
    http_response_code(403);
    echo json_encode(['error' => 'Email not found']);
    exit;
}

// ✅ Check for existing 'request' role within last 2 minutes
$stmt = $mysqli->prepare("
  SELECT created_at FROM invitations 
  WHERE listToken = ? AND email = ? AND role = 'request'
  ORDER BY created_at DESC LIMIT 1
");
$stmt->bind_param("ss", $token, $email);
$stmt->execute();
$stmt->bind_result($lastRequestTime);
$hasRequest = $stmt->fetch();
$stmt->close();

if ($hasRequest) {
    $last = strtotime($lastRequestTime);
    if (time() - $last < 120) { // 2-minute cooldown
        http_response_code(429);
        echo json_encode(['error' => 'Please wait before requesting again.']);
        exit;
    }
}

// ✅ Insert the new request entry
$stmt = $mysqli->prepare("INSERT INTO invitations (listToken, email, role, message) VALUES (?, ?, 'request', ?)");
$stmt->bind_param("sss", $token, $email, $message);
$success = $stmt->execute();
$stmt->close();

if ($success) {
    // Compose system message including optional user note
    $systemMessage = "Access requested by $email";
    if (!empty($message)) {
        $systemMessage .= ": " . $message;
    }

    $stmt = $mysqli->prepare("
        INSERT INTO chat_messages (listToken, username, message, created_at)
        VALUES (?, ?, ?, NOW())
    ");
    $systemUser = "System";
    $stmt->bind_param("sss", $token, $systemUser, $systemMessage);
    $stmt->execute();
    $stmt->close();

    http_response_code(200);
    echo json_encode(['status' => 'success']);
} else {
    http_response_code(500);
    echo json_encode(['error' => 'Could not save request']);
}

