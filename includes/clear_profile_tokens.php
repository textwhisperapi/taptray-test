<?php
include_once 'functions.php';
sec_session_start(true);
header('Content-Type: application/json; charset=utf-8');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['ok' => false, 'message' => 'Method not allowed']);
    exit;
}

$nonce = (string)($_POST['nonce'] ?? '');
$sessionNonce = (string)($_SESSION['profile_auto_login_nonce'] ?? '');
if ($nonce === '' || $sessionNonce === '' || !hash_equals($sessionNonce, $nonce)) {
    http_response_code(403);
    echo json_encode(['ok' => false, 'message' => 'Invalid request']);
    exit;
}

$secure = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off');
setcookie("tw_profile_tokens", '', time() - 3600, '/', getRootDomain(), $secure, true);

echo json_encode(['ok' => true]);

