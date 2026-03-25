<?php
include_once 'includes/db_connect.php';
include_once 'includes/functions.php';
sec_session_start();

$token = isset($_GET['token']) ? trim($_GET['token']) : '';
$tokenValid = false;
$tokenError = '';
$userId = null;
$username = null;

if ($token === '') {
    $tokenError = "No reset token provided.";
} elseif (!preg_match('/^[a-f0-9]{64}$/', $token)) {
    $tokenError = "Invalid reset token format.";
} else {
    if ($stmt = $mysqli->prepare("SELECT id, username, reset_expires FROM members WHERE reset_token = ? LIMIT 1")) {
        $stmt->bind_param('s', $token);
        $stmt->execute();
        $stmt->bind_result($foundUserId, $foundUsername, $resetExpires);
        if ($stmt->fetch()) {
            if ($resetExpires && strtotime($resetExpires) > time()) {
                $tokenValid = true;
                $userId = $foundUserId;
                $username = $foundUsername;
            } else {
                $tokenError = "Invalid or expired token.";
            }
        } else {
            $tokenError = "Invalid or expired token.";
        }
        $stmt->close();
    } else {
        $tokenError = "Database error. Please try again later.";
    }
}

if ($tokenValid && $_SERVER['REQUEST_METHOD'] === 'POST') {
    $newPassword = (string)($_POST['password'] ?? '');

    if ($newPassword === '') {
        $tokenError = "Please enter a new password.";
    } else {
        $hashedPassword = password_hash($newPassword, PASSWORD_BCRYPT);

        if ($stmt = $mysqli->prepare("
            UPDATE members
            SET password = ?, reset_token = NULL, reset_expires = NULL, session_version = COALESCE(session_version, 0) + 1
            WHERE id = ?
        ")) {
            $stmt->bind_param('si', $hashedPassword, $userId);
            if ($stmt->execute()) {
                $clearTokens = $mysqli->prepare("DELETE FROM member_tokens WHERE user_id = ?");
                if ($clearTokens) {
                    $clearTokens->bind_param('i', $userId);
                    $clearTokens->execute();
                    $clearTokens->close();
                }

                twClearRememberToken();

                $_SESSION['user_id'] = $userId;
                $_SESSION['username'] = $username;
                $_SESSION['session_version'] = getCurrentSessionVersion($userId, $mysqli);
                $_SESSION['login_string'] = hash('sha512', $hashedPassword . ($_SERVER['HTTP_USER_AGENT'] ?? ''));
                unset($_SESSION['active_selector']);
                $_SESSION['password_reset_success'] = true;

                $redirectTo = withAvatarOnboardingRedirect($mysqli, (int)$userId, '/');
                header("Location: $redirectTo");
                exit;
            } else {
                $tokenError = "Error updating password. Please try again.";
            }
            $stmt->close();
        } else {
            $tokenError = "Error preparing update statement.";
        }
    }
}
?>
<!DOCTYPE html>
<html>
<head>
    <title>Reset Password</title>
</head>
<body>
    <h2>Reset Password</h2>
    <?php if (!$tokenValid): ?>
        <p><?php echo htmlspecialchars($tokenError); ?></p>
    <?php else: ?>
        <?php if ($tokenError !== ''): ?>
            <p><?php echo htmlspecialchars($tokenError); ?></p>
        <?php endif; ?>
        <form action="reset_password.php?token=<?php echo htmlspecialchars($token); ?>" method="post">
            <label for="password">New Password:</label>
            <input type="password" name="password" id="password" required>
            <button type="submit">Reset Password</button>
        </form>
    <?php endif; ?>
</body>
</html>
