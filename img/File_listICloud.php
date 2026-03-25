<?php
// /File_listICloud.php
declare(strict_types=1);

require_once __DIR__ . "/includes/functions.php";
sec_session_start();
header('Content-Type: application/json; charset=utf-8');

function fail(int $code, string $msg): void {
  http_response_code($code);
  echo json_encode(['error' => $msg], JSON_UNESCAPED_SLASHES);
  exit;
}

function getICloudCreds(): array {
  $c = $_SESSION['icloud'] ?? null;
  if (!is_array($c) || empty($c['apple_id']) || empty($c['app_password'])) {
    fail(401, 'iCloud not connected');
  }
  return $c;
}

// Normalize path input like "/" or "/Folder/Sub"
function normPath(string $p): string {
  $p = trim($p);
  if ($p === '') return '/';
  if ($p[0] !== '/') $p = '/' . $p;
  $p = preg_replace('~/+~', '/', $p);
  // Do NOT allow directory traversal
  $p = str_replace(['..', "\0"], '', $p);
  return $p;
}

function guessMime(string $name, bool $isDir): string {
  if ($isDir) return 'application/vnd.icloud.folder'; // contains "folder"? not necessarily
  $ext = strtolower(pathinfo($name, PATHINFO_EXTENSION));
  if ($ext === 'pdf') return 'application/pdf';
  if (in_array($ext, ['mp3','wav','ogg','m4a','flac','aac','aif','aiff','webm','mid','midi'], true)) return 'audio';
  return 'application/octet-stream';
}

// IMPORTANT: some clients rely on mimeType containing "folder"
function folderMime(): string { return 'application/vnd.google-apps.folder'; }

function webdavPropfind(string $url, string $user, string $pass, int $depth = 1): string {
  $xml = <<<XML
<?xml version="1.0" encoding="utf-8" ?>
<d:propfind xmlns:d="DAV:">
  <d:prop>
    <d:displayname />
    <d:resourcetype />
    <d:getcontentlength />
    <d:getlastmodified />
  </d:prop>
</d:propfind>
XML;

  $ch = curl_init($url);
  curl_setopt_array($ch, [
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_HEADER => true,
    CURLOPT_CUSTOMREQUEST => 'PROPFIND',
    CURLOPT_POSTFIELDS => $xml,
    CURLOPT_HTTPHEADER => [
      'Depth: ' . $depth,
      'Content-Type: application/xml; charset=utf-8',
    ],
    CURLOPT_USERPWD => $user . ':' . $pass,
    CURLOPT_TIMEOUT => 20,
    CURLOPT_SSL_VERIFYPEER => true,
  ]);

  $resp = curl_exec($ch);
  if ($resp === false) {
    $err = curl_error($ch);
    curl_close($ch);
    fail(502, 'iCloud WebDAV request failed: ' . $err);
  }

  $status = curl_getinfo($ch, CURLINFO_HTTP_CODE);
  $headerSize = curl_getinfo($ch, CURLINFO_HEADER_SIZE);
  curl_close($ch);

  $body = substr($resp, $headerSize);

  // 207 Multi-Status is expected for PROPFIND
  if ($status !== 207 && $status !== 200) {
    fail($status, 'iCloud WebDAV returned HTTP ' . $status);
  }

  return $body;
}

// Apple iCloud Drive WebDAV base
// NOTE: This is the "iCloud Drive" WebDAV endpoint typically used for read-only listing.
// If your account/region returns different behavior, we may need to adjust to a discovered URL.
function icloudBaseUrl(): string {
  return 'https://p00-webdav.icloud.com';
}

$creds = getICloudCreds();
$path = normPath((string)($_GET['path'] ?? '/'));

$base = icloudBaseUrl();
$url = $base . rtrim($path, '/') . '/'; // for folders, ensure trailing slash

$xmlBody = webdavPropfind($url, $creds['apple_id'], $creds['app_password'], 1);

// Parse Multi-Status
$doc = new DOMDocument();
libxml_use_internal_errors(true);
if (!$doc->loadXML($xmlBody)) {
  fail(502, 'Failed to parse iCloud WebDAV response XML');
}

$xpath = new DOMXPath($doc);
$xpath->registerNamespace('d', 'DAV:');

$responses = $xpath->query('//d:response');
$children = [];

if ($responses) {
  // First response is usually the directory itself; skip it by comparing href
  $selfHref = null;

  // Grab href of first item as self
  if ($responses->length > 0) {
    $hrefNode = $xpath->query('d:href', $responses->item(0))->item(0);
    if ($hrefNode) $selfHref = (string)$hrefNode->nodeValue;
  }

  foreach ($responses as $respNode) {
    $hrefNode = $xpath->query('d:href', $respNode)->item(0);
    if (!$hrefNode) continue;
    $href = (string)$hrefNode->nodeValue;

    // Skip self
    if ($selfHref && $href === $selfHref) continue;

    $nameNode = $xpath->query('.//d:displayname', $respNode)->item(0);
    $display = $nameNode ? (string)$nameNode->nodeValue : '';

    // Determine directory by presence of <d:collection/>
    $isDir = false;
    $collection = $xpath->query('.//d:resourcetype/d:collection', $respNode);
    if ($collection && $collection->length > 0) $isDir = true;

    // Build child path from href: best-effort decode
    // href is typically absolute path under the base host
    $decodedHref = urldecode($href);
    $u = parse_url($decodedHref);
    $childPath = $u['path'] ?? $decodedHref;

    // If server gives no displayname, derive from path
    if ($display === '') {
      $display = basename(rtrim($childPath, '/'));
      if ($display === '') $display = '(item)';
    }

    // Provide a "path" field that your JS can use as id for iCloud
    // We store as normalized path starting with "/"
    $p = $childPath;
    if ($p === '' || $p[0] !== '/') $p = '/' . $p;
    $p = preg_replace('~/+~', '/', $p);

    $children[] = [
      'name' => $display,
      'mimeType' => $isDir ? folderMime() : guessMime($display, false),
      'path' => $p,
      // optional: use id too for non-dropbox providers (your code checks node.id for non-dropbox)
      'id' => $p,
      '_isDir' => $isDir,
    ];
  }
}

echo json_encode(['children' => $children], JSON_UNESCAPED_SLASHES);
