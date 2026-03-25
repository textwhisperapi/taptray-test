<?php
include_once './includes/db_connect.php';
include_once './includes/functions.php';
sec_session_start();

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
        $pairs[strtolower($selector)] = [$selector, $token];
    }

    return array_values($pairs);
}

function writeProfileTokenPairs(array $pairs): void {
    $safe = [];
    foreach ($pairs as $pair) {
        if (!is_array($pair) || count($pair) !== 2) continue;
        [$selector, $token] = $pair;
        if (!is_string($selector) || !is_string($token)) continue;
        if (!preg_match('/^[a-f0-9]{12}$/i', $selector)) continue;
        if (!preg_match('/^[a-f0-9]{64}$/i', $token)) continue;
        $safe[] = strtolower($selector) . ':' . strtolower($token);
        if (count($safe) >= 5) break;
    }

    setcookie("tw_profile_tokens", json_encode($safe), twCookieOptions([
        'expires'  => time() + (30 * 24 * 60 * 60)
    ]));
}

function upsertProfileTokenPair(string $selector, string $token): void {
    if (!preg_match('/^[a-f0-9]{12}$/i', $selector)) return;
    if (!preg_match('/^[a-f0-9]{64}$/i', $token)) return;

    $selector = strtolower($selector);
    $token = strtolower($token);
    $pairs = readProfileTokenPairs();
    $pairs = array_values(array_filter($pairs, function ($pair) use ($selector) {
        return strtolower($pair[0]) !== $selector;
    }));
    array_unshift($pairs, [$selector, $token]);
    writeProfileTokenPairs($pairs);
}

function removeProfileTokenPairBySelector(string $selector): void {
    if (!preg_match('/^[a-f0-9]{12}$/i', $selector)) return;
    $selector = strtolower($selector);
    $pairs = readProfileTokenPairs();
    $pairs = array_values(array_filter($pairs, function ($pair) use ($selector) {
        return strtolower($pair[0]) !== $selector;
    }));
    writeProfileTokenPairs($pairs);
}

// ✅ Handle logout via ?logout=1
if (isset($_GET['logout']) && $_GET['logout'] === '1') {
    $forgetRecent = isset($_GET['forget_recent']) && $_GET['forget_recent'] === '1';
    $selectorToRemove = '';
    if (!empty($_SESSION['active_selector'])) {
        $selector = $_SESSION['active_selector'];
        $selectorToRemove = (string)$selector;
        if ($forgetRecent) {
            $stmt = $mysqli->prepare("DELETE FROM member_tokens WHERE selector = ?");
            if ($stmt) {
                $stmt->bind_param("s", $selector);
                $stmt->execute();
                $stmt->close();
            }
        }
    } elseif (!empty($_COOKIE['remember_token'])) {
        $parts = explode(':', $_COOKIE['remember_token'], 2);
        $selector = $parts[0] ?? '';
        $selectorToRemove = (string)$selector;
        if ($forgetRecent && preg_match('/^[a-f0-9]{12}$/i', $selector)) {
            $stmt = $mysqli->prepare("DELETE FROM member_tokens WHERE selector = ?");
            if ($stmt) {
                $stmt->bind_param("s", $selector);
                $stmt->execute();
                $stmt->close();
            }
        }
    }

    if ($forgetRecent) {
        removeProfileTokenPairBySelector($selectorToRemove);
    }

    twClearRememberToken();

    $_SESSION = [];
    $params = session_get_cookie_params();
    setcookie(session_name(), '', time() - 42000, $params['path'], $params['domain'], $params['secure'], $params['httponly']);
    session_destroy();

    header("Location: /");
    exit;
}


$version = time();
$logged = login_check($mysqli) ? 'in' : 'out';
$switchMode = (isset($_GET['switch']) && $_GET['switch'] === '1');
if ($switchMode) {
    $logged = 'out';
}

// One-time seamless migration for existing sessions:
// keep a profile-token copy of current remember_token for quick switch.
if (!empty($_COOKIE['remember_token'])) {
    $parts = explode(':', $_COOKIE['remember_token'], 2);
    $rememberSelector = strtolower(trim((string)($parts[0] ?? '')));
    $rememberToken = strtolower(trim((string)($parts[1] ?? '')));
    if (
        preg_match('/^[a-f0-9]{12}$/', $rememberSelector) &&
        preg_match('/^[a-f0-9]{64}$/', $rememberToken)
    ) {
        upsertProfileTokenPair($rememberSelector, $rememberToken);
    }
}

// ✅ Redirect path handling
// Default to "/" so post-login can resolve to last-selected profile.
// Only honor explicit redirect query param.
$redirectCandidate = $_GET['redirect'] ?? '/';
$parsed = parse_url($redirectCandidate);
$redirectPath = $parsed['path'] ?? '/';

$cleanPath = strtolower(trim($redirectPath, "/"));
$excluded = ['login.php', 'forgot_password.php', 'reset_password.php', 'default', 'welcome', ''];

if (in_array($cleanPath, $excluded, true)) {
    $redirectPath = '/';
}

$recentProfiles = [];
$autoLoginNonce = '';
if ($logged === 'out') {
    if (
        empty($_SESSION['profile_auto_login_nonce']) ||
        !is_string($_SESSION['profile_auto_login_nonce']) ||
        strlen($_SESSION['profile_auto_login_nonce']) < 16
    ) {
        $_SESSION['profile_auto_login_nonce'] = bin2hex(random_bytes(16));
    }
    $autoLoginNonce = $_SESSION['profile_auto_login_nonce'];

    foreach (readProfileTokenPairs() as [$selector, $token]) {
        $stmtProfile = $mysqli->prepare("
            SELECT m.username, m.display_name, m.avatar_url, t.hashed_token
            FROM member_tokens t
            JOIN members m ON m.id = t.user_id
            WHERE t.selector = ? AND t.session_only = 0 AND t.expires > NOW()
            LIMIT 1
        ");
        if (!$stmtProfile) continue;

        $stmtProfile->bind_param("s", $selector);
        $stmtProfile->execute();
        $stmtProfile->bind_result($username, $displayName, $avatarUrl, $hashedToken);

        if ($stmtProfile->fetch()) {
            $expectedHash = hash('sha256', $token);
            $avatar = is_string($avatarUrl) ? trim($avatarUrl) : '';
            if ($avatar === '' || !preg_match('#^(https?://|/)#i', $avatar)) {
                $avatar = '/default-avatar.png';
            }
            if (hash_equals($hashedToken, $expectedHash)) {
                $recentProfiles[] = [
                    'selector' => $selector,
                    'username' => (string)$username,
                    'display_name' => (string)($displayName ?: $username),
                    'avatar_url' => $avatar
                ];
            }
        }
        $stmtProfile->close();

        if (count($recentProfiles) >= 5) break;
    }
}

// ✅ Parse device
function parseDevice($user_agent) {
    $os = 'Unknown OS';
    $browser = 'Unknown Browser';
    $type = 'Desktop';

    if (preg_match('/android/i', $user_agent)) {
        $os = 'Android'; $type = 'Mobile';
    } elseif (preg_match('/iphone|ipad|ipod/i', $user_agent)) {
        $os = 'iOS'; $type = 'Mobile';
    } elseif (preg_match('/windows/i', $user_agent)) {
        $os = 'Windows';
    } elseif (preg_match('/macintosh|mac os x/i', $user_agent)) {
        $os = 'Mac';
    } elseif (preg_match('/linux/i', $user_agent)) {
        $os = 'Linux';
    }

    if (preg_match('/samsungbrowser/i', $user_agent)) {
        $browser = 'Samsung Internet';
    } elseif (preg_match('/vivaldi/i', $user_agent)) {
        $browser = 'Vivaldi';
    } elseif (preg_match('/edga|edgios|edg|edge/i', $user_agent)) {
        $browser = 'Edge';
    } elseif (preg_match('/chrome/i', $user_agent)) {
        $browser = 'Chrome';
    } elseif (preg_match('/firefox/i', $user_agent)) {
        $browser = 'Firefox';
    } elseif (preg_match('/safari/i', $user_agent) && !preg_match('/chrome/i', $user_agent)) {
        $browser = 'Safari';
    }

    return "$os • $browser • $type";
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title>Log In - TextWhisper</title>
  <link rel="stylesheet" href="/login.css?v=<?= $version ?>">
  <script src="/js/sha512.js"></script>
  <script src="/js/forms.js"></script>
</head>

<body data-logged-in-user="<?= htmlspecialchars($_SESSION['username'] ?? '') ?>">
<div class="auth-container">
  <?php if ($logged === 'out'): ?>
    <h1>Log in to TextWhisper</h1>

    <?php if (isset($_GET['error'])): ?>
      <div class="error">
        <?php
          if ($_GET['error'] === 'unverified') {
            echo '⚠️ Please verify your email before logging in.';

            if (!empty($_SESSION['pending_verification_email'])) {
              ?>
              <div style="margin-top:8px; font-size:0.9em;">
                Didn’t receive the email?
                <a href="#" onclick="resendVerification(); return false;">
                  Resend verification email
                </a>
              </div>
              <?php
            }

          } elseif ($_GET['error'] === 'nda_required') {
            echo '⚠️ Please accept the Basic NDA before logging in.';
          } else {
            echo '⚠️ Error logging in. Please check your email and password.';
          }
        ?>
      </div>
    <?php endif; ?>

    <?php if (isset($_GET['verified']) && $_GET['verified'] === '1'): ?>
      <div class="confirmation-message">✅ Your email is verified. You can log in now.</div>
    <?php endif; ?>


    <!-- persistent placeholder for dynamic error message-->
    <div id="dynamicNdaError" class="error" style="display: none;"></div>
    
    <?php
    // At the top of your login.php (before HTML output)
    $showNdaCheckbox = isset($_GET['error']) && $_GET['error'] === 'nda_required';
    $emailPrefill = trim((string)($_GET['email'] ?? ''));
    if ($emailPrefill === '' && !empty($_SESSION['prefill_login_input'])) {
      $emailPrefill = trim((string)$_SESSION['prefill_login_input']);
      unset($_SESSION['prefill_login_input']);
    }
    ?>
    
<div class="login-center-block">

  <!-- NDA Agreement Text -->
  <p class="nda-agreement">
    By logging in you agree to the 
    <a href="https://trustagreements.org/basic-v1.html" target="_blank">Basic NDA v1</a>
  </p>

  <!-- Google Login Button -->
  <div class="google-login-button">
    <a href="/api/google-login.php">
      <img src="/assets/web_neutral_rd_SI.svg" alt="Sign in with Google">
    </a>
  </div>

  <!-- Separator -->
  <div class="login-separator">or...</div>

</div>




    <form action="/includes/process_login.php" method="post" name="login_form">
        <div id="recentUsersPanel" class="recent-users">
          <div class="recent-users-header">
            <strong>Recent profiles on this device</strong>
            <button type="button" id="clearRecentUsersBtn" class="recent-clear-btn">Clear</button>
          </div>
          <div id="recentUsersList" class="recent-users-list"></div>
        </div>

        <label for="email" style="font-size: 0.85rem;">Email or Username:</label>
        <input type="text" name="email" id="email" required placeholder="your@email.com or username" value="<?= htmlspecialchars($emailPrefill) ?>">
        
        <label for="password" style="font-size: 0.85rem;">Password:</label>
        <input type="password" name="password" id="password" required>

        <label class="checkbox-label">
        <input type="checkbox" id="rememberMe" name="rememberMe" checked> Stay logged in
        
        </label>
        
        <label class="checkbox-label">
        <input type="checkbox" name="logout_others"> Logout from all other devices
        </label>
      

<div id="ndaCheckboxWrapper" style="margin-top: 10px; <?= $showNdaCheckbox ? '' : 'display: none;' ?>">
  <label class="checkbox-label">
    <input type="checkbox" name="nda_agree" id="nda_agree">
    I agree to the <a href="https://trustagreements.org/basic-v1.html" target="_blank">Basic NDA v1</a>
  </label>
</div>




      <input type="hidden" name="redirectTo" value="<?= htmlentities($redirectPath) ?>">
      <button type="submit" id="loginSubmitBtn">Login</button>
    </form>

<div style="padding-left: 30px;">
  <p style="margin-bottom: 6px;"><a href="/forgot_password.php" id="forgotPasswordLink">Forgot password?</a></p>
  <p style="margin: 0;">Don't have an account? <a href="/register.php">Register here</a></p>
</div>


    

    <script>
      document.querySelector('form[name="login_form"]').addEventListener('keydown', function(e) {
        if (e.key === 'Enter') {
          e.preventDefault();
          const submitBtn = this.querySelector('#loginSubmitBtn');
          if (submitBtn) {
            submitBtn.click();
          } else if (typeof this.requestSubmit === 'function') {
            this.requestSubmit();
          } else {
            this.submit();
          }
        }
      });

      const forgotPasswordLink = document.getElementById('forgotPasswordLink');
      const loginEmailInput = document.getElementById('email');
      if (forgotPasswordLink && loginEmailInput) {
        forgotPasswordLink.addEventListener('click', function(e) {
          const emailValue = loginEmailInput.value.trim();
          if (!emailValue) {
            return;
          }
          e.preventDefault();
          this.href = '/forgot_password.php?email=' + encodeURIComponent(emailValue);
          window.location.href = this.href;
        });
      }
    </script>

  <?php else: ?>
    <a href="javascript:history.back()" class="back-link">← Back</a>
    <h1>You’re  logged in as: <?= htmlentities($_SESSION['username']) ?></h1>
    <!--<p>Welcome back, <?= htmlentities($_SESSION['username']) ?>.</p>-->
    
    <?php
    $stmt = $mysqli->prepare("SELECT nda_agreed_at, nda_version FROM members WHERE id = ?");
    $stmt->bind_param("i", $_SESSION['user_id']);
    $stmt->execute();
    $stmt->bind_result($nda_at, $nda_version);
    $stmt->fetch();
    $stmt->close();
    
    if ($nda_at):
    ?>
        <p style="margin-top: 10px; font-size: 14px; color: green;">
          ✅ NDA agreed on <?= date("Y-m-d", strtotime($nda_at)) ?><br>
          📄 <a href="<?= htmlspecialchars($nda_version) ?>" target="_blank">View NDA version</a>
        </p>
    <?php else: ?>
        <p style="margin-top: 10px; font-size: 14px; color: red;">
          ⚠️ NDA not yet accepted.
        </p>
    <?php endif; ?>


    <form action="/login.php" method="get">
      <input type="hidden" name="logout" value="1">
      <label class="checkbox-label" style="margin: 8px 0;">
        <input type="checkbox" name="forget_recent" value="1">
        Remove from Recent profiles on this device
      </label>
      <button type="submit">Logout</button>
    </form>

    <?php
    $user_id = $_SESSION['user_id'];
    $currentSelector = strtolower(trim((string)($_SESSION['active_selector'] ?? '')));
    if ($currentSelector === '' && !empty($_COOKIE['remember_token'])) {
        $parts = explode(':', (string)$_COOKIE['remember_token'], 2);
        $maybeSelector = strtolower(trim((string)($parts[0] ?? '')));
        if (preg_match('/^[a-f0-9]{12}$/', $maybeSelector)) {
            $currentSelector = $maybeSelector;
        }
    }
    $stmt = $mysqli->prepare("
      SELECT selector, user_agent, ip_address, expires, session_only
      FROM member_tokens
      WHERE user_id = ?
      ORDER BY expires DESC
    ");
    $stmt->bind_param("i", $user_id);
    $stmt->execute();
    $result = $stmt->get_result();
    if ($result->num_rows > 0):
    ?>
      <h3>🔐 Active Sessions</h3>
      <form action="/includes/revoke_sessions.php" method="post">
        <?php while ($row = $result->fetch_assoc()):
          $selectorRaw = strtolower((string)$row['selector']);
          $selector = htmlentities($selectorRaw);
          $device = parseDevice($row['user_agent']);
          $label = $row['session_only'] ? "🕓 Temporary" : "📌 Remembered";
          $isCurrent = ($currentSelector !== '' && $selectorRaw === $currentSelector);
          ?>
          <div style="margin-bottom: 1em;<?= $isCurrent ? ' background:#eef; padding:10px;' : '' ?>">
            <label>
              <input type="checkbox" name="selectors[]" value="<?= $selector ?>" <?= $isCurrent ? 'disabled' : '' ?>>
              <?= $isCurrent ? '🟢 <strong>This browser</strong>' : '' ?> <?= $label ?><br>
              📱 <strong><?= $device ?></strong><br>
              🌍 IP: <?= htmlentities($row['ip_address']) ?><br>
              ⏳ Expires: <?= htmlentities($row['expires']) ?>
            </label>
          </div>
        <?php endwhile; ?>
        <button type="submit" name="action" value="revoke_selected">🚫 Logout Selected</button>
        <button type="submit" name="action" value="revoke_all">🚫 Logout All Devices</button>
      </form>
    <?php endif; ?>
  <?php endif; ?>
</div>


<script>
async function resendVerification() {
  try {
    const res = await fetch("/includes/verify_email_resend.php", {
      method: "POST"
    });

    if (res.ok) {
      alert("Verification email sent. Please check your inbox.");
    } else {
      alert("Could not resend verification email.");
    }
  } catch (err) {
    alert("Network error. Please try again later.");
  }
}
</script>


<script>
document.querySelector('form[name="login_form"]').addEventListener('submit', function(e) {
  const ndaBox = document.getElementById('nda_agree');
  const ndaVisible = ndaBox && ndaBox.offsetParent !== null;

  if (ndaVisible && !ndaBox.checked) {
    e.preventDefault();
    alert("⚠️ Please agree to the Basic NDA v1 before logging in.");
  }
});
</script>

<script>
document.addEventListener("DOMContentLoaded", function () {
  const SEEDED_PROFILES = <?= json_encode($recentProfiles, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) ?>;
  const AUTO_LOGIN_NONCE = <?= json_encode($autoLoginNonce, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) ?>;
  const loginForm = document.querySelector('form[name="login_form"]');
  const emailInput = document.getElementById("email");
  const recentUsersPanel = document.getElementById("recentUsersPanel");
  const recentUsersList = document.getElementById("recentUsersList");
  const clearRecentUsersBtn = document.getElementById("clearRecentUsersBtn");
  const ndaWrapper = document.getElementById("ndaCheckboxWrapper");
  const ndaBox = document.getElementById("nda_agree");
  const ndaMessage = document.getElementById("dynamicNdaError");

  async function autoLoginBySelector(selector) {
    const redirectField = loginForm ? loginForm.querySelector('input[name="redirectTo"]') : null;
    const redirectTo = redirectField ? (redirectField.value || "/") : "/";
    const body = new URLSearchParams();
    body.set("selector", selector);
    body.set("nonce", AUTO_LOGIN_NONCE || "");
    body.set("redirectTo", redirectTo);

    const res = await fetch("/includes/auto_login_profile.php", {
      method: "POST",
      headers: { "Content-Type": "application/x-www-form-urlencoded" },
      body: body.toString()
    });

    let data = null;
    try {
      data = await res.json();
    } catch (err) {
      data = null;
    }

    if (!res.ok || !data || !data.ok) {
      throw new Error((data && data.message) ? data.message : "Auto-login failed");
    }
    window.location.href = data.redirect || "/";
  }

  function renderRecentProfiles() {
    if (!recentUsersPanel || !recentUsersList) return;
    recentUsersList.innerHTML = "";

    if (!Array.isArray(SEEDED_PROFILES) || SEEDED_PROFILES.length === 0) {
      recentUsersPanel.style.display = "none";
      return;
    }

    SEEDED_PROFILES.forEach(function (profile) {
      const btn = document.createElement("button");
      btn.type = "button";
      btn.className = "recent-profile-btn";

      const avatar = document.createElement("img");
      avatar.className = "recent-profile-avatar";
      avatar.alt = "";
      avatar.loading = "lazy";
      avatar.referrerPolicy = "no-referrer";
      avatar.src = profile.avatar_url || "/default-avatar.png";

      const textWrap = document.createElement("span");
      textWrap.className = "recent-profile-text";

      const nameEl = document.createElement("span");
      nameEl.className = "recent-profile-name";
      nameEl.textContent = profile.display_name || profile.username || "";

      const userEl = document.createElement("span");
      userEl.className = "recent-profile-username";
      userEl.textContent = "@" + (profile.username || "");

      textWrap.appendChild(nameEl);
      textWrap.appendChild(userEl);
      btn.appendChild(avatar);
      btn.appendChild(textWrap);

      btn.addEventListener("click", async function () {
        btn.disabled = true;
        try {
          await autoLoginBySelector(profile.selector);
        } catch (err) {
          btn.disabled = false;
          emailInput.value = profile.username || "";
          emailInput.focus();
          emailInput.dispatchEvent(new Event("blur"));
          alert("Could not auto-login this profile. Please enter password.");
        }
      });
      recentUsersList.appendChild(btn);
    });

    recentUsersPanel.style.display = "block";
  }

  if (clearRecentUsersBtn) {
    clearRecentUsersBtn.addEventListener("click", async function () {
      try {
        const body = new URLSearchParams();
        body.set("nonce", AUTO_LOGIN_NONCE || "");
        await fetch("/includes/clear_profile_tokens.php", {
          method: "POST",
          headers: { "Content-Type": "application/x-www-form-urlencoded" },
          body: body.toString()
        });
      } finally {
        window.location.reload();
      }
    });
  }

  renderRecentProfiles();

  emailInput.addEventListener("blur", function () {
    const email = emailInput.value.trim();
    if (!email) return;

    fetch("/checkNdaStatus.php", {
      method: "POST",
      headers: { "Content-Type": "application/x-www-form-urlencoded" },
      body: `email=${encodeURIComponent(email)}`
    })
    .then(res => res.json())
    .then(data => {
      if (!data.accepted) {
        console.log("❗ NDA not accepted – showing checkbox");

        // Show the NDA UI
        if (ndaWrapper) {
          ndaWrapper.style.display = "block";
          ndaWrapper.style.visibility = "visible";
          ndaWrapper.style.opacity = "1";
        }

        if (ndaBox) {
          ndaBox.required = true;
        }

        if (ndaMessage) {
          ndaMessage.textContent = "⚠️ Please accept the Basic NDA before logging in.";
          ndaMessage.style.display = "block";
        }
      } else {
        console.log("✅ NDA already accepted – hiding checkbox");

        if (ndaWrapper) ndaWrapper.style.display = "none";
        if (ndaBox) ndaBox.required = false;
        if (ndaMessage) ndaMessage.style.display = "none";
      }
    })
    .catch(err => {
      console.error("❌ Error checking NDA status:", err);
    });
  });
});
</script>






</body>
</html>
