<?php
header('Content-Type: text/html; charset=utf-8');
require_once __DIR__ . "/includes/functions.php";
require_once __DIR__ . "/includes/db_connect.php";


sec_session_start();

$locale = $_SESSION['locale'] ?? 'en';
$langFile = __DIR__ . "/lang/{$locale}.php";
$lang = file_exists($langFile) ? include $langFile : [];

$con = $mysqli;

$token = $_GET['list'] ?? '';
$username = $_SESSION['username'] ?? '';

if (!$token) {
    echo "<p class='text-light'>No list token provided.</p>";
    exit;
}

$list_id = null;
$user_id = null;
$isDefaultList = false;

// ✅ First: does token match a member (All Content)?
$stmt = $con->prepare("SELECT id FROM members WHERE username = ?");
$stmt->bind_param("s", $token);
$stmt->execute();
$stmt->bind_result($user_id);
$matchedUser = $stmt->fetch();
$stmt->close();

if ($matchedUser) {
    $isDefaultList = true;
} else {
    // ✅ Otherwise, resolve as list token
    $stmt = $con->prepare("SELECT cl.id, cl.owner_id, m.username 
                           FROM content_lists cl 
                           JOIN members m ON cl.owner_id = m.id 
                           WHERE cl.token = ?");
    $stmt->bind_param("s", $token);
    $stmt->execute();
    $stmt->bind_result($list_id, $owner_id, $ownerUsername);
    $matchedList = $stmt->fetch();
    $stmt->close();

    if ($matchedList) {
        $user_id = $owner_id;
        if ($token === $ownerUsername) {
            $isDefaultList = true; // it's an All Content list
        }
    } else {
        echo "<p class='text-light'>No such user or list: " . htmlspecialchars($token) . "</p>";
        exit;
    }
}

if ($isDefaultList) {
    // 🟦 Load all text items for this user, grouped by year
    $stmtYears = $con->prepare("
        SELECT DISTINCT YEAR(CreatedTime) AS year
        FROM text
        WHERE Owner = ?
          AND deleted != 'D'
        ORDER BY year DESC
    ");
    $stmtYears->bind_param("s", $token);
    $stmtYears->execute();
    $resYears = $stmtYears->get_result();

    while ($yearRow = $resYears->fetch_assoc()) {
        $year = intval($yearRow['year']);
        $isLatest = ($year === intval(date('Y')));
        $display = $isLatest ? "block" : "none";
        $arrow = $isLatest ? "▼" : "▶";

        echo "<div class='list-year-group-toggle' onclick='toggleYearGroup(this)'>
                <span class='year-arrow'>$arrow</span> {$year}
              </div>";
        echo "<div class='year-items' style='display: $display'>";

        // ✅ Use aggregated join to avoid duplicates
        $stmtItems = $con->prepare("
            SELECT 
                t.Surrogate,
                t.dataname,
                t.Owner,
                m.display_name,
                m.fileserver,
                COALESCE(MAX(i.role_rank), 0) AS role_rank
            FROM text t
            JOIN members m ON t.Owner = m.username
            LEFT JOIN invitations i 
              ON i.listToken = t.Owner
             AND i.email IN (SELECT email FROM members WHERE username = ?)
            WHERE t.Owner = ?
              AND t.deleted != 'D'
              AND YEAR(t.CreatedTime) = ?
            GROUP BY t.Surrogate, t.dataname, t.Owner, m.display_name, m.fileserver
            ORDER BY t.dataname
        ");
        $stmtItems->bind_param("ssi", $username, $token, $year);
        $stmtItems->execute();
        $resItems = $stmtItems->get_result();

        while ($row = $resItems->fetch_assoc()) {
            $surr  = htmlspecialchars($row['Surrogate']);
            $title = htmlspecialchars($row['dataname'], ENT_QUOTES, 'UTF-8');
            $owner = htmlspecialchars($row['Owner']);
            $displayName = htmlspecialchars($row['display_name']);
            $fileserver  = htmlspecialchars($row['fileserver']);
            $rank    = ($owner === $username) ? 90 : (int)$row['role_rank'];
            $canEdit = $rank >= 80;

            echo "<div class='list-sub-item'
                    data-value='$surr'
                    data-token='$token'
                    data-owner='$owner'
                    data-fileserver='$fileserver'
                    data-item-role-rank='$rank'
                    data-can-edit='" . ($canEdit ? "1" : "0") . "'>
                <span class='item-title' onclick=\"toggleTreeItemExpand(this, $surr, '$token'); event.stopPropagation();\">
                    <div class='item-subject'>• $title</div>
                    <div class='item-owner'>$displayName <span class='username'>[$owner]</span></div>
                </span>
                <button class='item-select-btn' type='button' onclick=\"taptraySelectItem(this, $surr, '$token'); event.stopPropagation();\">Select</button>
                <div class='item-menu-wrapper'>
                    <button class='menu-button' onclick='toggleItemMenu(this); event.stopPropagation();'>⋮</button>
                    <div class='item-menu-dropdown'>
                        <div class='list-choice' onclick=\"showTaptrayItemPreview($surr, '$token'); event.stopPropagation();\">👁 Preview</div>
                        <div class='list-choice' onclick=\"shareLink('$token', '$surr'); event.stopPropagation();\">🔗 " . ($lang['share_item'] ?? 'Share this item') . "</div>
                        <div class='list-choice new-list-entry' onclick='showNewListInput(this, $surr); event.stopPropagation();'>🆕 " . ($lang['new_list'] ?? 'New list...') . "</div>
                        <div class='list-choice last-used-entry' data-surrogate='$surr' style='display:none;' onclick='addItemToLastUsedList($surr); event.stopPropagation();'>
                            ➕ " . ($lang['add_to_last_used'] ?? 'Add to') . " <span class=\"last-used-name\"></span>
                        </div>
                        <div class='list-choice' onclick='addItemToListPrompt($surr, this); event.stopPropagation();'>➕ " . ($lang['add_to_list'] ?? 'Add to list...') . "</div>
                        <div class='list-choice' onclick='window.currentSurrogate = $surr; document.getElementById(\"mobilePdfInput\").click(); event.stopPropagation();'>📤 " . ($lang['attach_file'] ?? 'Attach File (PDF, MP3, MIDI)') . "</div>
                    </div>
                </div>
                <div class='item-expand-panel' hidden></div>
            </div>";
        }

        echo "</div>"; // close year-items
        $stmtItems->close();
    }

    $stmtYears->close();
} 

else {
    // 🟨 Standard saved list

    // Resolve list_id
    $stmt = $con->prepare("SELECT id FROM content_lists WHERE token = ?");
    $stmt->bind_param("s", $token);
    $stmt->execute();
    $stmt->bind_result($list_id);
    $stmt->fetch();
    $stmt->close();

    if (!$list_id) {
        echo "<div class='text-light'>List not found.</div>";
        exit;
    }

    // Build invited role ranks once
    $invitedRoleRanks = [];
    if (!empty($_SESSION['username'])) {
        // Invitations acceptance flag is deprecated; presence of invite grants access.
        $stmt = $con->prepare("
            SELECT i.listToken, i.role_rank
            FROM invitations i
            JOIN members m ON i.email = m.email
            WHERE m.username = ?
        ");
        $stmt->bind_param("s", $_SESSION['username']);
        $stmt->execute();
        $res = $stmt->get_result();
        while ($row = $res->fetch_assoc()) {
            $invitedRoleRanks[$row['listToken']] = (int)$row['role_rank'];
        }
        $stmt->close();
    }




    // 2️⃣ Then render the items
    $stmt = $con->prepare("
        SELECT 
            t.Surrogate,
            t.dataname,
            t.Owner,
            m.display_name,
            m.fileserver,
            COALESCE(MAX(i.role_rank), 0) AS role_rank
        FROM content_list_items cli
        JOIN text t ON cli.surrogate = t.Surrogate
        JOIN members m ON t.Owner = m.username
        LEFT JOIN invitations i 
          ON i.listToken = t.Owner
         AND i.email IN (SELECT email FROM members WHERE username = ?)
        WHERE cli.content_list_id = ?
          AND (t.deleted IS NULL OR t.deleted != 'D')
        GROUP BY t.Surrogate, t.dataname, t.Owner, m.display_name, m.fileserver
        ORDER BY cli.sort_order ASC, t.dataname
    ");
    $stmt->bind_param("si", $username, $list_id);
    $stmt->execute();
    $result = $stmt->get_result();

    if ($result->num_rows === 0 ) {
        echo "<div class='list-sub-item text-muted'>(empty)</div>";
    } else {
        while ($row = $result->fetch_assoc()) {
            $surr        = htmlspecialchars($row['Surrogate']);
            $title       = htmlspecialchars($row['dataname'], ENT_QUOTES, 'UTF-8');
            $owner       = htmlspecialchars($row['Owner']);
            $fileserver  = htmlspecialchars($row['fileserver']);
            $displayName = htmlspecialchars($row['display_name']);
            $rank        = ($owner === $username) ? 90 : (int)$row['role_rank'];
            $canEdit     = $rank >= 80;

            echo "<div class='list-sub-item'
                    data-value='$surr'
                    data-token='$token'
                    data-owner='$owner'
                    data-fileserver='$fileserver'
                    data-item-role-rank='$rank'
                    data-can-edit='" . ($canEdit ? "1" : "0") . "'>
                <span class='item-title' onclick=\"toggleTreeItemExpand(this, $surr, '$token'); event.stopPropagation();\">
                    <div class='item-subject'>• $title</div>
                    <div class='item-owner'>$displayName <span class='username'>[$owner]</span></div>
                </span>
                <button class='item-select-btn' type='button' onclick=\"taptraySelectItem(this, $surr, '$token'); event.stopPropagation();\">Select</button>
                <div class='item-menu-wrapper'>
                    <button class='menu-button' onclick='toggleItemMenu(this); event.stopPropagation();'>⋮</button>
                    <div class='item-menu-dropdown'>
                        <div class='list-choice' onclick=\"showTaptrayItemPreview($surr, '$token'); event.stopPropagation();\">👁 Preview</div>
                        <div class='list-choice' onclick=\"shareLink('$token', '$surr'); event.stopPropagation();\">🔗 " . ($lang['share_item'] ?? 'Share this item') . "</div>
                        <div class='list-choice new-list-entry' onclick='showNewListInput(this, $surr); event.stopPropagation();'>🆕 " . ($lang['new_list'] ?? 'New list...') . "</div>
                        <div class='list-choice last-used-entry' data-surrogate='$surr' style='display:none;' onclick='addItemToLastUsedList($surr); event.stopPropagation();'>
                            ➕ " . ($lang['add_to_last_used'] ?? 'Add to') . " <span class=\"last-used-name\"></span>
                        </div>
                        <div class='list-choice' onclick='addItemToListPrompt($surr, this); event.stopPropagation();'>➕ " . ($lang['add_to_list'] ?? 'Add to list...') . "</div>
                        <div class='list-choice remove-choice' onclick='(event => { removeItemFromList(\"$token\", $surr, event); })(event);'>🗑️ " . ($lang['remove_from_list'] ?? 'Remove from list') . "</div>
                        <div class='list-choice' onclick='window.currentSurrogate = $surr; document.getElementById(\"mobilePdfInput\").click(); event.stopPropagation();'>📤 " . ($lang['attach_file'] ?? 'Attach File (PDF, MP3, MIDI)') . "</div>
                    </div>
                </div>
                <div class='item-expand-panel' hidden></div>
            </div>";
        }
    }

    $stmt->close();
}

mysqli_close($con);
?>
