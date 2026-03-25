<?php
require_once __DIR__ . '/includes/db_connect.php';
require_once __DIR__ . '/includes/functions.php';

sec_session_start();

if (!isset($_SESSION['user_id'])) {
  http_response_code(403);
  exit("Not logged in");
}

$userId = $_SESSION['user_id'];
$locale = $_POST['locale'] ?? 'en';

// ✅ Optional: normalize to lowercase
$locale = strtolower($locale);

// ✅ Optional: only allow if the language file exists
$langFile = __DIR__ . "/lang/{$locale}.php";
if (!file_exists($langFile)) {
  $locale = 'en';
}

$stmt = $mysqli->prepare("UPDATE members SET locale = ? WHERE id = ?");
$stmt->bind_param("si", $locale, $userId);
$stmt->execute();

echo "OK";
