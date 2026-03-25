<?php
header("Content-Type: application/json");

/*
  GET ?path=            → root
  GET ?path=/Scores     → recursive folder listing
*/

/* ================= SESSION ================= */

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

function dropboxListFolderRecursive(string $path, string $token): array {
  $url = "https://api.dropboxapi.com/2/files/list_folder";

  $payload = json_encode([
    "path" => $path === "" ? "" : $path,
    "recursive" => true,
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
  $entries = $data['entries'] ?? [];

  while (!empty($data['has_more']) && !empty($data['cursor'])) {
    $cursor = $data['cursor'];

    $ch = curl_init("https://api.dropboxapi.com/2/files/list_folder/continue");
    curl_setopt_array($ch, [
      CURLOPT_RETURNTRANSFER => true,
      CURLOPT_POST => true,
      CURLOPT_HTTPHEADER => [
        "Authorization: Bearer $token",
        "Content-Type: application/json"
      ],
      CURLOPT_POSTFIELDS => json_encode(["cursor" => $cursor]),
      CURLOPT_TIMEOUT => 20
    ]);

    $res = curl_exec($ch);
    curl_close($ch);

    $data = json_decode($res, true);
    $more = $data['entries'] ?? [];
    if ($more) {
      $entries = array_merge($entries, $more);
    }
  }

  return $entries;
}

/* ================= LIST ================= */

$entries = dropboxListFolderRecursive($path, $token);
$files = [];

foreach ($entries as $e) {
  if (($e['.tag'] ?? '') !== 'file') continue;
  $files[] = [
    "id"   => $e['id'],
    "name" => $e['name'],
    "path" => $e['path_lower'],
    "size" => $e['size'] ?? null
  ];
}

echo json_encode([
  "path" => $path === "" ? "" : $path,
  "files" => $files
], JSON_PRETTY_PRINT);
