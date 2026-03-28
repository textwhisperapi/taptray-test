<?php
// Enforce HTTPS (proxy-aware) before sending any content.
$host = $_SERVER['HTTP_HOST'] ?? '';
$requestUri = $_SERVER['REQUEST_URI'] ?? '/';
$forwardedProto = strtolower(trim(explode(',', $_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '')[0] ?? ''));
$isHttps =
    (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ||
    (($_SERVER['SERVER_PORT'] ?? '') === '443') ||
    ($forwardedProto === 'https') ||
    (isset($_SERVER['HTTP_X_FORWARDED_SSL']) && strtolower($_SERVER['HTTP_X_FORWARDED_SSL']) === 'on') ||
    (isset($_SERVER['HTTP_CF_VISITOR']) && strpos($_SERVER['HTTP_CF_VISITOR'], '"https"') !== false);

$isLocalHost = ($host === 'localhost') ||
    (strpos($host, 'localhost:') === 0) ||
    (strpos($host, '127.0.0.1') === 0);

if (!$isLocalHost && !$isHttps && $host !== '') {
    header('Location: https://' . $host . $requestUri, true, 301);
    exit;
}

if (!$isLocalHost && $isHttps) {
    header('Strict-Transport-Security: max-age=31536000; includeSubDomains; preload');
}

header("Cache-Control: no-store, no-cache, must-revalidate, max-age=0");
header("Expires: 0");
header("Pragma: no-cache");
include_once __DIR__ . '/includes/db_connect.php';
include_once __DIR__ . '/includes/functions.php';
require_once __DIR__ . '/includes/translate.php';
require_once __DIR__ . '/chatConfig.php';
require_once __DIR__ . '/api/config_google.php';


cleanGhostCookies();
sec_session_start(); 


$listOwnerUsername = $_SESSION['username'] ?? '';

//Version is now se globally in service-worker.php
$version = 'v152';


header('Content-Type: text/html; charset=utf-8');

// Extract clean path
$segments = explode('/', trim(parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH), '/'));
$target = $segments[0] ?? '';
$surrogate = $segments[1] ?? '';
$owner = '';
$ownerDisplay = '';
$listName = '';

// Persist invite context for registration flow.
$inviteToken = $_GET['invite'] ?? '';
if ($target && !$surrogate && $inviteToken && preg_match('/^[a-f0-9]{64}$/', $inviteToken)) {
    $_SESSION['pending_invite_token'] = $inviteToken;
    $_SESSION['pending_invite_list_token'] = $target;
    $_SESSION['pending_invite_set_at'] = time();
}



if (
    $target === '' &&
    login_check($mysqli) &&
    isset($_SESSION['username'])
) {
    $redirectProfile = $_SESSION['username'];
    $safeCookieUser = preg_replace('/[^A-Za-z0-9_]/', '_', (string)$_SESSION['username']);
    $cookieKey = $safeCookieUser ? ('tw_last_profile_' . $safeCookieUser) : 'tw_last_profile';
    $cookieProfile = trim((string)($_COOKIE[$cookieKey] ?? ''));
    if (
        $cookieProfile !== '' &&
        strtolower($cookieProfile) !== 'welcome' &&
        preg_match('/^[A-Za-z0-9._-]{2,80}$/', $cookieProfile)
    ) {
        $redirectProfile = $cookieProfile;
    }
    header("Location: /" . urlencode($redirectProfile));
    exit;
}

$loggedIn = login_check($mysqli);

if (
    !$loggedIn &&
    strtolower((string)$target) === 'welcome' &&
    stripos((string)$host, 'taptray.com') !== false
) {
    header("Location: /", true, 302);
    exit;
}


// ✅ Fetch text content dynamically for Open Graph description
// Fetch text (raw HTML or plain)
$textContent = "";
$mysqli->set_charset("utf8mb4");

if ($stmt = $mysqli->prepare("SELECT LEFT(Text, 500) AS Text FROM text WHERE surrogate = ?")) {
    $stmt->bind_param("s", $surrogate);
    $stmt->execute();
    $result = $stmt->get_result();

    if ($row = $result->fetch_assoc()) {
        $textContent = trim($row['Text']);   // keep raw, no escaping here
    }

    $stmt->close();
}

// Fallback if missing
if (empty($textContent)) {
    $dynamicTitle = "TapTray - QR Menus and Instant Ordering";
    $previewText  = "Browse menus, select items, and pay quickly from your phone with TapTray.";
} else {

    //Split on <br> OR real newlines
    $parts = preg_split('/(<br\s*\/?>|\r\n|\n|\r)+/i', $textContent, 2);

    $firstLine = trim($parts[0]);
    $restLines = trim($parts[1] ?? '');

    // Clean them
    $dynamicTitle = og_clean($firstLine);
    $previewRaw   = og_clean($restLines);

    // Limit preview
    $previewText = mb_substr($previewRaw, 0, 300);

    // Final fallbacks
    if ($dynamicTitle === "") {
        $dynamicTitle = "TapTray - QR Menus and Instant Ordering";
    }
    if ($previewText === "") {
        $previewText = $dynamicTitle;
    }
}


//Set a proper fallback Open Graph image (600x315 required for Messenger)
$imageURL = "https://taptray.com/img/wrt.png"; 
//include_once __DIR__ . '/chat.php';


$siteName = $_SERVER['HTTP_HOST'];


//If no surrogate, treat as list link
if (!$surrogate && $target) {
    $listName = '';
    $owner = '';

    // ✅ Get list name and owner username
    if ($stmt = $mysqli->prepare("
        SELECT cl.name, m.username, m.display_name
        FROM content_lists cl
        JOIN members m ON cl.owner_id = m.id
        WHERE cl.token = ?
    ")) {
        $stmt->bind_param("s", $target);
        $stmt->execute();
        $stmt->bind_result($name, $username, $displayName);
        if ($stmt->fetch()) {
            $listName = trim($name);
            $owner = trim($username);
            $ownerDisplay = trim($displayName);
        }
        $stmt->close();
    }

    if (!empty($listName) && !empty($owner)) {
        $listNameEsc = htmlspecialchars($listName, ENT_QUOTES, 'UTF-8');
        $ownerEsc = htmlspecialchars($owner, ENT_QUOTES, 'UTF-8');

        $dynamicTitle = "$listNameEsc by $ownerEsc";
        $previewText = "A shared list on $siteName";
    }
}

$headerTitle = $lang['select_an_item'] ?? 'Select an item';
if (!empty($listName) && !empty($owner)) {
    $ownerLabel = $ownerDisplay ?: $owner;
    $headerTitle = ($target === $owner) ? $ownerLabel : ($ownerLabel . ' - ' . $listName);
}



// Default fallback
$locale = 'en';


// Check DB first and set session
$hasMemberGroupType = false;
$profileTypeValue = 'person';
$groupTypeValue = '';
if ($colRes = $mysqli->query("SHOW COLUMNS FROM members LIKE 'group_type'")) {
    $hasMemberGroupType = $colRes->num_rows > 0;
    $colRes->free();
}

if (!empty($_SESSION['username'])) {
    // Fetch from DB only if not already cached
    $selectGroupType = $hasMemberGroupType ? ", COALESCE(group_type, '') AS group_type" : ", '' AS group_type";
    $stmt = $mysqli->prepare("SELECT locale, display_name, fileserver, avatar_url, COALESCE(profile_type, 'person') AS profile_type{$selectGroupType} FROM members WHERE username = ?");
    $stmt->bind_param("s", $_SESSION['username']);
    $stmt->execute();
    $stmt->bind_result($fetchedLocale, $fetchedDisplayName, $fetchedFileserver, $fetchedAvatarUrl, $fetchedProfileType, $fetchedGroupType);

    if ($stmt->fetch()) {
        if (!empty($fetchedLocale)) {
            $locale = $fetchedLocale;
            $_SESSION['locale'] = $locale; // ✅ Cache locale
        }

        if (!empty($fetchedDisplayName)) {
            $_SESSION['display_name'] = $fetchedDisplayName; // ✅ Cache display name
        }

        if (!empty($fetchedFileserver)) {
            $_SESSION['fileserver'] = $fetchedFileserver; // ✅ Cache fileserver
        } else {
            $_SESSION['fileserver'] = 'php'; // default fallback
        }

        if (!empty($fetchedAvatarUrl)) {
            $_SESSION['avatar_url'] = $fetchedAvatarUrl; // ✅ Cache avatar url
        }
        if (!empty($fetchedProfileType)) {
            $profileTypeValue = strtolower(trim((string)$fetchedProfileType)) === 'group' ? 'group' : 'person';
        }
        if (!empty($fetchedGroupType)) {
            $groupTypeValue = strtolower(trim((string)$fetchedGroupType));
        }
    }

    $stmt->close();
}

$LoggedInUser   = htmlspecialchars($_SESSION['username'] ?? '');
$displayname    = htmlspecialchars($_SESSION['display_name'] ?? '');
$userFileserver = htmlspecialchars($_SESSION['fileserver'] ?? 'php');
$avatarUrl      = $_SESSION['avatar_url'] ?? '';
$avatarUrl      = $avatarUrl ?: '/default-avatar.png';
$avatarUrlSafe  = htmlspecialchars($avatarUrl, ENT_QUOTES, 'UTF-8');
$needsAvatarOnboarding = (trim((string)$avatarUrl) === '' || stripos((string)$avatarUrl, 'default-avatar.png') !== false);
$onboardingRequested = isset($_GET['tw_onboard']) && $_GET['tw_onboard'] === '1';
$showQuickOnboarding = $loggedIn && $onboardingRequested && $needsAvatarOnboarding;
$onboardOwnerToken = $target ?: '';
$onboardRoleOptions = [];
$onboardCurrentRole = '';
$onboardOwnerId = 0;
if ($showQuickOnboarding && !empty($_SESSION['user_id']) && $onboardOwnerToken !== '') {
    $ownerIdForOnboard = 0;
    if ($stmt = $mysqli->prepare("SELECT owner_id FROM content_lists WHERE token = ? LIMIT 1")) {
        $stmt->bind_param("s", $onboardOwnerToken);
        $stmt->execute();
        $stmt->bind_result($resolvedOwnerId);
        if ($stmt->fetch()) {
            $ownerIdForOnboard = (int)$resolvedOwnerId;
        }
        $stmt->close();
    }
    if ($ownerIdForOnboard <= 0 && $stmt = $mysqli->prepare("SELECT id FROM members WHERE username = ? LIMIT 1")) {
        $stmt->bind_param("s", $onboardOwnerToken);
        $stmt->execute();
        $stmt->bind_result($resolvedOwnerId);
        if ($stmt->fetch()) {
            $ownerIdForOnboard = (int)$resolvedOwnerId;
        }
        $stmt->close();
    }
    if ($ownerIdForOnboard > 0) {
        $onboardOwnerId = $ownerIdForOnboard;
        if ($stmt = $mysqli->prepare("
            SELECT name
            FROM ep_groups
            WHERE owner_id = ?
              AND is_role_group = 1
            ORDER BY sort_order IS NULL, sort_order ASC, name ASC
        ")) {
            $stmt->bind_param("i", $ownerIdForOnboard);
            $stmt->execute();
            $stmt->bind_result($roleName);
            while ($stmt->fetch()) {
                $roleName = trim((string)$roleName);
                if ($roleName !== '') {
                    $onboardRoleOptions[] = $roleName;
                }
            }
            $stmt->close();
        }
        if (empty($onboardRoleOptions) && $stmt = $mysqli->prepare("
            SELECT DISTINCT gm.role
            FROM ep_group_members gm
            JOIN ep_groups g ON g.id = gm.group_id
            WHERE g.owner_id = ?
              AND gm.role IS NOT NULL
              AND gm.role <> ''
            ORDER BY gm.role ASC
        ")) {
            $stmt->bind_param("i", $ownerIdForOnboard);
            $stmt->execute();
            $stmt->bind_result($roleName);
            while ($stmt->fetch()) {
                $roleName = trim((string)$roleName);
                if ($roleName !== '') {
                    $onboardRoleOptions[] = $roleName;
                }
            }
            $stmt->close();
        }
        if (!empty($onboardRoleOptions)) {
            $onboardRoleOptions = array_values(array_unique($onboardRoleOptions));
        }
        if ($stmt = $mysqli->prepare("
            SELECT gm.role
            FROM ep_group_members gm
            JOIN ep_groups g ON g.id = gm.group_id
            WHERE g.owner_id = ?
              AND g.is_all_members = 1
              AND gm.member_id = ?
            LIMIT 1
        ")) {
            $userIdForOnboard = (int)$_SESSION['user_id'];
            $stmt->bind_param("ii", $ownerIdForOnboard, $userIdForOnboard);
            $stmt->execute();
            $stmt->bind_result($existingRole);
            if ($stmt->fetch()) {
                $onboardCurrentRole = trim((string)$existingRole);
            }
            $stmt->close();
        }
    }
}
$guestLastProfile = trim((string)($_COOKIE['tw_last_profile'] ?? ''));
if ($guestLastProfile !== '' && !preg_match('/^[A-Za-z0-9._-]{2,80}$/', $guestLastProfile)) {
    $guestLastProfile = '';
}
$currentProfileUsername = $owner ?: ($target ?: '');
$currentProfileDisplay = '';
$currentProfileAvatar = '/default-avatar.png';
$hasCurrentProfile = !empty($currentProfileUsername);
if ($hasCurrentProfile) {
    $stmt = $mysqli->prepare("SELECT display_name, avatar_url FROM members WHERE username = ?");
    $stmt->bind_param("s", $currentProfileUsername);
    $stmt->execute();
    $stmt->bind_result($currentDisplayName, $currentAvatarUrl);
    if ($stmt->fetch()) {
        if (!empty($currentDisplayName)) {
            $currentProfileDisplay = $currentDisplayName;
        }
        if (!empty($currentAvatarUrl)) {
            $currentProfileAvatar = $currentAvatarUrl;
        }
    }
    $stmt->close();
}
$currentProfileLabel = $currentProfileDisplay ?: $currentProfileUsername;
$currentProfileLabelSafe = htmlspecialchars($currentProfileLabel, ENT_QUOTES, 'UTF-8');
$currentProfileAvatarSafe = htmlspecialchars($currentProfileAvatar, ENT_QUOTES, 'UTF-8');
$homeUserLabel = $displayname ?: $LoggedInUser;
$homeUserLabelSafe = htmlspecialchars($homeUserLabel, ENT_QUOTES, 'UTF-8');
$homeUserLink = !empty($_SESSION['username']) ? ("/" . rawurlencode((string)$_SESSION['username'])) : "/";
$currentProfileLink = !empty($currentProfileUsername) ? ("/" . rawurlencode($currentProfileUsername)) : "/";

$role      = $_SESSION['role'] ?? 'guest';
$username  = $_SESSION['username'] ?? '';
$isAdmin   = !empty($_SESSION['is_admin']);

// ✅ Load language file
$langFile = __DIR__ . "/lang/{$locale}.php";
$lang = file_exists($langFile) ? include $langFile : [];


function og_clean($text) {
    //Cleaning special characters from text 
    //before used in meta title and first line of text for FB, messenger etc. 
    
    // Decode &lt;br&gt; etc.
    $text = html_entity_decode($text, ENT_QUOTES, 'UTF-8');

    // Remove all forms of <br ...> (even broken ones)
    $text = preg_replace('/<\s*br[^>]*>/i', ' ', $text);

    // Strip remaining HTML
    $text = strip_tags($text);

    // Collapse whitespace
    $text = preg_replace('/\s+/', ' ', $text);

    return trim($text);
} 

?>

<!DOCTYPE html>
<html lang="<?= htmlspecialchars($locale, ENT_QUOTES, 'UTF-8') ?>">
<head>
  <meta charset="UTF-8">
  
<script>
window.DEV_MODE = <?= (
  $_SERVER['HTTP_HOST'] === 'localhost' ||
  $_SERVER['HTTP_HOST'] === 'skolaspjall.is'
) ? 'true' : 'false' ?>;
</script>


  <!-- 🧩 Inject runtime context -->
  <script>
    window.currentListToken = <?= json_encode($target) ?>;
    window.currentSurrogate = <?= json_encode($surrogate) ?>;
    window.SESSION_USERNAME = <?= json_encode($LoggedInUser) ?>;
    window.SESSION_DISPLAY_NAME = <?= json_encode($displayname) ?>;
    window.SESSION_AVATAR_URL = <?= json_encode($avatarUrl) ?>;
    window.fileServer = <?= json_encode($userFileserver) ?>;
    window.currentUsername = <?= json_encode($username) ?>;
    window.currentUserRole = <?= json_encode($role) ?>;
    window.isAdminUser = <?= $isAdmin ? 'true' : 'false' ?>;
    window.currentProfileUsername = <?= json_encode($currentProfileUsername) ?>;
    window.currentProfileToken = <?= json_encode($currentProfileUsername) ?>;
    window.CURRENT_PROFILE_AVATAR_URL = <?= json_encode($currentProfileAvatar) ?>;
    window.currentLocale = <?= json_encode($locale) ?>;
    window.translations = <?= json_encode($lang) ?>;
    window.homeToLabel = <?= json_encode($lang['home_to'] ?? 'Home to:') ?>;
    window.TW_SHOW_QUICK_ONBOARDING = <?= $showQuickOnboarding ? 'true' : 'false' ?>;
    window.TW_ONBOARD_OWNER_TOKEN = <?= json_encode($onboardOwnerToken) ?>;
    window.TW_ONBOARD_OWNER_ID = <?= (int)$onboardOwnerId ?>;
    window.TW_ONBOARD_ROLE_OPTIONS = <?= json_encode(array_values($onboardRoleOptions)) ?>;
    window.TW_ONBOARD_CURRENT_ROLE = <?= json_encode($onboardCurrentRole) ?>;

    // Google (client-side only)
    window.GOOGLE_CLIENT_ID = <?= json_encode(GOOGLE_CLIENT_ID) ?>; 
  </script>



<script>
  window.TW_DEBUG = <?= json_encode((stripos($_SERVER['HTTP_HOST'] ?? '', 'test.taptray.com') !== false)) ?>;
</script>
  
  
<script>
(function () {

  // STOP EVERYTHING if debug is off
  if (!window.TW_DEBUG) {
    console.log  = () => {};   // stop all your console.log debug output
    console.info = () => {};   // stop info-level messages (optional)
    window.logStep  = () => {};   // disable timing helper
    console.time    = () => {};   // disable timers
    console.timeEnd = () => {};   // disable timers
    return;
  }

  // Save originals
  const _log  = console.log;
  const _info = console.info;
  const _warn = console.warn;
  const _timeEnd = console.timeEnd;
  const _time = console.time;

  // ===== GLOBAL TIMING =====
  window.TW_START = performance.now();
  window.TW_LAST  = window.TW_START;

  window.logStep = function (label) {
    const now = performance.now();
    const total = (now - TW_START).toFixed(1);
    const diff  = (now - TW_LAST).toFixed(1);
    TW_LAST = now;
    _warn.call(console, `⏱ ${label}: +${diff} ms (total ${total} ms)`);
  };

  window.addEventListener("error", function (event) {
    _warn.call(console, "❌ window.error", {
      message: event.message || "",
      source: event.filename || "",
      line: event.lineno || 0,
      column: event.colno || 0
    });
  });

  window.addEventListener("unhandledrejection", function (event) {
    _warn.call(console, "❌ unhandledrejection", event.reason || event);
  });

  console.time = function(label) {
    _time.call(console, label);
  };

  console.timeEnd = function(label) {
    _timeEnd.call(console, label);
    _warn.call(console, `⏱ timer: ${label}`);
  };

  console.log = function (...args) {
    const msg = args[0];
    if (typeof msg === "string" && msg.includes("⏱")) {
      _log.apply(console, args);
      return;
    }
    if (msg === "__FORCE_LOG__") {
      _log.apply(console, args.slice(1));
      return;
    }
  };

})();
</script>


<script>
//   logStep("A: head + inline scripts parsed");
</script>





  <meta http-equiv="Content-Type" content="text/html; charset=UTF-8">
  <meta id="viewportMeta" name="viewport" content="width=device-width, initial-scale=1.0, user-scalable=yes">
  <script>
  (function () {
    const isStandalone =
      (window.matchMedia && window.matchMedia("(display-mode: standalone)").matches) ||
      window.navigator.standalone === true;
    if (!isStandalone) return;

    const viewportMeta = document.getElementById("viewportMeta");
    if (viewportMeta) {
      viewportMeta.setAttribute(
        "content",
        "width=device-width, initial-scale=1.0, maximum-scale=1.0, user-scalable=no, viewport-fit=cover"
      );
    }

    const preventGestureZoom = (e) => {
      if (e.cancelable) e.preventDefault();
    };
    document.addEventListener("gesturestart", preventGestureZoom, { passive: false });
    document.addEventListener("gesturechange", preventGestureZoom, { passive: false });
    document.addEventListener("gestureend", preventGestureZoom, { passive: false });
  })();
  </script>

  
  <title><?= htmlspecialchars(og_clean($dynamicTitle), ENT_QUOTES, 'UTF-8'); ?></title>

  <meta property="og:title"
      content="<?= htmlspecialchars(og_clean($dynamicTitle), ENT_QUOTES, 'UTF-8'); ?>" />

  <meta property="og:description"
      content="<?= htmlspecialchars(og_clean($previewText), ENT_QUOTES, 'UTF-8'); ?>" />

  
  <meta property="og:type" content="article" />
  <meta property="og:site_name" content="TapTray" />
  
    <meta property="og:image" content="https://taptray.com/img/wrt.png" />
    <meta property="og:image:width" content="1200" />
    <meta property="og:image:height" content="630" />
    <meta property="og:image:type" content="image/webp" />
  
  
  
    <!--For iPhone-->
    <meta name="apple-mobile-web-app-capable" content="yes">
    <meta name="apple-mobile-web-app-status-bar-style" content="black-translucent">  
  
    <!--For offline-->
    <link rel="manifest" href="/manifest-v6.json">
    <link rel="icon" href="/favicon-v6.ico" type="image/x-icon">
    <link rel="apple-touch-icon" href="/icons/wrt.png">
    <meta name="theme-color" content="#222831">
    <meta name="mobile-web-app-capable" content="yes">
    <meta name="apple-mobile-web-app-status-bar-style" content="black-translucent">


  <!--<script src="https://code.jquery.com/jquery-3.6.0.min.js"></script>-->
  <!--<script src="/assets/jquery.min.js"></script>-->

  <!-- ✅ Required URL (Now placed correctly) -->
  <meta property="og:url" content="https://textwhisper.com<?php echo $_SERVER['REQUEST_URI']; ?>" />
  <!-- ✅ Facebook App ID (Optional) -->
  <!--meta property="fb:app_id" content="YOUR_FACEBOOK_APP_ID" /-->

  <!-- Bootstrap & Styles -->
  <!--<link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">-->
  <link href="/assets/bootstrap.min.css" rel="stylesheet">
  <!--<script src="/assets/bootstrap.bundle.min.js"></script>-->
      
    <script src="/assets/bootstrap.bundle.min.js" defer></script>
    <script src="/assets/jquery.min.js?v=..." defer></script>
    <script src="/assets/pdf.min.js" defer></script>
    <script src="/assets/sortable.min.js" defer></script>

    <script src="https://apis.google.com/js/api.js" defer></script>
    <script src="https://accounts.google.com/gsi/client" defer></script>





   
  <link rel="stylesheet" href="/myStyles.css?v=<?= $version ?>">
  <link rel="stylesheet" href="/myStylesText.css?v=<?= $version ?>">
  <link rel="stylesheet" href="/myStylesPalette.css?v=<?= $version ?>">
  <link rel="stylesheet" href="/chatStyles.css?v=<?= $version ?>">
  <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/flatpickr/dist/flatpickr.min.css">

  <style>
    .tw-onboard-overlay { position: fixed; inset: 0; background: radial-gradient(circle at 20% 20%, rgba(66,133,244,.28), transparent 45%), radial-gradient(circle at 80% 85%, rgba(15,157,88,.20), transparent 40%), rgba(8,12,20,.62); display: none; align-items: center; justify-content: center; z-index: 12000; padding: 18px; backdrop-filter: blur(3px); }
    .tw-onboard-overlay.is-open { display: flex; }
    .tw-onboard-card { width: min(500px, 100%); background: linear-gradient(180deg, #ffffff 0%, #f8fbff 100%); border: 1px solid #dbe6f4; border-radius: 18px; padding: 20px; box-shadow: 0 20px 60px rgba(6,20,46,.28); color: #1d232f; animation: twOnboardAppear .22s ease-out; }
    .tw-onboard-card h3 { margin: 0 0 8px; font-size: 24px; font-weight: 700; letter-spacing: .1px; }
    .tw-onboard-card p { margin: 0 0 14px; color: #5a667d; font-size: 14px; }
    .tw-onboard-card label { font-weight: 700; font-size: 13px; margin: 11px 0 6px; display: block; color: #344055; text-transform: uppercase; letter-spacing: .45px; }
    .tw-onboard-card input, .tw-onboard-card select { width: 100%; border: 1px solid #c9d7ea; border-radius: 11px; padding: 10px 11px; background: #fff; box-shadow: inset 0 1px 0 rgba(255,255,255,.8); }
    .tw-onboard-card input:focus, .tw-onboard-card select:focus { outline: 0; border-color: #4684f0; box-shadow: 0 0 0 3px rgba(70,132,240,.16); }
    .tw-onboard-avatar-panel { display: flex; align-items: center; gap: 14px; padding: 12px; border: 1px solid #d4e2f4; border-radius: 12px; background: linear-gradient(135deg, #f6fbff 0%, #eef7ff 100%); margin-bottom: 10px; }
    .tw-onboard-avatar-panel img { width: 64px; height: 64px; border-radius: 50%; object-fit: cover; border: 2px solid #fff; box-shadow: 0 6px 18px rgba(44,100,183,.22); background: #eef2f8; }
    .tw-onboard-avatar-title { font-weight: 700; font-size: 15px; color: #1f2f4a; }
    .tw-onboard-avatar-hint { font-size: 12px; color: #66748b; }
    .tw-onboard-cropper { display: none; margin-top: 8px; }
    .tw-onboard-cropper.is-visible { display: block; }
    .tw-onboard-cropper canvas { width: 180px; height: 180px; border-radius: 50%; border: 1px solid #cfd6e3; background: #f2f5fa; box-shadow: inset 0 0 0 1px #fff, 0 6px 20px rgba(31,58,109,.14); touch-action: none; cursor: grab; }
    .tw-onboard-cropper canvas:active { cursor: grabbing; }
    .tw-onboard-cropper input[type="range"] { margin-top: 8px; }
    .tw-onboard-actions { margin-top: 14px; display: flex; gap: 10px; justify-content: flex-end; }
    .tw-onboard-actions button { border: 0; border-radius: 11px; padding: 10px 16px; font-weight: 700; letter-spacing: .2px; }
    #twOnboardSkipBtn { background: #e8eef7; color: #2a3850; }
    #twOnboardSkipBtn:hover { background: #dfe8f4; }
    #twOnboardSaveBtn { background: linear-gradient(135deg, #2f7cf6 0%, #1f68ea 100%); color: #fff; box-shadow: 0 8px 18px rgba(47,124,246,.32); }
    #twOnboardSaveBtn:hover { filter: brightness(1.04); }
    #twOnboardStatus { display: block; min-height: 20px; margin-top: 8px; font-size: 13px; font-weight: 600; }
    @keyframes twOnboardAppear { from { transform: translateY(8px) scale(.985); opacity: 0; } to { transform: translateY(0) scale(1); opacity: 1; } }
    @media (max-width: 560px) {
      .tw-onboard-card { padding: 16px; border-radius: 14px; }
      .tw-onboard-card h3 { font-size: 21px; }
      .tw-onboard-avatar-panel img { width: 56px; height: 56px; }
      .tw-onboard-cropper canvas { width: 160px; height: 160px; }
    }
  </style>


<script>
document.addEventListener("DOMContentLoaded", () => {
  logStep("B: DOMContentLoaded fired");
});
</script>
  
</head>



<body 




  class="<?php echo isset($_SESSION['user_id']) ? 'logged-in' : 'not-logged-in'; ?>"
  data-app-mode="taptray"
  data-logged-in-user="<?php echo htmlspecialchars($_SESSION['username'] ?? '', ENT_QUOTES, 'UTF-8'); ?>"
  data-user-id="<?php echo htmlspecialchars($_SESSION['user_id'] ?? '', ENT_QUOTES, 'UTF-8'); ?>">


<!-- 🚀 Navbar -->
<nav class="navbar navbar-dark bg-dark px-0">
  <div class="container-fluid d-flex align-items-center justify-content-between">

    <!-- 🔹 Home Icon (left aligned) -->
    <?php if ($loggedIn): ?>
      <div class="dropdown">
        <button class="icon-button dropdown-toggle btn btn-dark p-0" type="button" data-bs-toggle="dropdown" title="<?= $lang['home'] ?? 'Home' ?>">
          <i data-lucide="home" class="lucide-icon"></i>
        </button>
        <ul class="dropdown-menu dropdown-menu-start bg-dark text-light border-secondary">
          <li>
            <a class="dropdown-item d-flex align-items-center gap-2" href="<?= htmlspecialchars($homeUserLink, ENT_QUOTES, 'UTF-8') ?>">
              <img class="home-avatar" src="<?= $avatarUrlSafe ?>" alt="My avatar" onerror="this.onerror=null;this.src='/default-avatar.png';">
              <?= $lang['home_to'] ?? 'Home to:' ?> <?= $homeUserLabelSafe ?>
            </a>
          </li>
          <?php if ($hasCurrentProfile && $currentProfileUsername !== $username): ?>
            <li id="homeCurrentProfileItem">
              <a id="homeCurrentProfileLink" class="dropdown-item d-flex align-items-center gap-2" href="<?= htmlspecialchars($currentProfileLink, ENT_QUOTES, 'UTF-8') ?>">
                <img id="homeCurrentProfileAvatar" class="home-avatar" src="<?= $currentProfileAvatarSafe ?>" alt="Current profile avatar" onerror="this.onerror=null;this.src='/default-avatar.png';">
                <?= $lang['home_to'] ?? 'Home to:' ?> <span id="homeCurrentProfileLabel"><?= $currentProfileLabelSafe ?></span>
              </a>
            </li>
          <?php else: ?>
            <li id="homeCurrentProfileItem" style="display:none;">
              <a id="homeCurrentProfileLink" class="dropdown-item d-flex align-items-center gap-2" href="/">
                <img id="homeCurrentProfileAvatar" class="home-avatar" src="/default-avatar.png" alt="Current profile avatar" onerror="this.onerror=null;this.src='/default-avatar.png';">
                <?= $lang['home_to'] ?? 'Home to:' ?> <span id="homeCurrentProfileLabel"><?= $lang['profile'] ?? 'Profile' ?></span>
              </a>
            </li>
          <?php endif; ?>
          <li id="homeRecentProfilesMount" style="display:none;"></li>
        </ul>
      </div>
    <?php else: ?>
      <a href="/" class="d-flex align-items-center" title="<?= $lang['home'] ?? 'Home' ?>">
        <i data-lucide="home" class="lucide-icon"></i>
      </a>
    <?php endif; ?>

    <?php if ($loggedIn): ?>
    <button type="button" class="btn play-mode-btn ms-2" id="playModeButton">Live</button>

    <div class="dropdown ms-1" id="playOwnerDropdown" style="display:none;">
      <button class="btn btn-dark p-0 border-0 play-owner-btn" type="button" id="playOwnerBtn" data-bs-toggle="dropdown" aria-expanded="false" title="Play mode owner">
        <img id="playOwnerAvatar" src="/default-avatar.png" alt="Play owner avatar" class="play-owner-avatar" onerror="this.onerror=null;this.src='/default-avatar.png';">
      </button>
      <ul class="dropdown-menu dropdown-menu-start bg-dark text-light border-secondary" id="playOwnerMenu">
        <li><span class="dropdown-item-text text-light" id="playOwnerLabel">Play owner</span></li>
        <li><button type="button" class="dropdown-item text-danger" id="playStopBtn">Stop live mode</button></li>
        <li>
          <div class="dropdown-item">
            <div class="form-check form-switch ms-1">
              <input class="form-check-input follow-paging-toggle" type="checkbox" id="toggleFollowPagingPlay">
              <label class="form-check-label" for="toggleFollowPagingPlay">
                <?= $lang['follow_conductor_paging'] ?? 'Follow conductor paging' ?>
              </label>
            </div>
          </div>
        </li>
        <li><hr class="dropdown-divider border-secondary"></li>
        <li><span class="dropdown-item-text text-light small">Admins</span></li>
        <li><div id="playAdminList" class="play-admin-list"></div></li>
      </ul>
    </div>
    <?php endif; ?>

    <!-- 🔹 Title Tab -->
    <button class="nav-link active text-tab flex-grow-1 text-center" data-target="textTab">
      <span id="selectedItemTitle" class="text-truncate d-inline-block">
        <?= htmlspecialchars($headerTitle, ENT_QUOTES, 'UTF-8') ?>
      </span>
    </button>
    <button id="headerRefreshBtn" class="icon-button btn btn-dark p-0 ms-2" type="button" title="<?= htmlspecialchars($lang['reload_page'] ?? 'Reload page', ENT_QUOTES, 'UTF-8') ?>" aria-label="<?= htmlspecialchars($lang['reload_page'] ?? 'Reload page', ENT_QUOTES, 'UTF-8') ?>">
      <i data-lucide="refresh-cw" class="lucide-icon"></i>
    </button>

    <!-- 🔹 Edit toggle + Settings -->
    <div class="d-flex align-items-center gap-3" style="z-index: 1102; position: relative;">

      <?php if ($loggedIn): ?>
      <div class="edit-controls form-check form-switch">
        <label class="form-check-label text-light small" style="font-size:14px;" for="editModeToggle">Edit</label>
        <input class="form-check-input edit-mode-toggle" style="margin-left: 0; margin-right:-8px;" type="checkbox" id="editModeToggle">
      </div>
      <?php endif; ?>

      <!-- ⚙️ Settings Dropdown -->
      <div class="dropdown">
        <?php if ($loggedIn): ?>
          <button class="settings-icon dropdown-toggle btn btn-dark p-0" type="button" data-bs-toggle="dropdown" title="<?= $lang['settings'] ?? 'Settings' ?>">
            <img class="settings-avatar" src="<?= $avatarUrlSafe ?>" alt="<?= $LoggedInUser ? ($LoggedInUser . ' avatar') : 'User avatar' ?>" onerror="this.onerror=null;this.src='/default-avatar.png';" style="width:24px;height:24px;border-radius:50%;object-fit:cover;display:block;">
          </button>
        <?php else: ?>
          <button class="settings-icon dropdown-toggle btn btn-dark p-0" type="button" data-bs-toggle="dropdown" title="<?= $lang['settings'] ?? 'Settings' ?>">
            <i data-lucide="settings" class="lucide-icon"></i>
          </button>
        <?php endif; ?>

        <ul class="dropdown-menu dropdown-menu-end bg-dark text-light border-secondary">

          <li>
              <a href="<?= htmlspecialchars($homeUserLink, ENT_QUOTES, 'UTF-8') ?>" class="dropdown-item" title="<?= $lang['home'] ?? 'Home' ?>"><i data-lucide="home" class="me-1"></i> <?= $lang['home_to'] ?? 'Home to:' ?> <?= htmlspecialchars($LoggedInUser) ?></a>
          </li>


          <li>
            <a class="dropdown-item" href="/login.php">
              <i data-lucide="key" class="me-1"></i> <?= $loggedIn ? ($lang['logout'] ?? 'Logout') : ($lang['login'] ?? 'Login') ?>
            </a>
          </li>

          <?php if ($loggedIn): ?>
          <li>
            <a class="dropdown-item" href="/login.php?switch=1">
              <i data-lucide="users" class="me-1"></i> <?= $lang['switch_profile'] ?? 'Switch profile' ?>
            </a>
          </li>
          <?php endif; ?>

          <li><a class="dropdown-item" href="/sub_settings.php"><i data-lucide="user" class="me-1"></i> <?= $lang['my_profile'] ?? 'My Profile' ?></a></li>
          <li><a class="dropdown-item" href="/sub_pricing.php"><i data-lucide="credit-card" class="me-1"></i> <?= $lang['pricing'] ?? 'Pricing' ?></a></li>
          <li><a class="dropdown-item" href="/includes/about.php"><i data-lucide="help-circle" class="me-1"></i> <?= $lang['about'] ?? 'About' ?></a></li>
          <li><a class="dropdown-item" href="/includes/features.php"><i data-lucide="layers" class="me-1"></i> <?= $lang['features'] ?? 'Features' ?></a></li>
          <li><a class="dropdown-item" href="/includes/about_edge.php"><i data-lucide="book" class="me-1"></i> <?= $lang['advantages'] ?? 'Advantages' ?></a></li>
          <li id="installAppMenuItem" class="dropdown-item" style="cursor: pointer;"><i data-lucide="download" class="me-1"></i> <?= $lang['install_app'] ?? 'Install App' ?></li>
          
          <!--<li class="dropdown-item" onclick="resetApp()"><i data-lucide="trash-2" class="me-1"></i> <?= $lang['reset_cache'] ?? 'Reset App Cache' ?></li>-->
            
            <li class="dropdown-item">
              <div class="form-check form-switch ms-1">
                <input class="form-check-input" type="checkbox" id="togglePagedPDF">
                <label class="form-check-label" for="togglePagedPDF">
                  <?= $lang['paged_pdf_view'] ?? 'Paged PDF view' ?>
                </label>
              </div>
            </li>
            <?php if ($loggedIn): ?>
            <li>
              <div class="dropdown-item">
                <div class="edit-controls form-check form-switch ms-1">
                  <input class="form-check-input edit-mode-toggle" type="checkbox" id="editToggle">
                  <label class="form-check-label" for="editToggle">
                    <i data-lucide="pencil" class="me-1"></i> <?= $lang['edit_mode'] ?? 'Edit mode' ?>
                  </label>
                </div>
              </div>
            </li>
            <li>
              <div class="dropdown-item">
                <div class="play-controls form-check form-switch ms-1">
                  <input class="form-check-input play-mode-toggle" type="checkbox" id="playToggle">
                  <label class="form-check-label" for="playToggle">
                    <i data-lucide="radio" class="me-1"></i> <?= $lang['play_mode'] ?? 'Play mode' ?>
                  </label>
                </div>
              </div>
            </li>
            <?php endif; ?>
            <li class="dropdown-item">
              <div class="form-check form-switch ms-1">
                <input class="form-check-input follow-paging-toggle" type="checkbox" id="toggleFollowPaging">
                <label class="form-check-label" for="toggleFollowPaging">
                  <?= $lang['follow_conductor_paging'] ?? 'Follow conductor paging' ?>
                </label>
              </div>
            </li>
            
            <li class="dropdown-item">
              <div class="settings-section w-100">
                <label for="fontSizeSlider" class="form-label text-light mb-2 d-block">
                  <?= $lang['zoom_text'] ?? 'Zoom text' ?>
                </label>
                <input type="range" id="fontSizeSlider" min="14" max="40" step="1" value="16" class="form-range">
              </div>
            </li>
            
            <li class="dropdown-item">
              <div class="settings-section w-100">
                <label for="envSizeSlider" class="form-label text-light mb-2 d-block">
                  <?= $lang['zoom_env'] ?? 'Zoom menus' ?>
                </label>
                <input type="range" id="envSizeSlider" min="12" max="28" step="1" value="16" class="form-range">
              </div>
            </li>
            
            <li>
              <a class="dropdown-item" href="#" id="colorSettingsToggle">
                <i data-lucide="palette" class="me-1"></i> <?= $lang['color_settings'] ?? 'Color Settings' ?>
              </a>
            </li>

            <li class="dropdown-item"><i data-lucide="info" class="me-1"></i> <?= $lang['version'] ?? 'Version' ?> <?= $version ?></li>

        </ul>
      </div>
    </div>

  </div>
</nav>





<div id="findReplaceToolbar" style="display:none; gap:6px; padding:6px; background:#f5f5f5; border-bottom:1px solid #ccc;">
  <input type="text" id="findInput" placeholder="Find..." style="flex:1; padding:4px;">
  <input type="text" id="replaceInput" placeholder="Replace with..." style="flex:1; padding:4px;">
  <button id="findNextBtn">Find Next</button>
  <button id="replaceBtn">Replace</button>
  <button id="replaceAllBtn">Replace All</button>
</div>



<div class="sidebar-and-content">
    
    
    <!-- 🚀 Sidebar with Tabs -->
    <div id="sidebarContainer" class="sidebar">
      <div id="sidebarDragHandle"></div>
        
      <div class="sidebar-header">
        <div class="skin-picker-bar in-sidebar" id="skinPickerBar">
          <button class="skin-swatch" data-skin="legacy-dark" title="Legacy Dark"></button>
          <button class="skin-swatch" data-skin="silver" title="Silver"></button>
          <button class="skin-swatch" data-skin="gold" title="Gold"></button>
          <button class="skin-swatch" data-skin="blue" title="Blue"></button>
          <button class="skin-swatch" data-skin="rose" title="Rose"></button>
          <button class="skin-swatch" data-skin="green" title="Green"></button>
          <button class="skin-swatch" data-skin="red" title="Red"></button>
          <button class="skin-swatch" data-skin="purple" title="Purple"></button>
        </div>
          <div class="create-dropdown">
            <button id="createButton"
                    class="create-btn"
                    onclick="toggleCreateMenu(this); event.stopPropagation();">
              <?= $lang['create'] ?? 'Create' ?>
            </button>
            <div class="create-menu">

              <div class="menu-item"
                  onclick="closeCreateMenu(); createNewList('owned-<?= $username ?>'); event.stopPropagation();">
                🆕 <?= $lang['create_new_list'] ?? 'Create New List' ?>
              </div>

              <div class="menu-item"
                  onclick="closeCreateMenu(); createNewItemFromMenu(); event.stopPropagation();">
                ➕ <?= $lang['create_new_item'] ?? 'New Item' ?>
              </div>

              <div class="menu-item"
                  onclick="closeCreateMenu(); openImportItemFilesModal(); event.stopPropagation();">
                📁 <?= ($lang['import'] ?? 'Import') . ': ' . ($lang['import_from_device'] ?? 'this device') ?>
              </div>

              <div class="menu-item"
                  onclick="closeCreateMenu(); openDriveImportOverlay('tw'); event.stopPropagation();">
                <img src="/img/wrt.png"
                    style="height:18px; vertical-align:middle; margin-right:6px;">
                <?= ($lang['import'] ?? 'Import') . ': TapTray' ?>
              </div>

              <div class="menu-item"
                  onclick="closeCreateMenu(); openDriveImportOverlay('dropbox'); event.stopPropagation();">
                <img src="/icons/dropbox_0061ff.svg"
                    style="height:18px; vertical-align:middle; margin-right:6px;">
                <?= ($lang['import'] ?? 'Import') . ': Dropbox' ?>
              </div>

              <div class="menu-item"
                  onclick="closeCreateMenu(); openDriveImportOverlay('google'); event.stopPropagation();">
                <img src="/icons/googledrive.png"
                    style="height:14px; vertical-align:middle; margin-right:6px;">
                <?= ($lang['import'] ?? 'Import') . ': Google Drive' ?>
              </div>

              <div class="menu-item"
                  onclick="closeCreateMenu(); openDriveImportOverlay('onedrive'); event.stopPropagation();">
                <img src="/icons/onedrive2.png"
                    style="height:18px; vertical-align:middle; margin-right:6px;">
                <?= ($lang['import'] ?? 'Import') . ': OneDrive' ?>
              </div>

              <div class="menu-item"
                  onclick="closeCreateMenu(); openDriveImportOverlay('icloud'); event.stopPropagation();">
                <img src="/icons/icloud2.png"
                    style="height:12px; vertical-align:middle; margin-right:6px;">
                <?= ($lang['import'] ?? 'Import') . ': iCloud' ?>
              </div>

            </div>


          </div>
        </div>



      <!-- 🔹 Sidebar Search Box -->
      <div class="sidebar-search">
        <input type="text" id="searchSidebar" 
               placeholder="🔍 <?= $lang['search'] ?? 'Search...' ?>" 
               class="search-input">
      </div>
    
      <!-- 🔹 Sidebar Tabs -->
      <div class="sidebar-tabs" role="tablist">
        <button class="tab-link active" data-target="listsTab" role="tab">
          <i data-lucide="book-open"></i> Menu
        </button>
        <button class="tab-link" data-target="usersTab" role="tab">
          <i data-lucide="calendar-days"></i> Reservations
        </button>
      </div>
    
      <!-- 🔹 Sidebar Tab Content -->
      <div class="sidebar-content">
    
        <!-- 📚 Lists Tab (Default) -->
        <div id="listsTab" class="sidebar-tab-content active" role="tabpanel">
          <div class="sidebar-tab-header">
            <!--<div class="sidebar-section-header">Lists by: "<span id="realUserDisplay">Loading...</span>"</div>-->
          </div>
    
          <div class="scrollable-list-area">
            <div id="offlineLists" class="list-group"></div>
            <div id="followedListsContainer" class="list-group"></div>
            <div id="listManager" class="list-group"><?= $lang['loading_lists'] ?? 'Loading list...' ?></div>
            <div id="taptrayOrderBar" class="taptray-order-bar" hidden>
              <div class="taptray-order-head">
                <button id="taptrayOrderToggle" class="taptray-order-toggle" type="button" aria-expanded="false">
                  <span id="taptrayOrderChevron" class="taptray-order-chevron">▸</span>
                  <span class="taptray-order-copy">
                    <div id="taptrayOrderTitle" class="taptray-order-title">Order</div>
                    <div id="taptrayOrderMeta" class="taptray-order-meta">No items selected</div>
                  </span>
                </button>
                <button id="taptrayPayBtn" class="taptray-pay-btn" type="button">Pay</button>
              </div>
              <div id="taptrayOrderItems" class="taptray-order-items" hidden></div>
            </div>
          </div>
        </div>
    
        <!-- 👤 Users Tab -->
        <div id="usersTab" class="sidebar-tab-content hidden" role="tabpanel">
          <h6><?= $lang['users'] ?? 'Users' ?></h6>
          <div class="scrollable-list-area">
            <div id="userList" class="list-group"><?= $lang['loading_users'] ?? 'Loading users...' ?></div>
          </div>
        </div>
    
      </div>
    </div>
    
    
    
    <!-- Floating toggle button -->
    <button id="floatBtn" class="minimal-toggle" aria-label="Open Sidebar">&#x22EE;</button>
    
    
    
    

    <!-- 🏠 Main Content Wrapper -->
    <div id="mainContentWrapper" class="main-container">
    
        <!--<div id="textToolbar" class="top-edit-toolbar" style="display: none;">-->
        <!--    <button id="newButton" class="edit-btn new-btn">➕ <?= $lang['new'] ?? 'New' ?></button>-->
        <!--    <button id="saveButton" class="edit-btn save-btn" disabled>💾 <?= $lang['save'] ?? 'Save' ?></button>-->
        <!--    <button id="deleteButton" class="edit-btn delete-btn" disabled>🗑 <?= $lang['delete'] ?? 'Delete' ?></button>-->
        <!--    <button id="toggleCommentPalette" class="edit-btn comment-btn" title="Comments"><i data-lucide="message-square"></i></button>-->
        <!--</div>  -->
        
    
        <!-- TapTray item details workspace -->

        <!-- Hidden annotation action hooks (temporary) -->
        <div style="display:none;">
          <button id="saveAnnotation"></button>
          <button id="clearAnnotation"></button>
          <button id="undoAnnotation"></button>
          <button id="refreshAnnotation"></button>
          <button id="exportPdfBtn" onclick="exportPDF()"></button>
          <button id="printPdfBtn" onclick="printPDF()"></button>
          <button id="updatePdfBtn"></button>
        </div>

    
        <!-- 🚀 Text Content (Default View) -->
        <div id="textTabContent" class="main-tab-content active">
<div class="tt-item-details">
  <div class="tt-item-shell">
    <div class="tt-item-main">
      <div class="tt-item-header">
        <div>
          <div class="tt-item-kicker">TapTray</div>
          <h2>Legacy text area removed</h2>
        </div>
      </div>
      <div class="taptray-tree-item-description">
        Select a menu item to open the menu design view. The old dual-textarea editor is no longer part of this screen.
      </div>
    </div>
  </div>
</div>

        </div>
    
        <!-- TapTray item details container -->
        <div id="pdfTabContent" class="main-tab-content">
          <div id="taptrayItemDetails" class="tt-item-details">
            <div class="tt-item-shell">
              <div id="ttItemMedia" class="tt-item-media">
                <div class="tt-item-media-placeholder">Food image preview</div>
              </div>
              <div class="tt-item-main">
                <div class="tt-item-header">
                  <div>
                    <div id="ttItemKicker" class="tt-item-kicker">Menu item details</div>
                    <h2 id="ttItemTitle">Select an item</h2>
                  </div>
                  <div id="ttItemPriceWrap" class="tt-item-price-wrap">
                    <label for="ttItemPrice">Price</label>
                    <input id="ttItemPrice" class="tt-input" type="text" placeholder="e.g. 3490 ISK">
                  </div>
                </div>
                <div class="tt-item-grid">
                  <div class="tt-field">
                    <label for="ttItemShortDescription">Short description</label>
                    <textarea id="ttItemShortDescription" class="tt-textarea" rows="3" placeholder="Short customer-facing description"></textarea>
                  </div>
                  <div class="tt-field">
                    <label for="ttItemDetailedDescription">Detailed description</label>
                    <textarea id="ttItemDetailedDescription" class="tt-textarea" rows="5" placeholder="Expanded customer-facing description"></textarea>
                  </div>
                  <div id="ttItemImageField" class="tt-field">
                    <label for="ttItemImage">Food image URL</label>
                    <input id="ttItemImage" class="tt-input" type="url" placeholder="https://example.com/dish.jpg">
                  </div>
                  <div class="tt-field">
                    <label for="ttItemAllergens">Allergens</label>
                    <input id="ttItemAllergens" class="tt-input" type="text" placeholder="e.g. dairy, nuts, shellfish">
                  </div>
                  <div id="ttItemToggleRow" class="tt-field tt-toggle-row">
                    <label class="tt-check"><input id="ttItemAvailable" type="checkbox" checked> Available now</label>
                    <label class="tt-check"><input id="ttItemFeatured" type="checkbox"> Featured item</label>
                  </div>
                </div>
                <div id="ttItemNotesBlock" class="tt-item-notes">
                  <div class="tt-item-notes-label">Internal recipe / prep notes</div>
                  <textarea id="ttItemNotesInput" class="tt-textarea tt-item-notes-input" rows="10" placeholder="Kitchen prep, recipe details, plating notes"></textarea>
                </div>
              </div>
            </div>
          </div>
        </div>

        <div id="importTabContent" class="main-tab-content"></div>
        
        <!-- Hidden file input for attaching item images or PDFs -->
        <input type="file" id="mobilePdfInput" 
               accept=".jpg,.jpeg,.png,.webp,.gif,.pdf" 
               style="display: none;" />
    </div>
    
    
</div>    


<!-- ☁️ Google Drive Import Placeholder -->
<div id="driveImportOverlay" style="display:none;"></div>





<!-- Scripts -->

<!--<script src="https://cdnjs.cloudflare.com/ajax/libs/pdf.js/2.10.377/pdf.min.js"></script>-->
<!--<script src="/assets/pdf.min.js"></script>-->

<!--<script src="https://cdn.jsdelivr.net/npm/sortablejs@1.15.0/Sortable.min.js"></script>-->
<!--<script src="/assets/sortable.min.js"></script>-->







<!--Service worker for offline mode-->
<!-- Service worker for offline mode -->
<script>
window.appVersion = "<?= $version ?>";  
window.forceUpdate = false;
window.twIdleUpdateReload = false;
    //When to use forceUpdate
    // - Update bad or stuck service-worker
    // - Only for critical deploys
    // - When you know many users are still on an older version
    // - To silently ensure everyone gets the fix

if ('serviceWorker' in navigator) {
  const TW_IDLE_UPDATE_THRESHOLD_MS = 5 * 60 * 1000;
  const TW_RESUME_UPDATE_COOLDOWN_MS = 60 * 1000;
  const swUrl = `/service-worker.js?v=${window.appVersion}`;
  let hiddenAt = document.visibilityState === "hidden" ? Date.now() : 0;
  let lastResumeCheckAt = 0;

  const requestResumeUpdateCheck = async () => {
    const now = Date.now();
    if ((now - lastResumeCheckAt) < TW_RESUME_UPDATE_COOLDOWN_MS) return;
    lastResumeCheckAt = now;
    try {
      const reg = await navigator.serviceWorker.getRegistration();
      if (!reg) return;
      await reg.update();
      if (reg.waiting) {
        reg.waiting.postMessage({ type: 'SKIP_WAITING' });
      }
    } catch (_) {}
  };

  navigator.serviceWorker.register(swUrl, { updateViaCache: "none" })
    .then((reg) => {
      reg.update().catch(() => {});

      if (reg.waiting) {
        reg.waiting.postMessage({ type: 'SKIP_WAITING' });
      }

      if (reg.installing) {
        reg.installing.addEventListener('statechange', (e) => {
          if (e.target.state === 'installed' && navigator.serviceWorker.controller) {
          }
        });
      }
    })
    .catch(err => console.error("❌ SW registration failed:", err));

  let refreshing = false;
  navigator.serviceWorker.addEventListener("controllerchange", () => {
    if (refreshing) return;
    refreshing = true;

    const lastSeen = localStorage.getItem("lastSeenSWVersion");
    if (lastSeen !== window.appVersion) {
      localStorage.setItem("lastSeenSWVersion", window.appVersion);
      const shouldForceReload = window.forceUpdate === true;
      const shouldReloadAfterIdle = window.twIdleUpdateReload === true;

      const banner = document.createElement("div");
      banner.textContent = shouldForceReload || shouldReloadAfterIdle
        ? "Critical update applied. Refreshing app..."
        : "App cache updated.";
      banner.style = `
        position: fixed;
        top: 0; left: 0; right: 0;
        background: #222; color: #fff;
        text-align: center;
        padding: 8px; font-size: 14px;
        z-index: 9999;
      `;
      document.body.appendChild(banner);

      if (shouldForceReload || shouldReloadAfterIdle) {
        window.twIdleUpdateReload = false;
        setTimeout(() => window.location.reload(), 1200);
      } else {
        setTimeout(() => {
          banner.remove();
          refreshing = false;
        }, 1500);
      }
    } else {
      refreshing = false;
    }
  });

  document.addEventListener("visibilitychange", () => {
    if (document.visibilityState === "hidden") {
      hiddenAt = Date.now();
      return;
    }
    const idleFor = hiddenAt > 0 ? (Date.now() - hiddenAt) : 0;
    hiddenAt = 0;
    if (idleFor >= TW_IDLE_UPDATE_THRESHOLD_MS) {
      window.twIdleUpdateReload = true;
      requestResumeUpdateCheck();
    }
  });

  window.addEventListener("pageshow", () => {
    if (document.visibilityState !== "visible") return;
    requestResumeUpdateCheck();
  });

  window.addEventListener("focus", () => {
    if (document.visibilityState !== "visible") return;
    requestResumeUpdateCheck();
  });
}
</script>





<!-- Add VAPID key here -->
<?php
$vapidKey = getenv('VAPID_PUBLIC_KEY') ?: '';

if (!$vapidKey) {
  error_log("❌ VAPID_PUBLIC_KEY not set in environment");
} else {
  error_log("✅ VAPID_PUBLIC_KEY is set in environment");
}

?>
<script>
  window.VAPID_PUBLIC_KEY = <?= json_encode($vapidKey) ?>;
</script>
<script>
  (function () {
    let tapTrayOrderPollId = null;
    const tapTraySubscribedRefs = new Set();

    function getTapTrayVapidKeyBytes() {
      if (!window.VAPID_PUBLIC_KEY) return null;
      const padding = "=".repeat((4 - window.VAPID_PUBLIC_KEY.length % 4) % 4);
      const base64 = (window.VAPID_PUBLIC_KEY + padding).replace(/-/g, "+").replace(/_/g, "/");
      const raw = atob(base64);
      return Uint8Array.from([...raw].map((ch) => ch.charCodeAt(0)));
    }

    async function ensureTapTrayPushSubscriptionForOrders(orders) {
      const activeOrders = Array.isArray(orders) ? orders : [];
      if (!activeOrders.length || !("serviceWorker" in navigator) || !("PushManager" in window) || !window.VAPID_PUBLIC_KEY) {
        return;
      }

      const refs = activeOrders
        .map((entry) => String(entry?.order_reference || "").trim())
        .filter(Boolean)
        .filter((ref) => !tapTraySubscribedRefs.has(ref));

      if (!refs.length) {
        return;
      }

      try {
        if (Notification.permission === "default") {
          await Notification.requestPermission();
        }
        if (Notification.permission !== "granted") {
          return;
        }

        const reg = await navigator.serviceWorker.ready;
        const key = getTapTrayVapidKeyBytes();
        if (!key) return;
        const subscription = await reg.pushManager.getSubscription() || await reg.pushManager.subscribe({
          userVisibleOnly: true,
          applicationServerKey: key
        });

        for (const orderReference of refs) {
          const response = await fetch("/taptray_order_subscribe.php", {
            method: "POST",
            headers: { "Content-Type": "application/json", "Accept": "application/json" },
            credentials: "same-origin",
            body: JSON.stringify({
              order_reference: orderReference,
              env: location.host,
              subscription
            })
          });
          const data = await response.json().catch(() => null);
          if (response.ok && data && data.ok) {
            tapTraySubscribedRefs.add(orderReference);
          }
        }
      } catch (err) {
        console.warn("TapTray order push sync failed", err);
      }
    }

    async function loadTapTrayActiveOrder() {
      try {
        const response = await fetch("/taptray_order_status.php", {
          credentials: "same-origin",
          headers: { "Accept": "application/json" }
        });
        const data = await response.json().catch(() => null);
        const orders = Array.isArray(data && data.orders) ? data.orders : [];
        const pastOrders = Array.isArray(data && data.past_orders) ? data.past_orders : [];
        const order = data && data.order ? data.order : null;
        window.taptrayActiveOrders = orders;
        window.taptrayPastOrders = pastOrders;
        window.taptrayActiveOrder = order;
        ensureTapTrayPushSubscriptionForOrders(orders);
        document.dispatchEvent(new CustomEvent("taptray:active-order-updated", {
          detail: { order, orders, pastOrders }
        }));
      } catch (err) {
        console.warn("TapTray active order refresh failed", err);
      }
    }

    document.addEventListener("DOMContentLoaded", loadTapTrayActiveOrder);
    document.addEventListener("DOMContentLoaded", () => {
      if (tapTrayOrderPollId !== null) return;
      tapTrayOrderPollId = window.setInterval(loadTapTrayActiveOrder, 5000);
    });
    window.addEventListener("focus", loadTapTrayActiveOrder);
    window.addEventListener("pageshow", loadTapTrayActiveOrder);
  })();
</script>





<?php include_once __DIR__ . '/chat.php'; ?>







<script>
// Emergency cleanup: remove any leftover Zoom Math Test debug panel.
(function () {
  function removeZoomMathPanel() {
    try {
      const nodes = document.querySelectorAll("body *");
      for (const el of nodes) {
        const txt = String(el.textContent || "").replace(/\s+/g, " ").trim();
        if (!txt) continue;
        const isTarget =
          txt.includes("Zoom Math Test") ||
          (txt.includes("anchorWeight") &&
           txt.includes("driftWeight") &&
           txt.includes("yBias")) ||
          (txt.includes("scrollCoupling") &&
           txt.includes("yMin") &&
           txt.includes("yMax"));
        if (!isTarget) continue;
        const host = el.closest("aside, section, div") || el;
        host.remove();
      }
    } catch (_err) {}
  }
  removeZoomMathPanel();
})();
</script>

<script src="/JSDragDdropPDF.js?v=<?= $version ?>" defer></script>
<!--<script src="/JSExtractURLs.js?v=<?= $version ?>" defer></script>-->
<script src="/JSFunctions_Offline.js?v=<?= $version ?>" defer></script>
<script src="/JSCommon.js?v=<?= $version ?>" defer></script>
<script src="https://cdn.jsdelivr.net/npm/flatpickr" defer></script>
<script src="/assets/tw_flatpickr.js?v=<?= $version ?>" defer></script>
<script src="/JSTextUndo.js?v=<?= $version ?>" defer></script>
<script src="/JSTextComments.js?v=<?= $version ?>" defer></script>
<script src="/JSTextDrawing.js?v=<?= $version ?>" defer></script>
<script src="/JSText.js?v=<?= $version ?>" defer></script>
<script src="/JSFunctions.js?v=<?= $version ?>-status5" defer></script>
<script src="/JSCreateImportPDFs.js?v=<?= $version ?>" defer></script>
<script src="/JSDriveImport.js?v=<?= $version ?>" defer></script>
<script src="/JSDrawingPDF.js?v=<?= $version ?>" defer></script>
<script src="/JSPdfMarkers.js?v=<?= $version ?>" defer></script>
<script src="/JSFunctions_Trimmer.js?v=<?= $version ?>" defer></script>
<script src="/chatEncryption.js?v=<?= $version ?>" defer></script>
<script src="/assets/lucide.min.js?v=<?= $version ?>" defer></script>
<script src="/includes/colorAdjustments.js?v=<?= $version ?>" defer></script>

<!--at the end, but also defer -->
<script src="/JSEventsHeaderTabs.js?v=<?= $version ?>" defer></script>
<script src="/JSEvents.js?v=<?= $version ?>" defer></script>





<script>

window.taptrayRefreshItemDetails = function taptrayRefreshItemDetails() {
  const shellEl = document.getElementById("taptrayItemDetails");
  const kickerEl = document.getElementById("ttItemKicker");
  const priceWrapEl = document.getElementById("ttItemPriceWrap");
  const imageFieldEl = document.getElementById("ttItemImageField");
  const toggleRowEl = document.getElementById("ttItemToggleRow");
  const notesBlockEl = document.getElementById("ttItemNotesBlock");
  const titleEl = document.getElementById("ttItemTitle");
  const notesEl = document.getElementById("ttItemNotesInput");
  const shortDescEl = document.getElementById("ttItemShortDescription");
  const detailedDescEl = document.getElementById("ttItemDetailedDescription");
  const priceEl = document.getElementById("ttItemPrice");
  const imageEl = document.getElementById("ttItemImage");
  const allergensEl = document.getElementById("ttItemAllergens");
  const mediaEl = document.getElementById("ttItemMedia");
  if (!shellEl || !kickerEl || !priceWrapEl || !imageFieldEl || !toggleRowEl || !notesBlockEl || !titleEl || !notesEl || !shortDescEl || !detailedDescEl || !priceEl || !imageEl || !allergensEl || !mediaEl) return;

  const rawText = String(window._T2_RAWTEXT || "").trim();
  const rawTitle = document.getElementById("selectedItemTitle")?.textContent?.trim() || "";
  const lines = rawText.split(/\r?\n/).map(line => line.trim()).filter(Boolean);
  const title = lines[0] || rawTitle || "Select an item";
  const rest = lines.slice(1);

  let shortDescription = "";
  let detailedDescription = "";
  let notesBody = "";
  let price = "";
  let imageUrl = "";
  let allergens = "";

  for (const line of rest) {
    if (!imageUrl && /^https?:\/\/\S+\.(png|jpe?g|webp|gif)(\?\S*)?$/i.test(line)) {
      imageUrl = line;
      continue;
    }
    if (!price) {
      const priceMatch = line.match(/(\d{1,4}(?:[.,]\d{2})?)\s?(?:kr|isk|eur|\$|€)\b/i);
      if (priceMatch) price = priceMatch[0];
    }
    if (!allergens && /^allergens?\s*:/i.test(line)) {
      allergens = line.replace(/^allergens?\s*:/i, "").trim();
      continue;
    }
    if (!shortDescription) {
      shortDescription = line;
      continue;
    }
    detailedDescription += (detailedDescription ? "\n" : "") + line;
  }

  if (!detailedDescription) {
    detailedDescription = rest.filter((line) => {
      if (/^https?:\/\/\S+\.(png|jpe?g|webp|gif)(\?\S*)?$/i.test(line)) return false;
      if (/^allergens?\s*:/i.test(line)) return false;
      if (line.match(/(\d{1,4}(?:[.,]\d{2})?)\s?(?:kr|isk|eur|\$|€)\b/i)) return false;
      return line !== shortDescription;
    }).join("\n");
  }

  titleEl.textContent = title;
  notesEl.value = notesBody;
  shortDescEl.value = shortDescription;
  detailedDescEl.value = detailedDescription;
  priceEl.value = price;
  imageEl.value = imageUrl;
  allergensEl.value = allergens;

  const canEdit = !!window.canEditCurrentSurrogate;
  shellEl.classList.toggle("is-preview-only", !canEdit);
  kickerEl.textContent = canEdit ? "Menu item details" : "Menu item preview";
  priceWrapEl.hidden = !canEdit;
  imageFieldEl.hidden = !canEdit;
  toggleRowEl.hidden = !canEdit;
  notesBlockEl.hidden = !canEdit;
  shortDescEl.readOnly = !canEdit;
  detailedDescEl.readOnly = !canEdit;
  priceEl.readOnly = !canEdit;
  imageEl.readOnly = !canEdit;
  allergensEl.readOnly = !canEdit;
  notesEl.readOnly = !canEdit;

  if (imageUrl) {
    mediaEl.innerHTML = `<img src="${imageUrl.replace(/"/g, '&quot;')}" alt="${title.replace(/"/g, '&quot;')}" style="width:100%;height:100%;object-fit:cover;display:block;">`;
  } else {
    mediaEl.innerHTML = '<div class="tt-item-media-placeholder">Food image preview</div>';
  }
};

document.addEventListener("DOMContentLoaded", () => {
  window.taptrayRefreshItemDetails?.();
});

const taptrayDetailStyle = document.createElement("style");
taptrayDetailStyle.textContent = `
  .tt-item-details { height: 100%; overflow: auto; padding: 18px; background: linear-gradient(180deg, #f5efe5 0%, #fbf7f0 52%, #eef6f2 100%); }
  .tt-item-shell { max-width: 1080px; margin: 0 auto; display: grid; grid-template-columns: minmax(280px, 420px) minmax(0, 1fr); gap: 22px; align-items: start; }
  .tt-item-media { min-height: 320px; border-radius: 24px; overflow: hidden; background: linear-gradient(135deg, #f4d8bc, #f0eee8); border: 1px solid rgba(20, 17, 12, 0.12); box-shadow: 0 18px 40px rgba(20, 17, 12, 0.12); }
  .tt-item-media.is-dragover { outline: 2px dashed rgba(176, 66, 29, 0.55); outline-offset: -12px; }
  .tt-item-media.is-uploading::after { content: "Uploading image..."; position: absolute; inset: 0; display: flex; align-items: center; justify-content: center; background: rgba(255,248,235,0.88); color: #8a5c43; font-weight: 700; letter-spacing: 0.04em; text-transform: uppercase; }
  .tt-item-media-placeholder { height: 100%; min-height: 320px; display: flex; align-items: center; justify-content: center; color: #8a5c43; font-weight: 700; letter-spacing: 0.06em; text-transform: uppercase; }
  .tt-item-main { padding: 20px 22px; border-radius: 24px; background: rgba(255,255,255,0.92); border: 1px solid rgba(20, 17, 12, 0.1); box-shadow: 0 18px 40px rgba(20, 17, 12, 0.08); }
  .tt-item-details.is-preview-only .tt-item-main { max-width: 720px; }
  .tt-item-header { display: flex; justify-content: space-between; gap: 16px; align-items: start; }
  .tt-item-kicker { font-size: 12px; font-weight: 700; color: #b0421d; letter-spacing: 0.08em; text-transform: uppercase; }
  #ttItemTitle { margin: 8px 0 0; font-size: clamp(28px, 4vw, 40px); line-height: 1.08; font-family: Georgia, serif; }
  .tt-item-save-state { margin-top: 8px; font-size: 12px; font-weight: 600; color: #7a6a59; }
  .tt-item-save-state[data-state="saving"] { color: #b07d27; }
  .tt-item-save-state[data-state="error"] { color: #b0421d; }
  .tt-item-preview-btn { margin-top: 12px; border: 1px solid rgba(176, 66, 29, 0.18); background: #fff7f1; color: #8e451f; border-radius: 999px; padding: 9px 14px; font-weight: 700; }
  .tt-item-price-wrap { width: min(180px, 100%); }
  .tt-item-grid { display: grid; grid-template-columns: repeat(2, minmax(0, 1fr)); gap: 16px; margin-top: 18px; }
  .tt-field { display: flex; flex-direction: column; gap: 8px; }
  .tt-field label { font-size: 13px; font-weight: 700; color: #5a5147; }
  .tt-input, .tt-textarea { width: 100%; border: 1px solid rgba(20, 17, 12, 0.12); border-radius: 14px; padding: 12px 14px; font: inherit; background: #fff; }
  .tt-textarea { resize: vertical; min-height: 116px; }
  .tt-item-details.is-preview-only .tt-input[readonly],
  .tt-item-details.is-preview-only .tt-textarea[readonly] { background: rgba(249, 244, 236, 0.85); color: #3d3027; border-color: rgba(20, 17, 12, 0.08); box-shadow: none; }
  .tt-toggle-row { justify-content: center; gap: 14px; padding: 10px 0 0; }
  .tt-check { display: inline-flex; align-items: center; gap: 8px; font-size: 14px; }
  .tt-item-notes { margin-top: 18px; }
  .tt-item-notes-label { font-size: 13px; font-weight: 700; color: #5a5147; margin-bottom: 8px; }
  .tt-item-notes-input { min-height: 180px; background: #f8f4ed; line-height: 1.55; }
  .list-sub-item { position: relative; display: grid !important; grid-template-columns: minmax(0, 1fr) auto auto; gap: 10px; align-items: center; min-height: 82px; }
  .group-contents .list-sub-item.active .item-title,
  .group-contents .list-sub-item.active .item-summary { color: #fff !important; }
  .group-contents .list-sub-item.active .item-summary { opacity: 0.88; }
  .group-contents .list-sub-item.active .item-price-chip { background: rgba(255,255,255,0.16); color: #fff !important; border-color: rgba(255,255,255,0.28); }
  .group-contents .list-sub-item.active .item-price-chip.is-placeholder { color: rgba(255,255,255,0.72) !important; }
  .group-contents .list-sub-item.active .item-thumb { border-color: rgba(255,255,255,0.22); background: rgba(255,255,255,0.12); }
  .group-contents .list-sub-item.active .item-thumb.is-placeholder { color: rgba(255,255,255,0.78); }
  .group-contents .list-sub-item.active .taptray-order-btn { background: #fff; color: var(--skin-accent); border-color: rgba(255,255,255,0.36); }
  .item-title { cursor: pointer; min-width: 0; color: var(--skin-text); font-weight: 800; font-size: 18px; line-height: 1.14; letter-spacing: -0.01em; display: -webkit-box; -webkit-line-clamp: 2; -webkit-box-orient: vertical; overflow: hidden; }
  .taptray-menu-row { display: grid; grid-template-columns: 62px minmax(0, 1fr) 84px; gap: 10px; align-items: center; width: 100%; }
  .item-media-rail { display: flex; align-items: center; justify-content: center; align-self: stretch; }
  .taptray-menu-copy { min-width: 0; min-height: 56px; display: flex; flex-direction: column; justify-content: center; gap: 3px; padding: 0; }
  .item-head { display: block; }
  .item-price-chip { display: inline-flex; align-self: flex-start; margin-top: 1px; padding: 4px 10px; border-radius: 999px; background: color-mix(in srgb, var(--skin-accent) 10%, var(--skin-surface)); color: var(--skin-accent); font-size: 12px; line-height: 1.1; font-weight: 800; border: 1px solid color-mix(in srgb, var(--skin-accent) 14%, var(--skin-border)); white-space: nowrap; box-sizing: border-box; }
  .item-price-chip.is-placeholder { background: var(--skin-surface-2); color: var(--skin-muted); border-color: var(--skin-border); font-weight: 600; }
  .taptray-menu-copy .item-summary,
  .taptray-menu-row .item-summary { font-size: 12px !important; line-height: 1.28; color: var(--skin-muted); display: -webkit-box; -webkit-line-clamp: 2; -webkit-box-orient: vertical; overflow: hidden; max-width: 100%; }
  .item-summary.is-placeholder { color: var(--skin-muted); font-style: italic; }
  .item-action-square { display: grid; grid-template-rows: auto auto auto; gap: 4px; align-items: center; justify-items: stretch; width: 84px; }
  .item-square-main { position: relative; width: 60px; height: 60px; display: flex; align-items: center; justify-content: center; }
  .item-qty-badge { position: absolute; top: -4px; left: -4px; min-width: 16px; height: 16px; padding: 0 4px; display: inline-flex; align-items: center; justify-content: center; border-radius: 999px; background: var(--skin-surface); color: var(--skin-text); font-size: 9px; font-weight: 800; border: 1px solid var(--skin-border); box-shadow: 0 3px 8px rgba(12,16,24,0.10); z-index: 2; }
  .item-square-actions { display: inline-flex; width: 100%; gap: 6px; align-items: center; justify-content: center; }
  .taptray-order-side-meta { width: 100%; text-align: right; font-size: 11px; line-height: 1.05; font-weight: 700; color: var(--skin-muted); white-space: nowrap; overflow: hidden; text-overflow: ellipsis; }
  .taptray-status-badge { min-width: 84px; width: 100%; display: inline-flex; align-items: center; justify-content: center; box-sizing: border-box; padding: 4px 10px; border-radius: 999px; border: 1px solid #d7dde7; background: #f7f9fc; color: #2f3b52; font-size: 12px; font-weight: 800; line-height: 1.1; white-space: nowrap; }
  .taptray-status-badge.is-queued { background: #fff1df; color: #9a5a18; border-color: #f0c99a; }
  .taptray-status-badge.is-making { background: #fff0bf; color: #9a6a00; border-color: #ebd27b; }
  .taptray-status-badge.is-ready { background: #5b8f79; color: #ffffff; border-color: #497664; }
  .taptray-status-badge.is-closed { background: #eceff4; color: #536172; border-color: #cfd7e1; }
  .item-square-action { min-width: 84px; width: 100%; min-height: 0; display: inline-flex; align-items: center; justify-content: center; box-sizing: border-box; padding: 4px 10px; border-radius: 999px; border: 1px solid transparent; background: color-mix(in srgb, var(--skin-accent) 10%, var(--skin-surface)); color: var(--skin-accent); font-size: 12px; font-weight: 800; line-height: 1.1; white-space: nowrap; box-shadow: none; }
  .item-square-action.is-remove { color: var(--skin-accent); }
  .item-thumb { width: 60px; height: 60px; border-radius: 12px; overflow: hidden; background: var(--skin-surface-2); border: 1px solid var(--skin-border); flex-shrink: 0; box-shadow: inset 0 1px 0 rgba(255,255,255,0.35); }
  .item-action-square .item-thumb,
  .item-media-rail .item-thumb { width: 60px; height: 60px; border-radius: 12px; }
  .item-thumb img { width: 100%; height: 100%; object-fit: cover; display: block; }
  .item-thumb.is-placeholder { display: flex; align-items: center; justify-content: center; color: var(--skin-muted); font-size: 11px; font-weight: 700; letter-spacing: 0.06em; }
  .taptray-order-btn { min-width: 0; width: 100%; justify-content: center; padding: 5px 8px; font-size: 12px; line-height: 1; font-weight: 700; border-radius: 999px; background: #5fbe7b; color: #fff; border: 1px solid #4ea66a; box-shadow: 0 1px 0 rgba(255,255,255,0.25) inset, 0 3px 8px rgba(12,16,24,0.06); }
  .taptray-order-btn.is-selected { background: var(--skin-accent); color: #fff; border-color: var(--skin-accent); }
  .taptray-menu-copy .item-price-chip { padding: 4px 10px; font-size: 11px; font-weight: 700; }
  .item-menu-wrapper { justify-self: end; display: none; }
  body.edit-mode .item-menu-wrapper { display: block; }
  .item-expand-row { margin: -2px 0 8px; }
  .item-expand-panel { display: block; width: 100%; padding: 14px; border-radius: 18px; background: linear-gradient(180deg, var(--skin-surface) 0%, var(--skin-surface-2) 100%); border: 1px solid var(--skin-border); box-shadow: var(--skin-shadow); }
  .item-expand-panel[hidden] { display: none !important; }
  .taptray-tree-item-loading, .taptray-tree-item-error { padding: 8px 2px; color: var(--skin-muted); font-size: 14px; }
  .taptray-tree-item-body { display: grid; grid-template-columns: 120px minmax(0, 1fr); gap: 16px; align-items: start; }
  .taptray-tree-item-media { width: 120px; overflow: hidden; border-radius: 18px; background: var(--skin-surface-3); border: 1px solid var(--skin-border); display: block; align-self: start; }
  .taptray-tree-item-media img { width: 100%; height: auto; max-height: 240px; object-fit: contain; object-position: center top; display: block; background: rgba(255,255,255,0.6); }
  .taptray-tree-item-placeholder { width: 100%; height: 100%; display: flex; align-items: center; justify-content: center; color: var(--skin-muted); font-size: 11px; font-weight: 700; text-transform: uppercase; letter-spacing: 0.06em; }
  .taptray-tree-item-copy { min-width: 0; }
  .taptray-tree-item-title { color: var(--skin-text); font-size: 22px; line-height: 1.1; font-weight: 800; margin-bottom: 6px; }
  .taptray-tree-item-price { color: var(--skin-accent); font-size: 15px; font-weight: 800; margin-bottom: 8px; }
  .taptray-tree-item-description { color: var(--skin-text); line-height: 1.5; }
  .taptray-tree-item-description.is-empty { color: var(--skin-muted); font-style: italic; }
  .taptray-tree-item-meta { margin-top: 10px; color: var(--skin-muted); font-size: 13px; }
  .taptray-order-bar { margin-top: 8px; border-radius: 14px; overflow: hidden; background: var(--skin-surface); border: 1px solid var(--skin-border); box-shadow: 0 6px 14px rgba(31, 42, 46, 0.08); }
  .taptray-order-head { display: grid; grid-template-columns: minmax(0, 1fr) auto; gap: 12px; align-items: center; padding: 10px 12px; background: linear-gradient(180deg, var(--skin-surface-2), var(--skin-surface)); }
  .taptray-order-toggle { min-width: 0; display: inline-flex; align-items: center; gap: 10px; padding: 0; border: 0; background: transparent; text-align: left; color: inherit; }
  .taptray-order-chevron { width: 18px; text-align: center; color: var(--skin-muted); font-size: 14px; }
  .taptray-order-copy { min-width: 0; display: flex; flex-direction: column; }
  .taptray-order-title { font-size: 14px; font-weight: 800; color: var(--skin-text); }
  .taptray-order-meta { font-size: 14px; color: var(--skin-muted); white-space: nowrap; overflow: hidden; text-overflow: ellipsis; }
  .taptray-pay-btn { border: 1px solid #ba6232; background: linear-gradient(180deg, #dd8b4f 0%, #c26a37 100%); color: #fff; border-radius: 999px; padding: 9px 16px; font-size: 14px; font-weight: 800; line-height: 1; display: inline-flex; align-items: center; justify-content: center; }
  .taptray-pay-btn[disabled] { opacity: 0.45; }
  .taptray-order-items { display: grid; gap: 8px; padding: 10px 12px 12px; border-top: 1px solid var(--skin-border); background: var(--skin-surface); }
  .taptray-order-item { padding: 8px 10px; border-radius: 12px; background: var(--skin-surface-2); border: 1px solid var(--skin-border); }
  .taptray-order-item-title { font-size: 13px; font-weight: 700; color: var(--skin-text); line-height: 1.2; }
  .taptray-order-item-meta { margin-top: 2px; font-size: 12px; color: var(--skin-muted); }
  .taptray-order-list-item { min-height: 88px; }
  .taptray-order-list-item .taptray-menu-row { pointer-events: none; }
  .taptray-order-list-item .item-action-square,
  .taptray-order-list-item .item-square-action { pointer-events: auto; }
  .taptray-order-history { margin-top: 6px; border-top: 1px solid var(--skin-border); padding-top: 8px; }
  .taptray-order-history-title { cursor: pointer; font-size: 12px; font-weight: 800; color: var(--skin-muted); list-style: none; }
  .taptray-order-history-title::-webkit-details-marker { display: none; }
  .taptray-order-history-title::before { content: "▸"; display: inline-block; margin-right: 6px; color: var(--skin-muted); }
  .taptray-order-history[open] .taptray-order-history-title::before { content: "▾"; }
  .taptray-order-history-group { margin-top: 8px; }
  .taptray-order-history-meta { margin: 0 0 6px; font-size: 11px; font-weight: 700; color: var(--skin-muted); }
  @media (max-width: 720px) { .list-sub-item { grid-template-columns: minmax(0, 1fr) auto; min-height: 76px; } .taptray-menu-row { grid-template-columns: 52px minmax(0, 1fr) 84px; gap: 8px; } .item-title { font-size: 16px; } .taptray-menu-copy .item-summary, .taptray-menu-row .item-summary { font-size: 12px !important; line-height: 1.25; } .item-action-square { width: 84px; } .item-square-main { width: 50px; height: 50px; } .item-qty-badge { min-width: 14px; height: 14px; font-size: 8px; top: -4px; left: -4px; } .taptray-status-badge { min-width: 84px; width: 100%; padding: 4px 8px; font-size: 12px; line-height: 1.1; white-space: nowrap; } .item-square-action { min-width: 84px; width: 100%; min-height: 0; padding: 4px 8px; font-size: 12px; line-height: 1.1; white-space: nowrap; } .item-thumb, .item-action-square .item-thumb, .item-media-rail .item-thumb { width: 50px; height: 50px; } .taptray-order-btn { padding: 5px 6px; font-size: 12px; } .taptray-order-title { font-size: 13px; } .taptray-order-meta { font-size: 14px; } .item-menu-wrapper { grid-column: 2; grid-row: 1; } .taptray-tree-item-body { grid-template-columns: 92px minmax(0, 1fr); gap: 10px; } .taptray-tree-item-media { width: 92px; } .taptray-tree-item-media img { max-height: 184px; } .taptray-tree-item-title { font-size: 18px; } }
  @media (max-width: 980px) { .tt-item-shell { grid-template-columns: 1fr; } }
  @media (max-width: 720px) { .tt-item-details { padding: 10px; } .tt-item-main { padding: 16px; } .tt-item-grid { grid-template-columns: 1fr; } .tt-item-header { flex-direction: column; } .tt-item-price-wrap { width: 100%; } }
`;
document.head.appendChild(taptrayDetailStyle);

// to do: Move those scripts to script funtion

function applyFontSize(size) {
  localStorage.setItem('textwhisperFontSize', size);
}

// Restore on load
document.addEventListener("DOMContentLoaded", () => {
  const textSlider = document.getElementById('fontSizeSlider');
  if (!textSlider) return;

  const saved = localStorage.getItem('textwhisperFontSize');
  if (saved) {
    textSlider.value = saved;
    applyFontSize(saved);
  }

  textSlider.addEventListener('input', e => applyFontSize(e.target.value));


});

</script>

<script>
document.addEventListener("DOMContentLoaded", () => {
  // === Paged PDF toggle (added, separate) ===
  const pagedToggle = document.getElementById('togglePagedPDF');
  const savedPaged  = localStorage.getItem('pagedPdfView') === 'true';
  if (pagedToggle) {
    pagedToggle.checked = savedPaged;
    window.setPagedPDFMode?.(savedPaged);

    pagedToggle.addEventListener('change', (e) => {
      const enabled = e.target.checked;
      localStorage.setItem('pagedPdfView', enabled);
      window.setPagedPDFMode?.(enabled);
    });
  }

  // === Follow Conductor Paging (optional user preference) ===
  const followPagingToggles = Array.from(document.querySelectorAll('.follow-paging-toggle'));
  const storedFollowPaging = localStorage.getItem('followConductorPaging');
  if (storedFollowPaging === null) {
    localStorage.setItem('followConductorPaging', 'false');
  }
  const savedFollowPaging = (storedFollowPaging === null ? false : storedFollowPaging === 'true');
  window.followConductorPaging = savedFollowPaging;
  followPagingToggles.forEach(toggle => {
    toggle.checked = savedFollowPaging;
    toggle.addEventListener('change', (e) => {
      const enabled = e.target.checked;
      window.followConductorPaging = enabled;
      localStorage.setItem('followConductorPaging', String(enabled));
      followPagingToggles.forEach(t => {
        if (t !== e.target) t.checked = enabled;
      });
    });
  });
});

</script>

<script>
document.addEventListener("DOMContentLoaded", () => {
  localStorage.removeItem("twoColumns");
  localStorage.removeItem("textPaneSplit");
});
</script>

<script>
document.addEventListener("DOMContentLoaded", () => {
  const sidebar = document.getElementById("sidebarContainer");
  if (!sidebar) return;

  const applyTapTrayMobileSidebarState = () => {
    if (window.innerWidth <= 900) {
      sidebar.classList.add("show");
    }
  };

  applyTapTrayMobileSidebarState();
  window.addEventListener("resize", applyTapTrayMobileSidebarState);
});
</script>

<script>
function syncAppLayoutMetrics() {
  const root = document.documentElement;
  const viewportHeight = Math.max(
    0,
    Number(window.visualViewport?.height || window.innerHeight || document.documentElement.clientHeight || 0)
  );
  if (viewportHeight > 0) {
    root.style.setProperty('--vh', (viewportHeight * 0.01) + 'px');
  }

  const navbar = document.querySelector('nav.navbar');
  const navHeight = Math.max(0, Math.round(navbar?.offsetHeight || navbar?.getBoundingClientRect?.().height || 0));
  if (navHeight > 0) {
    root.style.setProperty('--navbar-height', navHeight + 'px');
  }

  const footerMenu = document.getElementById('footerMenu');
  const footerHeight = Math.max(0, Math.round(footerMenu?.offsetHeight || footerMenu?.getBoundingClientRect?.().height || 0));
  if (footerHeight > 0) {
    root.style.setProperty('--app-footer-height', footerHeight + 'px');
  }
}

function applyEnvZoom(size) {
  const uiSize = parseFloat(size) || 16;

  // Sidebar
  const sidebar = document.getElementById('sidebarContainer');
  if (sidebar) {
    sidebar.style.fontSize = size + 'px';
    if (!sidebar.dataset.resized) {
      sidebar.style.width = (size * 18) + 'px';
    }
    sidebar.querySelectorAll('*').forEach(el => {
      el.style.fontSize = size + 'px';
    });
  }

  // Header (navbar)
  document.querySelectorAll('nav.navbar, nav.navbar *')
    .forEach(el => {
      el.style.fontSize = size + 'px';
    });

  // Keep navbar icon buttons/icons proportional to env zoom.
  const navButtonPx = Math.max(24, uiSize * 2);
  const navIconPx = Math.max(14, uiSize * 1.2);
  document.querySelectorAll('nav.navbar .icon-button, nav.navbar .settings-icon')
    .forEach(btn => {
      btn.style.width = navButtonPx + 'px';
      btn.style.height = navButtonPx + 'px';
    });
  document.querySelectorAll('nav.navbar .icon-button svg, nav.navbar .settings-icon svg, nav.navbar .icon-button .lucide-icon, nav.navbar .settings-icon .lucide-icon')
    .forEach(icon => {
      icon.style.width = navIconPx + 'px';
      icon.style.height = navIconPx + 'px';
    });

  // Footer menu container
  const footerMenu = document.getElementById('footerMenu');
  if (footerMenu) {
    const footerPx = (size * 2.5);
    footerMenu.style.fontSize = size + 'px';
    footerMenu.style.height = footerPx + 'px';
    document.documentElement.style.setProperty('--app-footer-height', footerPx + 'px');
  }

  // Footer buttons
  document.querySelectorAll('#footerMenu .footer-tab-btn').forEach(btn => {
    btn.style.flex = '1 1 0';
    btn.style.minWidth = '0';
    btn.style.width = 'auto';
    btn.style.height = (size * 2.5) + 'px';
    btn.style.fontSize = size + 'px';
  });

  // Footer icons (Lucide SVGs)
  document.querySelectorAll('#footerMenu svg').forEach(svg => {
    svg.style.width = (size * 2) + 'px';
    svg.style.height = (size * 2) + 'px';
  });

  // Dropdown menus
  document.querySelectorAll('.dropdown-menu')
    .forEach(el => {
      el.style.fontSize = size + 'px';
    });

  // ✅ Chat container + children
  const chatContainer = document.getElementById('chatContainer');
  if (chatContainer) {
    chatContainer.style.fontSize = size + 'px';
    const isMobile = window.innerWidth <= 720;
    if (isMobile) {
      chatContainer.style.width = "";
      chatContainer.style.height = "";
      chatContainer.style.right = "";
      chatContainer.style.bottom = "";
      chatContainer.style.removeProperty('--footer-h');
      chatContainer.style.removeProperty('--header-h');
      chatContainer.style.removeProperty('--vvh');
    } else {
      const targetWidth = size * 26;
      const targetHeight = size * 40;
      const maxWidth = Math.max(360, window.innerWidth - 40);
      const maxHeight = Math.max(460, window.innerHeight - 44);
      chatContainer.style.width  = Math.min(targetWidth, maxWidth) + 'px';
      chatContainer.style.height = Math.min(targetHeight, maxHeight) + 'px';
      chatContainer.style.removeProperty('--footer-h');
      chatContainer.style.removeProperty('--header-h');
    }
    

    // Header (first child div inside chatContainer)
    const chatHeader = chatContainer.querySelector(':scope > div:first-child');
    if (chatHeader) {
      chatHeader.style.padding   = (size * 0.6) + 'px ' + (size * 0.75) + 'px';
      chatHeader.style.fontSize  = (size * 0.9) + 'px';
      chatHeader.style.minHeight = (size * 2.2) + 'px';
    }

    // Icons in header
    chatContainer.querySelectorAll('i[data-lucide], svg').forEach(svg => {
      svg.style.width  = (size * 1.2) + 'px';
      svg.style.height = (size * 1.2) + 'px';
    });

    // Messages area
    const chatMessages = document.getElementById('chatMessages');
    if (chatMessages) {
      chatMessages.style.fontSize = size + 'px';
      chatMessages.style.padding  = (size * 0.6) + 'px';
    }

    // Text input
    const chatInput = document.getElementById('chatInput');
    if (chatInput) {
      chatInput.style.fontSize  = size + 'px';
      chatInput.style.minHeight = (size * 2.2) + 'px';
      chatInput.style.padding   = (size * 0.45) + 'px ' + (size * 0.9) + 'px';
    }

    // Left-side chat actions (bell + poll)
    chatContainer.querySelectorAll('.chat-input-action').forEach((btn) => {
      btn.style.width = (size * 1.6) + 'px';
      btn.style.height = (size * 1.6) + 'px';
      btn.style.fontSize = (size * 0.95) + 'px';
    });

    // Send button
    const sendBtn = chatContainer.querySelector('.send-button');
    if (sendBtn) {
      sendBtn.style.fontSize  = size + 'px';
      sendBtn.style.padding   = (size * 0.5) + 'px ' + (size * 1.2) + 'px';
      sendBtn.style.minHeight = (size * 2.2) + 'px';
    }

    // Invite/manage wrapper
    const inviteWrapper = document.getElementById('chatInviteWrapper');
    if (inviteWrapper) {
      inviteWrapper.style.fontSize = size + 'px';
    }
  }
  

//----

    // 🧾 Scale PDF margin slider + label
    const marginWrap = document.querySelector(".pdf-margin-wrapper");
    if (marginWrap) {
      marginWrap.style.fontSize = size + "px";
    
      const slider = marginWrap.querySelector("input[type='range']");
      if (slider) {
        slider.style.height = (size * 0.5) + "px";
        slider.style.width = (size * 8) + "px";
      }
    
      const label = marginWrap.querySelector(".pdf-margin-label");
      if (label) label.style.fontSize = (size * 0.9) + "px";
    
      const value = marginWrap.querySelector(".pdf-margin-value");
      if (value) value.style.fontSize = (size * 0.9) + "px";
    }

//----


  // ✅ Scale chat selector dropdown (if it exists)
  scaleChatSelectors(size);

//-----

  syncAppLayoutMetrics();

  // Save preference
  localStorage.setItem('envZoom', size);
}

function scaleChatSelectors(size) {
    // Scale chat selector + prevent footer overlap
    document.querySelectorAll('.inline-chatlist-selector').forEach(sel => {
      sel.style.fontSize = size + 'px';
      sel.style.minWidth = (size * 20) + 'px';
      sel.style.maxWidth = (size * 25) + 'px';
      sel.style.maxHeight = (size * 20) + 'px';
    
      sel.querySelectorAll('.chatlist-choice').forEach(choice => {
        choice.style.fontSize = size + 'px';
        choice.style.padding = (size * 0.5) + 'px ' + (size * 0.8) + 'px';
      });
    
      // Push selector above footer
      const footer = document.getElementById('footerMenu');
      if (footer) {
        const footerHeight = footer.offsetHeight || (size * 3);
        sel.style.bottom = (footerHeight + 20) + 'px';
      }
    });
    
    // Push chat above footer too
    const chatContainer = document.getElementById('chatContainer');
    if (chatContainer) {
      const footer = document.getElementById('footerMenu');
      if (footer) {
        const footerHeight = footer.offsetHeight || (size * 3);
        const isMobile = window.innerWidth <= 720;
        if (!isMobile) {
          chatContainer.style.bottom = (footerHeight + 20) + 'px';
        }
      }
    }

}






document.addEventListener("DOMContentLoaded", () => {
  const slider = document.getElementById('envSizeSlider');
  if (!slider) return;

  const saved = localStorage.getItem('envZoom');
  if (saved) {
    slider.value = saved;
    applyEnvZoom(saved);
  } else {
    applyEnvZoom(slider.value);
  }
  scaleChatSelectors(saved || slider.value);

  // Listen for user changes
  slider.addEventListener('input', e => {
    applyEnvZoom(e.target.value);
  });

  window.addEventListener("resize", () => {
    const current = localStorage.getItem('envZoom') || slider.value;
    syncAppLayoutMetrics();
  });

  window.visualViewport?.addEventListener?.("resize", syncAppLayoutMetrics);
  window.visualViewport?.addEventListener?.("scroll", syncAppLayoutMetrics);
});
</script>

<script>
// Sidebar resize handle (drag to resize width)
document.addEventListener("DOMContentLoaded", () => {
  const sidebar = document.getElementById("sidebarContainer");
  const handle = document.getElementById("sidebarDragHandle");
  if (!sidebar || !handle) return;

  const minW = 220;
  const maxW = 520;
  const overlay = document.createElement("div");
  overlay.className = "resize-overlay";
  document.body.appendChild(overlay);

  const saved = Number(localStorage.getItem("sidebarWidth"));
  if (!Number.isNaN(saved) && saved >= minW && saved <= maxW) {
    sidebar.style.width = `${saved}px`;
    sidebar.dataset.resized = "1";
  }

  let dragging = false;
  let rafId = null;
  let lastX = null;

  const applyWidth = () => {
    if (!dragging || lastX === null) return;
    const next = Math.max(minW, Math.min(maxW, lastX));
    sidebar.style.width = `${next}px`;
    sidebar.dataset.resized = "1";
    localStorage.setItem("sidebarWidth", String(next));
    rafId = null;
  };

  const onMove = (e) => {
    if (!dragging) return;
    e.preventDefault?.();
    lastX = e.touches ? e.touches[0].clientX : e.clientX;
    if (rafId === null) rafId = requestAnimationFrame(applyWidth);
  };

  const stop = () => {
    dragging = false;
    document.body.style.userSelect = "";
    document.body.style.cursor = "";
    document.body.classList.remove("is-resizing");
    overlay.style.display = "none";
    if (rafId !== null) cancelAnimationFrame(rafId);
    rafId = null;
  };

  const start = (e) => {
    dragging = true;
    e.preventDefault?.();
    document.body.style.userSelect = "none";
    document.body.style.cursor = "col-resize";
    document.body.classList.add("is-resizing");
    overlay.style.display = "block";
    onMove(e);
  };

  handle.addEventListener("mousedown", start);
  window.addEventListener("mousemove", onMove);
  window.addEventListener("mouseup", stop);

  handle.addEventListener("touchstart", start, { passive: false });
  window.addEventListener("touchmove", onMove, { passive: false });
  window.addEventListener("touchend", stop);
});
</script>


<div id="twQuickOnboarding" class="tw-onboard-overlay" aria-hidden="true">
  <div class="tw-onboard-card" role="dialog" aria-modal="true" aria-labelledby="twOnboardTitle">
    <h3 id="twOnboardTitle">Finish your profile</h3>
    <p>Add avatar and select role.</p>

    <div class="tw-onboard-avatar-panel">
      <img id="twOnboardAvatarPreview" src="/default-avatar.png" alt="Avatar preview" onerror="this.onerror=null;this.src='/default-avatar.png';">
      <div>
        <div class="tw-onboard-avatar-title">Avatar preview</div>
        <div class="tw-onboard-avatar-hint">Result shown here before save.</div>
      </div>
    </div>

    <label for="twOnboardAvatarFile">Avatar image</label>
    <input type="file" id="twOnboardAvatarFile" accept="image/*">
    <div id="twOnboardCropper" class="tw-onboard-cropper" aria-hidden="true">
      <canvas id="twOnboardCropCanvas" width="180" height="180"></canvas>
      <label for="twOnboardZoom">Zoom</label>
      <input type="range" id="twOnboardZoom" min="1" max="3" step="0.01" value="1">
    </div>

    <label for="twOnboardInviteRole">Group role</label>
    <select id="twOnboardInviteRole"></select>

    <span id="twOnboardStatus" aria-live="polite"></span>
    <div class="tw-onboard-actions">
      <button type="button" id="twOnboardSkipBtn">Skip</button>
      <button type="button" id="twOnboardSaveBtn">Save</button>
    </div>
  </div>
</div>

<script>
document.addEventListener("DOMContentLoaded", () => {
  if (!window.TW_SHOW_QUICK_ONBOARDING) return;

  const overlay = document.getElementById("twQuickOnboarding");
  const avatarInput = document.getElementById("twOnboardAvatarFile");
  const avatarPreview = document.getElementById("twOnboardAvatarPreview");
  const onboardCropper = document.getElementById("twOnboardCropper");
  const onboardCropCanvas = document.getElementById("twOnboardCropCanvas");
  const onboardZoom = document.getElementById("twOnboardZoom");
  const cropCtx = onboardCropCanvas ? onboardCropCanvas.getContext("2d") : null;
  const inviteRoleSelect = document.getElementById("twOnboardInviteRole");
  const statusEl = document.getElementById("twOnboardStatus");
  const skipBtn = document.getElementById("twOnboardSkipBtn");
  const saveBtn = document.getElementById("twOnboardSaveBtn");

  if (!overlay || !statusEl || !skipBtn || !saveBtn) return;

  if (avatarPreview) {
    avatarPreview.src = (window.SESSION_AVATAR_URL || "/default-avatar.png").trim() || "/default-avatar.png";
  }
  const roleOptionsFallback = Array.isArray(window.TW_ONBOARD_ROLE_OPTIONS) ? window.TW_ONBOARD_ROLE_OPTIONS.filter(Boolean) : [];
  const currentRole = (window.TW_ONBOARD_CURRENT_ROLE || "").trim();
  const onboardOwnerId = parseInt(window.TW_ONBOARD_OWNER_ID || "0", 10);
  const cropState = {
    img: null,
    baseScale: 1,
    zoom: 1,
    offsetX: 0,
    offsetY: 0,
    dragging: false,
    lastX: 0,
    lastY: 0
  };

  const drawCropPreview = () => {
    if (!cropCtx || !onboardCropCanvas || !cropState.img) return;
    const canvasW = onboardCropCanvas.width;
    const canvasH = onboardCropCanvas.height;
    cropCtx.clearRect(0, 0, canvasW, canvasH);
    cropCtx.save();
    cropCtx.beginPath();
    cropCtx.arc(canvasW / 2, canvasH / 2, canvasW / 2, 0, Math.PI * 2);
    cropCtx.clip();

    const scale = cropState.baseScale * cropState.zoom;
    const drawW = cropState.img.width * scale;
    const drawH = cropState.img.height * scale;
    const x = (canvasW - drawW) / 2 + cropState.offsetX;
    const y = (canvasH - drawH) / 2 + cropState.offsetY;
    cropCtx.drawImage(cropState.img, x, y, drawW, drawH);
    cropCtx.restore();
  };

  const buildCropBlob = async (maxBytes) => {
    if (!cropState.img || !onboardCropCanvas) return null;
    const outputSize = 512;
    const output = document.createElement("canvas");
    output.width = outputSize;
    output.height = outputSize;
    const outputCtx = output.getContext("2d");
    if (!outputCtx) return null;

    const scale = cropState.baseScale * cropState.zoom;
    const offsetScale = outputSize / onboardCropCanvas.width;
    const drawW = cropState.img.width * scale * offsetScale;
    const drawH = cropState.img.height * scale * offsetScale;
    const x = (outputSize - drawW) / 2 + cropState.offsetX * offsetScale;
    const y = (outputSize - drawH) / 2 + cropState.offsetY * offsetScale;

    outputCtx.save();
    outputCtx.beginPath();
    outputCtx.arc(outputSize / 2, outputSize / 2, outputSize / 2, 0, Math.PI * 2);
    outputCtx.clip();
    outputCtx.drawImage(cropState.img, x, y, drawW, drawH);
    outputCtx.restore();

    let quality = 0.9;
    let blob = await new Promise((resolve) => output.toBlob(resolve, "image/jpeg", quality));
    while (blob && blob.size > maxBytes && quality > 0.4) {
      quality -= 0.1;
      blob = await new Promise((resolve) => output.toBlob(resolve, "image/jpeg", quality));
    }
    return blob;
  };
  const renderRoleOptions = (roles) => {
    if (!inviteRoleSelect) return;
    inviteRoleSelect.innerHTML = "";
    const cleanRoles = Array.isArray(roles) ? roles.filter(Boolean) : [];
    if (cleanRoles.length === 0) {
      const opt = document.createElement("option");
      opt.value = "";
      opt.textContent = "No role options";
      inviteRoleSelect.appendChild(opt);
      inviteRoleSelect.disabled = true;
      return;
    }
    inviteRoleSelect.disabled = false;
    const blank = document.createElement("option");
    blank.value = "";
    blank.textContent = "Select role";
    inviteRoleSelect.appendChild(blank);
    cleanRoles.forEach((role) => {
      const opt = document.createElement("option");
      opt.value = role;
      opt.textContent = role;
      if (currentRole !== "" && role === currentRole) opt.selected = true;
      inviteRoleSelect.appendChild(opt);
    });
  };

  const loadRoleOptions = async () => {
    if (onboardOwnerId > 0) {
      try {
        const res = await fetch(`/getMemberRolesDistinct.php?owner_id=${encodeURIComponent(onboardOwnerId)}`);
        const data = await res.json().catch(() => ({}));
        const roles = Array.isArray(data.roles) ? data.roles : [];
        if (roles.length > 0) {
          renderRoleOptions(roles);
          return;
        }
      } catch (_) {}
    }
    renderRoleOptions(roleOptionsFallback);
  };
  loadRoleOptions();

  const closeOnboarding = () => {
    overlay.classList.remove("is-open");
    overlay.setAttribute("aria-hidden", "true");
    try {
      const url = new URL(window.location.href);
      url.searchParams.delete("tw_onboard");
      window.history.replaceState({}, "", url.pathname + (url.search ? url.search : "") + (url.hash || ""));
    } catch (_) {}
  };

  overlay.classList.add("is-open");
  overlay.setAttribute("aria-hidden", "false");

  if (avatarInput) {
    avatarInput.addEventListener("change", () => {
      const file = avatarInput.files && avatarInput.files[0];
      if (!file || !avatarPreview) return;
      try {
        const previewUrl = URL.createObjectURL(file);
        avatarPreview.src = previewUrl;
        statusEl.style.color = "#44506a";
        statusEl.textContent = "Preview ready. Click Save to upload.";
      } catch (_) {}

      if (!onboardCropCanvas || !onboardCropper) return;
      const reader = new FileReader();
      reader.onload = () => {
        const img = new Image();
        img.onload = () => {
          cropState.img = img;
          cropState.offsetX = 0;
          cropState.offsetY = 0;
          cropState.zoom = 1;
          if (onboardZoom) onboardZoom.value = "1";
          cropState.baseScale = Math.max(
            onboardCropCanvas.width / img.width,
            onboardCropCanvas.height / img.height
          );
          onboardCropper.classList.add("is-visible");
          onboardCropper.setAttribute("aria-hidden", "false");
          drawCropPreview();
        };
        img.src = String(reader.result || "");
      };
      reader.readAsDataURL(file);
    });
  }

  if (onboardZoom) {
    onboardZoom.addEventListener("input", () => {
      cropState.zoom = parseFloat(onboardZoom.value) || 1;
      drawCropPreview();
    });
  }

  if (onboardCropCanvas) {
    onboardCropCanvas.addEventListener("pointerdown", (event) => {
      if (!cropState.img) return;
      cropState.dragging = true;
      cropState.lastX = event.clientX;
      cropState.lastY = event.clientY;
      onboardCropCanvas.setPointerCapture(event.pointerId);
    });

    onboardCropCanvas.addEventListener("pointermove", (event) => {
      if (!cropState.dragging) return;
      const dx = event.clientX - cropState.lastX;
      const dy = event.clientY - cropState.lastY;
      cropState.lastX = event.clientX;
      cropState.lastY = event.clientY;
      cropState.offsetX += dx;
      cropState.offsetY += dy;
      drawCropPreview();
    });

    const endDrag = (event) => {
      if (!cropState.dragging) return;
      cropState.dragging = false;
      onboardCropCanvas.releasePointerCapture(event.pointerId);
    };
    onboardCropCanvas.addEventListener("pointerup", endDrag);
    onboardCropCanvas.addEventListener("pointerleave", () => {
      cropState.dragging = false;
    });
  }

  skipBtn.addEventListener("click", () => {
    closeOnboarding();
  });

  saveBtn.addEventListener("click", async () => {
    statusEl.style.color = "#44506a";
    statusEl.textContent = "Saving...";
    saveBtn.disabled = true;
    skipBtn.disabled = true;

    try {
      let avatarUrl = (window.SESSION_AVATAR_URL || "").trim();
      const file = avatarInput && avatarInput.files ? avatarInput.files[0] : null;

      if (file) {
        let preparedFile = file;
        const maxBytes = 10 * 1024 * 1024;
        if (cropState.img) {
          const blob = await buildCropBlob(maxBytes);
          if (!blob) {
            throw new Error("Could not process image.");
          }
          if (blob.size > maxBytes) {
            throw new Error("Image exceeds 10MB after compression.");
          }
          preparedFile = new File([blob], "avatar.jpg", { type: blob.type });
        }
        const formData = new FormData();
        formData.append("avatar", preparedFile);
        const uploadRes = await fetch("/sub_upload_avatar.php", { method: "POST", body: formData });
        const uploadData = await uploadRes.json().catch(() => ({}));
        if (!uploadRes.ok) {
          throw new Error(uploadData.error || "Upload failed.");
        }
        avatarUrl = (uploadData.avatar_url || "").trim();
        if (avatarPreview && avatarUrl) {
          avatarPreview.src = avatarUrl;
        }
      }

      const displayName = (window.SESSION_DISPLAY_NAME || window.SESSION_USERNAME || "").trim();
      const body =
        "display_name=" + encodeURIComponent(displayName) +
        "&avatar_url=" + encodeURIComponent(avatarUrl) +
        "&profile_type=person" +
        "&group_type=";

      const profileRes = await fetch("/sub_update_user_profile.php", {
        method: "POST",
        headers: { "Content-Type": "application/x-www-form-urlencoded" },
        body
      });
      const profileData = await profileRes.json().catch(() => ({}));
      if (!profileRes.ok) {
        throw new Error(profileData.error || "Save failed.");
      }

      const newAvatar = (profileData.avatar_url || avatarUrl || "/default-avatar.png");
      const selectedRole = inviteRoleSelect && !inviteRoleSelect.disabled ? (inviteRoleSelect.value || "").trim() : "";
      const ownerToken = (window.TW_ONBOARD_OWNER_TOKEN || "").trim();
      if (ownerToken !== "" && selectedRole !== "") {
        const roleBody =
          "owner_token=" + encodeURIComponent(ownerToken) +
          "&role=" + encodeURIComponent(selectedRole);
        const roleRes = await fetch("/ep_set_my_group_role.php", {
          method: "POST",
          headers: { "Content-Type": "application/x-www-form-urlencoded" },
          body: roleBody
        });
        const roleData = await roleRes.json().catch(() => ({}));
        if (!roleRes.ok) {
          throw new Error(roleData.message || "Could not save group role.");
        }
      }

      window.SESSION_AVATAR_URL = newAvatar;
      document.querySelectorAll(".settings-avatar, .home-avatar").forEach((img) => {
        img.src = newAvatar;
      });
      const profileAvatar = document.getElementById("homeCurrentProfileAvatar");
      if (profileAvatar && window.currentProfileUsername === window.SESSION_USERNAME) {
        profileAvatar.src = newAvatar;
      }

      statusEl.style.color = "green";
      statusEl.textContent = "Saved.";
      closeOnboarding();
    } catch (err) {
      statusEl.style.color = "darkred";
      statusEl.textContent = (err && err.message) ? err.message : "Save failed.";
      saveBtn.disabled = false;
      skipBtn.disabled = false;
    }
  });
});
</script>

<!-- Lucide icon library -->
<!--<script src="https://unpkg.com/lucide@latest"></script>-->




<!--<footer class="footer-controls">-->

<footer>
  <div id="footerMenu" class="mobile-footer-menu">
    <button data-target="sidebar" class="footer-tab-btn" title="Sidebar">
      <i data-lucide="utensils-crossed"></i>
    </button>
    <button data-target="reservationsTab" class="footer-tab-btn" title="Reservation planner">
      <i data-lucide="calendar-clock"></i>
    </button>
    <button data-target="chatTab" class="footer-tab-btn" title="Chat">
      <div style="position: relative; display: inline-block;">
        <i data-lucide="message-circle"></i>
        <span id="chatUnreadBadge" class="footer-chat-badge zero" style="display:none;">0</span>
      </div>
    </button>
    <?php if (isset($_SESSION['user_id'])): ?>
    <button data-target="pdfTab" class="footer-tab-btn nav-link" title="Item details">
      <i data-lucide="cooking-pot"></i>
    </button>
    <button data-target="menuOrdersTab" class="footer-tab-btn" title="Menu orders">
      <i data-lucide="chef-hat"></i>
    </button>
    <button data-target="calendarTab" class="footer-tab-btn" title="Event planner">
      <i data-lucide="calendar-days"></i>
    </button>
    <?php endif; ?>
    <button data-target="fullscreen" class="footer-tab-btn" title="Fullscreen">
      <i data-lucide="maximize-2"></i>
    </button>
  </div>
</footer>



</body>


</html>
