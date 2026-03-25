<?php
include_once 'db_connect.php';
include_once 'functions.php';

sec_session_start(true);
header('Content-Type: application/json; charset=utf-8');

function json_error(string $message, int $status = 400): void {
    http_response_code($status);
    echo json_encode(['ok' => false, 'message' => $message]);
    exit;
}

function readProfileTokenPairsCookie(): array {
    if (empty($_COOKIE['tw_profile_tokens'])) return [];
    $decoded = json_decode($_COOKIE['tw_profile_tokens'], true);
    if (!is_array($decoded)) return [];

    $pairs = [];
    foreach ($decoded as $item) {
        if (!is_string($item)) continue;
        $parts = explode(':', $item, 2);
        if (count($parts) !== 2) continue;
        [$selector, $token] = $parts;
        if (!preg_match('/^[a-f0-9]{12}$/i', $selector)) continue;
        if (!preg_match('/^[a-f0-9]{64}$/i', $token)) continue;
        $pairs[strtolower($selector)] = [strtolower($selector), strtolower($token)];
    }

    return array_values($pairs);
}

function writeProfileTokenPairsCookie(array $pairs): void {
    $payload = [];
    foreach ($pairs as $pair) {
        if (!is_array($pair) || count($pair) !== 2) continue;
        [$selector, $token] = $pair;
        if (!is_string($selector) || !is_string($token)) continue;
        if (!preg_match('/^[a-f0-9]{12}$/i', $selector)) continue;
        if (!preg_match('/^[a-f0-9]{64}$/i', $token)) continue;
        $payload[] = strtolower($selector) . ':' . strtolower($token);
        if (count($payload) >= 5) break;
    }

    setcookie("tw_profile_tokens", json_encode($payload), twCookieOptions([
        'expires'  => time() + (30 * 24 * 60 * 60)
    ]));
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    json_error('Method not allowed', 405);
}

$nonce = (string)($_POST['nonce'] ?? '');
$sessionNonce = (string)($_SESSION['profile_auto_login_nonce'] ?? '');
if ($nonce === '' || $sessionNonce === '' || !hash_equals($sessionNonce, $nonce)) {
    json_error('Invalid request', 403);
}

$selector = strtolower(trim((string)($_POST['selector'] ?? '')));
if (!preg_match('/^[a-f0-9]{12}$/', $selector)) {
    json_error('Invalid selector', 422);
}

$redirectTo = (string)($_POST['redirectTo'] ?? '/');
$parsed = parse_url($redirectTo);
$path = $parsed['path'] ?? '/';
$cleanPath = trim(strtolower($path), '/');
$invalidRedirects = ['login.php', 'register.php', 'forgot_password.php', 'reset_password.php', '', 'welcome', 'default'];
$isSafeRedirect = !in_array($cleanPath, $invalidRedirects, true);

$token = null;
foreach (readProfileTokenPairsCookie() as [$cookieSelector, $cookieToken]) {
    if ($cookieSelector === $selector) {
        $token = $cookieToken;
        break;
    }
}
if ($token === null) {
    json_error('Profile token not found on this browser', 401);
}

$stmt = $mysqli->prepare("
    SELECT t.user_id, t.hashed_token, m.username, m.password, m.session_version
    FROM member_tokens t
    JOIN members m ON m.id = t.user_id
    WHERE t.selector = ? AND t.session_only = 0 AND t.expires > NOW()
    LIMIT 1
");
if (!$stmt) {
    json_error('Server error', 500);
}

$stmt->bind_param('s', $selector);
$stmt->execute();
$stmt->store_result();
if ($stmt->num_rows !== 1) {
    $stmt->close();
    json_error('Profile is no longer available', 401);
}

$stmt->bind_result($userId, $hashedToken, $username, $passwordHash, $sessionVersion);
$stmt->fetch();
$stmt->close();

$rawUa = $_SERVER['HTTP_USER_AGENT'] ?? '';
$currentUa = twClientAgentForStorage();
$expectedHash = hash('sha256', $token);
if (!hash_equals($hashedToken, $expectedHash)) {
    json_error('Profile token mismatch for this browser', 401);
}

$_SESSION['user_id'] = $userId;
$_SESSION['username'] = $username;
$_SESSION['session_version'] = $sessionVersion;
$_SESSION['login_string'] = hash('sha512', $passwordHash . $rawUa);

$newSelector = bin2hex(random_bytes(6));
$newToken = bin2hex(random_bytes(32));
$newHashedToken = hash('sha256', $newToken);
$expires = time() + (30 * 24 * 60 * 60);
$ipAddress = $_SERVER['REMOTE_ADDR'] ?? '0.0.0.0';

$deleteStmt = $mysqli->prepare("DELETE FROM member_tokens WHERE selector = ?");
if ($deleteStmt) {
    $deleteStmt->bind_param("s", $selector);
    $deleteStmt->execute();
    $deleteStmt->close();
}

$insertStmt = $mysqli->prepare("
    INSERT INTO member_tokens
    (user_id, selector, hashed_token, user_agent, ip_address, expires, session_only)
    VALUES (?, ?, ?, ?, ?, FROM_UNIXTIME(?), 0)
");
if (!$insertStmt) {
    json_error('Could not create session token', 500);
}
$insertStmt->bind_param("issssi", $userId, $newSelector, $newHashedToken, $currentUa, $ipAddress, $expires);
$insertStmt->execute();
$insertStmt->close();
twPruneMemberTokens($mysqli, (int)$userId, 2, $newSelector);

$_SESSION['active_selector'] = $newSelector;

twSetRememberToken($newSelector . ":" . $newToken, $expires);

$pairs = readProfileTokenPairsCookie();
$pairs = array_values(array_filter($pairs, function ($pair) use ($selector) {
    return strtolower($pair[0]) !== $selector;
}));
array_unshift($pairs, [strtolower($newSelector), strtolower($newToken)]);
writeProfileTokenPairsCookie($pairs);

$finalRedirect = $isSafeRedirect ? $path : '/';
echo json_encode(['ok' => true, 'redirect' => $finalRedirect]);
