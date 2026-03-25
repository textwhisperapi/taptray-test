<?php
error_reporting(E_ALL);
ini_set('display_errors', 1);
header('Content-Type: application/json');

// Step 1: Get and validate URL
//$url = $_GET['url'] ?? '';
$url = is_array($_GET['url']) ? $_GET['url'][0] : ($_GET['url'] ?? '');
if (!filter_var($url, FILTER_VALIDATE_URL)) {
    echo json_encode(['error' => 'Invalid URL']);
    exit;
}


// Debug logging
error_log("Fetching metadata for: $url");


// Step 2: Domain whitelist to prevent SSRF
$allowedDomains = [
    'soundslice.com',
    'open.spotify.com',
    'youtube.com',
    'youtu.be',
    'bandcamp.com',
    'music.apple.com'
];

$parsedUrl = parse_url($url);
$host = $parsedUrl['host'] ?? '';

$domainAllowed = false;
foreach ($allowedDomains as $domain) {
    if (stripos($host, $domain) !== false) {
        $domainAllowed = true;
        break;
    }
}

if (!$domainAllowed) {
    echo json_encode(['error' => 'URL not allowed']);
    exit;
}

// Step 3: Fetch HTML with curl
$ch = curl_init($url);
curl_setopt_array($ch, [
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_FOLLOWLOCATION => true,
    CURLOPT_USERAGENT => "Mozilla/5.0",
    CURLOPT_TIMEOUT => 10
]);
$html = curl_exec($ch);
$err = curl_error($ch);
curl_close($ch);

if (!$html || !is_string($html)) {
    echo json_encode(['error' => 'Failed to fetch content', 'detail' => $err]);
    exit;
}

// Step 4: Extract metadata
$meta = [];

if (preg_match('/<meta property="og:title" content="(.*?)"/i', $html, $m)) {
    $meta['ogTitle'] = trim($m[1]);
}
if (preg_match('/<title>(.*?)<\/title>/i', $html, $m)) {
    $meta['title'] = trim($m[1]);
}
if (preg_match('/<meta name="description" content="(.*?)"/i', $html, $m)) {
    $meta['description'] = trim($m[1]);
}
if (preg_match('/<meta property="og:image" content="(.*?)"/i', $html, $m)) {
    $meta['ogImage'] = trim($m[1]);
}

echo json_encode($meta);
