<?php
// Serve locally stored user avatars from an external user-data directory.
require_once __DIR__ . '/includes/system-paths.php';

$name = $_GET['name'] ?? '';
if (!is_string($name) || $name === '') {
  http_response_code(400);
  echo "Missing name";
  exit;
}

// Strict filename validation to prevent traversal and arbitrary file access.
if (!preg_match('/^avatar_[0-9]+_[0-9]+_[a-f0-9]{8}\.(jpg|png|gif|webp)$/i', $name)) {
  http_response_code(400);
  echo "Invalid avatar name";
  exit;
}

// IMPORTANT: Never read user files from the program repository.
// Avatar files are served only from external upload storage.
$avatarDir = rtrim((string)UPLOAD_PATH, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR . 'avatars';
$path = $avatarDir . DIRECTORY_SEPARATOR . $name;
$servePath = $path;

if (!is_file($servePath)) {
  http_response_code(404);
  echo "Not found";
  exit;
}

$mime = (new finfo(FILEINFO_MIME_TYPE))->file($servePath) ?: 'application/octet-stream';
$allowed = [
  'image/jpeg',
  'image/png',
  'image/gif',
  'image/webp'
];
if (!in_array($mime, $allowed, true)) {
  http_response_code(415);
  echo "Unsupported file";
  exit;
}

header('Content-Type: ' . $mime);
header('Cache-Control: public, max-age=86400, stale-while-revalidate=86400');
header('Content-Length: ' . filesize($servePath));
readfile($servePath);
