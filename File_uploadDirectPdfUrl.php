<?php
require_once __DIR__ . "/includes/functions.php";
require_once __DIR__ . "/includes/db_connect.php";

sec_session_start();

header("Content-Type: application/json");
ini_set('display_errors', 0);
ob_start();

function tw_is_public_ip(string $ip): bool {
    if (!filter_var($ip, FILTER_VALIDATE_IP)) return false;
    return (bool) filter_var(
        $ip,
        FILTER_VALIDATE_IP,
        FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE
    );
}

function tw_is_safe_remote_url(string $url): bool {
    $parts = @parse_url($url);
    if (!is_array($parts)) return false;
    $scheme = strtolower((string)($parts['scheme'] ?? ''));
    $host = strtolower((string)($parts['host'] ?? ''));
    if (($scheme !== 'https' && $scheme !== 'http') || $host === '') return false;
    if ($host === 'localhost' || $host === '127.0.0.1' || $host === '::1') return false;

    // Block direct-IP hosts that are not public routable.
    if (filter_var($host, FILTER_VALIDATE_IP)) {
        return tw_is_public_ip($host);
    }

    // Resolve host and ensure all returned A records are public.
    $ipv4 = @gethostbynamel($host);
    if (!is_array($ipv4) || !$ipv4) return false;
    foreach ($ipv4 as $ip) {
        if (!tw_is_public_ip((string)$ip)) return false;
    }
    return true;
}

function tw_resolve_redirect_url(string $baseUrl, string $location): ?string {
    $location = trim($location);
    if ($location === '') return null;
    if (preg_match('/^https?:\/\//i', $location)) return $location;
    if (strpos($location, '//') === 0) {
        $base = @parse_url($baseUrl);
        $scheme = strtolower((string)($base['scheme'] ?? 'https'));
        return $scheme . ':' . $location;
    }
    $base = @parse_url($baseUrl);
    if (!is_array($base)) return null;
    $scheme = strtolower((string)($base['scheme'] ?? 'https'));
    $host = (string)($base['host'] ?? '');
    if ($host === '') return null;
    $port = isset($base['port']) ? ':' . (int)$base['port'] : '';
    $path = (string)($base['path'] ?? '/');
    $dir = preg_replace('#/[^/]*$#', '/', $path);
    if ($dir === null || $dir === '') $dir = '/';
    if (strpos($location, '/') === 0) {
        return "{$scheme}://{$host}{$port}{$location}";
    }
    return "{$scheme}://{$host}{$port}{$dir}{$location}";
}

function tw_fetch_pdf_with_safe_redirects(string $url): array {
    $maxRedirects = 3;
    $currentUrl = $url;

    for ($i = 0; $i <= $maxRedirects; $i++) {
        if (!tw_is_safe_remote_url($currentUrl)) {
            return ['ok' => false, 'error' => 'Blocked remote URL', 'httpCode' => 0, 'contentType' => ''];
        }

        $ch = curl_init($currentUrl);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_FOLLOWLOCATION => false,
            CURLOPT_TIMEOUT => 20,
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_SSL_VERIFYHOST => 2,
            CURLOPT_USERAGENT => 'TextWhisperBot/1.0 (+https://skolaspjall.is)',
            CURLOPT_HTTPHEADER => ['Accept: application/pdf,*/*;q=0.9'],
            CURLOPT_HEADER => true
        ]);

        $raw = curl_exec($ch);
        $httpCode = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $contentType = (string)curl_getinfo($ch, CURLINFO_CONTENT_TYPE);
        $headerSize = (int)curl_getinfo($ch, CURLINFO_HEADER_SIZE);
        $curlErr = (string)curl_error($ch);
        curl_close($ch);

        if ($raw === false || $raw === '') {
            return ['ok' => false, 'error' => 'Fetch failed', 'httpCode' => $httpCode, 'contentType' => $contentType, 'curlError' => $curlErr];
        }

        $headers = substr($raw, 0, $headerSize);
        $body = substr($raw, $headerSize);

        if ($httpCode >= 300 && $httpCode < 400) {
            if ($i >= $maxRedirects) {
                return ['ok' => false, 'error' => 'Too many redirects', 'httpCode' => $httpCode, 'contentType' => $contentType];
            }
            $location = '';
            if (preg_match('/\r?\nLocation:\s*([^\r\n]+)/i', (string)$headers, $m)) {
                $location = trim((string)$m[1]);
            }
            $nextUrl = tw_resolve_redirect_url($currentUrl, $location);
            if (!$nextUrl) {
                return ['ok' => false, 'error' => 'Invalid redirect location', 'httpCode' => $httpCode, 'contentType' => $contentType];
            }
            $currentUrl = $nextUrl;
            continue;
        }

        return [
            'ok' => true,
            'httpCode' => $httpCode,
            'contentType' => $contentType,
            'data' => $body
        ];
    }

    return ['ok' => false, 'error' => 'Unexpected fetch loop', 'httpCode' => 0, 'contentType' => ''];
}


$url       = trim((string)($_POST['url'] ?? ''));
$surrogate = trim((string)($_POST['surrogate'] ?? ''));

if ($url === '' || $surrogate === '') {
    http_response_code(400);
    echo json_encode(['status' => 'error', 'error' => 'Missing url or surrogate']);
    exit;
}

// Must be logged in
if (!isset($_SESSION['user_id'])) {
    http_response_code(403);
    echo json_encode(['status' => 'error', 'error' => 'Not logged in']);
    exit;
}

// Owner lookup from DB
$surrogateSafe = $mysqli->real_escape_string($surrogate);
$query = "SELECT owner FROM `text` WHERE Surrogate = '$surrogateSafe' LIMIT 1";
$result = $mysqli->query($query);
$item = $result ? $result->fetch_assoc() : null;

if (!$item || empty($item['owner'])) {
    http_response_code(404);
    echo json_encode(['status' => 'error', 'error' => 'Item not found or missing owner']);
    exit;
}

$owner = $item['owner'];


$currentUser = $_SESSION['username'];
$currentUserId = $_SESSION['user_id'] ?? null;

// Owner id for general log (best-effort)
$ownerId = null;
$stmtOwner = $mysqli->prepare("SELECT id FROM members WHERE username = ? LIMIT 1");
if ($stmtOwner) {
    $stmtOwner->bind_param("s", $owner);
    $stmtOwner->execute();
    $stmtOwner->bind_result($ownerId);
    $stmtOwner->fetch();
    $stmtOwner->close();
}

// Rights check
if (!can_user_edit_surrogate($mysqli, $surrogate, $currentUser)) {
    http_response_code(403);
    echo json_encode([
        'status' => 'error',
        'error'  => 'You do not have rights to modify this item'
    ]);
    exit;
}



//Sanitize filename/key
$safeSurrogate = preg_replace('/[^a-zA-Z0-9_-]/', '', $surrogate);
$filename = "temp_pdf_surrogate-$safeSurrogate.pdf";
$r2Key = rawurlencode($owner) . "/pdf/" . rawurlencode($filename);
$r2UploadUrl = "https://r2-worker.textwhisper.workers.dev/?key={$r2Key}";
$canonicalUrl = "https://pub-1afc23a510c147a5a857168f23ff6db8.r2.dev/{$owner}/pdf/{$filename}";

$fetched = tw_fetch_pdf_with_safe_redirects($url);
$data = (string)($fetched['data'] ?? '');
$httpCode = (int)($fetched['httpCode'] ?? 0);
$contentType = (string)($fetched['contentType'] ?? '');
$fetchErr = (string)($fetched['error'] ?? '');

//Must be a successful PDF download
$looksLikePdf = (stripos((string)$contentType, 'pdf') !== false) || (preg_match('/^\s*%PDF-/', $data) === 1);
$maxBytes = 50 * 1024 * 1024;
if (!$fetched['ok'] || !$data || $httpCode !== 200 || !$looksLikePdf || strlen($data) > $maxBytes) {
    http_response_code(500);
    echo json_encode([
        'status' => 'error',
        'error' => 'Failed to fetch valid PDF',
        'httpCode' => $httpCode,
        'contentType' => $contentType,
        'curlError' => $fetchErr
    ]);
    exit;
}

//Upload binary to Cloudflare R2 via worker
$chUp = curl_init($r2UploadUrl);
curl_setopt_array($chUp, [
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_POST => true,
    CURLOPT_POSTFIELDS => $data,
    CURLOPT_HTTPHEADER => [
        'Content-Type: application/pdf',
        'Content-Length: ' . strlen($data)
    ],
    CURLOPT_TIMEOUT => 30,
    CURLOPT_SSL_VERIFYPEER => true,
    CURLOPT_SSL_VERIFYHOST => 2
]);
$upBody = curl_exec($chUp);
$upHttp = curl_getinfo($chUp, CURLINFO_HTTP_CODE);
$upErr = curl_error($chUp);
curl_close($chUp);

if ($upHttp < 200 || $upHttp >= 300) {
    http_response_code(500);
    echo json_encode([
        'status' => 'error',
        'error' => 'Failed to upload PDF to R2',
        'uploadHttp' => $upHttp,
        'uploadError' => $upErr
    ]);
    exit;
}

log_change($mysqli, 'upload', (int)$surrogate, $owner, $currentUser, 'pdf', $canonicalUrl, 'url');
log_change_general(
    $mysqli,
    'upload',
    'pdf',
    (int)$surrogate,
    null,
    $ownerId,
    $owner,
    $currentUserId,
    $currentUser,
    json_encode(['url' => $canonicalUrl, 'source' => 'url'], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)
);

//Done
ob_end_clean();
echo json_encode([
    'status' => 'success',
    'url' => $canonicalUrl
]);
