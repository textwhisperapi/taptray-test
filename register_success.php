<?php
//session_start();
$email = $_SESSION['last_email_sent_to'] ?? '';
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8" />
  <meta name="viewport" content="width=device-width, initial-scale=1" />
  <title>Registration Success – TextWhisper</title>
  <link rel="stylesheet" href="/login.css" />
</head>
<body>
  <div class="auth-container" style="text-align: center;">
    <h1>🎉 You're almost there!</h1>

    <p>We've sent a confirmation email to:</p>
    <p><strong><?= htmlentities($email) ?></strong></p>


    <p>Just click the link inside to verify your account.</p>
    <p>Once verified, you can <a href="/login.php">log in</a> and start using TextWhisper.</p>

    <p style="margin-top: 20px; font-size: 0.9rem; color: #555;">
      📬 Don’t see the email? Check your spam folder — or
      <a href="#" onclick="resendEmail(); return false;">resend it</a>.
    </p>
  </div>

  <script>
    function resendEmail() {
      fetch("/includes/verify_email_resend.php")
        .then(res => {
          if (res.ok) {
            alert("✅ A new verification email has been sent.");
          } else if (res.status === 409) {
            alert("✅ This email is already verified.");
          } else {
            alert("⚠️ Unable to resend the email. Try again later.");
          }
        })
        .catch(() => {
          alert("⚠️ Could not connect to the server.");
        });
    }
  </script>
</body>
</html>
