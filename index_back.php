<?php
header("Cache-Control: no-store, no-cache, must-revalidate, max-age=0");
header("Expires: 0");
header("Pragma: no-cache");
include_once __DIR__ . '/includes/db_connect.php';
include_once __DIR__ . '/includes/functions.php';
require_once __DIR__ . '/includes/translate.php';
require_once __DIR__ . '/chatConfig.php';

cleanGhostCookies(); 
sec_session_start(); 


$listOwnerUsername = $_SESSION['username'] ?? '';

//Version is now se globally in service-worker.php
$version = 'v1542'; 


header('Content-Type: text/html; charset=utf-8');

// Extract clean path
$segments = explode('/', trim(parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH), '/'));
$target = $segments[0] ?? '';
$surrogate = $segments[1] ?? '';




if (
    $target === '' &&
    login_check($mysqli) &&
    isset($_SESSION['username']) &&
    $_SESSION['username'] !== 'welcome'
) {
    header("Location: /" . urlencode($_SESSION['username']));
    exit;
}


$loggedIn = login_check($mysqli);


// ✅ Fetch text content dynamically for Open Graph description
$textContent = "";
$mysqli->set_charset("utf8mb4"); // Ensure proper UTF-8mb4 encoding

if ($stmt = $mysqli->prepare("SELECT Text FROM text WHERE surrogate = ?")) {
    $stmt->bind_param("s", $surrogate);
    $stmt->execute();
    $result = $stmt->get_result();

    if ($row = $result->fetch_assoc()) {
        $textContent = trim(htmlspecialchars($row['Text'], ENT_QUOTES, 'UTF-8'));
    }
    
    $stmt->close();
}

// ✅ Ensure description is not empty
if (empty($textContent)) {
    $textContent = "Explore creative texts on TextWhisper. Share your thoughts and read others!";
}

// ✅ Limit description to 150 characters for Messenger preview
$previewText = strip_tags(mb_substr($textContent, 0, 150));
$previewText = preg_replace('/\s+/', ' ', $previewText); // Remove excessive whitespace

// ✅ Generate a dynamic title based on the text
$titleText = explode(' ', $textContent);
$dynamicTitle = implode(' ', array_slice($titleText, 0, 8));

if (empty(trim($dynamicTitle))) {
    $dynamicTitle = "TextWhisper - Read & Share Text"; // Default if no text exists
}

//Set a proper fallback Open Graph image (600x315 required for Messenger)
$imageURL = "https://textwhisper.com/img/wrt.png"; 
//include_once __DIR__ . '/chat.php';


//If no surrogate, treat as list link
if (!$surrogate && $target) {
    $listName = '';
    $owner = '';

    // ✅ Get list name and owner username
    if ($stmt = $mysqli->prepare("
        SELECT cl.name, m.username
        FROM content_lists cl
        JOIN members m ON cl.owner_id = m.id
        WHERE cl.token = ?
    ")) {
        $stmt->bind_param("s", $target);
        $stmt->execute();
        $stmt->bind_result($name, $username);
        if ($stmt->fetch()) {
            $listName = trim($name);
            $owner = trim($username);
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



// Default fallback
$locale = 'en';


// Check DB first and set session
if (!empty($_SESSION['username'])) {
    // Fetch from DB only if not already cached
    $stmt = $mysqli->prepare("SELECT locale, display_name, fileserver FROM members WHERE username = ?");
    $stmt->bind_param("s", $_SESSION['username']);
    $stmt->execute();
    $stmt->bind_result($fetchedLocale, $fetchedDisplayName, $fetchedFileserver);

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
    }

    $stmt->close();
}

$LoggedInUser   = htmlspecialchars($_SESSION['username'] ?? '');
$displayname    = htmlspecialchars($_SESSION['display_name'] ?? '');
$userFileserver = htmlspecialchars($_SESSION['fileserver'] ?? 'php');

$role      = $_SESSION['role'] ?? 'guest';
$username  = $_SESSION['username'] ?? '';
$isAdmin   = !empty($_SESSION['is_admin']);

// ✅ Load language file
$langFile = __DIR__ . "/lang/{$locale}.php";
$lang = file_exists($langFile) ? include $langFile : [];
?>

<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">

<script>
try {
  document.cookie = "remember_token=;path=/;expires=Thu, 01 Jan 1970 00:00:00 GMT;SameSite=Lax";
  console.log("🧹 remember_token cookie deleted");
} catch (e) {}
</script>




  <!-- 🧩 Inject runtime context -->
  <script>
    window.currentListToken = <?= json_encode($target) ?>;
    window.currentSurrogate = <?= json_encode($surrogate) ?>;
    window.SESSION_USERNAME = <?= json_encode($LoggedInUser) ?>;
    window.SESSION_DISPLAY_NAME = <?= json_encode($displayname) ?>;
    window.fileServer = <?= json_encode($userFileserver) ?>;
    window.currentUsername = <?= json_encode($username) ?>;
    window.currentUserRole = <?= json_encode($role) ?>;
    window.isAdminUser = <?= $isAdmin ? 'true' : 'false' ?>;
    window.translations = <?= json_encode($lang) ?>;
  </script>

  <script>
       console.log = () => {}; // disable all console.log output
  </script>

  <meta http-equiv="Content-Type" content="text/html; charset=UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0, user-scalable=yes">
  <title><?= htmlspecialchars($dynamicTitle, ENT_QUOTES, 'UTF-8'); ?></title>

  <!-- ✅ Fixed Open Graph Meta Tags -->
  <meta property="og:title" content="<?php echo htmlspecialchars($dynamicTitle, ENT_QUOTES, 'UTF-8'); ?>" />
  <meta property="og:description" content="<?php echo htmlspecialchars($previewText, ENT_QUOTES, 'UTF-8'); ?>" />
  <meta property="og:type" content="article" />
  <meta property="og:site_name" content="TextWhisper" />
  
    <!--For iPhone-->
    <meta name="apple-mobile-web-app-capable" content="yes">
    <meta name="apple-mobile-web-app-status-bar-style" content="black-translucent">  
  
    <!--For offline-->
    <link rel="manifest" href="/manifest.json">
    <link rel="icon" href="/favicon.ico" type="image/x-icon">
    <link rel="apple-touch-icon" href="/wrt.png">
    <meta name="theme-color" content="#222831">
    <meta name="mobile-web-app-capable" content="yes">
    <meta name="apple-mobile-web-app-status-bar-style" content="black-translucent">


  <!--<script src="https://code.jquery.com/jquery-3.6.0.min.js"></script>-->
  <script src="/assets/jquery.min.js"></script>

  <!-- ✅ Required URL (Now placed correctly) -->
  <meta property="og:url" content="https://textwhisper.com<?php echo $_SERVER['REQUEST_URI']; ?>" />
  <!-- ✅ Facebook App ID (Optional) -->
  <!--meta property="fb:app_id" content="YOUR_FACEBOOK_APP_ID" /-->

  <!-- Bootstrap & Styles -->
  <!--<link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">-->
  <link href="/assets/bootstrap.min.css" rel="stylesheet">
  <script src="/assets/bootstrap.bundle.min.js"></script>
  <!-----for the audio recorder----->
  <script src="https://unpkg.com/fix-webm-duration"></script>

  
  <link rel="stylesheet" href="/myStyles.css?v=<?= $version ?>">
  <link rel="stylesheet" href="/chatStyles.css?v=<?= $version ?>">



  
</head>



<body 

  class="<?php echo isset($_SESSION['user_id']) ? 'logged-in' : 'not-logged-in'; ?>"
  data-user-id="<?php echo htmlspecialchars($_SESSION['user_id'] ?? '', ENT_QUOTES, 'UTF-8'); ?>">


<!-- 🚀 Navbar -->
<nav class="navbar navbar-dark bg-dark px-0">
  <div class="container-fluid d-flex align-items-center justify-content-between">

    <!-- 🔹 Home Icon (left aligned) -->
    <a href="/" class="d-flex align-items-center" title="<?= $lang['home'] ?? 'Home' ?>">
      <i data-lucide="home" class="lucide-icon"></i>
    </a>

    <!-- 🔹 Title Tab -->
    <button class="nav-link active text-tab flex-grow-1 text-center" data-target="textTab">
      <span id="selectedItemTitle" class="text-truncate d-inline-block">
        <?= $lang['loading'] ?? 'Loading...' ?>
      </span>
    </button>

    <!-- 🔹 Edit toggle + Settings -->
    <div class="d-flex align-items-center gap-3" style="z-index: 1102; position: relative;">

      <!-- ✅ Keep Edit Toggle as-is -->
      <div class="edit-controls form-check form-switch">
        <label class="form-check-label text-light small" style="font-size:14px;" for="editModeToggle">Edit</label>
        <input class="form-check-input edit-mode-toggle" style="margin-left: 0; margin-right:-8px;" type="checkbox" id="editModeToggle">
      </div>

      <!-- ⚙️ Settings Dropdown -->
      <div class="dropdown">
        <button class="settings-icon dropdown-toggle btn btn-dark p-0" type="button" data-bs-toggle="dropdown" title="<?= $lang['settings'] ?? 'Settings' ?>">
          <i data-lucide="settings" class="lucide-icon"></i>
        </button>

        <ul class="dropdown-menu dropdown-menu-end bg-dark text-light border-secondary">

          <li>
              <a href="/" class="dropdown-item" title="<?= $lang['home'] ?? 'Home' ?>"><i data-lucide="home" class="me-1"></i> <?= $lang['home_to'] ?? 'Home to:' ?> <?= htmlspecialchars($LoggedInUser) ?></a>
          </li>


          <li>
            <a class="dropdown-item" href="/login.php">
              <i data-lucide="key" class="me-1"></i> <?= $loggedIn ? ($lang['logout'] ?? 'Logout') : ($lang['login'] ?? 'Login') ?>
            </a>
          </li>

          <li>
            <div class="dropdown-item">
              <div class="edit-controls form-check form-switch ms-1">
                <input class="form-check-input edit-mode-toggle" type="checkbox" id="editToggle">
                <label class="form-check-label" for="editToggle">
                  <i data-lucide="pencil" class="me-1"></i> <?= $lang['edit'] ?? 'Edit' ?>
                </label>
              </div>
            </div>
          </li>

          <li><a class="dropdown-item" href="/sub_settings.php"><i data-lucide="user" class="me-1"></i> <?= $lang['my_profile'] ?? 'My Profile' ?></a></li>
          <li><a class="dropdown-item" href="/sub_pricing.php"><i data-lucide="credit-card" class="me-1"></i> <?= $lang['pricing'] ?? 'Pricing' ?></a></li>
          <li><a class="dropdown-item" href="/includes/about.php"><i data-lucide="help-circle" class="me-1"></i> <?= $lang['about'] ?? 'About' ?></a></li>
          <li><a class="dropdown-item" href="/includes/features.php"><i data-lucide="layers" class="me-1"></i> <?= $lang['features'] ?? 'Features' ?></a></li>
          <li><a class="dropdown-item" href="/includes/stories.php"><i data-lucide="book" class="me-1"></i> <?= $lang['stories'] ?? 'Stories' ?></a></li>
          <li id="installAppMenuItem" class="dropdown-item" style="cursor: pointer;"><i data-lucide="download" class="me-1"></i> <?= $lang['install_app'] ?? 'Install App' ?></li>
          
          <!--<li class="dropdown-item" onclick="resetApp()"><i data-lucide="trash-2" class="me-1"></i> <?= $lang['reset_cache'] ?? 'Reset App Cache' ?></li>-->
            
            <li class="dropdown-item">
              <div class="form-check form-switch ms-1">
                <input class="form-check-input" type="checkbox" id="toggleTextarea2" checked>
                <label class="form-check-label" for="toggleTextarea2">
                  <?= $lang['two_columns'] ?? 'Two columns' ?>
                </label>
              </div>
            </li>   
            <li class="dropdown-item">
              <div class="form-check form-switch ms-1">
                <input class="form-check-input" type="checkbox" id="togglePagedPDF">
                <label class="form-check-label" for="togglePagedPDF">
                  <?= $lang['paged_pdf_view'] ?? 'Paged PDF view' ?>
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
          <div class="create-dropdown">
            <button id="createButton"
                    class="create-btn"
                    onclick="toggleCreateMenu(this); event.stopPropagation();">
              Create
            </button>
            <div class="create-menu">
              <div onclick="closeCreateMenu(); createNewList('owned-<?= $username ?>'); event.stopPropagation();">🆕 New List</div>
              <div onclick="closeCreateMenu(); createNewItemFromMenu(); event.stopPropagation();">➕ New Item</div>
              <div onclick="closeCreateMenu(); openImportItemFilesModal(); event.stopPropagation();">📥 Import files</div>
              <div onclick="closeCreateMenu(); openImportItemsFromDropbox(); event.stopPropagation();">📦 Dropbox files</div>
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
          <i data-lucide="book"></i> <?= $lang['lists'] ?? 'Lists' ?>
        </button>
        <button class="tab-link" data-target="usersTab" role="tab">
          <i data-lucide="users"></i> <?= $lang['friends'] ?? 'Friends' ?>
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
    
        <div id="textToolbar" class="top-edit-toolbar" style="display: none;">
            <button id="newButton" class="edit-btn new-btn">➕ <?= $lang['new'] ?? 'New' ?></button>
            <button id="saveButton" class="edit-btn save-btn" disabled>💾 <?= $lang['save'] ?? 'Save' ?></button>
            <button id="deleteButton" class="edit-btn delete-btn" disabled>🗑 <?= $lang['delete'] ?? 'Delete' ?></button>
        </div>  
        
        <!-- 📄 PDF annotation toolbar -->

        <!-- Hidden annotation action hooks (temporary) -->
        <div style="display:none;">
          <button id="saveAnnotation"></button>
          <button id="clearAnnotation"></button>
          <button id="undoAnnotation"></button>
          <button id="refreshAnnotation"></button>
          <button id="printPdfBtn" onclick="printPDF()"></button>
          <button id="updatePdfBtn"></button>
        </div>

    
        <!-- 🚀 Text Content (Default View) -->
        <div id="textTabContent" class="main-tab-content active">
          <div class="slider-container">
            <label for="b"><?= $lang['trim'] ?? 'Trim' ?></label>
            <input onchange="textTrimmer(this.value)" type="range" id="b" value="0" min="0" max="8">
          </div>
        
<div class="textareas-container">
    
 <!-- 📄 Read-only mirror -->
<textarea id="myTextarea2" class="readonly-textarea" readonly><?php
  if (!empty($surrogate) && !empty($textContent)) {
    echo $textContent;
  }
?></textarea>



<!-- ✏️ Editable textarea -->
<div id="myTextarea"
     class="edit-textarea"
     contenteditable="true"
     placeholder="Subject: (The title of your list item 😊)

Body: (Write anything you like)

#hashtag → Prevents the trimming of words.

Special Tips: 📎
- Paste a PDF or Soundslice link to activate footer buttons.
- On desktop, you can drag and drop a PDF into the PDF tab.
- On mobile, use the item menu → “Add PDF” to upload from your device.

💡 Emoji Shortcut:
- Windows: Press Windows + . (period)
- Mac: Press Control + Command + Space
"><?php
  if (!empty($surrogate) && !empty($textContent)) {
    echo $textContent;
  }
?></div>



</div>
        </div>
    
        <!-- 📄 PDF Container -->
        <div id="pdfTabContent" class="main-tab-content">
            <!--<iframe id="pdfViewerFrame"></iframe>-->
        </div>
        
        <!-- 📁 Hidden file input for attaching PDF, MP3, or MIDI -->
        <input type="file" id="mobilePdfInput" 
               accept=".pdf,.mp3,.wav,.midi,.mid,audio/mpeg,audio/midi" 
               style="display: none;" />
    </div>
    
    
</div>    


<div id="musicTabContent" class="music-panel">
  <div id="midiList" class="sidebar-list-area"></div>
</div>


<link rel="stylesheet" href="/musicPanel.css?v=<?= $version ?>">
<!--<script src="/assets/Tone.min.js"></script>-->
<script src="https://unpkg.com/tone@next/build/Tone.js"></script>

<script src="/assets/MidiPlayerJS-master/browser/midiplayer.js"></script>
<script src="/assets/compactMidiPlayer.js?v=<?= $version ?>"></script>

<script src="/musicPanel.js?v=<?= $version ?>"></script>


<!-- 🔥 Scripts -->

<!--<script src="https://cdnjs.cloudflare.com/ajax/libs/pdf.js/2.10.377/pdf.min.js"></script>-->
<script src="/assets/pdf.min.js"></script>

<!--<script src="https://cdn.jsdelivr.net/npm/sortablejs@1.15.0/Sortable.min.js"></script>-->
<script src="/assets/sortable.min.js"></script>







<!--<script src="/assets/compactMidiPlayer.js?v=<?= $version ?>"></script>-->


<!--Service worker for offline mode-->
<!-- Service worker for offline mode -->
<script>
window.appVersion = "<?= $version ?>";  
window.forceUpdate = false;
    //When to use forceUpdate
    // - Update bad or stuck service-worker
    // - Only for critical deploys
    // - When you know many users are still on an older version
    // - To silently ensure everyone gets the fix

if ('serviceWorker' in navigator) {
  const swUrl = `/service-worker.js?v=${window.appVersion}`;
  navigator.serviceWorker.register(swUrl)
    .then((reg) => {
      console.log("✅ SW registered with version:", window.appVersion, reg);

      if (reg.waiting) {
        console.log("⚡ New SW is waiting, activating now...");
        reg.waiting.postMessage({ type: 'SKIP_WAITING' });
      }

      if (reg.installing) {
        reg.installing.addEventListener('statechange', (e) => {
          if (e.target.state === 'installed' && navigator.serviceWorker.controller) {
            console.log("⚡ New SW installed, waiting for activation…");
          }
        });
      }
    })
    .catch(err => console.error("❌ SW registration failed:", err));

  let refreshing = false;
  navigator.serviceWorker.addEventListener("controllerchange", () => {
    if (refreshing) return;
    refreshing = true;

    // 🟦 Only reload if appVersion changed
    const lastSeen = localStorage.getItem("lastSeenSWVersion");
    if (lastSeen !== window.appVersion) {
      localStorage.setItem("lastSeenSWVersion", window.appVersion);

      const banner = document.createElement("div");
      banner.textContent = "🔄 Updating app… Please wait";
      banner.style = `
        position: fixed;
        top: 0; left: 0; right: 0;
        background: #222; color: #fff;
        text-align: center;
        padding: 8px; font-size: 14px;
        z-index: 9999;
      `;
      document.body.appendChild(banner);

      console.log("🔄 New service worker activated, reloading page...");
      setTimeout(() => window.location.reload(), 1200);
    } else {
      console.log("⚠️ SW controllerchange fired, but version already applied. Skipping reload.");
    }
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





<!-- include chat.php and musicPanel.php here -->
<?php include_once __DIR__ . '/chat.php'; ?>



<!-- ✅ User Settings Panel -->
<!--?php include_once __DIR__ . '/includes/user_settings.php'; ?-->



<!-- ✅ Load event handlers first -->
<script src="/JSEvents.js?v=<?= $version ?>"></script>  
<script src="/JSEventsHeaderTabs.js?v=<?= $version ?>"></script>  

<script src="/JSDragDdropPDF.js?v=<?= $version ?>"></script> 
<script src="/JSExtractURLs.js?v=<?= $version ?>"></script> 
<script src="/JSFunctions_Offline.js?v=<?= $version ?>"></script> 

<script src="/JSCommon.js?v=<?= $version ?>"></script>
<script src="/JSFunctions.js?v=<?= $version ?>"></script>
<script src="/JSCreateImportPDFs.js?v=<?= $version ?>"></script>
<script src="/JSDrawingPDF.js?v=<?= $version ?>"></script>
<script src="/JSFunctions_Trimmer.js?v=<?= $version ?>"></script> 
<script src="/JSTextComments.js?v=<?= $version ?>"></script> 

<script src="/chatEncryption.js?v=<?= $version ?>"></script>

<script src="/assets/lucide.min.js?v=<?= $version ?>"></script> 
<script src="/includes/colorAdjustments.js?v=<?= $version ?>"></script>




<script>

// to do: Move those scripts to script funtion

function applyFontSize(size) {
  //document.body.style.fontSize = size + 'px';

  // ✅ Explicitly scale ONLY the main textareas
  const mainTextareas = [
    document.getElementById('myTextarea'),
    document.getElementById('myTextarea2')
  ];

//   mainTextareas.forEach(el => {
//     if (el) el.style.fontSize = size + 'px';
//   });

    mainTextareas.forEach(el => {
      if (!el) return;
      el.style.setProperty('font-size', size + 'px', 'important');
      el.style.lineHeight = Math.round(size * 1.4) + 'px'; // smoother spacing
    });


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
  const toggle = document.getElementById('toggleTextarea2');
  const box1   = document.getElementById('myTextarea2'); // first textarea
  const saved  = localStorage.getItem('twoColumns');

  // Restore state (default true = show both)
  if (saved === 'false') {
    toggle.checked = false;
    if (box1) box1.style.display = 'none';
  }

  // Listen for toggle
  toggle?.addEventListener('change', (e) => {
    if (box1) box1.style.display = e.target.checked ? '' : 'none';
    localStorage.setItem('twoColumns', e.target.checked);
  });


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
});

</script>

<script>
function applyEnvZoom(size) {
  // Sidebar
  const sidebar = document.getElementById('sidebarContainer');
  if (sidebar) {
    sidebar.style.fontSize = size + 'px';
    sidebar.style.width = (size * 18) + 'px';
    sidebar.querySelectorAll('*').forEach(el => {
      el.style.fontSize = size + 'px';
    });
  }

  // Header (navbar)
  document.querySelectorAll('nav.navbar, nav.navbar *')
    .forEach(el => {
      el.style.fontSize = size + 'px';
    });

  // Footer menu container
  const footerMenu = document.getElementById('footerMenu');
  if (footerMenu) {
    footerMenu.style.fontSize = size + 'px';
    footerMenu.style.height = (size * 2.5) + 'px';
  }

  // Footer buttons
  document.querySelectorAll('#footerMenu .footer-tab-btn').forEach(btn => {
    btn.style.width = (size * 2.5) + 'px';
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
    chatContainer.style.width  = (size * 19) + 'px';  // default ~288px
    chatContainer.style.height = (size * 25) + 'px';  // default ~400px
    

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
      chatInput.style.padding   = (size * 0.5) + 'px ' + (size * 2.5) + 'px ' + (size * 0.5) + 'px ' + (size * 0.8) + 'px';
    }

    // Emoji/bell toggle
    const emojiBtn = document.getElementById('emojiToggleBtn');
    if (emojiBtn) {
      emojiBtn.style.fontSize = (size * 1.2) + 'px';
      emojiBtn.style.right    = (size * 0.4) + 'px';
    }

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


  // 🎵 Scale music panels (floating + pinned)
  scaleMusicPanel(size);

//----

  // ✅ Scale chat selector dropdown (if it exists)
  scaleChatSelectors(size);

//-----

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
        chatContainer.style.bottom = (footerHeight + 20) + 'px';
      }
    }

}






function scaleMusicPanel(size) {
  // 🎵 Main music tab area
  const musicTab = document.getElementById("musicTabContent");
  if (musicTab) {
    musicTab.style.fontSize = size + "px";

    // Each music item row (.musicPanel-item.mb-2)
    musicTab.querySelectorAll(".musicPanel-item").forEach(item => {
      item.style.fontSize = size + "px";

      // Header row (title + pin button)
      const header = item.querySelector(".musicPanel-header");
      if (header) header.style.fontSize = size + "px";

      // Titles and labels
      item.querySelectorAll(".musicPanel-title").forEach(title => {
        title.style.fontSize = size + "px";
      });
    });

    // 🎚️ Music footer buttons (Add / Dropbox / Record)
    musicTab.querySelectorAll(
      ".music-dropbox-btn, .music-upload-toggle, .music-recording"
    ).forEach(btn => {
      btn.style.fontSize = size + "px";
      btn.style.padding = (size * 0.4) + "px " + (size * 0.8) + "px";
      btn.style.borderRadius = (size * 0.25) + "px";
      btn.style.margin = (size * 0.25) + "px";
    });
  }

  // 🎧 Pinned or floating players
  document.querySelectorAll(".musicPanel-floating, .musicPanel-bottomPinned").forEach(panel => {
    panel.style.fontSize = size + "px";

    // Buttons
    panel.querySelectorAll(".player-btn").forEach(btn => {
      btn.style.width  = (size * 2.2) + "px";
      btn.style.height = (size * 2.2) + "px";
      btn.style.fontSize = size + "px";
    });

    // Big play/pause button
    panel.querySelectorAll(".player-btn.big").forEach(btn => {
      btn.style.width  = (size * 2.8) + "px";
      btn.style.height = (size * 2.8) + "px";
      btn.style.fontSize = (size * 1.1) + "px";
    });

    // Speed selector
    panel.querySelectorAll(".speed-select").forEach(sel => {
      sel.style.fontSize = (size * 0.9) + "px";
      sel.style.height = (size * 1.8) + "px";
      sel.style.padding = (size * 0.2) + "px " + (size * 0.4) + "px";
    });

    // Progress bar
    panel.querySelectorAll(".progress-bar").forEach(bar => {
    //   bar.style.height = (size * 0.04) + "em";
      bar.style.width = "100%"; // full stretch
    });

    // Time labels
    panel.querySelectorAll(".time-labels span").forEach(span => {
      span.style.fontSize = (size * 0.9) + "px";
    });
  });
}






// ✅ Global observer to catch when selector is added dynamically
const observer = new MutationObserver(() => {
  const size = localStorage.getItem('envZoom') || 16;
  scaleChatSelectors(size);
});

observer.observe(document.body, { childList: true, subtree: true });

// 🎵 Global observer to scale music panels when they appear
const musicObserver = new MutationObserver(() => {
  const size = localStorage.getItem('envZoom') || 16;
  scaleMusicPanel(size);
});

musicObserver.observe(document.body, { childList: true, subtree: true });

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

  // Listen for user changes
  slider.addEventListener('input', e => {
    applyEnvZoom(e.target.value);
  });

  // Reapply zoom when sidebar content changes
  const sidebar = document.getElementById('sidebarContainer');
  if (sidebar) {
    const observer = new MutationObserver(() => {
      const current = localStorage.getItem('envZoom') || slider.value;
      applyEnvZoom(current);
    });
    observer.observe(sidebar, { childList: true, subtree: true });
  }
});
</script>


<!-- Lucide icon library -->
<!--<script src="https://unpkg.com/lucide@latest"></script>-->




<!--<footer class="footer-controls">-->

<footer>
  <div id="footerMenu" class="mobile-footer-menu">
    <button data-target="sidebar" class="footer-tab-btn" title="Sidebar">
      <i data-lucide="layout-dashboard"></i>
    </button>
    <button data-target="textTab" class="footer-tab-btn nav-link active" title="Text">
      <i data-lucide="file-text"></i>
    </button>
    <button data-target="pdfTab" class="footer-tab-btn nav-link" title="PDF">
      <i data-lucide="book-open"></i>
    </button>
    <button data-target="musicTab" class="footer-tab-btn" title="Music">
      <i data-lucide="music-4"></i>
    </button>
    <button data-target="chatTab" class="footer-tab-btn" title="Chat">
      <div style="position: relative; display: inline-block;">
        <i data-lucide="message-circle"></i>
        <span id="chatUnreadBadge" class="footer-chat-badge zero" style="display:none;">0</span>
      </div>
    </button>

    <button data-target="fullscreen" class="footer-tab-btn" title="Fullscreen">
      <i data-lucide="maximize-2"></i>
    </button>
  </div>
</footer>



</body>


</html>
