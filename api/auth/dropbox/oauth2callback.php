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

/* ================= STATE CHECK ================= */

if (
  !isset($_GET["code"], $_GET["state"]) ||
  $_GET["state"] !== ($_SESSION["DROPBOX_OAUTH_STATE"] ?? "")
) {
  http_response_code(400);
  echo "Invalid OAuth state";
  exit;
}

/* ================= CONFIG ================= */

require_once __DIR__ . "/../../config_dropbox.php";

$clientId     = $dropboxAppKey ?? null;
$clientSecret = $dropboxAppSecret ?? null;

if (!$clientId || !$clientSecret) {
  http_response_code(500);
  echo "Dropbox credentials missing";
  exit;
}

/* ================= TOKEN EXCHANGE ================= */

// $redirectUri = "https://textwhisper.com/api/auth/dropbox/oauth2callback.php";
$redirectUri = "https://{$currentDomain}/api/auth/dropbox/oauth2callback.php";

$ch = curl_init("https://api.dropboxapi.com/oauth2/token");
curl_setopt_array($ch, [
  CURLOPT_RETURNTRANSFER => true,
  CURLOPT_POST => true,
  CURLOPT_POSTFIELDS => http_build_query([
    "code" => $_GET["code"],
    "grant_type" => "authorization_code",
    "client_id" => $clientId,
    "client_secret" => $clientSecret,
    "redirect_uri" => $redirectUri
  ])
]);

$res = curl_exec($ch);
curl_close($ch);

$data = json_decode($res, true);

if (empty($data["access_token"])) {
  http_response_code(500);
  echo "Dropbox OAuth failed";
  exit;
}

/* ================= STORE TOKEN ================= */

$_SESSION["DROPBOX_ACCESS_TOKEN"] = $data["access_token"];

error_log("OAUTH_CALLBACK session_id=" . session_id());
error_log("OAUTH_CALLBACK token=" . substr($data["access_token"], 0, 8) . "...");


if (!empty($data["refresh_token"])) {
  $_SESSION["DROPBOX_REFRESH_TOKEN"] = $data["refresh_token"];
}
?>
<!DOCTYPE html>
<html>
<head>
  <meta charset="utf-8">
  <script>
    if (window.opener) {
      window.opener.postMessage({ type: "dropbox-auth-ok" }, "*");
      window.close();
    } else {
      window.location = "/";
    }
  </script>
</head>
<body>Dropbox connected. You may close this window.</body>
</html>
