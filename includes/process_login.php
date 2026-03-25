<?php
error_reporting(E_ALL);
ini_set('display_errors', '0');
ini_set('display_startup_errors', '0');

include_once 'db_connect.php';
include_once 'functions.php';

function readProfileTokenPairs(): array {
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

function writeProfileTokenPairs(array $pairs): void {
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

    setcookie("tw_profile_tokens", json_encode($payload), twCookieOptions([
        'expires'  => time() + (30 * 24 * 60 * 60)
    ]));
}

$persistent = !empty($_POST['rememberMe']);
sec_session_start($persistent);

// Redirect path
$redirectTo = $_POST['redirectTo'] ?? '/';
$parsed = parse_url($redirectTo);
$path = $parsed['path'] ?? '/';
$cleanPath = trim(strtolower($path), '/');
$invalidRedirects = ['login.php', 'register.php', 'forgot_password.php', 'reset_password.php', '', 'welcome', 'default'];
$isSafeRedirect = !in_array($cleanPath, $invalidRedirects);

if (isset($_POST['email'], $_POST['password'])) {
    $loginInput = trim($_POST['email']);
    $password = $_POST['password'];
    $loginHost = (string)($_SERVER['HTTP_HOST'] ?? 'unknown-host');
    error_log("[process_login] Attempt on {$loginHost} for {$loginInput}");

    $stmt = $mysqli->prepare("
        SELECT id, username, password, email_verified
        FROM members
        WHERE LOWER(email) = LOWER(?) OR LOWER(username) = LOWER(?)
        LIMIT 1
    ");

    if ($stmt) {
        $stmt->bind_param('ss', $loginInput, $loginInput);
        $stmt->execute();
        $stmt->store_result();

        if ($stmt->num_rows === 1) {
            $stmt->bind_result($user_id, $username, $db_password, $email_verified);
            $stmt->fetch();

            // if ($email_verified == 0) {
            //     header("Location: /login.php?error=unverified");
            //     exit;
            // }

            if ($email_verified == 0) {
                $_SESSION['pending_verification_email'] = $loginInput;
                $_SESSION['prefill_login_input'] = $loginInput;
                error_log("[process_login] Rejected: email not verified for {$loginInput}");
                header("Location: /login.php?error=unverified");
                exit;
            }


            
                // Before password verified
                // Before verifying password, check NDA agreement
                $stmt = $mysqli->prepare("SELECT nda_agreed_at FROM members WHERE id = ?");
                $stmt->bind_param("i", $user_id);
                $stmt->execute();
                $stmt->bind_result($ndaAgreedAt);
                $stmt->fetch();
                $stmt->close();
                
                if (empty($ndaAgreedAt) && empty($_POST['nda_agree'])) {
                    $_SESSION['prefill_login_input'] = $loginInput;
                    error_log("[process_login] Rejected: NDA required for {$loginInput}");
                    header("Location: /login.php?error=nda_required&agreement=" . urlencode($_POST['nda_agree'] ?? ''));
                    exit;
                }


            $passwordMatches = password_verify($password, $db_password);
            if (
                !$passwordMatches &&
                trim($password) !== $password
            ) {
                // Compatibility bridge for passwords reset while the reset form
                // trimmed leading/trailing whitespace before hashing.
                $passwordMatches = password_verify(trim($password), $db_password);
            }

            if ($passwordMatches) {
                $_SESSION['username'] = $username;
                $_SESSION['user_id'] = $user_id;
                $_SESSION['session_version'] = getCurrentSessionVersion($user_id, $mysqli);
                $_SESSION['login_string'] = hash('sha512', $db_password . $_SERVER['HTTP_USER_AGENT']);

                // Remember me token logic
                $selector = bin2hex(random_bytes(6));
                $token = bin2hex(random_bytes(32));
                $hashedToken = hash('sha256', $token);
                $expires = time() + (30 * 24 * 60 * 60); // 30 days
                $sessionOnly = $persistent ? 0 : 1;

                $user_agent = twClientAgentForStorage();
                $ip_address = $_SERVER['REMOTE_ADDR'] ?? '0.0.0.0';

                // Replace only this browser's current selector token (if present).
                // Deleting by user_agent is too broad and can invalidate valid sessions
                // from other devices/browsers that share a similar UA string.
                $existingSelector = '';
                if (!empty($_COOKIE['remember_token'])) {
                    $parts = explode(':', (string)$_COOKIE['remember_token'], 2);
                    $maybeSelector = strtolower(trim((string)($parts[0] ?? '')));
                    if (preg_match('/^[a-f0-9]{12}$/', $maybeSelector)) {
                        $existingSelector = $maybeSelector;
                    }
                }
                if ($existingSelector !== '') {
                    $cleanup = $mysqli->prepare("DELETE FROM member_tokens WHERE user_id = ? AND selector = ?");
                    if ($cleanup) {
                        $cleanup->bind_param("is", $user_id, $existingSelector);
                        $cleanup->execute();
                        $cleanup->close();
                    }
                }

                // Set remember_token cookie if persistent
                // if ($persistent) {
                //     $cookieValue = "$selector:$token";
                //     setcookie("remember_token", $cookieValue, [
                //         'expires' => $expires,
                //         'path' => '/',
                //         'secure' => SECURE,
                //         'httponly' => true,
                //         'samesite' => 'Lax'
                //     ]);
                // } else {
                //     setcookie("remember_token", '', time() - 3600, '/', '', SECURE, true);
                // }
                
                
                //all browsers should treat cookie identically — domain-wide and lasting 30 days
                if ($persistent) {
                    $cookieValue = "$selector:$token";
                    twSetRememberToken($cookieValue, $expires);

                    $pairs = readProfileTokenPairs();
                    $pairs = array_values(array_filter($pairs, function ($pair) use ($selector) {
                        return strtolower($pair[0]) !== strtolower($selector);
                    }));
                    array_unshift($pairs, [strtolower($selector), strtolower($token)]);
                    writeProfileTokenPairs($pairs);
                } else {
                    twClearRememberToken();
                }




                // Insert new token
                $stmt2 = $mysqli->prepare("
                    INSERT INTO member_tokens
                    (user_id, selector, hashed_token, user_agent, ip_address, expires, session_only)
                    VALUES (?, ?, ?, ?, ?, FROM_UNIXTIME(?), ?)
                ");
                $stmt2->bind_param("issssii", $user_id, $selector, $hashedToken, $user_agent, $ip_address, $expires, $sessionOnly);
                $stmt2->execute();
                $stmt2->close();
                twPruneMemberTokens($mysqli, (int)$user_id, 2, $selector);

                // Store selector in session (for targeted logout)
                $_SESSION['active_selector'] = $selector;

                // Optional: Logout all other sessions
                if (!empty($_POST['logout_others'])) {
                    $stmtLogout = $mysqli->prepare("DELETE FROM member_tokens WHERE user_id = ? AND selector != ?");
                    $stmtLogout->bind_param("is", $user_id, $selector);
                    $stmtLogout->execute();
                    $stmtLogout->close();
                }
                

                
                
                // ✅ Save NDA agreement if checkbox was checked
                if (isset($_POST['nda_agree'])) {
                    $nda_version = "https://trustagreements.org/basic-v1.html";
                    $stmtNDA = $mysqli->prepare("UPDATE members SET nda_agreed_at = NOW(), nda_version = ? WHERE id = ? AND nda_agreed_at IS NULL");
                    $stmtNDA->bind_param("si", $nda_version, $user_id);
                    $stmtNDA->execute();
                    $stmtNDA->close();
                    
                    $_SESSION['nda_agreed_at'] = date('Y-m-d H:i:s');
                    $_SESSION['nda_version'] = $nda_version;
                }

                
                // Redirect after login:
                // fallback to "/" so index.php can apply last-used profile redirect.
                $finalRedirect = $isSafeRedirect ? $path : "/";
                $finalRedirect = withAvatarOnboardingRedirect($mysqli, (int)$user_id, $finalRedirect);
                error_log("[process_login] Success for {$loginInput}; redirecting to {$finalRedirect}");
                header("Location: $finalRedirect");
                exit;

            } else {
                $_SESSION['prefill_login_input'] = $loginInput;
                error_log("[process_login] Rejected: password mismatch for {$loginInput}");
                header("Location: /login.php?error=1");
                exit;
            }

        } else {
            $_SESSION['prefill_login_input'] = $loginInput;
            error_log("[process_login] Rejected: no matching member for {$loginInput}");
            header("Location: /login.php?error=1");
            exit;
        }

    } else {
        error_log("[process_login] Rejected: prepare failed for {$loginInput}");
        header("Location: /login.php?error=1");
        exit;
    }

} else {
    error_log("[process_login] Rejected: missing email or password POST fields");
    header("Location: /login.php?error=1");
    exit;
}
