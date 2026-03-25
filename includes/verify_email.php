<?php
error_reporting(E_ALL);
ini_set('display_errors', '0');
ini_set('display_startup_errors', '0');

include_once 'db_connect.php';

$token = $_GET['token'] ?? '';
$token = trim($token);

// Validate format
if (!$token || !preg_match('/^[a-f0-9]{64}$/', $token)) {
    renderPage('❌ Invalid or missing token.', false);
    exit;
}

// Step 1: Fetch user info by token
$stmt = $mysqli->prepare("SELECT id, username FROM members WHERE verify_token = ?");
$stmt->bind_param('s', $token);
$stmt->execute();
$stmt->bind_result($user_id, $username);
if (!$stmt->fetch()) {
    renderPage('❌ Invalid or expired verification token.', false);
    exit;
}
$stmt->close();

// Step 2: Insert default All Content list if not already present
$stmt = $mysqli->prepare("SELECT COUNT(*) FROM content_lists WHERE token = ?");
$stmt->bind_param("s", $username);
$stmt->execute();
$stmt->bind_result($exists);
$stmt->fetch();
$stmt->close();

if ($exists == 0) {
    $name = 'All Content';
    $access = 'private';
    $stmt = $mysqli->prepare("INSERT INTO content_lists (name, token, owner_id, created_by_id, access_level) VALUES (?, ?, ?, ?, ?)");
    $stmt->bind_param("ssiis", $name, $username, $user_id, $user_id, $access);
    $stmt->execute();
    $stmt->close();
}

// Step 3: Mark user as verified
$stmt = $mysqli->prepare("UPDATE members SET email_verified = 1, verify_token = NULL WHERE verify_token = ?");
if (!$stmt) {
    error_log("DB prepare failed: " . $mysqli->error);
    renderPage("❌ Database error. Please try again later.", false);
    exit;
}

$stmt->bind_param('s', $token);
$stmt->execute();

if ($stmt->affected_rows > 0) {
    // ✅ Success: redirect to login page with verified=1 flag
    header("Location: /login.php?verified=1");
    exit;
} else {
    renderPage('❌ This verification link is invalid or has already been used.', false);
    exit;
}

// 🧱 Error page renderer
function renderPage($message, $success = false) {
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8" />
  <meta name="viewport" content="width=device-width, initial-scale=1" />
  <title>Email Verification – TextWhisper</title>
  <link rel="stylesheet" href="/login.css" />
</head>
<body>
  <div class="auth-container" style="text-align: center;">
    <h1><?= $success ? '🎉 Success!' : '⚠️ Oops...' ?></h1>
    <p><?= $message ?></p>
  </div>
</body>
</html>
<?php
}
