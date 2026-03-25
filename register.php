<?php
//current/register.php
include_once 'includes/register.inc.php';
include_once 'includes/functions.php';
$version = time();
$pendingInviteToken = $_SESSION['pending_invite_list_token'] ?? '';
$pendingInviteEmail = strtolower(trim((string)($_SESSION['pending_invite_email'] ?? '')));
$inviteLocked = filter_var($pendingInviteEmail, FILTER_VALIDATE_EMAIL) ? true : false;
$prefillEmail = $inviteLocked
  ? $pendingInviteEmail
  : (isset($_POST['email']) ? trim((string)$_POST['email']) : '');
$prefillDisplayName = isset($_POST['display_name']) ? trim((string)$_POST['display_name']) : '';
$prefillAutoUsername = !isset($_POST['auto_username']) || $_POST['auto_username'] === '1';
$prefillUsername = isset($_POST['username']) ? trim((string)$_POST['username']) : '';
$inviteListToken = $_SESSION['pending_invite_list_token'] ?? '';
$inviteHash = $_SESSION['pending_invite_token'] ?? '';
$googleRegisterLink = '/api/google-login.php';
if (
  preg_match('/^[A-Za-z0-9._-]{2,120}$/', $inviteListToken) &&
  preg_match('/^[a-f0-9]{64}$/', $inviteHash)
) {
  $googleRegisterLink .= '?register=1&list=' . urlencode($inviteListToken)
    . '&invite=' . urlencode($inviteHash);
}
?>
<!DOCTYPE html>
<html>
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title>Register - TextWhisper</title>
  <link rel="stylesheet" href="/login.css?v=<?= $version ?>">
</head>

<body>
  <div class="auth-container">
    <h1>Create your account</h1>

    <?php if (!empty($error_msg)) : ?>
      <div class="error"><?= $error_msg ?></div>
    <?php endif; ?>
    <?php if (isset($_GET['error']) && $_GET['error'] === 'invite_email_mismatch') : ?>
      <div class="error">Google account email does not match the invited email.</div>
    <?php endif; ?>
    <?php if (isset($_GET['error']) && $_GET['error'] === 'invalid_invite') : ?>
      <div class="error">Invite link is invalid or expired. Please ask for a new invite.</div>
    <?php endif; ?>

    <form action="<?= esc_url($_SERVER['PHP_SELF']) ?>" method="post" name="registration_form">
      <label for="display_name">Display name:</label>
      <input type="text" name="display_name" id="display_name" required placeholder="Your name?" value="<?= htmlspecialchars($prefillDisplayName) ?>">

      <label for="email">Email address:</label>
      <?php if ($inviteLocked): ?>
      <input
        type="email"
        id="email"
        required
        placeholder="you@example.com"
        value="<?= htmlspecialchars($prefillEmail) ?>"
        readonly
        disabled
      >
      <input type="hidden" name="email" value="<?= htmlspecialchars($prefillEmail) ?>">
      <?php else: ?>
      <input
        type="email"
        name="email"
        id="email"
        required
        placeholder="you@example.com"
        value="<?= htmlspecialchars($prefillEmail) ?>"
      >
      <?php endif; ?>
      <div id="emailStatus" style="font-size: 0.9em; margin-top: 4px;"></div>

      <label class="checkbox-label" style="margin-top: 10px;">
        <input type="checkbox" id="auto_username" name="auto_username" value="1" <?= $prefillAutoUsername ? 'checked' : '' ?>>
        Auto-generate User ID from email
      </label>
      <label for="username">User ID:</label>
      <input
        type="text"
        name="username"
        id="username"
        placeholder="Choose your user ID"
        value="<?= htmlspecialchars($prefillUsername) ?>"
        autocomplete="off"
      >
      <div id="usernameStatus" style="font-size: 0.9em; margin-top: 4px;"></div>

      <label for="password">Password:</label>
      <input type="password" name="password" id="password" required placeholder="Contains 6+ chars, A-Z, a-z, 0–9">

      <label for="confirmpwd">Confirm password:</label>
      <input type="password" name="confirmpwd" id="confirmpwd" required placeholder="repeat password exactly">

      <div id="ndaRegisterWrapper" style="margin-top: 10px;">
        <label class="checkbox-label">
          <input type="checkbox" name="nda_agree" id="nda_agree" required>
          I agree to the <a href="https://trustagreements.org/basic-v1.html" target="_blank">Basic NDA v1</a>
        </label>
      </div>

      <br><br>
      <button type="submit">Register</button>
    </form>

    <?php if (!empty($pendingInviteToken) && $inviteLocked) : ?>
      <div class="google-login-button" style="margin-top:12px;">
        <a href="<?= htmlspecialchars($googleRegisterLink) ?>">
          <img src="/assets/web_neutral_rd_SI.svg" alt="Register with Google">
        </a>
      </div>
    <?php endif; ?>
    
    <?php if (!empty($pendingInviteToken)) : ?>
      <p>You're registering from an invite link. If your email matches the invite, we'll verify it automatically.</p>
    <?php else : ?>
      <p>You will get a confirmation email to finish the registration.</p>
    <?php endif; ?>
    <p>Return to the <a href="login.php">login page</a>.</p>

    <div class="form-note">
      <ul>
        <li>Emails must have a valid format</li>
        <li>Passwords must be at least 6 characters and contain:
          <ul>
            <li>At least one uppercase letter (A..Z)</li>
            <li>At least one lowercase letter (a..z)</li>
            <li>At least one number (0..9)</li>
          </ul>
        </li>
        <li>Your password and confirmation must match</li>
      </ul>
    </div>
  </div>

  <script>
    async function checkAvailability() {
      const email = document.getElementById("email").value.trim();
      const emailStatusBox = document.getElementById("emailStatus");
      const usernameInput = document.getElementById("username");
      const usernameStatusBox = document.getElementById("usernameStatus");
      const username = usernameInput.value.trim().replace(/[^a-zA-Z0-9_]/g, "");

      if (usernameInput.value !== username) {
        usernameInput.value = username;
      }

      try {
        const res = await fetch(`/includes/check_availability.php?email=${encodeURIComponent(email)}&username=${encodeURIComponent(username)}`);
        const data = await res.json();

        if (data.email_status === "taken") {
          emailStatusBox.textContent = "⚠️ This email is already registered.";
          emailStatusBox.style.color = "darkred";
        } else if (data.email_status === "available") {
          emailStatusBox.textContent = "✅ Email is available.";
          emailStatusBox.style.color = "green";
        } else if (email) {
          emailStatusBox.textContent = "⚠️ Email check skipped.";
          emailStatusBox.style.color = "gray";
        } else {
          emailStatusBox.textContent = "";
        }

        if (!username) {
          usernameStatusBox.textContent = "";
        } else if (data.username_status === "taken") {
          usernameStatusBox.textContent = "⚠️ This User ID is already taken.";
          usernameStatusBox.style.color = "darkred";
        } else if (data.username_status === "available") {
          usernameStatusBox.textContent = "✅ User ID is available.";
          usernameStatusBox.style.color = "green";
        } else {
          usernameStatusBox.textContent = "";
        }
      } catch (err) {
        console.error("Availability check failed:", err);
        emailStatusBox.textContent = "⚠️ Error checking email.";
        emailStatusBox.style.color = "orange";
        usernameStatusBox.textContent = "⚠️ Error checking User ID.";
        usernameStatusBox.style.color = "orange";
      }
    }

    function syncUsernameFromEmail() {
      const autoUsernameCheckbox = document.getElementById("auto_username");
      const usernameInput = document.getElementById("username");
      const email = document.getElementById("email").value.trim();
      const base = email.includes("@") ? email.split("@")[0] : "";

      if (autoUsernameCheckbox?.checked) {
        usernameInput.value = base.replace(/[^a-zA-Z0-9_]/g, "");
      }

      checkAvailability();
    }

    const emailInput = document.getElementById("email");
    if (emailInput && !emailInput.hasAttribute("readonly")) {
      emailInput.addEventListener("input", syncUsernameFromEmail);
      emailInput.addEventListener("blur", checkAvailability);
    } else {
      syncUsernameFromEmail();
    }
    document.getElementById("auto_username").addEventListener("change", syncUsernameFromEmail);
    document.getElementById("username").addEventListener("input", checkAvailability);
    syncUsernameFromEmail();

    async function resendVerification() {
      const email = document.getElementById("email").value.trim();
      if (!email) return;

      try {
        const res = await fetch("/includes/resend_verification.php", {
          method: "POST",
          headers: { "Content-Type": "application/json" },
          body: JSON.stringify({ email })
        });

        alert("If the account exists and is not verified, a verification email has been sent.");
      } catch (err) {
        alert("Could not send verification email. Please try again later.");
      }
    }

    function validateRegistrationForm(event) {
      const pwd = document.getElementById('password').value.trim();
      const confirm = document.getElementById('confirmpwd').value.trim();
      const email = document.getElementById('email').value.trim();
      const displayName = document.getElementById('display_name').value.trim();
      const username = document.getElementById('username').value.trim();

      const errors = [];

      if (!/^[^@\s]+@[^@\s]+\.[^@\s]+$/.test(email)) {
        errors.push("Email must have a valid format.");
      }

      if (pwd.length < 6) {
        errors.push("Password must be at least 6 characters.");
      }
      if (!/[A-Z]/.test(pwd)) {
        errors.push("Password must contain at least one uppercase letter (A–Z).");
      }
      if (!/[a-z]/.test(pwd)) {
        errors.push("Password must contain at least one lowercase letter (a–z).");
      }
      if (!/[0-9]/.test(pwd)) {
        errors.push("Password must contain at least one number (0–9).");
      }

      if (pwd !== confirm) {
        errors.push("Your password and confirmation must match.");
      }

    if (!/^[\p{L} ._'’\-]{3,40}$/u.test(displayName)) {
      errors.push("Display name contains invalid characters or length.");
    }
    
    if (/\d/.test(displayName)) {
      errors.push("Display name cannot include digits.");
    }
    
    if (/^(.)\1{4,}$/u.test(displayName)) {
      errors.push("Display name has too many repeated characters.");
    }
    
    if (/[bcdfghjklmnpqrstvwxyz]{6,}/i.test(displayName)) {
      errors.push("Display name looks too artificial.");
    }
    
    if (["admin", "root", "system", "support"].includes(displayName.toLowerCase())) {
      errors.push("Display name is reserved and cannot be used.");
    }

      if (!/^[a-zA-Z0-9_]{3,40}$/.test(username)) {
        errors.push("User ID must be 3-40 characters using letters, numbers, or underscore.");
      }


      if (errors.length > 0) {
        alert("⚠️ Please fix the following:\n\n" + errors.join("\n"));
        event.preventDefault();
      }
    }

    document.querySelector('form[name="registration_form"]')
      .addEventListener('submit', validateRegistrationForm);
  </script>
</body>
</html>
