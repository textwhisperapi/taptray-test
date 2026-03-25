<?php
error_reporting(E_ALL);
ini_set('display_errors', '0');
ini_set('display_startup_errors', '0');

include_once 'db_connect.php';
include_once 'functions.php';

sec_session_start(true);

function readProfileTokenPairsForGoogleNda(): array {
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

function writeProfileTokenPairsForGoogleNda(array $pairs): void {
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

    setcookie('tw_profile_tokens', json_encode($payload), twCookieOptions([
        'expires' => time() + (30 * 24 * 60 * 60)
    ]));
}

$pending = $_SESSION['pending_google_nda'] ?? null;
if (!is_array($pending) || empty($pending['user_id']) || empty($pending['set_at'])) {
    header('Location: /login.php');
    exit;
}

if ((time() - (int)$pending['set_at']) > 1200) {
    unset($_SESSION['pending_google_nda']);
    header('Location: /login.php?error=1');
    exit;
}

if (empty($_POST['nda_agree'])) {
    header('Location: /google-nda.php?error=required');
    exit;
}

$user_id = (int)$pending['user_id'];
$nda_version = 'https://trustagreements.org/basic-v1.html';

$stmt = $mysqli->prepare('SELECT username, password, nda_agreed_at FROM members WHERE id = ? LIMIT 1');
if (!$stmt) {
    unset($_SESSION['pending_google_nda']);
    header('Location: /login.php?error=1');
    exit;
}
$stmt->bind_param('i', $user_id);
$stmt->execute();
$stmt->store_result();
if ($stmt->num_rows !== 1) {
    $stmt->close();
    unset($_SESSION['pending_google_nda']);
    header('Location: /login.php?error=1');
    exit;
}
$stmt->bind_result($username, $db_password, $ndaAgreedAt);
$stmt->fetch();
$stmt->close();

if (empty($ndaAgreedAt)) {
    $stmtNDA = $mysqli->prepare('UPDATE members SET nda_agreed_at = NOW(), nda_version = ? WHERE id = ? AND nda_agreed_at IS NULL');
    if ($stmtNDA) {
        $stmtNDA->bind_param('si', $nda_version, $user_id);
        $stmtNDA->execute();
        $stmtNDA->close();
    }
}

$_SESSION['user_id'] = $user_id;
$_SESSION['username'] = $username;
$_SESSION['session_version'] = getCurrentSessionVersion($user_id, $mysqli);
$_SESSION['login_string'] = hash('sha512', $db_password . ($_SERVER['HTTP_USER_AGENT'] ?? ''));
$_SESSION['nda_agreed_at'] = date('Y-m-d H:i:s');
$_SESSION['nda_version'] = $nda_version;

$selector = bin2hex(random_bytes(6));
$token = bin2hex(random_bytes(32));
$hashedToken = hash('sha256', $token);
$expires = time() + (30 * 24 * 60 * 60);
$sessionOnly = 0;
$user_agent = twClientAgentForStorage();
$ip_address = $_SERVER['REMOTE_ADDR'] ?? '';

$stmtToken = $mysqli->prepare("\n    INSERT INTO member_tokens\n    (user_id, selector, hashed_token, user_agent, ip_address, expires, session_only)\n    VALUES (?, ?, ?, ?, ?, FROM_UNIXTIME(?), ?)\n");
if ($stmtToken) {
    $stmtToken->bind_param('issssii', $user_id, $selector, $hashedToken, $user_agent, $ip_address, $expires, $sessionOnly);
    $stmtToken->execute();
    $stmtToken->close();
}
twPruneMemberTokens($mysqli, (int)$user_id, 2, $selector);

twSetRememberToken("$selector:$token", $expires);

$pairs = readProfileTokenPairsForGoogleNda();
$pairs = array_values(array_filter($pairs, function ($pair) use ($selector) {
    return strtolower($pair[0]) !== strtolower($selector);
}));
array_unshift($pairs, [strtolower($selector), strtolower($token)]);
writeProfileTokenPairsForGoogleNda($pairs);

$_SESSION['active_selector'] = $selector;

$redirectTo = $_SESSION['redirect_after_login'] ?? '/';
unset(
    $_SESSION['pending_google_nda'],
    $_SESSION['redirect_after_login'],
    $_SESSION['pending_invite_token'],
    $_SESSION['pending_invite_list_token'],
    $_SESSION['pending_invite_set_at'],
    $_SESSION['pending_invite_email']
);

if (!preg_match('#^/[\\w\\-\\/\\?\\=\\&]*$#', $redirectTo)) {
    $redirectTo = '/';
}

$redirectTo = withAvatarOnboardingRedirect($mysqli, $user_id, $redirectTo);
header("Location: $redirectTo");
exit;
