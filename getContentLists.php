<?php
require_once __DIR__ . "/includes/functions.php";
require_once __DIR__ . "/includes/db_connect.php";
require_once __DIR__ . '/includes/translate.php';

sec_session_start();



$locale = $_SESSION['locale'] ?? 'en';
$langFile = __DIR__ . "/lang/{$locale}.php";
$lang = file_exists($langFile) ? include $langFile : [];

$con = $mysqli;

$expandToken = $_GET['token'] ?? null;
$user_id = null;
$username = "welcome";

$renderedListTokens = [];


//Get invitedRoleRanks
$invitedRoleRanks = [];
if (isset($_SESSION['username'])) {
    // Invitations acceptance flag is deprecated; presence of invite grants access.
    $stmt = $con->prepare("
        SELECT i.listToken, i.role_rank
        FROM invitations i
        JOIN members m ON i.email = m.email
        WHERE m.username = ?
    ");
    $stmt->bind_param("s", $_SESSION['username']);
    $stmt->execute();
    $result = $stmt->get_result();
    while ($row = $result->fetch_assoc()) {
        $invitedRoleRanks[$row['listToken']] = (int)$row['role_rank'];
    }
    $stmt->close();
}



function renderListNodes($ownerId) {
    global $con, $expandToken, $unreadCounts, $invitedRoleRanks, $lang, $renderedListTokens, $allContentRow;

    $sql = "
        SELECT cl.id, cl.name, cl.token, cl.owner_id, cl.access_level,
               COUNT(cli.id) AS item_count
        FROM content_lists cl
        LEFT JOIN content_list_items cli ON cli.content_list_id = cl.id
        WHERE cl.owner_id = ? AND cl.parent_id IS NULL
        GROUP BY cl.id
        ORDER BY cl.order_index ASC, cl.created_at ASC
    ";

    $stmt = $con->prepare($sql);
    $stmt->bind_param("i", $ownerId);
    $stmt->execute();
    $res = $stmt->get_result();

    while ($row = $res->fetch_assoc()) {
        if (!userCanAccessList($row, $expandToken, $con)) continue;

        // Handle All Content separately
        if ($row['name'] === "All Content") {
            $allContentRow = $row;
            continue;
        }

        $token = htmlspecialchars($row['token'], ENT_QUOTES);
        if (in_array($row['token'], $renderedListTokens)) continue;
        $renderedListTokens[] = $row['token'];

        $name        = htmlspecialchars($row['name'], ENT_QUOTES);
        $count       = (int)$row['item_count'];
        $listOwnerId = (int)$row['owner_id'];

        $isExpanded  = ($row['token'] === $expandToken);
        $arrow       = $isExpanded ? '▼' : '▶';
        $display     = $isExpanded ? 'block' : 'none';
        $activeClass = $isExpanded ? 'active-list' : '';

        $unread      = $unreadCounts[$token] ?? 0;
        $privacyIcon = match ($row['access_level']) {
            'public' => '🌐',
            'private' => '🔒',
            'secret' => '🕵️',
            default => '❓',
        };
        $isSaved     = in_array($row['token'], $_SESSION['saved_lists'] ?? []);
        $chatBadge   = "<span id='chat-unread-$token' class='chat-inline-badge" . ($unread > 0 ? " unread" : "") . "' onclick='event.stopPropagation(); openChatFromMenu(\"$token\")' title=\"Open chat\">$unread</span>";
        $canCreate   = (($_SESSION['user_id'] ?? null) === $listOwnerId) || (($invitedRoleRanks[$token] ?? 0) >= 60);


        echo "
          <div class='list-group-item group-item owned-list $activeClass' data-group='$token'>
            <div class='list-header-row'>
              <span class='arrow'>$arrow</span>
              <span class='list-title' id='list-title-$token'>$name</span>
              <span class='list-count'>($count)</span> $chatBadge
              <div class='list-menu-wrapper'>
                <button class='menu-button' data-token='$token' onclick='toggleListMenu(this); event.stopPropagation();'>⋮</button>
                <div class='list-menu-dropdown'>
                  <div class='list-choice' onclick='shareLink(\"$token\"); event.stopPropagation();'>🔗 " . ($lang['share_list'] ?? 'Share this list') . "</div>
                  <div class='list-choice' onclick='openChatFromMenu(\"$token\"); event.stopPropagation();'>💬 " . ($lang['list_chat'] ?? 'List Chat') . "</div>
                  <div class='list-choice' data-token='$token' onclick='setListPrivacyPrompt(\"$token\", this); event.stopPropagation();'>
                    <span class='privacy-icon' id='privacy-icon-$token'>$privacyIcon</span> " . ($lang['set_list_privacy'] ?? 'Set list privacy') . "
                  </div>
                  <div class='list-choice toggle-offline-status' data-token='$token' onclick='toggleOfflineStatus(\"$token\", this);'>📥 " . ($lang['make_offline'] ?? 'Make available offline') . "</div>
                  <div class='list-choice toggle-list-status' onclick='toggleMyList(\"$token\", this);'>" . ($isSaved ? "📤 " . ($lang['remove_from_my_lists'] ?? 'Remove from My Lists') : "📥 " . ($lang['add_to_my_lists'] ?? 'Add to My Lists')) . "</div>
                  " . ($canCreate ? "
                    <div class='list-choice' onclick='createNewList(\"$token\"); event.stopPropagation();'>🆕 " . ($lang['create_new_list'] ?? 'Create New List') . "</div>
                    <div class='list-choice' onclick='showRenameListInput(this, \"$token\"); event.stopPropagation();'>✏️ " . ($lang['rename_list'] ?? 'Rename List') . "</div>
                    <div class='list-choice' onclick='confirmDeleteList(\"$token\"); event.stopPropagation();'>🗑️ " . ($lang['delete_list'] ?? 'Delete List') . "</div>
                    <div class='list-choice' onclick='createNewItemInline(\"$token\"); this.closest(\".list-menu-wrapper\").classList.remove(\"open\"); event.stopPropagation();'>➕ " . ($lang['create_new_item'] ?? 'Create New Item') . "</div>
                  " : "") . "
                </div>
              </div>
            </div>

            <div class='list-contents' id='list-$token' data-token='$token' data-owner='$listOwnerId' style='display:$display; margin-left:12px;'></div>
          </div>
        ";
    }

    $stmt->close();
}



// ----------------------------




function userCanAccessList($row, $expandToken, $con) {
    $access = $row['access_level'];
    $listToken = $row['token'];
    $ownerId = (int)$row['owner_id'];

    $isLoggedIn = isset($_SESSION['user_id']);
    $isOwner = $isLoggedIn && $_SESSION['user_id'] === $ownerId;

    if ($access === "public") return true;
    if ($access === "private" && $isOwner) return true;
    if ($access === "secret" && ($isOwner || $listToken === $expandToken)) return true;

    if (!$isLoggedIn) return false;

    if (in_array($listToken, $_SESSION['saved_lists'] ?? [])) return true;

    $invited = false;
    if (isset($_SESSION['username'])) {
        // Invitations acceptance flag is deprecated; presence of invite grants access.
        $stmt = $con->prepare("
            SELECT 1
            FROM invitations i
            JOIN members m ON i.email = m.email
            WHERE i.listToken = ? AND m.username = ?
        ");
        $stmt->bind_param("ss", $listToken, $_SESSION['username']);
        $stmt->execute();
        $stmt->store_result();
        $invited = $stmt->num_rows > 0;
        $stmt->close();
    }

    return $invited;
}


// 🔍 Resolve as list token
if ($expandToken) {
    $stmt = $con->prepare("SELECT owner_id FROM content_lists WHERE token = ?");
    $stmt->bind_param("s", $expandToken);
    $stmt->execute();
    $stmt->bind_result($user_id);
    $stmt->fetch();
    $stmt->close();
}

// 🔁 Fallback to resolving as a username
if (!$user_id && $expandToken) {
    $stmt = $con->prepare("SELECT id FROM members WHERE username = ?");
    $stmt->bind_param("s", $expandToken);
    $stmt->execute();
    $stmt->bind_result($user_id);
    $stmt->fetch();
    $stmt->close();

    if (!$user_id) {
        echo "<p class='text-light'>No such user or list: " . htmlspecialchars($expandToken) . "</p>";
        exit;
    }
}

// ✅ Resolve username
// ✅ Resolve username, display name, and avatar
$stmt = $con->prepare("SELECT username, display_name, COALESCE(avatar_url, '/default-avatar.png') 
                       FROM members WHERE id = ?");
$stmt->bind_param("i", $user_id);
$stmt->execute();
$stmt->bind_result($username, $display_name, $avatar_url);
$stmt->fetch();
$stmt->close();



 //echo '<div id="realOwnerData" data-owner="' . htmlspecialchars($username, ENT_QUOTES, 'UTF-8') . '" style="display:none;"></div>';

// ✅ Current user
$current_user_id = $_SESSION['user_id'] ?? null;


// ✅ Load unread message counts
$unreadCounts = [];
if (isset($_SESSION['username'])) {
    $stmt = $con->prepare("
        SELECT listToken, COUNT(*) AS unread_count
        FROM chat_messages
        WHERE created_at > COALESCE(
          (SELECT last_read_at FROM chat_reads 
           WHERE username = ? AND listToken = chat_messages.listToken),
          (SELECT created_at FROM invitations 
           WHERE listToken = chat_messages.listToken 
             AND email = (SELECT email FROM members WHERE username = ?)
             LIMIT 1)
        )
        AND username != ?
        GROUP BY listToken
    ");
    //// AND username != ?        -- 👈 exclude own messages
    $stmt->bind_param("sss", $_SESSION['username'], $_SESSION['username'],$_SESSION['username']);

    $stmt->execute();
    $result = $stmt->get_result();
    while ($row = $result->fetch_assoc()) {
        $unreadCounts[$row['listToken']] = (int)$row['unread_count'];
    }
    $stmt->close();
}


// ✅ Preload saved lists
$_SESSION['saved_lists'] = [];
if ($current_user_id) {
    $stmt = $con->prepare("
        SELECT list_token FROM favorite_lists WHERE user_id = ?
        UNION
        SELECT list_token FROM followed_lists WHERE user_id = ?
    ");
    $stmt->bind_param("ii", $current_user_id, $current_user_id);
    $stmt->execute();
    $result = $stmt->get_result();
    while ($row = $result->fetch_assoc()) {
        $_SESSION['saved_lists'][] = $row['list_token'];
    }
    $stmt->close();
}


//Create Owenrs All Content if non existing
if (isset($_SESSION['user_id']) && $_SESSION['user_id'] === $user_id) {
    $token = $_SESSION['username']; // token = username
    $defaultListName = "All Content";
    $access = "private";

    // Check if that token exists already
    $exists = $con->prepare("SELECT 1 FROM content_lists WHERE token = ? LIMIT 1");
    $exists->bind_param("s", $token);
    $exists->execute();
    $exists->store_result();

    if ($exists->num_rows === 0) {
        $insert = $con->prepare("
            INSERT INTO content_lists (name, token, owner_id, created_by_id, access_level)
            VALUES (?, ?, ?, ?, ?)
        ");
        $insert->bind_param("ssiis", $defaultListName, $token, $user_id, $user_id, $access);
        $insert->execute();
        $insert->close();
    }

    $exists->close();
}


// ✅ Load owned content lists


//use the username for the header
$name = htmlspecialchars($_SESSION['username'], ENT_QUOTES);

// Header for owned content lists
echo "
<div class='list-group-wrapper' data-group='owned-$username'
data-name='" . htmlspecialchars($name, ENT_QUOTES) . "'>
  <div class='sidebar-section-header collapsible-group'>
    <span class='group-arrow'>&gt;</span>

    <img src='" . htmlspecialchars($avatar_url ?: '/default-avatar.png') . "'
         alt='avatar'
         class='list-owner-avatar' />

    <div class='list-owner-text'>
      <div class='list-label'>" . ($lang['lists_by'] ?? 'Lists by') . ":</div>
      <div class='list-name'>$display_name</div>
    </div>

    <div class='list-menu-wrapper'>
      <button class='menu-button' onclick='toggleListMenu(this); event.stopPropagation();'>⋮</button>
      <div class='list-menu-dropdown'>
        <div class='list-choice' onclick='createNewList(\"owned-$username\"); event.stopPropagation();'>
          🆕 " . ($lang['create_new_list'] ?? 'Create New List') . "
        </div>
        <div class='list-choice' onclick='shareGroup(\"owned-$username\"); event.stopPropagation();'>
          🔗 " . ($lang['share_group'] ?? 'Share Group') . "
        </div>
      </div>
    </div>
  </div>
  <div class='group-contents' data-token=''>

";



// -----------------------------

// ----- Render here  --------

renderListNodes($user_id);

// ------------------------------


echo "</div></div>";



// ✅ Header for followed & offline lists
if ($current_user_id) {
    //echo "<div class='sidebar-section-header'>⭐ Followed Lists</div>";
    

        
}

// ✅ Load followed lists not owned by selected user
if ($current_user_id) {
    
    // ✅ Header for followed & offline lists
    echo "
    <div class='list-group-wrapper' data-group='followed-$username'>
      <div class='sidebar-section-header collapsible-group'>
        <span class='group-arrow'>&gt;</span> ⭐ " . ($lang['followed_lists'] ?? 'Followed Lists') . "
      </div>
      <div class='group-contents' data-token=''>
    ";

    
    
    $stmt = $con->prepare("
        SELECT 
            cl.name, 
            cl.token, 
            cl.owner_id, 
            cl.access_level, 
            COUNT(cli.id) AS item_count,
            fl.order_index
        FROM followed_lists fl
        JOIN content_lists cl ON cl.token = fl.list_token
        LEFT JOIN content_list_items cli ON cli.content_list_id = cl.id
        WHERE fl.user_id = ? 
          AND cl.owner_id != ?
        GROUP BY cl.id, fl.order_index
        ORDER BY fl.order_index ASC, cl.name ASC

    ");
    $stmt->bind_param("ii", $current_user_id, $user_id);
    $stmt->execute();
    $result = $stmt->get_result();

    while ($row = $result->fetch_assoc()) {
        if (!userCanAccessList($row, $expandToken, $con)) continue;

        $token = htmlspecialchars($row['token'], ENT_QUOTES);

if (in_array($row['token'], $renderedListTokens)) continue;
$renderedListTokens[] = $row['token'];
        
        
        $name = htmlspecialchars($row['name'], ENT_QUOTES);
        $isExpanded  = ($row['token'] === $expandToken);
        $arrow       = $isExpanded ? '▼' : '▶';
        $count = (int)$row['item_count'];
        $ownerId = (int)$row['owner_id'];
        
        $unread = $unreadCounts[$token] ?? 0;
$unread = $unreadCounts[$token] ?? 0;
$chatBadge = "<span id='chat-unread-$token' class='chat-inline-badge" . ($unread > 0 ? " unread" : "") . "' onclick='event.stopPropagation(); openChatFromMenu(\"$token\")' title=\"Open chat\">$unread</span>";

$canCreate = ($_SESSION['user_id'] ?? null) === $ownerId || ($invitedRoleRanks[$token] ?? 0) >= 60;


    echo "
        <div class='list-group-item group-item followed-list' data-group='$token'
        data-name='" . htmlspecialchars($name, ENT_QUOTES) . "'>
            <div class='list-header-row'>
                <span class='arrow'>$arrow</span>
                <span class='list-title' id='list-title-$token'>⭐ $name</span>
                <span class='list-count'>($count)</span> $chatBadge
                <div class='list-menu-wrapper'>
                    <button class='menu-button' data-token='$token' onclick='toggleListMenu(this); event.stopPropagation();'>⋮</button>
                    <div class='list-menu-dropdown'>
                        <div class='list-choice' onclick='shareLink(\"$token\"); event.stopPropagation();'>🔗 " . ($lang['share_list'] ?? 'Share this list') . "</div>
                        <div class='list-choice' onclick='openChatFromMenu(\"$token\"); event.stopPropagation();'>💬 " . ($lang['list_chat'] ?? 'List Chat') . "</div>
                        <div class='list-choice toggle-offline-status' data-token='$token' onclick='toggleOfflineStatus(\"$token\", this);'>📥 " . ($lang['make_offline'] ?? 'Make available offline') . "</div>
                        <div class='list-choice toggle-list-status' onclick='toggleMyList(\"$token\", this);'>📤 " . ($lang['remove_from_my_lists'] ?? 'Remove from My Lists') . "</div>
                        " . ($canCreate ? "<div class='list-choice' onclick='enableEditAndCreateNew(); event.stopPropagation();'>➕ " . ($lang['create_new_item'] ?? 'Create New Item') . "</div>" : "") . "
                    </div>
                </div>
            </div>
            <div class='list-contents' id='list-$token' data-token='$token' data-owner='$ownerId' style='display:none; margin-left:12px;'></div>
        </div>
    ";


    }
    $stmt->close();
    
    echo "</div></div>"; // closes .group-contents and .list-group-wrapper
}





// ✅ Header for invitations  
if (isset($_SESSION['username'])) {
   // echo "<div class='sidebar-section-header'>💬 Invited Lists</div>";

}

// ✅ Load invitation lists (invited per email) not owned by selected user

if (isset($_SESSION['username'])) {

    
// ✅ Header for invitations      
    echo "
    <div class='list-group-wrapper' data-group='invited-$username'>
      <div class='sidebar-section-header collapsible-group'>
        <span class='group-arrow'></span> 💬 " . ($lang['invited_lists'] ?? 'Invited Lists') . "
      </div>
      <div class='group-contents' data-token=''>
    ";


    
    $stmt = $con->prepare("
        SELECT 
            cl.name, 
            cl.token, 
            cl.owner_id, 
            cl.access_level, 
            COUNT(cli.id) AS item_count,
            m.username AS owner_username,
            i.order_index
        FROM invitations i
        JOIN content_lists cl ON cl.token = i.listToken
        LEFT JOIN content_list_items cli ON cli.content_list_id = cl.id
        JOIN members m ON cl.owner_id = m.id
        WHERE i.email = (SELECT email FROM members WHERE username = ?)
          AND cl.owner_id != (SELECT id FROM members WHERE username = ?)
        GROUP BY cl.id, i.order_index
        ORDER BY i.order_index ASC, cl.name ASC

    ");
    $stmt->bind_param("ss", $_SESSION['username'], $_SESSION['username']);
    $stmt->execute();
    $result = $stmt->get_result();

    while ($row = $result->fetch_assoc()) {
        // Same rendering logic as followed/owned lists
        $token = htmlspecialchars($row['token'], ENT_QUOTES);
if (in_array($row['token'], $renderedListTokens)) continue;
$renderedListTokens[] = $row['token'];

        $name = htmlspecialchars($row['name'], ENT_QUOTES);

$ownerName = htmlspecialchars($row['owner_username'], ENT_QUOTES);

$name = $row['name'] === "All Content"
    ? "$ownerName - All Content"
    : htmlspecialchars($row['name'], ENT_QUOTES);
    
$expandToken = $_GET['token'] ?? null;    
    
        $isExpanded  = ($row['token'] === $expandToken);
        $arrow       = $isExpanded ? '▼' : '▶';    
        
        $count = (int)$row['item_count'];
        $ownerId = (int)$row['owner_id'];

$unread = $unreadCounts[$token] ?? 0;
$chatBadge = "<span id='chat-unread-$token' class='chat-inline-badge" . ($unread > 0 ? " unread" : "") . "' onclick='event.stopPropagation(); openChatFromMenu(\"$token\")' title=\"Open chat\">$unread</span>"; 

$canCreate = ($_SESSION['user_id'] ?? null) === $ownerId || ($invitedRoleRanks[$token] ?? 0) >= 60;

        echo "
            <div class='list-group-item group-item invited-list' data-group='$token'
            data-name='" . htmlspecialchars($name, ENT_QUOTES) . "'>
                <div class='list-header-row'>
                    <span class='arrow'>$arrow</span>
                    <span class='list-title' id='list-title-$token'>💬 $name</span>
                    <span class='list-count'>($count)</span> $chatBadge
                    <div class='list-menu-wrapper'>
                        <button class='menu-button' data-token='$token' onclick='toggleListMenu(this); event.stopPropagation();'>⋮</button>
                        <div class='list-menu-dropdown'>
                            <div class='list-choice' onclick='shareLink(\"$token\"); event.stopPropagation();'>🔗 " . ($lang['share_list'] ?? 'Share this list') . "</div>
                            <div class='list-choice' onclick='openChatFromMenu(\"$token\"); event.stopPropagation();'>💬 " . ($lang['list_chat'] ?? 'List Chat') . "</div>
                            <div class='list-choice toggle-offline-status' data-token='$token' onclick='toggleOfflineStatus(\"$token\", this);'>📥 " . ($lang['make_offline'] ?? 'Make available offline') . "</div>
                            <div class='list-choice toggle-list-status' onclick='toggleMyList(\"$token\", this);'>📥 " . ($lang['add_to_my_lists'] ?? 'Add to My Lists') . "</div>
                            " . ($canCreate ? "<div class='list-choice' onclick='enableEditAndCreateNew(); event.stopPropagation();'>➕ " . ($lang['create_new_item'] ?? 'Create New Item') . "</div>" : "") . "
                        </div>
                    </div>
                </div>
                <div class='list-contents' id='list-$token' data-token='$token' data-owner='$ownerId' style='display:none; margin-left:12px;'></div>
            </div>
        ";

    }

    $stmt->close();
    
    echo "</div></div>"; // closes .group-contents and .list-group-wrapper
}




// ✅ Render All Content last if accessible
$showAllContent = false;
if ($allContentRow) {
    $listToken = $allContentRow['token'];
    $listOwnerId = (int)$allContentRow['owner_id'];
    $isOwner = isset($_SESSION['user_id']) && $_SESSION['user_id'] === $listOwnerId;
    $isAdminOrEditor = false;

    if (!$isOwner && isset($_SESSION['username'])) {
        // Invitations acceptance flag is deprecated; presence of invite grants access.
        $invCheck = $con->prepare("SELECT role FROM invitations WHERE listToken = ? AND email = ?");
        $invCheck->bind_param("ss", $listToken, $_SESSION['username']);
        $invCheck->execute();
        $invCheck->bind_result($role);
        if ($invCheck->fetch() && in_array($role, ['admin', 'editor'])) {
            $isAdminOrEditor = true;
        }
        $invCheck->close();
    }

    if ($isOwner || $isAdminOrEditor) {
        $showAllContent = true;
    }
}



if ($showAllContent) {
    $token = htmlspecialchars($allContentRow['token'], ENT_QUOTES);
    $name = htmlspecialchars($allContentRow['name'], ENT_QUOTES);

    $stmtCount = $con->prepare("SELECT COUNT(*) FROM text WHERE Owner = ?");
    $stmtCount->bind_param("s", $username);
    $stmtCount->execute();
    $stmtCount->bind_result($count);
    $stmtCount->fetch();
    $stmtCount->close();

    $count = (int)$count;
    $unread = $unreadCounts[$token] ?? 0;

    $chatBadge = "<span id='chat-unread-$token' class='chat-inline-badge" . ($unread > 0 ? " unread" : "") .
                 "' onclick='event.stopPropagation(); openChatFromMenu(\"$token\")' title=\"Open chat\">$unread</span>";

    $canCreate = ($_SESSION['user_id'] ?? null) === $listOwnerId ||
                 ($invitedRoleRanks[$token] ?? 0) >= 60;

        echo "
          <div class='list-group-wrapper' data-group='all-$token'
          data-name='" . htmlspecialchars($name, ENT_QUOTES) . "'>
            <div class='sidebar-section-header collapsible-group' data-allcontent='1'>
              <span class='group-arrow'>▶</span>
              <span class='list-title' id='list-title-$token'>🗂️ $name</span>
              <span class='list-count'>($count)</span> $chatBadge
              <div class='list-menu-wrapper'>
                <button class='menu-button' data-token='$token' onclick='toggleListMenu(this); event.stopPropagation();'>⋮</button>
                <div class='list-menu-dropdown'>
                  <div class='list-choice' onclick='shareLink(\"$token\"); event.stopPropagation();'>🔗 " . ($lang['share_list'] ?? 'Share this list') . "</div>
                  <div class='list-choice' onclick='openChatFromMenu(\"$token\"); event.stopPropagation();'>💬 " . ($lang['list_chat'] ?? 'List Chat') . "</div>
                  " . ($canCreate ? "
                    <div class='list-choice' onclick='createNewList(\"$token\"); event.stopPropagation();'>🆕 " . ($lang['create_new_list'] ?? 'Create New List') . "</div>
                    <div class='list-choice' onclick='enableEditAndCreateNew(\"$token\"); event.stopPropagation();'>➕ " . ($lang['create_new_item'] ?? 'Create New Item') . "</div>
                  " : "") . "
                </div>
              </div>
            </div>
            <div class='group-contents' id='list-$token' data-token='$token' data-owner='$user_id' style='display:none;'>
              <!-- All Content items will load here directly -->
            </div>
          </div>
        ";


}






mysqli_close($con);
?>
