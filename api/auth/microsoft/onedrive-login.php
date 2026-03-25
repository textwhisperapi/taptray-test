<?php
require_once __DIR__ . "/../../../includes/functions.php";
sec_session_start();


$clientId = 'fb1a4eb8-2b8e-47ce-bc45-9493078f6002';
$tenantId = 'common';

$currentDomain = $_SERVER['HTTP_HOST'];
$redirectUri = "https://{$currentDomain}/api/auth/microsoft/onedrive-callback.php";

$scopes = [
  'Files.Read',
  'Files.Read.All',
  'offline_access'
];

$state = bin2hex(random_bytes(16));
$_SESSION['ONEDRIVE_OAUTH_STATE'] = $state;

$url =
  "https://login.microsoftonline.com/{$tenantId}/oauth2/v2.0/authorize" .
  "?client_id=" . urlencode($clientId) .
  "&response_type=code" .
  "&redirect_uri=" . urlencode($redirectUri) .
  "&response_mode=query" .
  "&scope=" . urlencode(implode(' ', $scopes)) .
  "&state=" . urlencode($state) .
  "&prompt=select_account";

header("Location: $url");
exit;
