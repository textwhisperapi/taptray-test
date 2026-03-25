<?php
header("Content-Type: application/json");

/*
  GET ?path=            → root
  GET ?path=/Scores     → folder children
*/

/* ================= SESSION (CRITICAL) ================= */

// Force session cookie to be shared across /api/* and /
session_set_cookie_params([
  'lifetime' => 0,
  'path' => '/',
  'domain' => '.skolaspjall.is',
  'secure' => true,
  'httponly' => true,
  'samesite' => 'Lax'
]);
session_start();

/* ================= AUTH ================= */

$token = $_SESSION['DROPBOX_ACCESS_TOKEN'] ?? null;

if (!$token) {
  http_response_code(401);
  echo json_encode(["error" => "Dropbox not connected"]);
  exit;
}

$path = $_GET['path'] ?? "";

/* ================= DROPBOX QUERY ================= */

function dropboxListFolder(string $path, string $token): array {
  $url = "https://api.dropboxapi.com/2/files/list_folder";

  $payload = json_encode([
    "path" => $path === "" ? "" : $path,
    "recursive" => false,
    "include_media_info" => false,
    "include_deleted" => false,
    "include_non_downloadable_files" => false
  ]);

  $ch = curl_init($url);
  curl_setopt_array($ch, [
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_POST => true,
    CURLOPT_HTTPHEADER => [
      "Authorization: Bearer $token",
      "Content-Type: application/json"
    ],
    CURLOPT_POSTFIELDS => $payload,
    CURLOPT_TIMEOUT => 20
  ]);

  $res = curl_exec($ch);
  curl_close($ch);

  $data = json_decode($res, true);
  return $data['entries'] ?? [];
}

function dropboxMimeFromName(string $name): string {
  $ext = strtolower(pathinfo($name, PATHINFO_EXTENSION));

  if ($ext === 'pdf') return 'application/pdf';
  if ($ext === 'docx') return 'application/vnd.openxmlformats-officedocument.wordprocessingml.document';
  if ($ext === 'doc') return 'application/msword';
  if (in_array($ext, ['mid', 'midi'], true)) return 'audio/midi';
  if (in_array($ext, ['mp3', 'wav', 'ogg', 'm4a', 'flac', 'aac', 'aif', 'aiff', 'webm'], true)) return 'audio/mpeg';
  if (in_array($ext, ['txt', 'md', 'markdown'], true)) return 'text/plain';

  return 'application/octet-stream';
}

/* ================= LIST ================= */

$entries = dropboxListFolder($path, $token);
$children = [];

foreach ($entries as $e) {
  if (($e['.tag'] ?? '') === 'folder') {
    $children[] = [
      "id"       => $e['id'],
      "name"     => $e['name'],
      "path"     => $e['path_lower'],
      "mimeType" => "application/vnd.dropbox.folder"
    ];
  }

  if (($e['.tag'] ?? '') === 'file') {
    $name = $e['name'] ?? '';
    $children[] = [
      "id"       => $e['id'],
      "name"     => $name,
      "path"     => $e['path_lower'],
      "size"     => $e['size'] ?? null,
      "mimeType" => dropboxMimeFromName($name)
    ];
  }
}

/* ================= OUTPUT ================= */

echo json_encode([
  "id"       => $path === "" ? "root" : $path,
  "name"     => $path === "" ? "Dropbox" : basename($path),
  "mimeType" => "application/vnd.dropbox.folder",
  "children" => $children
], JSON_PRETTY_PRINT);
