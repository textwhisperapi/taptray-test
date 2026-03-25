<?php

/* ================= SESSION (CRITICAL) ================= */

$currentDomain = $_SERVER['HTTP_HOST'];

session_set_cookie_params([
  'lifetime' => 0,
  'path' => '/',
  'domain'   => ".{$currentDomain}",
  'secure' => true,
  'httponly' => true,
  'samesite' => 'Lax'
]);
session_start();

/* ================= CONFIG ================= */

require_once __DIR__ . "/../../config_dropbox.php";

if (empty($dropboxAppKey)) {
  http_response_code(500);
  echo "Dropbox app key missing";
  exit;
}

/* ================= OAUTH ================= */

// $redirectUri = "https://textwhisper.com/api/auth/dropbox/oauth2callback.php";
$redirectUri = "https://{$currentDomain}/api/auth/dropbox/oauth2callback.php";

$state = bin2hex(random_bytes(16));
$_SESSION["DROPBOX_OAUTH_STATE"] = $state;

// $params = [
//   "client_id" => $dropboxAppKey,
//   "response_type" => "code",
//   "redirect_uri" => $redirectUri,
//   "state" => $state,
//   "token_access_type" => "offline"
// ];


$force = !empty($_GET['force']);

$params = [
  "client_id" => $dropboxAppKey,
  "response_type" => "code",
  "redirect_uri" => $redirectUri,
  "state" => $state,
  "token_access_type" => "offline"
];

if ($force) {
  $params["force_reauthentication"] = "true";
}



header(
  "Location: https://www.dropbox.com/oauth2/authorize?" .
  http_build_query($params)
);
exit;
