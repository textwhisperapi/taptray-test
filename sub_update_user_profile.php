<?php
require_once __DIR__ . '/includes/db_connect.php';
require_once __DIR__ . '/includes/functions.php';
require_once __DIR__ . '/includes/system-paths.php';

sec_session_start();

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
  header('Content-Type: application/json');
  echo json_encode(['error' => 'Not logged in']);
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

$displayName = trim($_POST['display_name'] ?? '');
$avatarUrl = trim($_POST['avatar_url'] ?? '');
$profileType = strtolower(trim($_POST['profile_type'] ?? 'person'));
$groupType = strtolower(trim($_POST['group_type'] ?? ''));
$errors = [];

if ($displayName === '') {
  $errors[] = 'Display name is required.';
} else {
  if (!preg_match("/^[\\p{L} ._'’\\-]{3,40}$/u", $displayName)) {
    $errors[] = 'Display name contains invalid characters or length.';
  }
  if (preg_match("/\\d/", $displayName)) {
    $errors[] = 'Display name cannot include digits.';
  }
  if (preg_match("/^(.)\\1{4,}$/u", $displayName)) {
    $errors[] = 'Display name has too many repeated characters.';
  }
  if (preg_match("/[bcdfghjklmnpqrstvwxyz]{6,}/i", $displayName)) {
    $errors[] = 'Display name looks too artificial.';
  }
  if (in_array(strtolower($displayName), ['admin', 'root', 'system', 'support'], true)) {
    $errors[] = 'Display name is reserved and cannot be used.';
  }
}

if ($avatarUrl !== '') {
  if (strlen($avatarUrl) > 2048) {
    $errors[] = 'Avatar URL is too long.';
  } else {
    $isAbsolute = filter_var($avatarUrl, FILTER_VALIDATE_URL);
    $isRelative = strpos($avatarUrl, '/') === 0;
    if (!$isAbsolute && !$isRelative) {
      $errors[] = 'Avatar URL must be a valid URL or a site path.';
    } elseif ($isRelative) {
      $lower = strtolower($avatarUrl);
      // Keep user data out of product paths such as /uploads/.
      if (strpos($lower, '/uploads/') === 0) {
        $errors[] = 'Avatar path cannot point to product uploads.';
      }
      // Allow only vetted internal avatar routes.
      if (
        $lower !== '/default-avatar.png' &&
        strpos($lower, '/avatar-file.php?') !== 0 &&
        strpos($lower, '/avatar-proxy.php?') !== 0
      ) {
        $errors[] = 'Avatar site path is not allowed.';
      }
    }
  }
}

if (!in_array($profileType, ['person', 'group'], true)) {
  $profileType = 'person';
}
if (!in_array($groupType, ['', 'mixed', 'men', 'women'], true)) {
  $groupType = '';
}
if ($profileType !== 'group') {
  $groupType = '';
}

if (!empty($errors)) {
  http_response_code(400);
  header('Content-Type: application/json');
  echo json_encode(['error' => $errors[0]]);
  exit;
}

$avatarDb = $avatarUrl === '' ? null : $avatarUrl;
$hasGroupType = false;
$colRes = $mysqli->query("SHOW COLUMNS FROM members LIKE 'group_type'");
if ($colRes && $colRes->num_rows > 0) {
  $hasGroupType = true;
}
if ($colRes) {
  $colRes->free();
}

if ($hasGroupType) {
  $groupTypeDb = $groupType === '' ? null : $groupType;
  $stmt = $mysqli->prepare("UPDATE members SET display_name = ?, avatar_url = ?, profile_type = ?, group_type = ? WHERE id = ?");
  $stmt->bind_param("ssssi", $displayName, $avatarDb, $profileType, $groupTypeDb, $userId);
} else {
  $stmt = $mysqli->prepare("UPDATE members SET display_name = ?, avatar_url = ?, profile_type = ? WHERE id = ?");
  $stmt->bind_param("sssi", $displayName, $avatarDb, $profileType, $userId);
}
$stmt->execute();
$stmt->close();

$oldAvatarName = tw_avatar_name_from_url($oldAvatarUrl);
$newAvatarName = tw_avatar_name_from_url((string)($avatarDb ?? ''));
if ($oldAvatarName !== null && strcasecmp($oldAvatarName, (string)$newAvatarName) !== 0) {
  $avatarDir = rtrim((string)UPLOAD_PATH, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR . 'avatars';
  $oldPath = $avatarDir . DIRECTORY_SEPARATOR . $oldAvatarName;
  if (is_file($oldPath)) {
    @unlink($oldPath);
  }
}

$_SESSION['display_name'] = $displayName;

header('Content-Type: application/json');
echo json_encode([
  'ok' => true,
  'display_name' => $displayName,
  'avatar_url' => $avatarDb ?: '/default-avatar.png',
  'profile_type' => $profileType,
  'group_type' => $groupType
]);
