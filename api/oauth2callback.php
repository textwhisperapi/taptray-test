<?php
error_reporting(E_ALL);
ini_set('display_errors', '0');
ini_set('display_startup_errors', '0');


require_once __DIR__ . '/../includes/db_connect.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/config_google.php';

require_once __DIR__ . '/../includes/system-paths.php';
require_once VENDOR_PATH . 'autoload.php';


sec_session_start(); // ✅ Use same session logic as conventional login

function readProfileTokenPairsForGoogle(): array {
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

function writeProfileTokenPairsForGoogle(array $pairs): void {
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

$host = $_SERVER['HTTP_HOST'];
//$protocol = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? "https" : "http";
$protocol = 'https';
$redirectUri = "$protocol://$host/api/oauth2callback.php";

// 🔧 Google Client config
$client = new Google_Client();
$client->setClientId(GOOGLE_CLIENT_ID);
$client->setClientSecret(GOOGLE_CLIENT_SECRET);
$client->setRedirectUri($redirectUri);
$client->addScope(['email', 'profile']);

if (!isset($_GET['code'])) {
    exit('❌ No code received from Google.');
}

try {
    $client->authenticate($_GET['code']);
    $token = $client->getAccessToken();
    if (empty($token['access_token'])) {
        throw new Exception('No access token returned by Google.');
    }

    $client->setAccessToken($token);
    $oauth2 = new Google_Service_Oauth2($client);
    $userinfo = $oauth2->userinfo->get();

    $email = $userinfo->email ?? null;
    $googleName = $userinfo->name ?? '';
    $baseUsername = explode("@", $email)[0];

    if (!$email) {
        throw new Exception('Email not returned by Google.');
    }
    $pendingInviteEmail = strtolower(trim((string)($_SESSION['pending_invite_email'] ?? '')));
    $pendingInviteListToken = (string)($_SESSION['pending_invite_list_token'] ?? '');
    $pendingInviteToken = (string)($_SESSION['pending_invite_token'] ?? '');

    if ($pendingInviteEmail !== '' && strcasecmp($email, $pendingInviteEmail) !== 0) {
        $redirect = '/register.php?error=invite_email_mismatch';
        if (preg_match('/^[A-Za-z0-9._-]{2,120}$/', $pendingInviteListToken) && preg_match('/^[a-f0-9]{64}$/', $pendingInviteToken)) {
            $redirect .= '&list=' . urlencode($pendingInviteListToken)
                . '&invite=' . urlencode($pendingInviteToken);
        }
        header("Location: $redirect");
        exit;
    }

    // 🔍 Lookup or create user
    $stmt = $mysqli->prepare("SELECT id, username, password, nda_agreed_at FROM members WHERE email = ? LIMIT 1");
    $stmt->bind_param("s", $email);
    $stmt->execute();
    $stmt->store_result();

    if ($stmt->num_rows === 1) {
        $stmt->bind_result($user_id, $username, $db_password, $ndaAgreedAt);
        $stmt->fetch();

    // 🩹 Patch missing profile fields if needed
    $needsPatch = false;
    $updateFields = [];
    $params = [];
    
    // Pull fresh data from Google
    $firstName = $userinfo->givenName ?? '';
    $lastName = $userinfo->familyName ?? '';
    $avatarUrl = $userinfo->picture ?? '';
    $locale = $userinfo->locale ?? '';
    $displayName = $userinfo->name ?? '';
    
    
    //-------------------
    
    
    // 🩹 Patch missing profile fields if needed
    $stmtCheck = $mysqli->prepare("
        SELECT display_name, first_name, last_name, avatar_url, locale 
        FROM members 
        WHERE id = ?
    ");
    $stmtCheck->bind_param("i", $user_id);
    $stmtCheck->execute();
    $stmtCheck->bind_result($currDisplay, $currFirst, $currLast, $currAvatar, $currLocale);
    $stmtCheck->fetch();
    $stmtCheck->close();
    
    // ✅ Pull fresh from Google
    $firstName   = $userinfo->givenName ?? '';
    $lastName    = $userinfo->familyName ?? '';
    $avatarUrl   = $userinfo->picture ?? '';
    $locale      = $userinfo->locale ?? '';
    $displayName = $userinfo->name ?? '';
    
    $updateFields = [];
    $params = [];
    
    // Patch missing or default fields
    if (empty($currDisplay) && $displayName) {
        $updateFields[] = "display_name = ?";
        $params[] = $displayName;
    }
    if (empty($currFirst) && $firstName) {
        $updateFields[] = "first_name = ?";
        $params[] = $firstName;
    }
    if (empty($currLast) && $lastName) {
        $updateFields[] = "last_name = ?";
        $params[] = $lastName;
    }
    // ✅ Avatar: replace if missing OR still default
    if ((empty($currAvatar) || strpos($currAvatar, 'default-avatar.png') !== false) && $avatarUrl) {
        $updateFields[] = "avatar_url = ?";
        $params[] = $avatarUrl;
    }
    if (empty($currLocale) && $locale) {
        $updateFields[] = "locale = ?";
        $params[] = $locale;
    }
    
    if (!empty($updateFields)) {
        $sql = "UPDATE members SET " . implode(", ", $updateFields) . " WHERE id = ?";
        $stmtPatch = $mysqli->prepare($sql);
        $params[] = $user_id;
        $types = str_repeat("s", count($params) - 1) . "i";
        $stmtPatch->bind_param($types, ...$params);
        $stmtPatch->execute();
        $stmtPatch->close();
    }
        
    
    
    
    // -----------------

        // 🛠️ Patch missing password
        if (empty($db_password)) {
            $randomPassword = password_hash(bin2hex(random_bytes(16)), PASSWORD_DEFAULT);
            $update = $mysqli->prepare("UPDATE members SET password = ? WHERE id = ?");
            $update->bind_param("si", $randomPassword, $user_id);
            $update->execute();
            $update->close();
            $db_password = $randomPassword;
        }

        // ✅ Ensure verify_token is NULL
        $patchToken = $mysqli->prepare("UPDATE members SET verify_token = NULL, email_verified = 1 WHERE id = ?");
        $patchToken->bind_param("i", $user_id);
        $patchToken->execute();
        $patchToken->close();

    } else {
        // 🆕 New Google user
        $username = $baseUsername;
        $randomPassword = password_hash(bin2hex(random_bytes(16)), PASSWORD_DEFAULT);

        $firstName = $userinfo->givenName ?? '';
        $lastName = $userinfo->familyName ?? '';
        $avatarUrl = $userinfo->picture ?? '';
        if (empty($avatarUrl)) {
            $hash = md5(strtolower(trim($email)));
            $avatarUrl = "https://www.gravatar.com/avatar/$hash?d=identicon&s=200";
        }
        $locale = $userinfo->locale ?? '';
        
        $stmtInsert = $mysqli->prepare("
            INSERT INTO members (
                email, username, password,
                nda_agreed_at, nda_version,
                registered_via, email_verified,
                verify_token, display_name,
                first_name, last_name, avatar_url, locale
            )
            VALUES (?, ?, ?, NULL, NULL, 'google', 1, NULL, ?, ?, ?, ?, ?)
        ");
        
        $stmtInsert->bind_param(
            "ssssssss",
            $email,
            $username,
            $randomPassword,
            $googleName,
            $firstName,
            $lastName,
            $avatarUrl,
            $locale
        );

        
        $stmtInsert->execute();
        $user_id = $stmtInsert->insert_id;
        $stmtInsert->close();

        $db_password = $randomPassword;
        $ndaAgreedAt = null;
    }

    $stmt->close();

    // Link legacy/pending invite rows to this member account.
    if (!empty($user_id) && !empty($email)) {
        $linkInvites = $mysqli->prepare("
            UPDATE invitations
            SET member_id = ?
            WHERE email = ?
              AND member_id IS NULL
        ");
        if ($linkInvites) {
            $linkInvites->bind_param("is", $user_id, $email);
            $linkInvites->execute();
            $linkInvites->close();
        }
    }

    if (empty($ndaAgreedAt)) {
        $_SESSION['pending_google_nda'] = [
            'user_id' => (int)$user_id,
            'set_at' => time()
        ];
        header("Location: /google-nda.php");
        exit;
    }

    // ✅ Set session
    $user_id = (int)$user_id;
    $_SESSION['user_id'] = $user_id;
    $_SESSION['username'] = $username;
    $_SESSION['session_version'] = getCurrentSessionVersion($user_id, $mysqli);
    $_SESSION['login_string'] = hash('sha512', $db_password . $_SERVER['HTTP_USER_AGENT']);

    // ✅ Remember token setup
    $selector = bin2hex(random_bytes(6));
    $token = bin2hex(random_bytes(32));
    $hashedToken = hash('sha256', $token);
    $expires = time() + (30 * 24 * 60 * 60); // 30 days
    $sessionOnly = 0;
    $user_agent = twClientAgentForStorage();
    $ip_address = $_SERVER['REMOTE_ADDR'] ?? '';

    // Insert into member_tokens
    $stmtToken = $mysqli->prepare("
        INSERT INTO member_tokens
        (user_id, selector, hashed_token, user_agent, ip_address, expires, session_only)
        VALUES (?, ?, ?, ?, ?, FROM_UNIXTIME(?), ?)
    ");
    $stmtToken->bind_param("issssii", $user_id, $selector, $hashedToken, $user_agent, $ip_address, $expires, $sessionOnly);
    $stmtToken->execute();
    $stmtToken->close();
    twPruneMemberTokens($mysqli, (int)$user_id, 2, $selector);

    twSetRememberToken("$selector:$token", $expires);

    $pairs = readProfileTokenPairsForGoogle();
    $pairs = array_values(array_filter($pairs, function ($pair) use ($selector) {
        return strtolower($pair[0]) !== strtolower($selector);
    }));
    array_unshift($pairs, [strtolower($selector), strtolower($token)]);
    writeProfileTokenPairsForGoogle($pairs);

    

    $_SESSION['active_selector'] = $selector;

    // ✅ Redirect
    $redirectTo = $_SESSION['redirect_after_login'] ?? '/';
    unset(
        $_SESSION['redirect_after_login'],
        $_SESSION['pending_invite_token'],
        $_SESSION['pending_invite_list_token'],
        $_SESSION['pending_invite_set_at'],
        $_SESSION['pending_invite_email']
    );
    if (!preg_match('#^/[\w\-\/\?\=\&]*$#', $redirectTo)) {
        $redirectTo = '/';
    }
    $redirectTo = withAvatarOnboardingRedirect($mysqli, (int)$user_id, $redirectTo);

    header("Location: $redirectTo");
    exit;

} catch (Exception $e) {
    echo "⚠️ Google Login failed: " . htmlspecialchars($e->getMessage());
}
