<?php
require_once __DIR__ . "/../../../includes/functions.php";
require_once __DIR__ . "/../../../includes/psl-config.php";
sec_session_start();

if (
  empty($_GET['code']) ||
  ($_GET['state'] ?? '') !== ($_SESSION['ONEDRIVE_OAUTH_STATE'] ?? '')
) {
  http_response_code(400);
  echo "Invalid OAuth state";
  exit;
}

$clientId = tw_get_env('TW_MICROSOFT_CLIENT_ID', '');
$clientSecret = tw_get_env('TW_MICROSOFT_CLIENT_SECRET', '');
$tenantId = tw_get_env('TW_MICROSOFT_TENANT_ID', 'common');

if ($clientId === '' || $clientSecret === '') {
  http_response_code(500);
  echo "Microsoft OAuth is not configured";
  exit;
}

$currentDomain = $_SERVER['HTTP_HOST'];
$redirectUri = "https://{$currentDomain}/api/auth/microsoft/onedrive-callback.php";

$tokenUrl = "https://login.microsoftonline.com/{$tenantId}/oauth2/v2.0/token";

$post = [
  'client_id'     => $clientId,
  'client_secret' => $clientSecret,
  'grant_type'    => 'authorization_code',
  'code'          => $_GET['code'],
  'redirect_uri'  => $redirectUri,
  'scope'         => 'Files.Read Files.Read.All offline_access'
];

$ch = curl_init($tokenUrl);
curl_setopt_array($ch, [
  CURLOPT_POST => true,
  CURLOPT_POSTFIELDS => http_build_query($post),
  CURLOPT_RETURNTRANSFER => true
]);
$res = curl_exec($ch);
curl_close($ch);

$data = json_decode($res, true);

if (empty($data['access_token'])) {
  http_response_code(500);
  echo "Token error";
  exit;
}

$_SESSION['ONEDRIVE_ACCESS_TOKEN'] = $data['access_token'];
if (!empty($data['refresh_token'])) {
  $_SESSION['ONEDRIVE_REFRESH_TOKEN'] = $data['refresh_token'];
}
?>
<!DOCTYPE html>
<html>
<body>
<script>
  if (window.opener) {
    window.opener.postMessage(
      { type: "onedrive-auth-ok" },
      window.location.origin
    );
    window.close();
  }
</script>
</body>
</html>
