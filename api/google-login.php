<?php
require_once __DIR__ . '/../includes/system-paths.php';
require_once VENDOR_PATH . 'autoload.php';
require_once __DIR__ . '/config_google.php';
require_once __DIR__ . '/../includes/db_connect.php';
require_once __DIR__ . '/../includes/functions.php';

//require_once '/home1/wecanrec/textwhisper_vendor/autoload.php';


error_reporting(E_ALL);
ini_set('display_errors', '0');
ini_set('display_startup_errors', '0');

sec_session_start();

$inviteTokenParam = $_GET['invite'] ?? '';
$inviteListParam = $_GET['list'] ?? '';
$isRegisterFlow = (isset($_GET['register']) && $_GET['register'] === '1');

if (
    $isRegisterFlow &&
    preg_match('/^[a-f0-9]{64}$/', $inviteTokenParam) &&
    preg_match('/^[A-Za-z0-9._-]{2,120}$/', $inviteListParam)
) {
    $inviteMaxAgeDays = defined('INVITE_TOKEN_MAX_AGE_DAYS') ? (int)INVITE_TOKEN_MAX_AGE_DAYS : 7;
    $resolvedInviteEmail = resolveInviteEmailForToken($mysqli, $inviteListParam, $inviteTokenParam, $inviteMaxAgeDays);
    if (!filter_var($resolvedInviteEmail, FILTER_VALIDATE_EMAIL)) {
        header("Location: /register.php?error=invalid_invite");
        exit;
    }
    $_SESSION['pending_invite_token'] = $inviteTokenParam;
    $_SESSION['pending_invite_list_token'] = $inviteListParam;
    $_SESSION['pending_invite_set_at'] = time();
    $_SESSION['pending_invite_email'] = strtolower(trim((string)$resolvedInviteEmail));
    $_SESSION['redirect_after_login'] = '/' . $inviteListParam;
}



// ✅ Universal base URI
$host = $_SERVER['HTTP_HOST'];
//$protocol = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? "https" : "http";
$protocol = "https";
$redirectUri = "$protocol://$host/api/oauth2callback.php";

// ✅ Shared credentials from Google Cloud (same client for both domains)
$client = new Google_Client();
$client->setClientId(GOOGLE_CLIENT_ID);
$client->setClientSecret(GOOGLE_CLIENT_SECRET);
$client->setRedirectUri($redirectUri);
$client->addScope(['email', 'profile']);


//echo "REDIRECT: " . $redirectUri;
//exit;


header('Location: ' . $client->createAuthUrl());
exit;
