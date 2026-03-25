<?php
require_once __DIR__ . '/includes/db_connect.php';
require_once __DIR__ . '/includes/functions.php';

sec_session_start();

if (!isset($_SESSION['user_id'])) {
  http_response_code(403);
  exit("Not logged in");
}

$userId   = $_SESSION['user_id'];
$homeMode = $_POST['home_mode'] ?? 'default';
$homePage = trim($_POST['home_page'] ?? '');

// ✅ Normalize
$homeMode = strtolower($homeMode);

// ✅ Allow only supported modes
if (!in_array($homeMode, ['default', 'page', 'pdf'], true)) {
  $homeMode = 'default';
}

// ✅ Clear only when empty
if ($homePage === '') {
  $homePage = null;
}

if ($homeMode === 'page' && $homePage !== null) {
  $isHttps = filter_var($homePage, FILTER_VALIDATE_URL) && stripos($homePage, 'https://') === 0;
  if (!$isHttps) {
    http_response_code(400);
    exit("Custom page must be a valid https URL.");
  }
}

$stmt = $mysqli->prepare("
  UPDATE members
  SET home_mode = ?, home_page = ?
  WHERE id = ?
");
$stmt->bind_param("ssi", $homeMode, $homePage, $userId);
$stmt->execute();

echo "OK";
