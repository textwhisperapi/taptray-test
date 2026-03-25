<?php
// Current/includes/functions.php
include_once 'psl-config.php';
require_once 'translate.php';


define('TW_LOG_PATH', __DIR__ . '/../error.log');
ini_set('log_errors', 1);
ini_set('error_log', TW_LOG_PATH);

//Deefine vendor path, used for push notifications and login with google etc...
//define('VENDOR_PATH', dirname(__DIR__) . '/textwhisper_vendor');
define('VENDOR_PATH', '/home1/wecanrec/textwhisper_vendor');


// function getRootDomain(): string {
//     $host = $_SERVER['HTTP_HOST'];
//     $host = preg_replace('/^www\./', '', $host);
//     $parts = explode('.', $host);
//     $domain = count($parts) >= 2
//         ? $parts[count($parts) - 2] . '.' . $parts[count($parts) - 1]
//         : $host;
        
//     // 🧼 Forcefully remove leading dot if added elsewhere
//     return ltrim($domain, '.');
// }

// function applySessionCookieSettings(int $lifetime, string $domain, bool $secure, bool $httponly = true): void {
//     session_set_cookie_params([
//         'lifetime' => $lifetime,
//         'path' => '/',
//         'domain' => $domain, // ✅ no leading dot!
//         'secure' => $secure,
//         'httponly' => $httponly,
//         'samesite' => 'Lax'
//     ]);
// }



function getRootDomain(): string {
    $host = $_SERVER['HTTP_HOST'] ?? '';
    $host = preg_replace('/^www\./', '', $host);

    $parts = explode('.', $host);
    if (count($parts) >= 2) {
        // 👇 Always prefix with dot so cookies are domain-wide
        $domain = '.' . $parts[count($parts) - 2] . '.' . $parts[count($parts) - 1];
    } else {
        $domain = $host;
    }

    return $domain;
}

function applySessionCookieSettings(int $lifetime, string $domain, bool $secure, bool $httponly = true): void {
    session_set_cookie_params([
        'lifetime' => $lifetime,
        'path'     => '/',
        'domain'   => $domain,   // 👈 now has leading dot from getRootDomain()
        'secure'   => $secure,
        'httponly' => $httponly,
        'samesite' => 'Lax'
    ]);
}





// 1. Securely start a PHP session



function sec_session_starXXXXXt(bool $persistent = false): void {

    if (session_status() !== PHP_SESSION_ACTIVE) {
        
        session_start();
        
        //first get this $persistent setting from db or the remember_token
        //if ($persistent) { 

            setcookie(
                session_name(),          // typically "PHPSESSID"
                session_id(),
                [
                    'expires' => time() + 60 * 60 * 24 * 30, // 30 days
                    'path' => '/',
                    'secure' => true,
                    'httponly' => true,
                    'samesite' => 'Lax'
                ]
            );
        //}
    }
}

// use only the domain for cache
function sec_session_start(bool $persistent = false): void {
    if (session_status() === PHP_SESSION_ACTIVE) return;

    $use_https = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off');
    $domain    = getRootDomain();   // already defined above
    $lifetime  = $persistent ? 60 * 60 * 24 * 30 : 0;

    session_set_cookie_params([
        'lifetime' => $lifetime,
        'path'     => '/',           // 👈 ensures cookie is valid for entire domain
        'domain'   => $domain,       // e.g., textwhisper.com
        'secure'   => $use_https,
        'httponly' => true,
        'samesite' => 'Lax'
    ]);

    ini_set('session.cookie_path', '/');
    ini_set('session.cookie_httponly', '1');
    ini_set('session.cookie_secure', $use_https ? '1' : '0');
    ini_set('session.cookie_samesite', 'Lax');

    session_name('sec_session_id');
    session_start();

    // Prevent fixation attacks
    if (!isset($_SESSION['initiated'])) {
        session_regenerate_id(true);
        $_SESSION['initiated'] = true;
    }

    // Refresh cookie expiration for persistent sessions
    if ($persistent) {
        setcookie(
            session_name(),
            session_id(),
            [
                'expires'  => time() + 60 * 60 * 24 * 30,
                'path'     => '/',
                'domain'   => $domain,
                'secure'   => $use_https,
                'httponly' => true,
                'samesite' => 'Lax'
            ]
        );
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
function login_check_XXOLD($mysqli) {
    if (
        isset($_SESSION['user_id'], $_SESSION['username'], $_SESSION['login_string'], $_SESSION['session_version'])
    ) {
        $user_id = $_SESSION['user_id'];
        $login_string = $_SESSION['login_string'];
        $user_browser = $_SERVER['HTTP_USER_AGENT'];

        $stmt = $mysqli->prepare("
            SELECT password, session_version
            FROM members
            WHERE id = ?
            LIMIT 1
        ");
        if ($stmt) {
            $stmt->bind_param('i', $user_id);
            $stmt->execute();
            $stmt->store_result();

            if ($stmt->num_rows === 1) {
                $stmt->bind_result($password, $db_version);
                $stmt->fetch();

                $valid = hash_equals(
                    hash('sha512', $password . $user_browser),
                    $login_string
                ) && $_SESSION['session_version'] === $db_version;

                if (!$valid) {
                    // 🚫 Session mismatch, cleanup
                    session_unset();
                    session_destroy();
                }

                return $valid;
            }
        }
    }

    return false;
}

function login_check_XX_NotSoOld($mysqli) {
    if (
        isset($_SESSION['user_id'], $_SESSION['username'], $_SESSION['login_string'], $_SESSION['session_version'])
    ) {
        $user_id = $_SESSION['user_id'];
        $login_string = $_SESSION['login_string'];
        $user_browser = $_SERVER['HTTP_USER_AGENT'];

        $stmt = $mysqli->prepare("
            SELECT password, session_version
            FROM members
            WHERE id = ?
            LIMIT 1
        ");
        if ($stmt) {
            $stmt->bind_param('i', $user_id);
            $stmt->execute();
            $stmt->store_result();

            if ($stmt->num_rows === 1) {
                $stmt->bind_result($password, $db_version);
                $stmt->fetch();

                $valid = hash_equals(
                    hash('sha512', $password . $user_browser),
                    $login_string
                ) && $_SESSION['session_version'] === $db_version;

                if (!$valid) {
                    // 🚫 Session mismatch, cleanup
                    session_unset();
                    session_destroy();
                }

                return $valid;
            }
        }
    }

    // 🔁 FALLBACK: restore from selector cookie
    if (isset($_COOKIE['selector'])) {
        $selector = $_COOKIE['selector'];
        $stmt = $mysqli->prepare("SELECT user_id, selector FROM user_tokens WHERE selector = ?");
        $stmt->bind_param("s", $selector);
        $stmt->execute();
        $stmt->store_result();

        if ($stmt->num_rows === 1) {
            $stmt->bind_result($user_id, $selectorFromDb);
            $stmt->fetch();

            // Rehydrate session
            $stmt2 = $mysqli->prepare("SELECT username, password, session_version FROM members WHERE id = ?");
            $stmt2->bind_param("i", $user_id);
            $stmt2->execute();
            $stmt2->store_result();

            if ($stmt2->num_rows === 1) {
                $stmt2->bind_result($username, $password, $session_version);
                $stmt2->fetch();

                $_SESSION['user_id'] = $user_id;
                $_SESSION['username'] = $username;
                $_SESSION['session_version'] = $session_version;
                $_SESSION['login_string'] = hash('sha512', $password . $_SERVER['HTTP_USER_AGENT']);
                $_SESSION['active_selector'] = $selectorFromDb;

                return true;
            }
        }
    }

    return false;
}


function login_check($mysqli) {
    $log = [];

    // Step 1: Try validating existing session
    if (
        isset($_SESSION['user_id'], $_SESSION['username'], $_SESSION['login_string'], $_SESSION['session_version'])
    ) {
        //$log[] = "✅ Session variables found, validating...";

        $user_id = $_SESSION['user_id'];
        $login_string = $_SESSION['login_string'];
        $user_browser = $_SERVER['HTTP_USER_AGENT'];

        $stmt = $mysqli->prepare("
            SELECT password, session_version
            FROM members
            WHERE id = ?
            LIMIT 1
        ");
        if ($stmt) {
            $stmt->bind_param('i', $user_id);
            $stmt->execute();
            $stmt->store_result();

            if ($stmt->num_rows === 1) {
                $stmt->bind_result($password, $db_version);
                $stmt->fetch();

                $valid = hash_equals(
                    hash('sha512', $password . $user_browser),
                    $login_string
                ) && $_SESSION['session_version'] === $db_version;

                if (!$valid) {
                    $log[] = "🚫 Session hash mismatch.";
                }


                if (!$valid) {
                    $log[] = "🔁 Destroying invalid session.";
                    session_unset();
                    session_destroy();
                }

                error_log("[login_check] " . implode(" | ", $log));
                return $valid;
            } else {
                $log[] = "❌ No matching user found in DB for session.";
            }
        }
    } else {
        $log[] = "⚠️ Session empty, trying remember_token fallback...";
    }

    // Step 2: Fallback — persistent login via cookie
    if (isset($_COOKIE['remember_token'])) {
        list($selector, $token) = explode(':', $_COOKIE['remember_token'], 2);
        $log[] = "🍪 remember_token cookie found, selector: $selector";

        $stmt = $mysqli->prepare("
            SELECT user_id, hashed_token, user_agent, expires
            FROM member_tokens
            WHERE selector = ?
              AND expires > NOW()
            LIMIT 1
        ");
        $stmt->bind_param("s", $selector);
        $stmt->execute();
        $stmt->store_result();

        if ($stmt->num_rows === 1) {
            $stmt->bind_result($user_id, $hashedToken, $user_agent_db, $expires);
            $stmt->fetch();

            $expectedHash = hash('sha256', $token);
            $currentAgent = $_SERVER['HTTP_USER_AGENT'] ?? '';

            if (hash_equals($hashedToken, $expectedHash) && $user_agent_db === $currentAgent) {
                $log[] = "✅ Token validated. Restoring session...";

                $stmt2 = $mysqli->prepare("SELECT username, password, session_version FROM members WHERE id = ?");
                $stmt2->bind_param("i", $user_id);
                $stmt2->execute();
                $stmt2->store_result();

                if ($stmt2->num_rows === 1) {
                    $stmt2->bind_result($username, $password, $session_version);
                    $stmt2->fetch();

                    $_SESSION['user_id'] = $user_id;
                    $_SESSION['username'] = $username;
                    $_SESSION['session_version'] = $session_version;
                    $_SESSION['login_string'] = hash('sha512', $password . $currentAgent);
                    $_SESSION['active_selector'] = $selector;

                    error_log("[login_check] " . implode(" | ", $log));
                    return true;
                } else {
                    $log[] = "❌ Could not fetch user data from members.";
                }
            } else {
                $log[] = "🚫 Token or user agent mismatch.";
            }
        } else {
            $log[] = "🚫 No matching token found in member_tokens.";
        }
    } else {
        $log[] = "🚫 No remember_token cookie found.";
    }

    $usernameForLog = $_SESSION['username'] ?? '(no session)';
    if (!empty($log)) {
        error_log("[login_check][$usernameForLog] " . implode(" | ", $log));
    }

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

function sendVerificationEmail($email, $displayName, $token) {
    $verifyLink = BASE_URL . "/includes/verify_email.php?token=$token";

    $mail = new PHPMailer(true);
    try {
        $mail->isSMTP();
        $mail->Host = 'mail.textwhisper.com';
        $mail->SMTPAuth = true;
        $mail->Username = 'noreply@textwhisper.com';
        $mail->Password = 'VegaVinnur.99';
        $mail->SMTPSecure = PHPMailer::ENCRYPTION_STARTTLS;
        $mail->Port = 587;

        $mail->setFrom('noreply@textwhisper.com', 'TextWhisper');
        $mail->addAddress($email);
        $mail->isHTML(true);
        $mail->Subject = 'Verify your email address';
        $mail->Body = "
            <p>Hello " . htmlentities($displayName) . ",</p>
            <p>Please verify your email by clicking the link below:</p>
            <p><a href='$verifyLink'>$verifyLink</a></p>
            <p>If you didn’t register, you can ignore this email.</p>
            <p>– TextWhisper Team</p>";
        $mail->AltBody = "Verify your email: $verifyLink";

        return $mail->send();

    } catch (Exception $e) {
        error_log("❌ Failed to send verification to $email: " . $mail->ErrorInfo);
        return false;
    }
}



function sendInviteEmailxxxV1($inviteEmail, $inviterName, $listToken, $role) {
    $inviteLink = BASE_URL . "/$listToken";

    $mail = new PHPMailer(true);
    try {
        $mail->isSMTP();
        $mail->Host = 'mail.textwhisper.com';
        $mail->SMTPAuth = true;
        $mail->Username = 'noreply@textwhisper.com';
        $mail->Password = 'VegaVinnur.99'; // 🔒 consider moving this to config
        $mail->SMTPSecure = PHPMailer::ENCRYPTION_STARTTLS;
        $mail->Port = 587;

        $mail->setFrom('noreply@textwhisper.com', 'TextWhisper');
        $mail->addAddress($inviteEmail);
        $mail->isHTML(true);
        $mail->Subject = "You've been invited to a list on TextWhisper"; // ✅ plain ASCII subject

        $mail->Body = "
            <p>Hello,</p>
            <p><strong>" . htmlentities($inviterName) . "</strong> has invited you to join a list with role: <strong>" . htmlentities($role) . "</strong>.</p>
            <p>You can access the list here:</p>
            <p><a href='$inviteLink'>$inviteLink</a></p>
            <p>Please log in or register to use TextWhisper.</p>
            <p>– TextWhisper Team</p>";
        $mail->AltBody = "You've been invited by $inviterName to a list ($role).\nJoin here: $inviteLink\n\nPlease log in or register to use TextWhisper.";

        return $mail->send();

    } catch (Exception $e) {
        error_log("❌ Failed to send invite to $inviteEmail: " . $mail->ErrorInfo);
        return false;
    }
}


function sendInviteEmailYYYY($inviteEmail, $inviterName, $listToken, $role) {
    global $mysqli;

    $inviteLink = BASE_URL . "/$listToken";

    // Check if invitee is already a member
    $isMember = false;
    $stmt = $mysqli->prepare("SELECT id FROM members WHERE email = ? LIMIT 1");
    $stmt->bind_param("s", $inviteEmail);
    $stmt->execute();
    $stmt->store_result();
    if ($stmt->num_rows > 0) {
        $isMember = true;
    }
    $stmt->close();

    $subject = "You've been invited to a list on TextWhisper";

    if ($isMember) {
        $body = "
            <p>Hello,</p>
            <p><strong>" . htmlentities($inviterName) . "</strong> has added you to a list with role: <strong>" . htmlentities($role) . "</strong>.</p>
            <p>You can access the list here:</p>
            <p><a href='$inviteLink'>$inviteLink</a></p>
            <p>Please log in to use TextWhisper.</p>
            <p>– TextWhisper Team</p>";
        $altBody = "You've been added by $inviterName to a list ($role).\nJoin here: $inviteLink\n\nPlease log in to use TextWhisper.";
    } else {
        $body = "
            <p>Hello,</p>
            <p><strong>" . htmlentities($inviterName) . "</strong> has invited you to join a list with role: <strong>" . htmlentities($role) . "</strong>.</p>
            <p>You can access the list here:</p>
            <p><a href='$inviteLink'>$inviteLink</a></p>
            <p>For the best experience, please log in or register to use TextWhisper.</p>
            <p>– TextWhisper Team</p>";
        $altBody = "You've been invited by $inviterName to a list ($role).\nJoin here: $inviteLink\n\nFor the best experience, please log in or register to use TextWhisper.";
    }

    $mail = new PHPMailer(true);
    try {
        $mail->isSMTP();
        $mail->Host = 'mail.textwhisper.com';
        $mail->SMTPAuth = true;
        $mail->Username = 'noreply@textwhisper.com';
        $mail->Password = 'VegaVinnur.99'; // ⚠️ better in config
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



function sendInviteEmail($inviteEmail, $inviterName, $listToken, $role, $locale = 'en') {
    global $mysqli;

    $inviteLink = BASE_URL . "/$listToken";
    
    $locale = $_SESSION['locale'] ?? 'en';

    // 🔹 Load translations
    $langPath = __DIR__ . "/../lang/{$locale}.php";
    $translations = file_exists($langPath) ? include $langPath : [];

    // 🔹 Simple translation helper
    $tr = function($key, ...$args) use ($translations) {
        $string = $translations[$key] ?? $key;
        return $args ? vsprintf($string, $args) : $string;
    };

    // Try to fetch list name (optional)
    $listName = "a shared list";
    if ($stmt = $mysqli->prepare("SELECT name FROM content_lists WHERE token = ? LIMIT 1")) {
        $stmt->bind_param("s", $listToken);
        if ($stmt->execute()) {
            $stmt->bind_result($fetchedName);
            if ($stmt->fetch() && !empty($fetchedName)) {
                $listName = $fetchedName;
            }
        }
        $stmt->close();
    }

    // Subject and body
    $subject     = $tr('invite_subject');
    $bodyMessage = $tr('invite_message', htmlentities($inviterName), htmlentities($listName), htmlentities($role));

    $body = "
        <p>{$tr('greeting')}</p>
        <p>$bodyMessage</p>
        <p>{$tr('invite_access')}</p>
        <p><a href='$inviteLink'>$inviteLink</a></p>
        <p>{$tr('invite_experience')}</p>
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
        $mail->Password = 'VegaVinnur.99'; // ⚠️ better to load from config
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

    // Otherwise check invitations for editor/admin rights
    // Admin 90 and Editor 80 have rights
    $stmt = $mysqli->prepare("
        SELECT i.role_rank
        FROM invitations i
        JOIN members m ON m.email = i.email
        WHERE i.listToken = ? AND m.username = ?
        LIMIT 1
    ");
    $stmt->bind_param("ss", $owner, $username);
    $stmt->execute();
    $stmt->bind_result($roleRank);
    $ok = ($stmt->fetch() && $roleRank >= 80);
    $stmt->close();

    return $ok;
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
    if (!$username || !$listToken) return 0;

    $ranks = [];
    $ownerUsername = null;

    // All Content (user's own space)
    if ($listToken === $username) $ranks[] = 90;

    // Owner of the list
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
    if ($stmt->fetch() && $ownerUsername === $username) $ranks[] = 90;
    $stmt->close();

    // Invited to this specific list
    $stmt = $mysqli->prepare("
        SELECT i.role_rank
        FROM invitations i
        JOIN members m ON m.email = i.email
        WHERE i.listToken = ? AND m.username = ?
        LIMIT 1
    ");
    $stmt->bind_param("ss", $listToken, $username);
    $stmt->execute();
    $stmt->bind_result($roleRank);
    if ($stmt->fetch()) $ranks[] = (int)$roleRank;
    $stmt->close();

    // 🟩 General access (invited to all content of the list owner OR matching alias)
    $generalToken = $ownerUsername ?: $listToken;
    $stmt = $mysqli->prepare("
        SELECT i.role_rank
        FROM invitations i
        JOIN members m ON m.email = i.email
        WHERE i.listToken = ? AND m.username = ?
        LIMIT 1
    ");
    $stmt->bind_param("ss", $generalToken, $username);
    $stmt->execute();
    $stmt->bind_result($roleRank);
    if ($stmt->fetch()) $ranks[] = (int)$roleRank;
    $stmt->close();

    // ✅ Return the highest found
    return $ranks ? max($ranks) : 0;
}



/**
 * Check if a user can edit a list (≥ Editor).
 */
function can_user_edit_list($mysqli, $listToken, $username) {
    return get_user_list_role_rank($mysqli, $listToken, $username) >= 80;
}



?>
