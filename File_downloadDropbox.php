<?php
/**
 * Proxy download for Dropbox files.
 * Called by JS tree-based import.
 *
 * GET ?path=/path/to/file.pdf
 */

header("Content-Type: application/octet-stream");

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
$path  = $_GET['path'] ?? null;

if (!$token || !$path) {
  http_response_code(401);
  echo "Dropbox not connected or path missing";
  exit;
}

/* ================= DROPBOX DOWNLOAD ================= */

$ch = curl_init("https://content.dropboxapi.com/2/files/download");

curl_setopt_array($ch, [
  CURLOPT_RETURNTRANSFER => true,
  CURLOPT_HTTPHEADER => [
    "Authorization: Bearer {$token}",
    "Dropbox-API-Arg: " . json_encode([
      "path" => $path
    ])
  ],
  CURLOPT_TIMEOUT => 60
]);

$data = curl_exec($ch);
$status = curl_getinfo($ch, CURLINFO_HTTP_CODE);

if ($data === false || $status !== 200) {
  http_response_code($status ?: 500);
  echo "Dropbox download failed";
  curl_close($ch);
  exit;
}

curl_close($ch);

/* ================= OUTPUT ================= */

// Best-effort filename
$filename = basename($path);
header("Content-Disposition: attachment; filename=\"" . addslashes($filename) . "\"");
header("Content-Length: " . strlen($data));

echo $data;
