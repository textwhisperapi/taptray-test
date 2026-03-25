<?php
// /File_downloadICloud.php
declare(strict_types=1);

require_once __DIR__ . "/includes/functions.php";

sec_session_start();

function fail(int $code, string $msg): void {
  http_response_code($code);
  header('Content-Type: text/plain; charset=utf-8');
  echo $msg;
  exit;
}

function getICloudCreds(): array {
  $c = $_SESSION['icloud'] ?? null;
  if (!is_array($c) || empty($c['apple_id']) || empty($c['app_password'])) {
    fail(401, 'iCloud not connected');
  }
  return $c;
}

function normPath(string $p): string {
  $p = trim($p);
  if ($p === '') fail(400, 'Missing path');
  if ($p[0] !== '/') $p = '/' . $p;
  $p = preg_replace('~/+~', '/', $p);
  $p = str_replace(['..', "\0"], '', $p);
  return $p;
}

function icloudBaseUrl(): string {
  return 'https://p00-webdav.icloud.com';
}

$creds = getICloudCreds();
$path = normPath((string)($_GET['path'] ?? ''));

// NOTE: for files we should NOT force trailing slash
$url = icloudBaseUrl() . $path;

$ch = curl_init($url);
curl_setopt_array($ch, [
  CURLOPT_RETURNTRANSFER => true,
  CURLOPT_HEADER => true,
  CURLOPT_HTTPAUTH => CURLAUTH_BASIC,
  CURLOPT_USERPWD => $creds['apple_id'] . ':' . $creds['app_password'],
  CURLOPT_FOLLOWLOCATION => true,
  CURLOPT_TIMEOUT => 60,
  CURLOPT_SSL_VERIFYPEER => true,
]);

$resp = curl_exec($ch);
if ($resp === false) {
  $err = curl_error($ch);
  curl_close($ch);
  fail(502, 'iCloud download failed: ' . $err);
}

$status = curl_getinfo($ch, CURLINFO_HTTP_CODE);
$headerSize = curl_getinfo($ch, CURLINFO_HEADER_SIZE);
curl_close($ch);

$headersRaw = substr($resp, 0, $headerSize);
$body = substr($resp, $headerSize);

if ($status < 200 || $status >= 300) {
  fail($status, 'iCloud download returned HTTP ' . $status);
}

// Determine filename
$filename = basename($path);
if ($filename === '') $filename = 'download.bin';

// Basic content-type guess
$ext = strtolower(pathinfo($filename, PATHINFO_EXTENSION));
$contentType = 'application/octet-stream';
if ($ext === 'pdf') $contentType = 'application/pdf';

header('Content-Type: ' . $contentType);
header('Content-Disposition: attachment; filename="' . rawurlencode($filename) . '"');
header('Content-Length: ' . strlen($body));
echo $body;
