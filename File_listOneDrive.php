<?php
header("Content-Type: application/json");
require_once __DIR__ . "/includes/functions.php";
sec_session_start();

/*
  GET ?folder=            → root
  GET ?folder={id}        → folder children
*/

/* ================= AUTH ================= */

$token = $_SESSION['ONEDRIVE_ACCESS_TOKEN'] ?? null;

if (!$token) {
  http_response_code(401);
  echo json_encode(["error" => "OneDrive not connected"]);
  exit;
}

/* ================= INPUT ================= */

$folderId = $_GET['folder'] ?? 'root';

/* ================= GRAPH REQUEST ================= */

if ($folderId === 'root') {
  $url = "https://graph.microsoft.com/v1.0/me/drive/root/children";
} else {
  $url = "https://graph.microsoft.com/v1.0/me/drive/items/" . rawurlencode($folderId) . "/children";
}

$ch = curl_init($url);
curl_setopt_array($ch, [
  CURLOPT_RETURNTRANSFER => true,
  CURLOPT_HTTPHEADER => [
    "Authorization: Bearer $token"
  ],
  CURLOPT_TIMEOUT => 20
]);

$res = curl_exec($ch);
$http = curl_getinfo($ch, CURLINFO_HTTP_CODE);
curl_close($ch);

if ($http !== 200 || !$res) {
  http_response_code($http ?: 500);
  echo json_encode(["error" => "OneDrive API error"]);
  exit;
}

$data = json_decode($res, true);

/* ================= NORMALIZE ================= */

$children = [];

function mime_to_audio_ext($mime) {
  switch (strtolower((string)$mime)) {
    case 'audio/mpeg':
    case 'audio/mp3':
      return 'mp3';
    case 'audio/wav':
    case 'audio/x-wav':
      return 'wav';
    case 'audio/ogg':
      return 'ogg';
    case 'audio/flac':
      return 'flac';
    case 'audio/aac':
      return 'aac';
    case 'audio/mp4':
    case 'audio/x-m4a':
      return 'm4a';
    case 'audio/aiff':
    case 'audio/x-aiff':
      return 'aiff';
    default:
      return 'mp3';
  }
}

foreach (($data['value'] ?? []) as $item) {

  // Folder
  if (isset($item['folder'])) {
    $children[] = [
      "id"       => $item['id'],
      "name"     => $item['name'],
      "mimeType" => "application/vnd.microsoft.folder"
    ];
    continue;
  }

  // File
  if (isset($item['file'])) {

    // optional: filter PDFs only
    // $ext = strtolower(pathinfo($item['name'], PATHINFO_EXTENSION));
    // if ($ext !== 'pdf') continue;

    $mimeType = $item['file']['mimeType'] ?? 'application/octet-stream';
    $name = $item['name'] ?? '';
    if (isset($item['audio']) && strpos($mimeType, 'audio/') !== 0) {
      $mimeType = 'audio/mpeg';
    }
    if ($mimeType === 'application/pdf' && !preg_match('/\.pdf$/i', $name)) {
      $name .= '.pdf';
    }
    if (strpos($mimeType, 'audio/') === 0 && !preg_match('/\.[a-z0-9]+$/i', $name)) {
      $name .= '.' . mime_to_audio_ext($mimeType);
    }

    $children[] = [
      "id"       => $item['id'],
      "name"     => $name,
      "size"     => $item['size'] ?? null,
      "mimeType" => $mimeType
    ];
  }
}

/* ================= OUTPUT ================= */

echo json_encode([
  "id"       => $folderId === 'root' ? "root" : $folderId,
  "name"     => $folderId === 'root' ? "OneDrive" : null,
  "mimeType" => "application/vnd.microsoft.folder",
  "children" => $children
], JSON_PRETTY_PRINT);
