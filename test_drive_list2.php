<?php
header("Content-Type: application/json");

/*
  GET ?folder=root        → My Drive
  GET ?folder=shared      → Shared with me
  GET ?folder=<folderId>  → Normal folder children

  HEADER: Authorization: Bearer <oauth-token>
*/

/* ================= AUTH ================= */

$folderId = $_GET['folder'] ?? null;

// nginx / CF safe auth header
$auth = '';
if (isset($_SERVER['HTTP_AUTHORIZATION'])) {
  $auth = $_SERVER['HTTP_AUTHORIZATION'];
} elseif (function_exists('getallheaders')) {
  $h = getallheaders();
  if (isset($h['Authorization'])) $auth = $h['Authorization'];
}

if (!$folderId || !preg_match('/Bearer\s+(.*)$/', $auth, $m)) {
  http_response_code(400);
  echo json_encode(["error" => "Missing folder or token"]);
  exit;
}

$token = $m[1];

/* ================= DRIVE QUERY ================= */

function driveQuery(string $q, string $token): array {
  $url = "https://www.googleapis.com/drive/v3/files?" . http_build_query([
    "q" => $q,
    "fields" => "files(id,name,mimeType,size,modifiedTime,owners,parents)",
    "pageSize" => 1000
  ]);

  $ch = curl_init($url);
  curl_setopt_array($ch, [
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_HTTPHEADER => ["Authorization: Bearer $token"],
    CURLOPT_TIMEOUT => 20
  ]);

  $res = curl_exec($ch);
  curl_close($ch);

  $data = json_decode($res, true);
  return $data['files'] ?? [];
}

/* ================= ROOT: MY DRIVE ================= */

if ($folderId === 'root') {
  $items = driveQuery(
    "'root' in parents and trashed=false",
    $token
  );

  echo json_encode([
    "id"       => "root",
    "name"     => "My Drive",
    "mimeType" => "application/vnd.google-apps.folder",
    "children" => $items
  ], JSON_PRETTY_PRINT);

  exit;
}

/* ================= ROOT: SHARED WITH ME ================= */

if ($folderId === 'shared') {
  $items = driveQuery(
    "sharedWithMe = true and trashed = false",
    $token
  );

  // mark explicitly as shared
  foreach ($items as &$i) {
    $i['shared'] = true;
  }

  echo json_encode([
    "id"       => "shared",
    "name"     => "Shared with me",
    "mimeType" => "application/vnd.google-apps.folder",
    "children" => $items
  ], JSON_PRETTY_PRINT);

  exit;
}

/* ================= NORMAL FOLDER ================= */

$items = driveQuery(
  sprintf("'%s' in parents and trashed=false", $folderId),
  $token
);

echo json_encode([
  "id"       => $folderId,
  "mimeType" => "application/vnd.google-apps.folder",
  "children" => $items
], JSON_PRETTY_PRINT);
