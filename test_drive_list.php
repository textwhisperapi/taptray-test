<?php
header("Content-Type: application/json");

/*
  GET  ?folder=<folderId|root>
  HEADER Authorization: Bearer <oauth-token>
*/

$folderId = $_GET['folder'] ?? null;
$auth     = $_SERVER['HTTP_AUTHORIZATION'] ?? '';

if (
  !$folderId ||
  !preg_match('/Bearer\s+(.*)$/', $auth, $m)
) {
  http_response_code(400);
  echo json_encode(["error" => "Missing folder or token"]);
  exit;
}

$token = $m[1];

/* ======================================================
   Drive API list helper
   ====================================================== */

function driveList($folderId, $token) {

  // 🔑 IMPORTANT: root must be handled explicitly
  $parent = ($folderId === 'root') ? 'root' : $folderId;

  $q = sprintf("'%s' in parents and trashed=false", $parent);

  $url = "https://www.googleapis.com/drive/v3/files?" . http_build_query([
    "q" => $q,
    "fields" => "files(id,name,mimeType,size,modifiedTime)",
    "pageSize" => 1000
  ]);

  $ch = curl_init($url);
  curl_setopt_array($ch, [
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_HTTPHEADER => [
      "Authorization: Bearer $token"
    ]
  ]);

  $res = curl_exec($ch);
  curl_close($ch);

  $data = json_decode($res, true);
  return $data['files'] ?? [];
}

/* ======================================================
   Recursive tree builder
   ====================================================== */

function buildTree($folderId, $token) {
  $children = [];

  foreach (driveList($folderId, $token) as $item) {
    if ($item['mimeType'] === 'application/vnd.google-apps.folder') {
      $children[] = [
        "id"       => $item['id'],
        "name"     => $item['name'],
        "mimeType" => $item['mimeType'],
        "children" => buildTree($item['id'], $token)
      ];
    } else {
      $children[] = [
        "id"       => $item['id'],
        "name"     => $item['name'],
        "mimeType" => $item['mimeType'],
        "size"     => $item['size'] ?? null
      ];
    }
  }

  return $children;
}

/* ======================================================
   Root node
   ====================================================== */

$tree = [
  "id"       => $folderId,
  "name"     => ($folderId === 'root' ? 'My Drive' : null),
  "mimeType" => "application/vnd.google-apps.folder",
  "children" => buildTree($folderId, $token)
];

echo json_encode($tree, JSON_PRETTY_PRINT);
