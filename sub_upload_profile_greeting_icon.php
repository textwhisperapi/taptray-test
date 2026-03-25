<?php
require_once __DIR__ . '/includes/db_connect.php';
require_once __DIR__ . '/includes/functions.php';
require_once __DIR__ . '/includes/system-paths.php';

sec_session_start();
header('Content-Type: application/json');

function tw_profile_greeting_icon_name_from_url(string $url): ?string {
  $url = trim($url);
  if ($url === '') return null;
  $parts = parse_url($url);
  $path = $parts['path'] ?? '';
  if ($path !== '/avatar-file.php') return null;
  $query = [];
  parse_str((string)($parts['query'] ?? ''), $query);
  $name = trim((string)($query['name'] ?? ''));
  if (!preg_match('/^greeting_icon_[0-9]+_[0-9]+_[a-f0-9]{8}\.(jpg|png|gif|webp)$/i', $name)) {
    return null;
  }
  return $name;
}

function tw_ensure_member_appearance_columns(mysqli $mysqli): void {
  static $done = false;
  if ($done) return;
  $done = true;

  $required = [
    'menu_greeting_icon_url' => "ALTER TABLE members ADD COLUMN menu_greeting_icon_url TEXT NULL AFTER menu_greeting_icon",
  ];

  $existing = [];
  if ($result = $mysqli->query("SHOW COLUMNS FROM members")) {
    while ($row = $result->fetch_assoc()) {
      $existing[$row['Field']] = true;
    }
    $result->close();
  }

  foreach ($required as $column => $sql) {
    if (!isset($existing[$column])) {
      @$mysqli->query($sql);
    }
  }
}

if (!isset($_SESSION['user_id'])) {
  http_response_code(403);
  echo json_encode(['error' => 'Not logged in']);
  exit;
}

if (empty($_FILES['icon']) || $_FILES['icon']['error'] !== UPLOAD_ERR_OK) {
  http_response_code(400);
  echo json_encode(['error' => 'No valid file uploaded.']);
  exit;
}

$file = $_FILES['icon'];
if ($file['size'] > 10 * 1024 * 1024) {
  http_response_code(400);
  echo json_encode(['error' => 'Image exceeds 10MB.']);
  exit;
}

$finfo = new finfo(FILEINFO_MIME_TYPE);
$mime = $finfo->file($file['tmp_name']);
$allowed = [
  'image/jpeg' => '.jpg',
  'image/png'  => '.png',
  'image/gif'  => '.gif',
  'image/webp' => '.webp'
];
if (!isset($allowed[$mime])) {
  http_response_code(400);
  echo json_encode(['error' => 'Unsupported image type.']);
  exit;
}

$userId = (int)$_SESSION['user_id'];
tw_ensure_member_appearance_columns($mysqli);

$oldUrl = '';
$stmtCurrent = $mysqli->prepare("SELECT menu_greeting_icon_url FROM members WHERE id = ? LIMIT 1");
if ($stmtCurrent) {
  $stmtCurrent->bind_param("i", $userId);
  $stmtCurrent->execute();
  $stmtCurrent->bind_result($fetchedUrl);
  if ($stmtCurrent->fetch()) {
    $oldUrl = (string)$fetchedUrl;
  }
  $stmtCurrent->close();
}

$iconDir = rtrim((string)UPLOAD_PATH, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR . 'avatars';
if (!is_dir($iconDir) && !@mkdir($iconDir, 0755, true)) {
  http_response_code(500);
  echo json_encode(['error' => 'Icon storage directory could not be created.']);
  exit;
}
if (!is_writable($iconDir)) {
  http_response_code(500);
  echo json_encode(['error' => 'Icon storage directory is not writable.']);
  exit;
}

$ext = $allowed[$mime] ?? '.jpg';
$filename = 'greeting_icon_' . $userId . '_' . time() . '_' . bin2hex(random_bytes(4)) . $ext;
$targetPath = $iconDir . DIRECTORY_SEPARATOR . $filename;

if (!@move_uploaded_file($file['tmp_name'], $targetPath) || !is_file($targetPath)) {
  http_response_code(500);
  echo json_encode(['error' => 'Could not save icon file.']);
  exit;
}

$iconUrl = '/avatar-file.php?name=' . rawurlencode($filename);
$stmt = $mysqli->prepare("UPDATE members SET menu_greeting_icon_url = ?, menu_greeting_icon = NULL WHERE id = ?");
$stmt->bind_param("si", $iconUrl, $userId);
$stmt->execute();
$stmt->close();

$oldName = tw_profile_greeting_icon_name_from_url($oldUrl);
if ($oldName !== null && strcasecmp($oldName, $filename) !== 0) {
  $oldPath = $iconDir . DIRECTORY_SEPARATOR . $oldName;
  if (is_file($oldPath)) {
    @unlink($oldPath);
  }
}

echo json_encode(['ok' => true, 'greeting_icon_url' => $iconUrl]);
