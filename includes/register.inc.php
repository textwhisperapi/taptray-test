<?php
// current/includes/register.inc.php
include_once 'psl-config.php';
include_once 'functions.php'; // must come after psl-config (it depends on constants from it)
sec_session_start();           // start session after required functions loaded
include_once 'db_connect.php';

$error_msg = "";

// Accept invite context directly from register invite links.
$inviteTokenParam = $_GET['invite'] ?? '';
$inviteListParam = $_GET['list'] ?? '';
if (
    preg_match('/^[a-f0-9]{64}$/', $inviteTokenParam) &&
    preg_match('/^[A-Za-z0-9._-]{2,120}$/', $inviteListParam)
) {
    $inviteMaxAgeDays = defined('INVITE_TOKEN_MAX_AGE_DAYS') ? (int)INVITE_TOKEN_MAX_AGE_DAYS : 7;
    $resolvedInviteEmail = resolveInviteEmailForToken($mysqli, $inviteListParam, $inviteTokenParam, $inviteMaxAgeDays);
    $_SESSION['pending_invite_token'] = $inviteTokenParam;
    $_SESSION['pending_invite_list_token'] = $inviteListParam;
    $_SESSION['pending_invite_set_at'] = time();
    if (filter_var($resolvedInviteEmail, FILTER_VALIDATE_EMAIL)) {
        $_SESSION['pending_invite_email'] = $resolvedInviteEmail;
    } else {
        unset($_SESSION['pending_invite_email']);
        $error_msg .= '<p class="error">Invite link is invalid or expired. Please ask for a new invite.</p>';
    }
}

function isValidInviteForEmail(mysqli $mysqli, string $listToken, string $inviteToken, string $email, int $maxAgeDays): bool {
    if ($listToken === '' || $inviteToken === '' || $email === '') {
        return false;
    }
    if (!preg_match('/^[a-f0-9]{64}$/', $inviteToken)) {
        return false;
    }
    $emailLower = strtolower(trim($email));
    $inviteSecret = defined('INVITE_TOKEN_SECRET') ? (string)INVITE_TOKEN_SECRET : '';
    if ($inviteSecret === '') {
        error_log('⚠️ INVITE_TOKEN_SECRET is empty; invite-token validation is running in fallback mode.');
    }
    $expectedToken = hash_hmac('sha256', $emailLower . '|' . $listToken, $inviteSecret);
    if (!hash_equals($expectedToken, $inviteToken)) {
        return false;
    }

    $cutoff = date('Y-m-d H:i:s', time() - (max(1, $maxAgeDays) * 86400));
    $stmt = $mysqli->prepare("
        SELECT 1
        FROM invitations
        WHERE listToken = ?
          AND LOWER(email) = ?
          AND created_at >= ?
        LIMIT 1
    ");

    if ($stmt) {
        $stmt->bind_param("sss", $listToken, $emailLower, $cutoff);
        $stmt->execute();
        $stmt->store_result();
        $isValid = $stmt->num_rows > 0;
        $stmt->close();
        return $isValid;
    }

    // Backward-compatible fallback when created_at is missing in invitations table.
    $fallback = $mysqli->prepare("
        SELECT 1
        FROM invitations
        WHERE listToken = ?
          AND LOWER(email) = ?
        LIMIT 1
    ");
    if (!$fallback) {
        return false;
    }
    $fallback->bind_param("ss", $listToken, $emailLower);
    $fallback->execute();
    $fallback->store_result();
    $isValid = $fallback->num_rows > 0;
    $fallback->close();
    if ($isValid) {
        error_log('⚠️ Invite validation fallback used (no created_at support).');
    }

    return $isValid;
}




/**
 * Create a user's root "All Content" list if it doesn't already exist.
 */
function createAllContentListIfMissing(mysqli $mysqli, int $userId, string $username): void {
    $defaultListName = "All Content";
    $access = "private";

    $check = $mysqli->prepare("SELECT 1 FROM content_lists WHERE token = ? LIMIT 1");
    $check->bind_param("s", $username);
    $check->execute();
    $check->store_result();

    if ($check->num_rows === 0) {
        $insert = $mysqli->prepare("
            INSERT INTO content_lists (name, token, owner_id, created_by_id, access_level)
            VALUES (?, ?, ?, ?, ?)
        ");
        $insert->bind_param("ssiis", $defaultListName, $username, $userId, $userId, $access);
        if ($insert->execute()) {
            error_log("✅ Created 'All Content' list for new user: $username (id: $userId)");
        } else {
            error_log("⚠️ Could not create 'All Content' list for $username: " . $insert->error);
        }
        $insert->close();
    }

    $check->close();
}




// [1] 🕳️ Honeypot anti-bot field
// if (!empty($_POST['hpcheck'])) {
//     $error_msg .= '<p class="error">Bot-like behavior detected.</p>';
//     error_log("🕷 Honeypot triggered");
//     return;
// }


$blocked = false;

if (!empty($_POST['hpcheck'])) {
    $blocked = true;
    $error_msg .= '<p class="error">Bot-like behavior detected.</p>';
    error_log("🕷 Honeypot triggered during registration");
}


if (!$blocked) {
    // registration + sendVerificationEmail()


    if (isset($_POST['email'], $_POST['password'], $_POST['display_name'])) {
        $rawEmail = trim($_POST['email']);
        $email = filter_var($rawEmail, FILTER_SANITIZE_EMAIL);
        $email = filter_var($email, FILTER_VALIDATE_EMAIL);
        $displayName = htmlspecialchars(trim($_POST['display_name']), ENT_QUOTES);
        $pendingInviteEmail = strtolower(trim((string)($_SESSION['pending_invite_email'] ?? '')));
        $hasInviteEmailLock = filter_var($pendingInviteEmail, FILTER_VALIDATE_EMAIL) ? true : false;
        $inviteListToken = (string)($_SESSION['pending_invite_list_token'] ?? '');
        $inviteToken = (string)($_SESSION['pending_invite_token'] ?? '');
        $inviteMaxAgeDays = defined('INVITE_TOKEN_MAX_AGE_DAYS') ? (int)INVITE_TOKEN_MAX_AGE_DAYS : 7;
        $hasInviteContext = $inviteListToken !== '' && $inviteToken !== '';

        if ($hasInviteEmailLock) {
            if (!$email || strcasecmp($email, $pendingInviteEmail) !== 0) {
                $error_msg .= '<p class="error">Registration email must match the invited email.</p>';
            }
            $rawEmail = $pendingInviteEmail;
            $email = $pendingInviteEmail;
        }

        $inviteIsValidForEmail = false;
        if ($email && $hasInviteContext) {
            $inviteIsValidForEmail = isValidInviteForEmail($mysqli, $inviteListToken, $inviteToken, $email, $inviteMaxAgeDays);
        }
        if ($hasInviteEmailLock && !$inviteIsValidForEmail) {
            $error_msg .= '<p class="error">Invite link is invalid or expired. Please ask for a new invite.</p>';
        }

        $baseUsername = explode('@', $rawEmail)[0] ?? '';
        $username = isset($_POST['username']) ? trim((string)$_POST['username']) : '';
        $username = preg_replace('/[^a-zA-Z0-9_]/', '', $username);

        if ($username === '') {
            $error_msg .= '<p class="error">User ID is required.</p>';
        } elseif (!preg_match('/^[a-zA-Z0-9_]{3,40}$/', $username)) {
            $error_msg .= '<p class="error">User ID must be 3-40 characters using letters, numbers, or underscore.</p>';
        }

        $password = $_POST['password'];
        $confirm = $_POST['confirmpwd'] ?? '';
        $ndaAgreed = !empty($_POST['nda_agree']);
        $ndaVersion = "https://trustagreements.org/basic-v1.html";

        // // [2] 📧 Require Gmail addresses only
        // if (!preg_match('/@gmail\.com$/i', $rawEmail)) {
        //     $error_msg .= '<p class="error">Only Gmail addresses are allowed.</p>';
        //     error_log("📧 Non-Gmail rejected: $rawEmail");
        // }

        // [4] 🤖 Block synthetic/junk usernames
        if (strlen($username) > 20 && preg_match('/[a-z]+[0-9]{2,}[A-Z][a-zA-Z0-9]{5,}/', $username)) {
            $error_msg .= '<p class="error">Username appears autogenerated. Please choose a simpler one.</p>';
            error_log("🤖 Blocked synthetic username: $username");
        }

        // [5] 🕵️ Block spookie email prefixes
        if (strlen($baseUsername) > 20 && preg_match('/[a-z]{5,}[0-9]{2,}[A-Z][a-zA-Z0-9]{4,}/', $baseUsername)) {
            $error_msg .= '<p class="error">Email prefix appears autogenerated. Please use a more natural address.</p>';
            error_log("🕵️ Blocked spookie email: $rawEmail");
        }

        // [6] 🛡️ Disposable domain check
        if (!$email) {
            $error_msg .= '<p class="error">Invalid email address.</p>';
            error_log("Invalid email: $rawEmail");
        }

        if (isDisposableEmail($rawEmail)) {
            $error_msg .= '<p class="error">Disposable email addresses are not allowed.</p>';
            error_log("Blocked disposable: $rawEmail");
        }

        // Display name validation
        if (empty($displayName)) {
            $error_msg .= '<p class="error">Display name is required.</p>';
        } elseif (!preg_match('/^[\p{L} ._\'’\-]{3,40}$/u', $displayName)) {
            $error_msg .= '<p class="error">Display name contains invalid characters or wrong length.</p>';
        } elseif (preg_match('/\d/', $displayName)) {
            $error_msg .= '<p class="error">Display name cannot include digits.</p>';
        } elseif (preg_match('/(.)\1{4,}/u', $displayName)) {
            $error_msg .= '<p class="error">Display name has too many repeated characters.</p>';
        } elseif (preg_match('/[bcdfghjklmnpqrstvwxyz]{6,}/i', $displayName)) {
            $error_msg .= '<p class="error">Display name looks too artificial. Try something more natural.</p>';
        } elseif (in_array(strtolower($displayName), ['admin', 'root', 'system', 'support'])) {
            $error_msg .= '<p class="error">Display name is reserved and cannot be used.</p>';
        }



        // Password strength
        if (
            empty($password) ||
            strlen($password) < 6 ||
            !preg_match('/[A-Z]/', $password) ||
            !preg_match('/[a-z]/', $password) ||
            !preg_match('/[0-9]/', $password)
        ) {
            $error_msg .= '<p class="error">Password must be at least 6 characters and include A-Z, a-z, and 0–9.</p>';
            error_log("Weak password for: $rawEmail");
        }

        if (!$ndaAgreed) {
            $error_msg .= '<p class="error">You must accept the Basic NDA to register.</p>';
        }

        // Email already exists (allow invite-based auto-verify for unverified accounts)
        if (empty($error_msg)) {
            $stmt = $mysqli->prepare("SELECT id, username, password, email_verified FROM members WHERE email = ? LIMIT 1");
            if ($stmt) {
                $stmt->bind_param('s', $email);
                $stmt->execute();
                $stmt->bind_result($existingId, $existingUsername, $existingPasswordHash, $emailVerified);
                if ($stmt->fetch()) {
                    $stmt->close();

                    $canAutoVerify = $inviteIsValidForEmail;

                    if (!$emailVerified && $canAutoVerify) {
                        $verifyStmt = $mysqli->prepare("UPDATE members SET email_verified = 1, verify_token = NULL WHERE id = ?");
                        if ($verifyStmt) {
                            $verifyStmt->bind_param("i", $existingId);
                            $verifyStmt->execute();
                            $verifyStmt->close();
                        }
                        // Auto-login for invite-verified existing account.
                        $_SESSION['user_id'] = (int)$existingId;
                        $_SESSION['username'] = (string)$existingUsername;
                        $_SESSION['session_version'] = getCurrentSessionVersion((int)$existingId, $mysqli);
                        $_SESSION['login_string'] = hash('sha512', (string)$existingPasswordHash . ($_SERVER['HTTP_USER_AGENT'] ?? ''));
                        $_SESSION['prefill_login_input'] = $email;
                        unset(
                            $_SESSION['pending_invite_token'],
                            $_SESSION['pending_invite_list_token'],
                            $_SESSION['pending_invite_set_at'],
                            $_SESSION['pending_invite_email']
                        );
                        $redirectTo = $inviteListToken ? '/' . $inviteListToken : '/';
                        $redirectTo = withAvatarOnboardingRedirect($mysqli, (int)$existingId, $redirectTo);
                        header('Location: ' . $redirectTo);
                        exit;
                    }

                    $error_msg .= '<p class="error">This email is already registered.</p>';
                } else {
                    $stmt->close();
                }
            }
        }

        // Username already exists
        if (empty($error_msg)) {
            $stmt = $mysqli->prepare("SELECT id FROM members WHERE username = ? LIMIT 1");
            if ($stmt) {
                $stmt->bind_param('s', $username);
                $stmt->execute();
                $stmt->store_result();
                if ($stmt->num_rows > 0) {
                    $error_msg .= '<p class="error">This user ID is already taken. Try a different email prefix.</p>';
                }
                $stmt->close();
            }
        }

        $autoVerify = empty($error_msg) && $inviteIsValidForEmail;

        // Insert new user
        if (empty($error_msg)) {
            $hashedPassword = password_hash($password, PASSWORD_BCRYPT);
            $verifyToken = bin2hex(random_bytes(32));

            if ($autoVerify) {
                $stmt = $mysqli->prepare("INSERT INTO members (username, display_name, email, password, verify_token, email_verified) VALUES (?, ?, ?, ?, NULL, 1)");
            } else {
                $stmt = $mysqli->prepare("INSERT INTO members (username, display_name, email, password, verify_token, email_verified) VALUES (?, ?, ?, ?, ?, 0)");
            }
            if ($stmt) {
                if ($autoVerify) {
                    $stmt->bind_param('ssss', $username, $displayName, $email, $hashedPassword);
                } else {
                    $stmt->bind_param('sssss', $username, $displayName, $email, $hashedPassword, $verifyToken);
                }
                if ($stmt->execute()) {
                    $newMemberId = $mysqli->insert_id;   // new member's ID
                    $newMemberEmail = $email;            // signup email

                    if ($ndaAgreed) {
                        $stmtNda = $mysqli->prepare("UPDATE members SET nda_agreed_at = NOW(), nda_version = ? WHERE id = ? AND nda_agreed_at IS NULL");
                        if ($stmtNda) {
                            $stmtNda->bind_param("si", $ndaVersion, $newMemberId);
                            $stmtNda->execute();
                            $stmtNda->close();
                        }
                    }
                    
                    // Create default root list "All Content"
                    createAllContentListIfMissing($mysqli, $newMemberId, $username);
                    
                
                    // 🔄 Update invitations to link this member
                    $updateStmt = $mysqli->prepare("
                        UPDATE invitations
                        SET member_id = ?
                        WHERE email = ?
                        AND member_id IS NULL
                    ");
                    if ($updateStmt) {
                        $updateStmt->bind_param("is", $newMemberId, $newMemberEmail);
                        $updateStmt->execute();
                        $updateStmt->close();
                    }

                
                    if ($autoVerify) {
                        // Auto-login immediately after invite-verified registration.
                        $_SESSION['user_id'] = (int)$newMemberId;
                        $_SESSION['username'] = $username;
                        $_SESSION['session_version'] = getCurrentSessionVersion((int)$newMemberId, $mysqli);
                        $_SESSION['login_string'] = hash('sha512', $hashedPassword . ($_SERVER['HTTP_USER_AGENT'] ?? ''));
                        $_SESSION['prefill_login_input'] = $email;
                        $_SESSION['nda_agreed_at'] = date('Y-m-d H:i:s');
                        $_SESSION['nda_version'] = $ndaVersion;
                        unset(
                            $_SESSION['pending_invite_token'],
                            $_SESSION['pending_invite_list_token'],
                            $_SESSION['pending_invite_set_at'],
                            $_SESSION['pending_invite_email']
                        );
                        $redirectTo = $inviteListToken ? '/' . $inviteListToken : '/';
                        $redirectTo = withAvatarOnboardingRedirect($mysqli, (int)$newMemberId, $redirectTo);
                        header('Location: ' . $redirectTo);
                        exit;
                    }

                    // 📧 Send verification email as before
                    error_log("✅ REGISTER: about to call sendVerificationEmail for $email");

                    if (sendVerificationEmail($email, $displayName, $verifyToken)) {
                        // $_SESSION['last_email_sent_to'] = $email;
                        $_SESSION['pending_verification_email'] = $email;
                        $_SESSION['prefill_login_input'] = $email;

                        header('Location: /register_success.php');
                        exit;
                    } else {
                        $error_msg .= '<p class="error">Verification email could not be sent.</p>';
                    }
                }
                else {
                    $error_msg .= '<p class="error">Could not register user. Try again later.</p>';
                }
            } else {
                $error_msg .= '<p class="error">Internal error preparing registration.</p>';
            }
        }
    }

}


// Helper to block disposable domains — [6] 🛡️
function isDisposableEmail($email) {
    $disposableDomains = [
        'mailinator.com', 'tempmail.com', '10minutemail.com', 'yopmail.com',
        'guerrillamail.com', 'trashmail.com', 'fakeinbox.com', 'getnada.com'
    ];
    $domain = strtolower(substr(strrchr($email, "@"), 1));
    return in_array($domain, $disposableDomains);
}

?>
