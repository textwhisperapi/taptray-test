<?php
ob_start(); // Start output buffering to suppress accidental output
include_once 'functions.php';
sec_session_start();

function readProfileTokenPairsForLogout(): array {
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

function writeProfileTokenPairsForLogout(array $pairs): void {
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

    $secure = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off');
    setcookie("tw_profile_tokens", json_encode($payload), [
        'expires'  => time() + (30 * 24 * 60 * 60),
        'path'     => '/',
        'domain'   => getRootDomain(),
        'secure'   => $secure,
        'httponly' => true,
        'samesite' => 'Lax'
    ]);
}

// Remove persistent token from DB for security.
$forgetRecent = isset($_GET['forget_recent']) && $_GET['forget_recent'] === '1';
$selectorToRemove = '';
if (!empty($_SESSION['active_selector'])) {
    $selector = $_SESSION['active_selector'];
    $selectorToRemove = (string)$selector;
    if ($forgetRecent) {
        include_once 'db_connect.php';
        global $mysqli;
        $stmt = $mysqli->prepare("DELETE FROM member_tokens WHERE selector = ?");
        if ($stmt) {
            $stmt->bind_param("s", $selector);
            $stmt->execute();
            $stmt->close();
        }
    }
}

if (!empty($_COOKIE['remember_token'])) {
    $parts = explode(':', $_COOKIE['remember_token'], 2);
    $selector = $parts[0] ?? '';
    if ($selectorToRemove === '') {
        $selectorToRemove = (string)$selector;
    }
    if ($forgetRecent && preg_match('/^[a-f0-9]{12}$/i', $selector)) {
        include_once 'db_connect.php';
        global $mysqli;
        $stmt = $mysqli->prepare("DELETE FROM member_tokens WHERE selector = ?");
        if ($stmt) {
            $stmt->bind_param("s", $selector);
            $stmt->execute();
            $stmt->close();
        }
    }
}

if ($forgetRecent && preg_match('/^[a-f0-9]{12}$/i', $selectorToRemove)) {
    $target = strtolower($selectorToRemove);
    $pairs = readProfileTokenPairsForLogout();
    $pairs = array_values(array_filter($pairs, function ($pair) use ($target) {
        return strtolower($pair[0]) !== $target;
    }));
    writeProfileTokenPairsForLogout($pairs);
}

// ✅ Clear remember_token cookie (all domain variants)
twClearRememberToken();

// ✅ Unset all session values
$_SESSION = [];

// ✅ Delete the session cookie
$params = session_get_cookie_params();
setcookie(session_name(), '', time() - 42000,
    $params["path"],
    $params["domain"],
    $params["secure"],
    $params["httponly"]
);

// ✅ Destroy the session
session_destroy();

// ✅ Redirect handling
$redirectTo = $_GET['redirect'] ?? '/';
$parsed = parse_url($redirectTo);
$path = $parsed['path'] ?? '/';

// Prevent redirecting to login/logout page
if (strtolower($path) === '/login.php' || strtolower($path) === '/includes/logout.php') {
    $path = '/';
}

// ✅ Send redirect and exit
header("Location: $path");
exit;
