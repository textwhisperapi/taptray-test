<?php
// Simple avatar proxy to allow same-origin caching/offline support.
// Usage: /avatar-proxy.php?url=https://example.com/avatar.jpg

$url = $_GET['url'] ?? '';
if (!$url) {
  http_response_code(400);
  echo "Missing url";
  exit;
}

// Basic validation
if (!preg_match('#^https?://#i', $url)) {
  http_response_code(400);
  echo "Invalid url";
  exit;
}

$ch = curl_init($url);
curl_setopt_array($ch, [
  CURLOPT_RETURNTRANSFER => true,
  CURLOPT_FOLLOWLOCATION => true,
  CURLOPT_TIMEOUT => 8,
  CURLOPT_CONNECTTIMEOUT => 5,
  CURLOPT_USERAGENT => 'TextWhisperAvatarProxy/1.0',
  CURLOPT_HEADER => true
]);

$response = curl_exec($ch);
if ($response === false) {
  http_response_code(502);
  echo "Fetch failed";
  exit;
}

$status = curl_getinfo($ch, CURLINFO_HTTP_CODE);
$headerSize = curl_getinfo($ch, CURLINFO_HEADER_SIZE);
$headersRaw = substr($response, 0, $headerSize);
$body = substr($response, $headerSize);

if ($status < 200 || $status >= 300) {
  http_response_code($status);
  echo "Upstream error";
  exit;
}

$contentType = "image/jpeg";
foreach (explode("\r\n", $headersRaw) as $line) {
  if (stripos($line, "Content-Type:") === 0) {
    $contentType = trim(substr($line, strlen("Content-Type:")));
    break;
  }
}

header("Content-Type: {$contentType}");
header("Cache-Control: public, max-age=86400, stale-while-revalidate=86400");
echo $body;
