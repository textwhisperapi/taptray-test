<?php
require_once __DIR__ . '/../../../includes/psl-config.php';
require_once __DIR__ . '/../../../includes/functions.php';

sec_session_start();

$clientId = tw_get_env('TW_MICROSOFT_CLIENT_ID', '');
$clientSecret = tw_get_env('TW_MICROSOFT_CLIENT_SECRET', '');
$tenantId = tw_get_env('TW_MICROSOFT_TENANT_ID', 'common');

if ($clientId === '' || $clientSecret === '') {
    http_response_code(500);
    exit('Microsoft OAuth is not configured.');
}

$currentDomain = $_SERVER['HTTP_HOST'];
$redirectUri   = "https://{$currentDomain}/api/auth/microsoft/oauth2callback.php";

// Check for OAuth error
if (isset($_GET['error'])) {
    die('OAuth Error: ' . htmlspecialchars($_GET['error_description'] ?? $_GET['error']));
}
if (!isset($_GET['code'])) {
    die('No authorization code returned.');
}

// Exchange code for tokens
$tokenUrl = "https://login.microsoftonline.com/{$tenantId}/oauth2/v2.0/token";
$postFields = [
    'client_id'     => $clientId,
    'scope'         => 'openid profile email User.Read',
    'code'          => $_GET['code'],
    'redirect_uri'  => $redirectUri,
    'grant_type'    => 'authorization_code',
    'client_secret' => $clientSecret,
];

$ch = curl_init($tokenUrl);
curl_setopt($ch, CURLOPT_POST, true);
curl_setopt($ch, CURLOPT_POSTFIELDS, http_build_query($postFields));
curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
$response = curl_exec($ch);
curl_close($ch);

$tokens = json_decode($response, true);
if (!isset($tokens['access_token'])) {
    die('Token error: ' . htmlspecialchars($response));
}

// Decode ID token to get a clean email
$email = '';
if (isset($tokens['id_token'])) {
    $parts = explode('.', $tokens['id_token']);
    if (count($parts) === 3) {
        $claims = json_decode(base64_decode(strtr($parts[1], '-_', '+/')), true);
        if (!empty($claims['preferred_username'])) {
            $email = $claims['preferred_username'];
        }
    }
}

// Get profile from Graph as backup
$userInfoUrl = "https://graph.microsoft.com/v1.0/me";
$ch = curl_init($userInfoUrl);
curl_setopt($ch, CURLOPT_HTTPHEADER, [
    'Authorization: Bearer ' . $tokens['access_token']
]);
curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
$userResponse = curl_exec($ch);
curl_close($ch);

$user = json_decode($userResponse, true);

if (!$email) {
    if (!empty($user['mail'])) {
        $email = $user['mail'];
    } elseif (!empty($user['userPrincipalName'])) {
        $email = $user['userPrincipalName'];
    }
}

// ===== Integrate with your existing login/session system =====
// Example: mimic Google login session setup
$_SESSION['user_email'] = $email;
$_SESSION['user_name']  = $user['displayName'] ?? $email;

// TODO: If your Google login inserts/updates a user in DB, call that function here
// createOrUpdateUser($email, $_SESSION['user_name'], 'microsoft');

// Redirect to UI
header("Location: /app"); // Change '/app' to your UI landing page
exit;
