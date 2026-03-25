<?php
// Current/includes/functions.php
//include_once 'psl-config.php';
//include_once 'system-paths.php';
//require_once 'translate.php';

require_once 'psl-config.php';
require_once 'system-paths.php';
require_once 'translate.php';


define('TW_LOG_PATH', __DIR__ . '/../error.log');
ini_set('log_errors', 1);
ini_set('error_log', TW_LOG_PATH);

//Deefine vendor path, used for push notifications and login with google etc...
//define('VENDOR_PATH', dirname(__DIR__) . '/textwhisper_vendor');

//VENDOR_PATH is defined in system-paths.php
//define('VENDOR_PATH', '/home1/wecanrec/textwhisper_vendor');


function getRootDomain(): string {
    $host = strtolower(trim((string)($_SERVER['HTTP_HOST'] ?? '')));
    $host = preg_replace('/:\d+$/', '', $host); // strip port
    $host = preg_replace('/^www\./', '', $host);

    // For localhost/IP hosts, do not force a Domain attribute.
    // Returning an empty string lets callers set host-only cookies safely.
    if ($host === '' || $host === 'localhost' || filter_var($host, FILTER_VALIDATE_IP)) {
        return '';
    }

    $parts = explode('.', $host);
    if (count($parts) < 2) {
        return '';
    }

    $domain = $parts[count($parts) - 2] . '.' . $parts[count($parts) - 1];
    return ltrim($domain, '.');
}

function twCookieOptions(array $overrides = []): array {
    $secure = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off');
    $options = [
        'path' => '/',
        'secure' => $secure,
        'httponly' => true,
        'samesite' => 'Lax'
    ];

    $domain = getRootDomain();
    if ($domain !== '') {
        $options['domain'] = $domain;
    }

    return array_merge($options, $overrides);
}

function twClientAgentForStorage(): string {
    $ua = trim((string)($_SERVER['HTTP_USER_AGENT'] ?? ''));
    $ch = trim((string)($_SERVER['HTTP_SEC_CH_UA'] ?? ''));
    $chFull = trim((string)($_SERVER['HTTP_SEC_CH_UA_FULL_VERSION_LIST'] ?? ''));
    $platform = trim((string)($_SERVER['HTTP_SEC_CH_UA_PLATFORM'] ?? ''));
    $parts = [];
    if ($ua !== '') $parts[] = $ua;
    if ($ch !== '') $parts[] = 'CH-UA:' . $ch;
    if ($chFull !== '') $parts[] = 'CH-UA-FULL:' . $chFull;
    if ($platform !== '') $parts[] = 'CH-PLATFORM:' . $platform;
    $sig = trim(implode(' | ', $parts));
    if ($sig === '') $sig = 'unknown';
    return substr($sig, 0, 500);
}

function twLogLoginCheckFailure(array $reasons): void {
    $requestPath = parse_url((string)($_SERVER['REQUEST_URI'] ?? '/'), PHP_URL_PATH) ?: '/';
    $ipAddress = trim((string)($_SERVER['REMOTE_ADDR'] ?? '0.0.0.0'));
    $sessionUser = trim((string)($_SESSION['username'] ?? ''));
    $hasRememberToken = !empty($_COOKIE['remember_token']) ? 'yes' : 'no';
    $reasonText = trim(implode(' | ', array_filter(array_map('trim', $reasons))));
    if ($reasonText === '') {
        $reasonText = 'Unknown reason';
    }

    error_log(
        "[login_check] ❌ Login check failed"
        . " path={$requestPath}"
        . " ip={$ipAddress}"
        . " session_user=" . ($sessionUser !== '' ? $sessionUser : '-')
        . " remember_cookie={$hasRememberToken}"
        . " reason=\"{$reasonText}\""
    );
}

function twLogLoginCheckSuccess(string $mode, string $username = ''): void {
    $requestPath = parse_url((string)($_SERVER['REQUEST_URI'] ?? '/'), PHP_URL_PATH) ?: '/';
    $ipAddress = trim((string)($_SERVER['REMOTE_ADDR'] ?? '0.0.0.0'));
    $sessionUser = trim((string)($_SESSION['username'] ?? ''));
    $user = trim($username) !== '' ? trim($username) : ($sessionUser !== '' ? $sessionUser : '-');

    error_log(
        "[login_check] ✅ Auth restored"
        . " mode={$mode}"
        . " user={$user}"
        . " path={$requestPath}"
        . " ip={$ipAddress}"
    );
}

function twClearCookieEverywhere(string $name): void {
    $expired = time() - 3600;
    $domain = getRootDomain();

    $clearOne = function (?string $targetDomain, bool $secureFlag) use ($name, $expired): void {
        $opts = [
            'expires' => $expired,
            'path' => '/',
            'secure' => $secureFlag,
            'httponly' => true,
            'samesite' => 'Lax'
        ];
        if ($targetDomain !== null && $targetDomain !== '') {
            $opts['domain'] = $targetDomain;
        }
        setcookie($name, '', $opts);
    };

    // Clear host-only cookie for both secure and non-secure variants.
    $clearOne(null, false);
    $clearOne(null, true);

    if ($domain !== '') {
        // Clear domain cookie variants (with and without leading dot), both secure and non-secure.
        $clearOne($domain, false);
        $clearOne($domain, true);
        $clearOne('.' . $domain, false);
        $clearOne('.' . $domain, true);
    }
}

function twSetRememberToken(string $value, int $expires): void {
    twClearCookieEverywhere('remember_token');
    setcookie('remember_token', $value, twCookieOptions([
        'expires' => $expires
    ]));
}

function twClearRememberToken(): void {
    twClearCookieEverywhere('remember_token');
}

function twPruneMemberTokens($mysqli, int $userId, int $keepPerFingerprint = 2, string $currentSelector = ''): void {
    if ($userId <= 0 || !$mysqli) {
        return;
    }
    $keepPerFingerprint = max(1, min(10, $keepPerFingerprint));
    $currentSelector = strtolower(trim($currentSelector));

    // Always remove expired rows first.
    $stmtExpired = $mysqli->prepare("DELETE FROM member_tokens WHERE user_id = ? AND expires <= NOW()");
    if ($stmtExpired) {
        $stmtExpired->bind_param("i", $userId);
        $stmtExpired->execute();
        $stmtExpired->close();
    }

    $stmt = $mysqli->prepare("
        SELECT selector, user_agent, ip_address, session_only
        FROM member_tokens
        WHERE user_id = ?
        ORDER BY expires DESC
    ");
    if (!$stmt) {
        return;
    }
    $stmt->bind_param("i", $userId);
    $stmt->execute();
    $res = $stmt->get_result();
    if (!$res) {
        $stmt->close();
        return;
    }

    $seenByFingerprint = [];
    $selectorsToDelete = [];

    while ($row = $res->fetch_assoc()) {
        $selector = strtolower((string)($row['selector'] ?? ''));
        if (!preg_match('/^[a-f0-9]{12}$/', $selector)) {
            continue;
        }

        if ($currentSelector !== '' && $selector === $currentSelector) {
            continue; // never prune current active selector
        }

        $fingerprint = strtolower(trim((string)($row['user_agent'] ?? '')))
            . '|' . strtolower(trim((string)($row['ip_address'] ?? '')))
            . '|' . (int)($row['session_only'] ?? 0);

        $count = $seenByFingerprint[$fingerprint] ?? 0;
        if ($count < $keepPerFingerprint) {
            $seenByFingerprint[$fingerprint] = $count + 1;
        } else {
            $selectorsToDelete[] = $selector;
        }
    }
    $stmt->close();

    if (empty($selectorsToDelete)) {
        return;
    }

    $selectorsToDelete = array_values(array_unique($selectorsToDelete));
    $placeholders = implode(',', array_fill(0, count($selectorsToDelete), '?'));
    $types = 'i' . str_repeat('s', count($selectorsToDelete));
    $sql = "DELETE FROM member_tokens WHERE user_id = ? AND selector IN ($placeholders)";
    $del = $mysqli->prepare($sql);
    if (!$del) {
        return;
    }
    $params = array_merge([$userId], $selectorsToDelete);
    $bind = [$types];
    foreach ($params as $i => $v) {
        $bind[] = &$params[$i];
    }
    call_user_func_array([$del, 'bind_param'], $bind);
    $del->execute();
    $del->close();
}

function applySessionCookieSettings(int $lifetime, string $domain, bool $secure, bool $httponly = true): void {
    session_set_cookie_params([
        'lifetime' => $lifetime,
        'path' => '/',
        'domain' => $domain, // ✅ no leading dot!
        'secure' => $secure,
        'httponly' => $httponly,
        'samesite' => 'Lax'
    ]);
}








// 1. Securely start a PHP session



function sec_session_start(bool $persistent = false): void {
    if (session_status() !== PHP_SESSION_ACTIVE) {
        $secure = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off');
        $domain = getRootDomain();
        // If a remember cookie exists, keep session cookie persistent too.
        if (!$persistent && !empty($_COOKIE['remember_token'])) {
            $persistent = true;
        }

        $params = [
            'lifetime' => $persistent ? 60 * 60 * 24 * 30 : 0,
            'path'     => '/',
            'secure'   => $secure,
            'httponly' => true,
            'samesite' => 'Lax'
        ];
        if ($domain !== '') {
            $params['domain'] = $domain;
        }

        session_set_cookie_params($params);

        session_start();
    }
}




function cleanGhostCookies(): void {
    $host = strtolower(trim((string)($_SERVER['HTTP_HOST'] ?? '')));
    $host = preg_replace('/:\d+$/', '', $host); // strip port
    if ($host === '' || $host === 'localhost' || filter_var($host, FILTER_VALIDATE_IP)) {
        return; // nothing to normalize for host-only cookie environments
    }
    $root = preg_replace('/^test\./', '', preg_replace('/^www\./', '', $host));

    $domainsToCheck = [
        $root,
        ".$root",
        "www.$root",
        "test.$root"
    ];

    $cookiesToClean = [session_name(), 'remember_token'];
    $secure = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off');
    $allowedDomains = [$host, $root, ".$root"];

    foreach ($cookiesToClean as $name) {
        foreach ($domainsToCheck as $d) {
            if (isset($_COOKIE[$name]) && !in_array($d, $allowedDomains, true)) {
                setcookie($name, '', time() - 3600, '/', $d, $secure, true);
            }
        }
    }
}




// 2. Login function
function login($email, $password, $mysqli) {
    if ($stmt = $mysqli->prepare("SELECT id, username, password FROM members WHERE email = ? LIMIT 1")) {
        $stmt->bind_param('s', $email);
        $stmt->execute();
        $stmt->store_result();

        if ($stmt->num_rows === 1) {
            $stmt->bind_result($user_id, $username, $db_password);
            $stmt->fetch();

            if (!empty($db_password) && password_verify($password, $db_password)) {
                $_SESSION['user_id'] = $user_id;
                $_SESSION['username'] = $username;
                $_SESSION['login_string'] = hash('sha512', $db_password . $_SERVER['HTTP_USER_AGENT']);
                return true;
            } else {
                return false;
            }
        }
    }
    return false;
}

// 3. Check for brute force attempts
function checkbrute($user_id, $mysqli) {
    $now = time();
    $valid_attempts = $now - (2 * 60 * 60);
    if ($stmt = $mysqli->prepare("SELECT time FROM login_attempts WHERE user_id = ? AND time > ?")) {
        $stmt->bind_param('ii', $user_id, $valid_attempts);
        $stmt->execute();
        $stmt->store_result();
        return $stmt->num_rows > 5;
    }
    return false;
}

// 4. Check logged-in status

function login_check($mysqli) {
    $log = [];

    // 1️⃣ Check session validity first
    if (
        isset($_SESSION['user_id'], $_SESSION['username'], $_SESSION['login_string'], $_SESSION['session_version'])
    ) {
        $user_id = $_SESSION['user_id'];
        $login_string = $_SESSION['login_string'];
        $user_browser = $_SERVER['HTTP_USER_AGENT'];

        $stmt = $mysqli->prepare("SELECT password, session_version FROM members WHERE id = ? LIMIT 1");
        if ($stmt) {
            $stmt->bind_param('i', $user_id);
            $stmt->execute();
            $stmt->store_result();

            if ($stmt->num_rows === 1) {
                $stmt->bind_result($password, $db_version);
                $stmt->fetch();

                $expected = hash('sha512', $password . $user_browser);
                $valid = hash_equals($expected, $login_string) && ($_SESSION['session_version'] == $db_version);

                if ($valid) {
                    return true;
                } else {
                    // Don't destroy yet — let fallback cookie try
                    session_unset();
                    $log[] = "Session invalid, falling back to cookie";
                }
            }
        }
    } else {
        $log[] = "No active session found";
    }

    // 2️⃣ Persistent login cookie
    if (!empty($_COOKIE['remember_token'])) {
        $parts = explode(':', $_COOKIE['remember_token'], 2);
        if (count($parts) !== 2) {
            $log[] = "Invalid remember_token format";
            twLogLoginCheckFailure($log);
            return false;
        }
        [$selector, $token] = $parts;
        $selector = strtolower(trim((string)$selector));
        $token = strtolower(trim((string)$token));
        if (!preg_match('/^[a-f0-9]{12}$/', $selector) || !preg_match('/^[a-f0-9]{64}$/', $token)) {
            $log[] = "Invalid remember_token payload";
            twLogLoginCheckFailure($log);
            return false;
        }

        $stmt = $mysqli->prepare("
            SELECT user_id, hashed_token, user_agent, expires
            FROM member_tokens
            WHERE selector = ? AND expires > NOW()
            LIMIT 1
        ");
        $stmt->bind_param('s', $selector);
        $stmt->execute();
        $stmt->store_result();

        if ($stmt->num_rows === 1) {
            $stmt->bind_result($user_id, $hashedToken, $user_agent_db, $expires);
            $stmt->fetch();

            $expectedHash = hash('sha256', $token);
            $currentAgent = $_SERVER['HTTP_USER_AGENT'];

            if (hash_equals($hashedToken, $expectedHash)) {
                $stmt2 = $mysqli->prepare("SELECT username, password, session_version FROM members WHERE id = ? LIMIT 1");
                $stmt2->bind_param('i', $user_id);
                $stmt2->execute();
                $stmt2->store_result();

                if ($stmt2->num_rows === 1) {
                    $stmt2->bind_result($username, $password, $session_version);
                    $stmt2->fetch();

                    // ✅ Restore session
                    $_SESSION['user_id'] = $user_id;
                    $_SESSION['username'] = $username;
                    $_SESSION['session_version'] = $session_version;
                    $_SESSION['login_string'] = hash('sha512', $password . $currentAgent);
                    $_SESSION['active_selector'] = $selector;

                    // Sliding expiration: keep persistent login alive while user returns.
                    $expires = time() + (30 * 24 * 60 * 60);
                    $ip_address = $_SERVER['REMOTE_ADDR'] ?? '0.0.0.0';
                    $agentForStorage = twClientAgentForStorage();
                    $refreshStmt = $mysqli->prepare("
                        UPDATE member_tokens
                        SET expires = FROM_UNIXTIME(?), user_agent = ?, ip_address = ?
                        WHERE selector = ?
                    ");
                    if ($refreshStmt) {
                        $refreshStmt->bind_param("isss", $expires, $agentForStorage, $ip_address, $selector);
                        $refreshStmt->execute();
                        $refreshStmt->close();
                    }

                    twSetRememberToken($selector . ":" . $token, $expires);
                    twPruneMemberTokens($mysqli, (int)$user_id, 2, $selector);

                    twLogLoginCheckSuccess('remember_token', $username);
                    return true;
                }
            } else {
                $log[] = "Token mismatch";
            }
        } else {
            $log[] = "No valid remember_token found";
        }
    } else {
        $log[] = "No remember_token cookie";
    }

    twLogLoginCheckFailure($log);
    return false;
}



// 5. URL sanitizer
function esc_url($url) {
    if ($url === '') return $url;

    $url = preg_replace('|[^a-z0-9-~+_.?#=!&;,/:%@$\|*\'()\\x80-\\xff]|i', '', $url);
    $url = str_replace(['%0d', '%0a', '%0D', '%0A'], '', $url);
    $url = str_replace(';//', '://', $url);
    $url = htmlentities($url);
    $url = str_replace('&amp;', '&#038;', $url);
    $url = str_replace("'", '&#039;', $url);

    return ($url[0] === '/') ? $url : '';
}


function getCurrentSessionVersion($user_id, $mysqli) {
    $stmt = $mysqli->prepare("SELECT session_version FROM members WHERE id = ?");
    $stmt->bind_param("i", $user_id);
    $stmt->execute();
    $stmt->bind_result($version);
    $stmt->fetch();
    return $version;
}

function resolveInviteEmailForToken(mysqli $mysqli, string $listToken, string $inviteToken, int $maxAgeDays = 7): ?string {
    $listToken = trim($listToken);
    $inviteToken = strtolower(trim($inviteToken));
    if ($listToken === '' || !preg_match('/^[A-Za-z0-9._-]{2,120}$/', $listToken)) {
        return null;
    }
    if (!preg_match('/^[a-f0-9]{64}$/', $inviteToken)) {
        return null;
    }

    $inviteSecret = defined('INVITE_TOKEN_SECRET') ? (string)INVITE_TOKEN_SECRET : '';
    $cutoff = date('Y-m-d H:i:s', time() - (max(1, $maxAgeDays) * 86400));
    $emails = [];

    $stmt = $mysqli->prepare("
        SELECT email
        FROM invitations
        WHERE listToken = ?
          AND created_at >= ?
        ORDER BY created_at DESC
        LIMIT 500
    ");
    if ($stmt) {
        $stmt->bind_param("ss", $listToken, $cutoff);
        $stmt->execute();
        $stmt->bind_result($email);
        while ($stmt->fetch()) {
            $emailLower = strtolower(trim((string)$email));
            if ($emailLower !== '') {
                $emails[] = $emailLower;
            }
        }
        $stmt->close();
    } else {
        // Backward-compatible fallback when invitations.created_at is missing.
        $fallback = $mysqli->prepare("
            SELECT email
            FROM invitations
            WHERE listToken = ?
            LIMIT 500
        ");
        if (!$fallback) {
            return null;
        }
        $fallback->bind_param("s", $listToken);
        $fallback->execute();
        $fallback->bind_result($email);
        while ($fallback->fetch()) {
            $emailLower = strtolower(trim((string)$email));
            if ($emailLower !== '') {
                $emails[] = $emailLower;
            }
        }
        $fallback->close();
    }

    $emails = array_values(array_unique($emails));
    foreach ($emails as $candidateEmail) {
        $expected = hash_hmac('sha256', $candidateEmail . '|' . $listToken, $inviteSecret);
        if (hash_equals($expected, $inviteToken)) {
            return $candidateEmail;
        }
    }

    return null;
}

function userNeedsAvatarOnboarding(mysqli $mysqli, int $userId): bool {
    if ($userId <= 0) return false;

    $stmt = $mysqli->prepare("SELECT avatar_url FROM members WHERE id = ? LIMIT 1");
    if (!$stmt) return false;
    $stmt->bind_param("i", $userId);
    $stmt->execute();
    $stmt->bind_result($avatarUrl);
    $found = $stmt->fetch();
    $stmt->close();
    if (!$found) return false;

    $avatarUrl = trim((string)$avatarUrl);
    if ($avatarUrl === '') return true;
    if (stripos($avatarUrl, 'default-avatar.png') !== false) return true;
    return false;
}

function twAvatarFileNameFromUrl(string $avatarUrl): ?string {
    $avatarUrl = trim($avatarUrl);
    if ($avatarUrl === '') return null;
    $parts = parse_url($avatarUrl);
    $path = $parts['path'] ?? '';
    if ($path !== '/avatar-file.php') return null;
    $query = [];
    parse_str((string)($parts['query'] ?? ''), $query);
    $name = trim((string)($query['name'] ?? ''));
    if (!preg_match('/^avatar_[0-9]+_[0-9]+_[a-f0-9]{8}\.(jpg|png|gif|webp)$/i', $name)) {
        return null;
    }
    return $name;
}

function twMoveAvatarToTrashAndCleanup(string $avatarFileName, int $retentionDays = 7): void {
    if (!preg_match('/^avatar_[0-9]+_[0-9]+_[a-f0-9]{8}\.(jpg|png|gif|webp)$/i', $avatarFileName)) {
        return;
    }

    $avatarDir = rtrim((string)UPLOAD_PATH, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR . 'avatars';
    $sourcePath = $avatarDir . DIRECTORY_SEPARATOR . $avatarFileName;
    if (!is_file($sourcePath)) {
        return;
    }

    $trashDir = $avatarDir . DIRECTORY_SEPARATOR . 'recycle';
    if (!is_dir($trashDir) && !@mkdir($trashDir, 0755, true)) {
        return;
    }

    $trashName = date('YmdHis') . '_' . bin2hex(random_bytes(3)) . '_' . $avatarFileName;
    $trashPath = $trashDir . DIRECTORY_SEPARATOR . $trashName;
    if (!@rename($sourcePath, $trashPath)) {
        if (@copy($sourcePath, $trashPath)) {
            @unlink($sourcePath);
        }
    }

    $ttl = max(1, $retentionDays) * 86400;
    $now = time();
    $trashFiles = glob($trashDir . DIRECTORY_SEPARATOR . '*');
    if ($trashFiles === false) return;

    foreach ($trashFiles as $filePath) {
        if (!is_file($filePath)) continue;
        $mtime = @filemtime($filePath);
        if ($mtime === false) continue;
        if (($now - $mtime) >= $ttl) {
            @unlink($filePath);
        }
    }
}

function buildAvatarOnboardingUrl(string $nextPath = '/'): string {
    $nextPath = trim($nextPath);
    if (!preg_match('#^/[\w\-\/\?\=\&%\.]*$#', $nextPath)) {
        $nextPath = '/';
    }
    $parts = parse_url($nextPath);
    $path = $parts['path'] ?? '/';
    if (!is_string($path) || $path === '') {
        $path = '/';
    }

    $queryParams = [];
    if (!empty($parts['query'])) {
        parse_str((string)$parts['query'], $queryParams);
    }
    $queryParams['tw_onboard'] = '1';

    $query = http_build_query($queryParams);
    return $path . ($query !== '' ? ('?' . $query) : '');
}

function withAvatarOnboardingRedirect(mysqli $mysqli, int $userId, string $nextPath): string {
    if (userNeedsAvatarOnboarding($mysqli, $userId)) {
        return buildAvatarOnboardingUrl($nextPath);
    }
    return $nextPath;
}

function userIsAdmin($pdo, $token, $username) {
    $stmt = $pdo->prepare("
        SELECT 1
        FROM (
            SELECT token, owner FROM lists
            UNION
            SELECT username AS token, username AS owner FROM users
        ) AS t
        LEFT JOIN invitations i 
          ON i.token = t.token 
         AND i.email = :username 
         AND i.role = 'admin'
        WHERE t.token = :token
          AND (t.owner = :username OR i.role = 'admin')
        LIMIT 1
    ");
    $stmt->execute([
        ':token' => $token,
        ':username' => $username
    ]);
    return $stmt->fetchColumn() !== false;
}

use PHPMailer\PHPMailer\PHPMailer;
use PHPMailer\PHPMailer\Exception;

require_once __DIR__ . '/../libs/PHPMailer/src/PHPMailer.php';
require_once __DIR__ . '/../libs/PHPMailer/src/SMTP.php';
require_once __DIR__ . '/../libs/PHPMailer/src/Exception.php';

function twAppBaseUrl(): string {
    $forwardedHost = trim((string)($_SERVER['HTTP_X_FORWARDED_HOST'] ?? ''));
    if ($forwardedHost !== '' && strpos($forwardedHost, ',') !== false) {
        $forwardedHost = trim(explode(',', $forwardedHost, 2)[0]);
    }

    $host = $forwardedHost !== ''
        ? $forwardedHost
        : trim((string)($_SERVER['HTTP_HOST'] ?? ''));

    if ($host === '') {
        $parsedBaseHost = parse_url(BASE_URL, PHP_URL_HOST);
        if (is_string($parsedBaseHost) && $parsedBaseHost !== '') {
            $host = $parsedBaseHost;
        } else {
            $host = 'textwhisper.com';
        }
    }

    $scheme = 'https';
    $forwardedProto = strtolower(trim((string)($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '')));
    if ($forwardedProto === 'http' || $forwardedProto === 'https') {
        $scheme = $forwardedProto;
    } elseif (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') {
        $scheme = 'https';
    } elseif (isset($_SERVER['REQUEST_SCHEME']) && $_SERVER['REQUEST_SCHEME'] === 'http') {
        $scheme = 'http';
    }

    $scriptName = (string)($_SERVER['SCRIPT_NAME'] ?? '');
    $basePath = str_replace('\\', '/', dirname($scriptName));
    if ($basePath === '/' || $basePath === '.' || $basePath === '\\') {
        $basePath = '';
    } else {
        $basePath = '/' . trim($basePath, '/');
    }

    return rtrim($scheme . '://' . $host . $basePath, '/');
}

function twConfiguredMailer(): PHPMailer {
    $mail = new PHPMailer(true);
    $mail->CharSet = 'UTF-8';
    $mail->Encoding = 'base64';
    $mail->isSMTP();
    $mail->Host = 'mail.textwhisper.com';
    $mail->SMTPAuth = true;
    $mail->Username = 'noreply@textwhisper.com';
    $mail->Password = SMTP_PASSWORD;
    $mail->SMTPSecure = PHPMailer::ENCRYPTION_STARTTLS;
    $mail->Port = 587;
    $mail->setFrom('noreply@textwhisper.com', 'TextWhisper');
    return $mail;
}

function sendPasswordResetEmail(string $email, string $token): bool {
    $resetLink = twAppBaseUrl() . '/reset_password.php?token=' . urlencode($token);

    $mail = twConfiguredMailer();
    try {
        $mail->addAddress($email);
        $mail->isHTML(true);
        $mail->Subject = 'Password Reset Request';
        $mail->Body = "
            <p>Hello,</p>
            <p>We received a request to reset your password. Click the link below to reset it:</p>
            <p><a href=\"$resetLink\">Reset your password</a></p>
            <p>If the button does not open, copy and paste this link into your browser:</p>
            <p>$resetLink</p>
            <p>If you did not request this, you can safely ignore this email.</p>
            <p>TextWhisper Team</p>
        ";
        $mail->AltBody = "Reset your password: $resetLink";
        return $mail->send();
    } catch (Exception $e) {
        error_log("❌ Password reset mail FAILED for $email");
        error_log("❌ PHPMailer ErrorInfo: " . $mail->ErrorInfo);
        return false;
    }
}

// function sendVerificationEmail($email, $displayName, $token) {
//     // $verifyLink = BASE_URL . "/includes/verify_email.php?token=$token";
//     $scheme = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
//     $host = $_SERVER['HTTP_HOST'];
//     $verifyLink = "$scheme://$host/includes/verify_email.php?token=" . urlencode($token);

//     // $body = "
//     //     <p>Hello " . htmlentities($displayName) . ",</p>
//     //     <p>Please verify your email by clicking the link below:</p>
//     //     <p><a href='$verifyLink'>$verifyLink</a></p>
//     //     <p>If you didn’t register, you can ignore this email.</p>
//     //     <p> TextWhisper Team</p>"; 
         
//     // $subject = 'Verify your email address'; 

//     $altBody = "Verify your email: $verifyLink";     
  


//     $subject = "Please confirm your registration to TextWhisper";
//     $body = "
//     <p>Hello {$displayName},</p>
//     <p>You can access TextWhisper here:</p>
//     <p><a href='$verifyLink'>$verifyLink</a></p>
//     <p>– TextWhisper Team</p>";



//     $mail = new PHPMailer(true);
//     try {
//         $mail->CharSet = 'UTF-8';
//         $mail->Encoding = 'base64';
//         $mail->isSMTP();
//         $mail->Host = 'mail.textwhisper.com';
//         $mail->SMTPAuth = true;
//         $mail->Username = 'noreply@textwhisper.com';
//         $mail->Password = SMTP_PASSWORD;
//         $mail->SMTPSecure = PHPMailer::ENCRYPTION_STARTTLS;
//         $mail->Port = 587;
//         $mail->setFrom('noreply@textwhisper.com', 'TextWhisper');
//         $mail->addAddress($email);
//         $mail->isHTML(true);
//         $mail->Subject = $subject;
//         $mail->Body    = $body;
//         $mail->AltBody = $altBody;        
//         return $mail->send();

//     // } catch (Exception $e) {
//     //     error_log("❌ Failed to send verification to $email: " . $mail->ErrorInfo);
//     //     return false;
//     // }

//     } catch (Exception $e) {
//         error_log("❌ Verification mail FAILED");
//         error_log("❌ PHPMailer ErrorInfo: " . $mail->ErrorInfo);
//         error_log("❌ Exception message: " . $e->getMessage());
//         error_log("❌ Exception trace: " . $e->getTraceAsString());
//         return false;
//     }

// }


function sendVerificationEmail($email, $displayName, $token) {
    $scheme = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
    $host = $_SERVER['HTTP_HOST'];
    $verifyLink = "$scheme://$host/includes/verify_email.php?token=" . urlencode($token);

    $subject = "Please confirm your registration to TextWhisper";

    $body = "
        <p>Hello " . htmlentities($displayName) . ",</p>
        <p>Please confirm your registration by clicking the link below:</p>
        <p><a href=\"$verifyLink\">Confirm your email address</a></p>
        <p>If you did not create an account, you can safely ignore this email.</p>
        <p>TextWhisper Team</p>
    ";

    $altBody = "Please confirm your registration: $verifyLink";

    $mail = new PHPMailer(true);
    try {
        $mail->CharSet = 'UTF-8';
        $mail->Encoding = 'base64';
        $mail->isSMTP();
        $mail->Host = 'mail.textwhisper.com';
        $mail->SMTPAuth = true;
        $mail->Username = 'noreply@textwhisper.com';
        $mail->Password = SMTP_PASSWORD;
        $mail->SMTPSecure = PHPMailer::ENCRYPTION_STARTTLS;
        $mail->Port = 587;

        $mail->setFrom('noreply@textwhisper.com', 'TextWhisper');
        $mail->addAddress($email);
        $mail->isHTML(true);
        $mail->Subject = $subject;
        $mail->Body    = $body;
        $mail->AltBody = $altBody;

        return $mail->send();
    } catch (Exception $e) {
        error_log("❌ Verification mail FAILED");
        error_log("❌ PHPMailer ErrorInfo: " . $mail->ErrorInfo);
        return false;
    }
}



function sendInviteEmail($inviteEmail, $inviterName, $listToken, $role, $locale = 'en') {
    global $mysqli;

    $inviteEmailLower = strtolower(trim($inviteEmail));
    $inviteToken = hash_hmac('sha256', $inviteEmailLower . '|' . $listToken, INVITE_TOKEN_SECRET);

    // Build links from the current request host first (supports aliases/test domains),
    // then keep sub-directory deployments (e.g. /textwhisper-test).
    $forwardedHost = trim((string)($_SERVER['HTTP_X_FORWARDED_HOST'] ?? ''));
    if ($forwardedHost !== '' && strpos($forwardedHost, ',') !== false) {
        $forwardedHost = trim(explode(',', $forwardedHost, 2)[0]);
    }
    $host = $forwardedHost !== ''
        ? $forwardedHost
        : trim((string)($_SERVER['HTTP_HOST'] ?? ''));

    if ($host === '') {
        $parsedBaseHost = parse_url(BASE_URL, PHP_URL_HOST);
        if (is_string($parsedBaseHost) && $parsedBaseHost !== '') {
            $host = $parsedBaseHost;
        } else {
            $host = 'textwhisper.com';
        }
    }

    $scheme = 'https';
    $forwardedProto = strtolower(trim((string)($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '')));
    if ($forwardedProto === 'http' || $forwardedProto === 'https') {
        $scheme = $forwardedProto;
    } elseif (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') {
        $scheme = 'https';
    } elseif (isset($_SERVER['REQUEST_SCHEME']) && $_SERVER['REQUEST_SCHEME'] === 'http') {
        $scheme = 'http';
    }

    $scriptName = (string)($_SERVER['SCRIPT_NAME'] ?? '');
    $basePath = str_replace('\\', '/', dirname($scriptName));
    if ($basePath === '/' || $basePath === '.' || $basePath === '\\') {
        $basePath = '';
    } else {
        $basePath = '/' . trim($basePath, '/');
    }

    $linkBase = rtrim($scheme . '://' . $host . $basePath, '/');

    $inviteLink = $linkBase . "/$listToken?invite=" . urlencode($inviteToken);

    // Send register link unless user already has a verified TW account.
    $isExistingMember = false;
    $memberStmt = $mysqli->prepare("SELECT email_verified FROM members WHERE LOWER(email) = ? LIMIT 1");
    if ($memberStmt) {
        $memberStmt->bind_param("s", $inviteEmailLower);
        $memberStmt->execute();
        $memberStmt->bind_result($memberEmailVerified);
        if ($memberStmt->fetch()) {
            $isExistingMember = ((int)$memberEmailVerified === 1);
        }
        $memberStmt->close();
    }

    if (!$isExistingMember) {
        $inviteLink = $linkBase
            . "/register.php?list=" . urlencode($listToken)
            . "&invite=" . urlencode($inviteToken);
    }
    
    $locale = $_SESSION['locale'] ?? 'en';

    // 🔹 Load translations
    $langPath = __DIR__ . "/../lang/{$locale}.php";
    $translations = file_exists($langPath) ? include $langPath : [];

    // 🔹 Simple translation helper
    $tr = function($key, ...$args) use ($translations) {
        $string = $translations[$key] ?? $key;
        return $args ? vsprintf($string, $args) : $string;
    };

    // Resolve invitation context for clearer wording.
    $listName = "Shared Group";
    $ownerDisplay = "";
    if ($stmt = $mysqli->prepare("
        SELECT cl.name, m.username, COALESCE(NULLIF(m.display_name, ''), m.username) AS owner_display
        FROM content_lists cl
        JOIN members m ON m.id = cl.owner_id
        WHERE cl.token = ?
        LIMIT 1
    ")) {
        $stmt->bind_param("s", $listToken);
        if ($stmt->execute()) {
            $stmt->bind_result($fetchedName, $fetchedOwnerUsername, $fetchedOwnerDisplay);
            if ($stmt->fetch()) {
                if (!empty($fetchedName)) {
                    $listName = $fetchedName;
                }
                if (!empty($fetchedOwnerUsername)) {
                    $ownerUsername = $fetchedOwnerUsername;
                }
                if (!empty($fetchedOwnerDisplay)) {
                    $ownerDisplay = $fetchedOwnerDisplay;
                }
            }
        }
        $stmt->close();
    }

    $ownerUsername = $ownerUsername ?? '';
    $listNameTrimmed = trim((string)$listName);
    $isGroupInvite = ($ownerUsername !== '' && strcasecmp($listToken, $ownerUsername) === 0)
        || strcasecmp($listNameTrimmed, 'All Content') === 0;
    $groupNameRaw = $ownerDisplay !== '' ? $ownerDisplay : 'Shared Group';
    $groupLabel = htmlentities($groupNameRaw, ENT_QUOTES);
    $listLabel = htmlentities($listNameTrimmed !== '' ? $listNameTrimmed : 'Shared list', ENT_QUOTES);
    $safeInviter = htmlentities($inviterName, ENT_QUOTES);
    $safeRole = htmlentities($role, ENT_QUOTES);

    // Subject and body
    $subject     = $tr('invite_subject');
    $bodyMessage = $isGroupInvite
        ? "{$safeInviter} has invited you to join the group {$groupLabel} on TextWhisper."
        : "{$safeInviter} has invited you to join the list {$listLabel} in the group {$groupLabel} on TextWhisper.";
    $roleLine = "Role: {$safeRole}.";
    $actionHint = $isExistingMember
        ? "Open this invitation link to continue:"
        : "Use this link to register to TextWhisper and accept this invitation:";
    $verificationHint = $isExistingMember
        ? ""
        : "Your invited email will be verified automatically.";
    $fallbackHint = "If the link does not open, copy and paste it into your browser.";

    $body = "
        <p>{$tr('greeting')}</p>
        <p>$bodyMessage</p>
        <p>{$roleLine}</p>
        <p>{$actionHint}</p>
        <p><a href='$inviteLink'>$inviteLink</a></p>
        <p>{$fallbackHint}</p>
        " . ($verificationHint !== "" ? "<p>{$verificationHint}</p>" : "") . "
        <p>{$tr('signature')}</p>";

    $altBody = strip_tags($body);

    // 🔹 Send with PHPMailer
    $mail = new PHPMailer(true);
    try {
        $mail->CharSet = 'UTF-8';
        $mail->Encoding = 'base64';
        $mail->isSMTP();
        $mail->Host = 'mail.textwhisper.com';
        $mail->SMTPAuth = true;
        $mail->Username = 'noreply@textwhisper.com';
        $mail->Password = SMTP_PASSWORD;
        $mail->SMTPSecure = PHPMailer::ENCRYPTION_STARTTLS;
        $mail->Port = 587;
        $mail->setFrom('noreply@textwhisper.com', 'TextWhisper');
        $mail->addAddress($inviteEmail);
        $mail->isHTML(true);
        $mail->Subject = $subject;
        $mail->Body    = $body;
        $mail->AltBody = $altBody;
        return $mail->send();

        } catch (Exception $e) {
        error_log("❌ Failed to send invite to $inviteEmail: " . $mail->ErrorInfo);
        return false;
    }
}






/**
 * Check if a user can modify/upload to a surrogate.
 *
 * @param mysqli $mysqli
 * @param string|int $surrogate
 * @param string $username
 * @return bool
 */
function can_user_edit_surrogate($mysqli, $surrogate, $username) {
    $surrogateSafe = $mysqli->real_escape_string($surrogate);

    // Lookup owner
    $query  = "SELECT owner FROM `text` WHERE Surrogate = '$surrogateSafe' LIMIT 1";
    $result = $mysqli->query($query);
    $item   = $result ? $result->fetch_assoc() : null;

    if (!$item || empty($item['owner'])) {
        return false; // no such surrogate or missing owner
    }

    $owner = $item['owner'];

    // Owner always allowed
    if ($username === $owner) {
        return true;
    }

    // Otherwise check role on the owner's root list.
    return get_user_list_role_rank($mysqli, $owner, $username) >= 80;
}

/**
 * Log a change event (best-effort; no-op if table missing).
 *
 * @param mysqli $mysqli
 * @param string $action
 * @param int $surrogate
 * @param string $owner
 * @param string $actor
 * @param string|null $fileType
 * @param string|null $fileUrl
 * @param string|null $source
 * @param string|null $metaJson
 * @return void
 */
function log_change($mysqli, $action, $surrogate, $owner, $actor, $fileType = null, $fileUrl = null, $source = null, $metaJson = null) {
    if (!$mysqli) return;
    $stmt = $mysqli->prepare("
        INSERT INTO change_log
            (action, surrogate, owner_username, actor_username, file_type, file_url, source, meta_json)
        VALUES (?, ?, ?, ?, ?, ?, ?, ?)
    ");
    if (!$stmt) return;
    $surrogate = (int)$surrogate;
    $stmt->bind_param("sissssss", $action, $surrogate, $owner, $actor, $fileType, $fileUrl, $source, $metaJson);
    $stmt->execute();
    $stmt->close();
}

/**
 * General change log (best-effort; no-op if table missing).
 *
 * @param mysqli $mysqli
 * @param string $action
 * @param string $targetType
 * @param int|null $targetId
 * @param string|null $targetToken
 * @param int|null $ownerId
 * @param string|null $ownerUsername
 * @param int|null $actorId
 * @param string|null $actorUsername
 * @param string|null $metaJson
 * @return void
 */
function log_change_general($mysqli, $action, $targetType, $targetId, $targetToken, $ownerId, $ownerUsername, $actorId, $actorUsername, $metaJson = null) {
    if (!$mysqli) return;
    $stmt = $mysqli->prepare("
        INSERT INTO change_log_general
            (action, target_type, target_id, target_token, owner_id, owner_username, actor_id, actor_username, meta_json)
        VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)
    ");
    if (!$stmt) return;
    $targetId = $targetId !== null ? (int)$targetId : null;
    $ownerId = $ownerId !== null ? (int)$ownerId : null;
    $actorId = $actorId !== null ? (int)$actorId : null;
    $stmt->bind_param(
        "ssisisiss",
        $action,
        $targetType,
        $targetId,
        $targetToken,
        $ownerId,
        $ownerUsername,
        $actorId,
        $actorUsername,
        $metaJson
    );
    $stmt->execute();
    $stmt->close();
}

/**
 * Get the list role rank for a user.
 * Owner = 90, Admin = 90, Editor = 80, others = 0
 *
 * @param mysqli $mysqli
 * @param string $listToken
 * @param string $username
 * @return int
 */
function get_user_list_role_rank($mysqli, $listToken, $username) {
    if (!$username || !$listToken) {
        return 0;
    }

    $mapRoleToRank = static function ($roleRaw) {
        $role = strtolower(trim((string)$roleRaw));
        if ($role === 'owner' || $role === 'admin') return 90;
        if ($role === 'editor') return 80;
        if ($role === 'viewer' || $role === 'commenter') return 60;
        return 0;
    };

    // Case: All Content (user's own space)
    if ($listToken === $username) {
        return 90;
    }

    // Case: explicit content_lists ownership
    $stmt = $mysqli->prepare("
        SELECT m.username
        FROM content_lists cl
        JOIN members m ON cl.owner_id = m.id
        WHERE cl.token = ?
        LIMIT 1
    ");
    $stmt->bind_param("s", $listToken);
    $stmt->execute();
    $stmt->bind_result($ownerUsername);
    $ok = $stmt->fetch();
    $stmt->close();

    if ($ok && $ownerUsername === $username) {
        return 90; // owner
    }

    // Load member identity for invitation checks.
    $stmt = $mysqli->prepare("SELECT id, email FROM members WHERE username = ? LIMIT 1");
    $stmt->bind_param("s", $username);
    $stmt->execute();
    $stmt->bind_result($memberId, $memberEmail);
    $hasMember = $stmt->fetch();
    $stmt->close();

    if (!$hasMember) {
        return 0;
    }

    // Case: invited (support both legacy email invites and member_id invites)
    $stmt = $mysqli->prepare("
        SELECT COALESCE(i.role_rank, 0) AS role_rank, COALESCE(i.role, '') AS role_name
        FROM invitations i
        WHERE i.listToken = ?
          AND (i.email = ? OR i.member_id = ?)
        LIMIT 1
    ");
    $stmt->bind_param("ssi", $listToken, $memberEmail, $memberId);
    $stmt->execute();
    $stmt->bind_result($roleRank, $roleName);
    $ok = $stmt->fetch();
    $stmt->close();

    if (!$ok) {
        return 0;
    }

    $rank = (int)$roleRank;
    if ($rank > 0) {
        return $rank;
    }

    return $mapRoleToRank($roleName);
}

/**
 * Check if a user can edit a list (≥ Editor).
 */
function can_user_edit_list($mysqli, $listToken, $username) {
    return get_user_list_role_rank($mysqli, $listToken, $username) >= 80;
}

/**
 * Resolve a user's profile role rank for a list.
 *
 * Management rights for a list come from the owner's default/root list,
 * whose token is the owner's username.
 */
function get_profile_role_rank_for_list($mysqli, $listToken, $userId) {
    if (!$mysqli || !$listToken || !$userId) {
        return 0;
    }

    $stmt = $mysqli->prepare("
        SELECT cl.owner_id, owner_m.username
        FROM content_lists cl
        JOIN members owner_m ON owner_m.id = cl.owner_id
        WHERE cl.token = ?
        LIMIT 1
    ");
    if (!$stmt) {
        return 0;
    }

    $stmt->bind_param("s", $listToken);
    $stmt->execute();
    $stmt->bind_result($ownerId, $ownerUsername);

    if (!$stmt->fetch()) {
        $stmt->close();
        return 0;
    }
    $stmt->close();

    if ((int)$ownerId === (int)$userId) {
        return 90;
    }

    $stmt = $mysqli->prepare("
        SELECT COALESCE(role_rank, 0)
        FROM invitations
        WHERE listToken = ? AND member_id = ?
        LIMIT 1
    ");
    if (!$stmt) {
        return 0;
    }

    $stmt->bind_param("si", $ownerUsername, $userId);
    $stmt->execute();
    $stmt->bind_result($roleRank);

    if (!$stmt->fetch()) {
        $stmt->close();
        return 0;
    }
    $stmt->close();

    return (int)$roleRank;
}



?>
