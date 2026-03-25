<?php
require_once __DIR__ . '/includes/db_connect.php';
require_once __DIR__ . '/includes/functions.php';
require_once __DIR__ . '/includes/system-paths.php';

sec_session_start();

header('Content-Type: application/json');

function tw_avatar_name_from_url(string $avatarUrl): ?string {
  $avatarUrl = trim($avatarUrl);
  if ($avatarUrl === '') return null;
  $parts = parse_url($avatarUrl);
  $path = $parts['path'] ?? '';
  if ($path !== '/avatar-file.php') return null;
  $query = [];
  parse_str((string)($parts['query'] ?? ''), $query);
  $name = trim((string)($query['name'] ?? ''));
  if (!preg_match('/^avatar_[0-9]+_[0-9]+_[a-f0-9]{8}\.(jpg|png|gif|webp)$/i', $name)) {
    return null;
  }
  return $name;
}

if (!isset($_SESSION['user_id'])) {
  http_response_code(403);
  echo json_encode(['error' => 'Not logged in']);
  exit;
}

if (empty($_FILES['avatar']) || $_FILES['avatar']['error'] !== UPLOAD_ERR_OK) {
  http_response_code(400);
  echo json_encode(['error' => 'No valid file uploaded.']);
  exit;
}

$file = $_FILES['avatar'];
$maxUploadBytes = 10 * 1024 * 1024;
if ($file['size'] > $maxUploadBytes) {
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

$userId = $_SESSION['user_id'];
$oldAvatarUrl = '';
$stmtCurrent = $mysqli->prepare("SELECT avatar_url FROM members WHERE id = ? LIMIT 1");
if ($stmtCurrent) {
  $stmtCurrent->bind_param("i", $userId);
  $stmtCurrent->execute();
  $stmtCurrent->bind_result($fetchedAvatarUrl);
  if ($stmtCurrent->fetch()) {
    $oldAvatarUrl = (string)$fetchedAvatarUrl;
  }
  $stmtCurrent->close();
}

// IMPORTANT: Never store user data inside the program repository.
// Avatar files are stored only in external upload storage.
$avatarDir = rtrim((string)UPLOAD_PATH, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR . 'avatars';
$targetPath = null;
$filename = 'avatar_' . $userId . '_' . time() . '_' . bin2hex(random_bytes(4)) . '.jpg';

$tmpPath = $file['tmp_name'];
$processedTmpPath = null;
$tempFilesToCleanup = [];
$workingMime = $mime;

// Normalize huge images to JPEG <= 2MB for better mobile reliability.
if (
  function_exists('imagecreatefromstring') &&
  function_exists('imagecreatetruecolor') &&
  function_exists('imagecopyresampled') &&
  function_exists('imagejpeg') &&
  function_exists('imagedestroy') &&
  ($file['size'] > 2 * 1024 * 1024 || $mime === 'image/png' || $mime === 'image/webp')
) {
  $imageData = @file_get_contents($tmpPath);
  $image = $imageData !== false ? @imagecreatefromstring($imageData) : false;
  if ($image !== false) {
    $srcW = imagesx($image);
    $srcH = imagesy($image);
    $maxDim = 1400;
    $scale = min(1, $maxDim / max(1, $srcW), $maxDim / max(1, $srcH));
    $dstW = max(1, (int)round($srcW * $scale));
    $dstH = max(1, (int)round($srcH * $scale));
    $canvas = imagecreatetruecolor($dstW, $dstH);
    imagecopyresampled($canvas, $image, 0, 0, 0, 0, $dstW, $dstH, $srcW, $srcH);

    $processedTmpPath = tempnam(sys_get_temp_dir(), 'twava_');
    if ($processedTmpPath) {
      $quality = 85;
      imagejpeg($canvas, $processedTmpPath, $quality);
      $workingMime = 'image/jpeg';
      $tmpPath = $processedTmpPath;
      $tempFilesToCleanup[] = $processedTmpPath;
    }
    imagedestroy($canvas);
    imagedestroy($image);
  }
}

if (!is_dir($avatarDir) && !@mkdir($avatarDir, 0755, true)) {
  error_log('Avatar upload failed: could not create avatar directory ' . $avatarDir);
  http_response_code(500);
  echo json_encode(['error' => 'Avatar storage directory could not be created.']);
  exit;
}
if (!is_writable($avatarDir)) {
  error_log('Avatar upload failed: avatar directory not writable ' . $avatarDir);
  http_response_code(500);
  echo json_encode(['error' => 'Avatar storage directory is not writable.']);
  exit;
}

$ext = $workingMime === 'image/jpeg' ? '.jpg' : ($allowed[$workingMime] ?? '.jpg');
$targetPath = rtrim($avatarDir, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR . preg_replace('/\.jpg$/', $ext, $filename);
$filename = basename($targetPath);

$moved = false;
if ($processedTmpPath !== null) {
  $moved = @rename($tmpPath, $targetPath) || @copy($tmpPath, $targetPath);
} else {
  $moved = @move_uploaded_file($tmpPath, $targetPath);
}

foreach ($tempFilesToCleanup as $tmpFile) {
  if (is_file($tmpFile)) @unlink($tmpFile);
}

if (!$moved || !is_file($targetPath)) {
  error_log('Avatar upload failed: could not move file to ' . (string)$targetPath);
  http_response_code(500);
  echo json_encode(['error' => 'Could not save avatar file.']);
  exit;
}

$avatarUrl = '/avatar-file.php?name=' . rawurlencode($filename);
$stmt = $mysqli->prepare("UPDATE members SET avatar_url = ? WHERE id = ?");
$stmt->bind_param("si", $avatarUrl, $userId);
$stmt->execute();
$stmt->close();

// Cleanup previous local avatar file when replacing avatar.
$oldAvatarName = tw_avatar_name_from_url($oldAvatarUrl);
if ($oldAvatarName !== null && strcasecmp($oldAvatarName, $filename) !== 0) {
  $oldPath = rtrim($avatarDir, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR . $oldAvatarName;
  if (is_file($oldPath)) {
    @unlink($oldPath);
  }
}

echo json_encode(['ok' => true, 'avatar_url' => $avatarUrl]);
