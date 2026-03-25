<?php
include_once 'includes/db_connect.php';
include_once 'includes/functions.php';

$flashMessage = "";
$prefillEmail = trim((string)($_GET['email'] ?? ''));

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $email = trim((string)($_POST['email'] ?? ''));
    $prefillEmail = $email;
    $emailLookup = strtolower($email);

    if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        $flashMessage = "❌ Please enter a valid email address.";
    } elseif ($stmt = $mysqli->prepare("SELECT id FROM members WHERE LOWER(email) = ? LIMIT 1")) {
        $stmt->bind_param('s', $emailLookup);
        $stmt->execute();
        $stmt->store_result();

        if ($stmt->num_rows > 0) {
            $stmt->close();

            $token = bin2hex(random_bytes(32));
            $updateStmt = $mysqli->prepare("UPDATE members SET reset_token = ?, reset_expires = NOW() + INTERVAL 1 HOUR WHERE LOWER(email) = ?");

            if (!$updateStmt) {
                $flashMessage = "❌ Database error. Please try again later.";
                error_log("Reset token prepare failed for $email. DB error: " . ($mysqli->error ?? 'unknown'));
            } else {
                $updateStmt->bind_param('ss', $token, $emailLookup);
                $updateOk = $updateStmt->execute();
                $rowsUpdated = $updateStmt->affected_rows ?? 0;
                $updateStmt->close();

                if (!$updateOk || $rowsUpdated < 1) {
                    $flashMessage = "❌ Could not generate reset token. Please try again.";
                    error_log("Reset token update failed for $email. DB error: " . ($mysqli->error ?? 'unknown'));
                } else {
                    if (sendPasswordResetEmail($email, $token)) {
                        $flashMessage = "✅ We’ve emailed a password reset link to <strong>$email</strong>.<br>
                        Please check your inbox and follow the instructions to reset your password.<br>
                        ⚠️ If you don’t see the email within a few minutes, check your spam or junk folder.";
                        $flashMessage .= "<script>setTimeout(() => window.location.href = '/login.php', 5000);</script>";
                    } else {
                        $flashMessage = "❌ We could not send the reset email. Please try again shortly.";
                    }
                }
            }
        } else {
            $stmt->close();
            $flashMessage = "❌ No account found with that email address.";
        }
    } else {
        $flashMessage = "❌ Database error. Please try again later.";
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>Forgot Password - TextWhisper</title>
    <link rel="stylesheet" href="/login.css">
    <style>
        .flash {
            background-color: #f8f9fa;
            border: 1px solid #ccc;
            padding: 12px 16px;
            margin-top: 16px;
            border-radius: 6px;
            color: #333;
            font-size: 14px;
            animation: fadeIn 0.5s ease-in;
        }

        .flash.success { border-left: 4px solid #28a745; }
        .flash.error { border-left: 4px solid #dc3545; }

        @keyframes fadeIn {
            from { opacity: 0; transform: translateY(-5px); }
            to   { opacity: 1; transform: translateY(0); }
        }

        .note {
            font-size: 0.88rem;
            color: #555;
            margin-top: 16px;
            line-height: 1.5;
        }
    </style>
</head>
<body>
<div class="auth-container">
    <h1>Reset your password</h1>

    <?php if ($flashMessage): ?>
        <div class="flash <?= (strpos($flashMessage, '✅') !== false) ? 'success' : 'error'; ?>" id="flashMsg">
            <?= $flashMessage ?>
        </div>
        <script>
            setTimeout(() => {
                const msg = document.getElementById('flashMsg');
                if (msg) msg.style.opacity = '0.4';
            }, 9000);
        </script>
    <?php endif; ?>

    <form action="forgot_password.php" method="post" onsubmit="showLoading()">
        <label for="email">Enter your email:</label>
        <input type="email" name="email" id="email" required value="<?= htmlspecialchars($prefillEmail) ?>">
        <button type="submit">Send reset link</button>
    </form>

    <div id="feedback" style="margin-top: 10px; font-size: 0.9em; color: #555;"></div>

    <div class="note">
        If the email exists, you will receive a reset link shortly.<br>
        Please check your <strong>spam or junk folder</strong> if it doesn't arrive.
    </div>
</div>

<script>
    function showLoading() {
        document.getElementById('feedback').textContent = "⏳ Sending request...";
    }
</script>
</body>
</html>
