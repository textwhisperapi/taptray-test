<?php

// getOwnersListsJSON.php version 3

header('Content-Type: application/json; charset=utf-8');
require_once __DIR__ . "/includes/functions.php";
require_once __DIR__ . "/includes/db_connect.php";

sec_session_start();

/**
 * -------------------------------------------------
 * 0. TIMING LOGGING SUPPORT
 * -------------------------------------------------
 */
$ENABLE_TIMING_LOGS = true;        // << master switch
$_t0 = microtime(true);
function tlog($label) {
    global $ENABLE_TIMING_LOGS, $_t0;
    if ($ENABLE_TIMING_LOGS) {
        $ms = round((microtime(true) - $_t0) * 1000);
        error_log("⏱ {$ms}ms — {$label}");
    }
}

tlog("Start request");

$expandToken = $_GET['token'] ?? null;
if (!$expandToken) {
    http_response_code(400);
    echo json_encode(['error' => 'No token provided']);
    exit;
}

$locale   = $_SESSION['locale'] ?? 'en';
$langFile = __DIR__ . "/lang/{$locale}.php";
$lang     = file_exists($langFile) ? include $langFile : [];

// $con = mysqli_connect("localhost", "wecanrec_text", "gotext", "wecanrec_text");

$con=$mysqli;

mysqli_set_charset($con, "utf8mb4");
tlog("DB connected");

$currentUserId   = $_SESSION['user_id']   ?? 0;
$currentUsername = $_SESSION['username']  ?? '';
$currentEmail    = $_SESSION['email']     ?? '';

/**
 * -------------------------------------------------
 * 1. Handle invited-username tokens
 * -------------------------------------------------
 */
if (str_starts_with($expandToken, 'invited-')) {
    $invitedUser = substr($expandToken, strlen('invited-'));

    $stmt = $con->prepare("SELECT id FROM members WHERE username = ?");
    $stmt->bind_param("s", $invitedUser);
    $stmt->execute();
    $stmt->bind_result($ownerId);
    $stmt->fetch();
    $stmt->close();

    $expandToken = $_SESSION['username'];
}
tlog("Handled invited token");

/**
 * -------------------------------------------------
 * 2. Resolve owner id
 * -------------------------------------------------
 */
$stmt = $con->prepare("SELECT owner_id FROM content_lists WHERE token = ?");
$stmt->bind_param("s", $expandToken);
$stmt->execute();
$stmt->bind_result($ownerId);
$stmt->fetch();
$stmt->close();

if (!$ownerId) {
    $stmt = $con->prepare("SELECT id FROM members WHERE username = ?");
    $stmt->bind_param("s", $expandToken);
    $stmt->execute();
    $stmt->bind_result($ownerId);
    $stmt->fetch();
    $stmt->close();
}

if (!$ownerId) {
    echo json_encode(['error' => 'Owner not found for token']);
    exit;
}

tlog("Resolved owner id");

/**
 * -------------------------------------------------
 * 3. Ensure All Content root
 * -------------------------------------------------
 */
if ($currentUserId && $currentUserId == $ownerId) {

    $token = $_SESSION['username'];
    $exists = $con->prepare("SELECT 1 FROM content_lists WHERE token = ? LIMIT 1");
    $exists->bind_param("s", $token);
    $exists->execute();
    $exists->store_result();

    if ($exists->num_rows === 0) {
        $insert = $con->prepare("
            INSERT INTO content_lists (name, token, owner_id, created_by_id, access_level)
            VALUES ('All Content', ?, ?, ?, 'private')
        ");
        $insert->bind_param("sii", $token, $ownerId, $ownerId);
        $insert->execute();
        $insert->close();
    }

    $exists->close();
}

tlog("Ensured All Content root");

/**
 * -------------------------------------------------
 * 4. propagateRoleRanks()
 * -------------------------------------------------
 */
function propagateRoleRanks(&$lists) {
    if (empty($lists)) return;

    $byId = [];
    foreach ($lists as &$list) {
        $byId[$list['id']] = &$list;
    }
    unset($list);

    $changed = true;
    while ($changed) {
        $changed = false;
        foreach ($lists as &$list) {
            if ($list['parent_id'] && isset($byId[$list['parent_id']])) {
                $parentRank = (int)$byId[$list['parent_id']]['role_rank'];
                if ($parentRank > (int)$list['role_rank']) {
                    $list['role_rank'] = $parentRank;
                    $changed = true;
                }
            }
        }
        unset($list);
    }
}

/**
 * -------------------------------------------------
 * 4b. Parent privacy inheritance
 * -------------------------------------------------
 */
function propagatePrivateAccess(&$lists) {
    if (empty($lists)) return;

    $byId = [];
    foreach ($lists as &$list) {
        $byId[$list['id']] = &$list;
    }
    unset($list);

    $changed = true;
    while ($changed) {
        $changed = false;
        foreach ($lists as &$list) {
            $parentId = $list['parent_id'] ?? null;
            if (!$parentId || !isset($byId[$parentId])) {
                continue;
            }
            if (($byId[$parentId]['access'] ?? '') === 'private' && ($list['access'] ?? '') !== 'private') {
                $list['access'] = 'private';
                $changed = true;
            }
        }
        unset($list);
    }
}


/**
 * -------------------------------------------------
 * 5. attachItemsToLists()
 * -------------------------------------------------
 */
function attachItemsToLists($con, &$lists) {
    tlog("attachItemsToLists start");

    global $currentUsername;
    if (empty($lists)) return;

    static $itemSettingsEnsured = false;
    if (!$itemSettingsEnsured) {
        $itemSettingsEnsured = true;
        $required = [
            "short_description" => "ALTER TABLE item_settings ADD COLUMN short_description TEXT NULL AFTER public_description",
            "detailed_description" => "ALTER TABLE item_settings ADD COLUMN detailed_description MEDIUMTEXT NULL AFTER short_description",
        ];
        $existing = [];
        if ($result = $con->query("SHOW COLUMNS FROM item_settings")) {
            while ($row = $result->fetch_assoc()) {
                $existing[$row["Field"]] = true;
            }
            $result->close();
        }
        foreach ($required as $column => $sql) {
            if (!isset($existing[$column])) {
                @$con->query($sql);
            }
        }
    }

    $listIds = array_column($lists, 'id');
    if (empty($listIds)) return;

    $inClause = implode(',', array_fill(0, count($listIds), '?'));
    $types    = str_repeat('i', count($listIds));

    $sql = "
        SELECT 
            cli.content_list_id,
            t.Surrogate AS surrogate,
            t.dataname  AS title,
            t.Owner     AS owner,
            m.display_name,
            m.fileserver,
            COALESCE(NULLIF(TRIM(s.short_description), ''), NULLIF(TRIM(s.public_description), ''), '') AS short_description,
            COALESCE(NULLIF(TRIM(s.detailed_description), ''), '') AS detailed_description,
            COALESCE(NULLIF(TRIM(s.public_description), ''), '') AS public_description,
            COALESCE(NULLIF(TRIM(s.price_label), ''), '') AS price_label,
            COALESCE(NULLIF(TRIM(s.image_url), ''), '') AS image_url,
            COALESCE(NULLIF(TRIM(s.allergens), ''), '') AS allergens,
            COALESCE(s.is_available, 1) AS is_available
        FROM content_list_items cli
        JOIN text t ON cli.surrogate = t.Surrogate
        JOIN members m ON t.Owner = m.username
        LEFT JOIN item_settings s ON s.surrogate = t.Surrogate
        WHERE cli.content_list_id IN ($inClause)
          AND (t.deleted IS NULL OR t.deleted!='D')
        ORDER BY cli.sort_order ASC, t.dataname ASC
    ";

    $stmt = $con->prepare($sql);
    $stmt->bind_param($types, ...$listIds);
    $stmt->execute();
    $res = $stmt->get_result();

    $roleCache   = [];
    $itemsByList = [];

    while ($row = $res->fetch_assoc()) {
        $listId = (int)$row['content_list_id'];
        $owner  = $row['owner'];

        if (!isset($roleCache[$owner])) {
            $roleCache[$owner] = get_user_list_role_rank($con, $owner, $currentUsername);
        }

        $itemsByList[$listId][] = [
            "surrogate"    => (int)$row['surrogate'],
            "title"        => $row['title'],
            "owner"        => $owner,
            "display_name" => $row['display_name'],
            "fileserver"   => $row['fileserver'],
            "role_rank"    => (int)$roleCache[$owner],
            "short_description" => $row['short_description'] ?? '',
            "detailed_description" => $row['detailed_description'] ?? '',
            "public_description" => $row['public_description'] ?? '',
            "price_label"  => $row['price_label'] ?? '',
            "image_url"    => $row['image_url'] ?? '',
            "allergens"    => $row['allergens'] ?? '',
            "is_available" => (int)($row['is_available'] ?? 1)
        ];
    }
    $stmt->close();

    foreach ($lists as &$list) {
        $list['items']      = $itemsByList[$list['id']] ?? [];
        $list['item_count'] = count($list['items']);
    }
    unset($list);

    tlog("attachItemsToLists done");
}

/**
 * -------------------------------------------------
 * 6. buildTree()
 * -------------------------------------------------
 */
function buildTree(&$lists) {
    if (empty($lists)) return [];

    $byId = [];
    foreach ($lists as &$l) {
        $l['children'] = $l['children'] ?? [];
        $byId[$l['id']] = &$l;
    }
    unset($l);

    $tree = [];
    foreach ($lists as &$l) {
        if ($l['parent_id'] && isset($byId[$l['parent_id']])) {
            $byId[$l['parent_id']]['children'][] = &$l;
        } else {
            $tree[] = &$l;
        }
    }
    unset($l);

    return $tree;
}

/**
 * -------------------------------------------------
 * 7. Get owned lists
 * -------------------------------------------------
 */
tlog("Loading owned lists");

$ownedLists = [];
$sqlOwned = "
  SELECT cl.id, cl.token, cl.name, cl.parent_id, cl.owner_id, cl.access_level,
         COUNT(cli.id) AS item_count,
         m.username     AS owner_username,
         m.display_name AS owner_display_name,
         m.avatar_url   AS owner_avatar_url
  FROM content_lists cl
  LEFT JOIN content_list_items cli ON cli.content_list_id = cl.id
  JOIN members m ON cl.owner_id = m.id
  WHERE cl.owner_id = ?
  GROUP BY cl.id
  ORDER BY cl.order_index ASC, cl.created_at ASC
";
$stmt = $con->prepare($sqlOwned);
$stmt->bind_param("i", $ownerId);
$stmt->execute();
$res = $stmt->get_result();
while ($row = $res->fetch_assoc()) {
    $ownedLists[$row['token']] = [
        'id'          => (int)$row['id'],
        'token'       => $row['token'],
        'title'       => $row['name'],
        'parent_id'   => $row['parent_id'] ? (int)$row['parent_id'] : null,
        'owner_id'    => (int)$row['owner_id'],
        'owner_username'     => $row['owner_username'],
        'owner_display_name' => $row['owner_display_name'] ?: $row['owner_username'],
        'owner_avatar_url'   => $row['owner_avatar_url'] ?: '/default-avatar.png',
        'access'      => $row['access_level'],
        'item_count'  => (int)$row['item_count'],
        'relationship'=> 'owner',
        'role_rank'   => 0,
        'items'       => [],
        'children'    => []
    ];
}
$stmt->close();

tlog("Loaded owned lists");

/**
 * -------------------------------------------------
 * 8. Invited lists
 * -------------------------------------------------
 */
tlog("Loading invited lists");

$accessibleLists = [];
if ($currentUsername) {
    $sqlInvited = "
        SELECT 
            cl.id, cl.token, cl.name, cl.parent_id, cl.owner_id, cl.access_level,
            COUNT(cli.id) AS item_count,
            m.username     AS owner_username,
            m.display_name AS owner_display_name,
            m.avatar_url   AS owner_avatar_url,
            i.order_index, i.role_rank
        FROM invitations i
        JOIN content_lists cl ON cl.token = i.listToken
        LEFT JOIN content_list_items cli ON cli.content_list_id = cl.id
        JOIN members m ON cl.owner_id = m.id
        WHERE i.email = (SELECT email FROM members WHERE username = ?)
        GROUP BY cl.id, i.order_index
        ORDER BY i.order_index ASC, cl.name ASC
    ";
    $stmt = $con->prepare($sqlInvited);
    $stmt->bind_param("s", $currentUsername);
    $stmt->execute();
    $res = $stmt->get_result();

    while ($row = $res->fetch_assoc()) {
        $token = $row['token'];
        $accessibleLists[$token] = [
            'id'                 => (int)$row['id'],
            'token'              => $token,
            'title'              => $row['name'],
            'parent_id'          => $row['parent_id'] ? (int)$row['parent_id'] : null,
            'owner_id'           => (int)$row['owner_id'],
            'owner_username'     => $row['owner_username'],
            'owner_display_name' => $row['owner_display_name'],
            'owner_avatar_url'   => $row['owner_avatar_url'] ?: '/default-avatar.png',
            'access'             => $row['access_level'],
            'item_count'         => (int)$row['item_count'],
            'relationship'       => 'invited',
            'role_rank'          => (int)$row['role_rank'],
            'order_index'        => (int)$row['order_index'],
            'items'              => [],
            'children'           => []
        ];
    }
    $stmt->close();
}

tlog("Loaded invited lists");

/**
 * -------------------------------------------------
 * 9. Followed lists
 * -------------------------------------------------
 */
tlog("Loading followed lists");

if ($currentUserId) {
    $sqlFollowed = "
        SELECT 
            cl.id, cl.token, cl.name, cl.parent_id, cl.owner_id, cl.access_level,
            COUNT(cli.id) AS item_count,
            fl.order_index,
            m.username     AS owner_username,
            m.display_name AS owner_display_name,
            m.avatar_url   AS owner_avatar_url
        FROM followed_lists fl
        JOIN content_lists cl ON cl.token = fl.list_token
        LEFT JOIN content_list_items cli ON cli.content_list_id = cl.id
        JOIN members m ON cl.owner_id = m.id
        WHERE fl.user_id = ?
          AND cl.owner_id != ?
        GROUP BY cl.id, fl.order_index
        ORDER BY fl.order_index ASC, cl.name ASC
    ";
    $stmt = $con->prepare($sqlFollowed);
    $stmt->bind_param("ii", $currentUserId, $ownerId);
    $stmt->execute();
    $res = $stmt->get_result();

    while ($row = $res->fetch_assoc()) {

        $token = $row['token'];

        if (isset($accessibleLists[$token])) {
            $accessibleLists[$token]['relationship'] = 'both';
        } else {
            $accessibleLists[$token] = [
                'id'                 => (int)$row['id'],
                'token'              => $token,
                'title'              => $row['name'],
                'parent_id'          => $row['parent_id'] ? (int)$row['parent_id'] : null,
                'owner_id'           => (int)$row['owner_id'],
                'owner_username'     => $row['owner_username'],
                'owner_display_name' => $row['owner_display_name'],
                'owner_avatar_url'   => $row['owner_avatar_url'] ?: '/default-avatar.png',
                'access'             => $row['access_level'],
                'item_count'         => (int)$row['item_count'],
                'relationship'       => 'followed',
                'role_rank'          => 0,
                'order_index'        => (int)$row['order_index'],
                'items'              => [],
                'children'           => []
            ];
        }
    }

    $stmt->close();
}

tlog("Loaded followed lists");

/**
 * -------------------------------------------------
 * 10. Permissions / role assignment
 * -------------------------------------------------
 */
tlog("Assigning roles");

// Enforce "private parent => private descendants" in response view.
propagatePrivateAccess($ownedLists);
propagatePrivateAccess($accessibleLists);

if ($currentUserId && $currentUserId == $ownerId) {

    foreach ($ownedLists as &$l)  $l['role_rank'] = 90;
    foreach ($accessibleLists as &$l) $l['role_rank'] = 90;
    unset($l);

} elseif ($currentUserId) {

    $allTokens = array_merge(array_keys($ownedLists), array_keys($accessibleLists));
    if ($allTokens) {
        $ph = implode(',', array_fill(0, count($allTokens), '?'));
        $types = str_repeat('s', count($allTokens));

        $sql = "SELECT listToken, role_rank FROM invitations WHERE member_id = ? AND listToken IN ($ph)";
        $stmt = $con->prepare($sql);
        $stmt->bind_param("i".$types, $currentUserId, ...$allTokens);
        $stmt->execute();
        $res = $stmt->get_result();

        $roleMap = [];
        while ($row = $res->fetch_assoc()) {
            $roleMap[$row['listToken']] = (int)$row['role_rank'];
        }
        $stmt->close();

        foreach ($ownedLists as &$l) {
            $l['role_rank'] = $roleMap[$l['token']] ?? 0;
        }
        unset($l);

        foreach ($accessibleLists as &$l) {
            $l['role_rank'] = $roleMap[$l['token']] ?? 0;
        }
        unset($l);
    }

} else {

    foreach ($ownedLists as &$l)  $l['role_rank'] = 0;
    foreach ($accessibleLists as &$l) $l['role_rank'] = 0;
    unset($l);
}

tlog("Assigned roles");

// 🔁 Apply parent → child role inheritance for accessible lists
propagateRoleRanks($ownedLists);
propagateRoleRanks($accessibleLists);

// ✅ If user is invited to the owner's root token (e.g. "choir"),
// treat it as access to all lists of that owner (including private/secret).
$ownerRootRank = 0;
if (!empty($currentUsername) && !empty($ownerId)) {
    $ownerTokenForRank = '';
    $stmtOwnerToken = $con->prepare("SELECT username FROM members WHERE id = ? LIMIT 1");
    $stmtOwnerToken->bind_param("i", $ownerId);
    $stmtOwnerToken->execute();
    $stmtOwnerToken->bind_result($ownerTokenForRank);
    $stmtOwnerToken->fetch();
    $stmtOwnerToken->close();

    if ($ownerTokenForRank !== '') {
        $ownerRootRank = get_user_list_role_rank($con, $ownerTokenForRank, $currentUsername);
    }

    if ($ownerRootRank > 0) {
        foreach ($ownedLists as &$l) {
            if ($ownerRootRank > (int)$l['role_rank']) {
                $l['role_rank'] = $ownerRootRank;
            }
        }
        unset($l);
    }
}


/**
 * -------------------------------------------------
 * 11. Owned visibility rules
 * -------------------------------------------------
 */
tlog("Propagate roles + visibility");

propagateRoleRanks($ownedLists);

foreach ($ownedLists as $token => $list) {
    
    $access  = $list['access'];
    $rank    = $list['role_rank'];
    $isOwner = ($currentUserId == $list['owner_id']);

    $canView = false;
    
    if ($isOwner) {
        $canView = true;
    }
    elseif ($rank >= 10) {
        // 🔑 parent-granted access overrides ALL child privacy
        $canView = true;
    }
    elseif ($access === 'public') {
        $canView = true;
    }
    elseif ($access === 'secret' && $expandToken === $token) {
        $canView = true;
    }


    if (!$canView) {
        unset($ownedLists[$token]);
    }
}

tlog("Owned lists role propagation completed");

/**
 * -------------------------------------------------
 * 12. Attach items to OWNED lists
 * -------------------------------------------------
 */
attachItemsToLists($con, $ownedLists);

/**
 * -------------------------------------------------
 * 13. Load owner info
 * -------------------------------------------------
 */
tlog("Loading owner profile");

function tw_owner_appearance_defaults(): array {
    return [
        'skin_preset' => 'legacy-dark',
        'pattern_base' => 'melody',
        'pattern_size' => 40,
        'top_banner_url' => '',
        'greeting_text' => '',
        'greeting_icon_url' => ''
    ];
}

function tw_ensure_member_appearance_columns(mysqli $con): void {
    static $done = false;
    if ($done) return;
    $done = true;

    $required = [
        'menu_skin_preset' => "ALTER TABLE members ADD COLUMN menu_skin_preset VARCHAR(32) NULL AFTER home_page",
        'menu_pattern_base' => "ALTER TABLE members ADD COLUMN menu_pattern_base VARCHAR(32) NULL AFTER menu_skin_preset",
        'menu_pattern_size' => "ALTER TABLE members ADD COLUMN menu_pattern_size INT NULL AFTER menu_pattern_base",
        'menu_top_banner_url' => "ALTER TABLE members ADD COLUMN menu_top_banner_url TEXT NULL AFTER menu_pattern_size",
        'menu_greeting_text' => "ALTER TABLE members ADD COLUMN menu_greeting_text VARCHAR(120) NULL AFTER menu_top_banner_url",
        'menu_greeting_icon' => "ALTER TABLE members ADD COLUMN menu_greeting_icon VARCHAR(16) NULL AFTER menu_greeting_text",
        'menu_greeting_icon_url' => "ALTER TABLE members ADD COLUMN menu_greeting_icon_url TEXT NULL AFTER menu_greeting_icon",
    ];

    $existing = [];
    if ($result = $con->query("SHOW COLUMNS FROM members")) {
        while ($row = $result->fetch_assoc()) {
            $existing[$row['Field']] = true;
        }
        $result->close();
    }

    foreach ($required as $column => $sql) {
        if (!isset($existing[$column])) {
            @$con->query($sql);
        }
    }
}

// $ownerInfo = [
//     'username'     => '',
//     'display_name' => '',
//     'avatar_url'   => '/default-avatar.png'
// ];

$ownerInfo = [
    'username'     => '',
    'display_name' => '',
    'avatar_url'   => '/default-avatar.png',
    'home_mode'    => 'page',
    'home_page'    => '',
    'appearance'   => tw_owner_appearance_defaults()
];

tw_ensure_member_appearance_columns($con);

$stmt = $con->prepare("
    SELECT username, display_name, avatar_url, home_mode, home_page,
           menu_skin_preset, menu_pattern_base, menu_pattern_size, menu_top_banner_url,
           menu_greeting_text, menu_greeting_icon, menu_greeting_icon_url
    FROM members WHERE id = ?
");
$stmt->bind_param("i", $ownerId);
$stmt->execute();
$stmt->bind_result($uname, $dname, $avatar, $homeMode, $homePage, $menuSkinPreset, $menuPatternBase, $menuPatternSize, $menuTopBannerUrl, $menuGreetingText, $menuGreetingIcon, $menuGreetingIconUrl);
if ($stmt->fetch()) {
    $ownerInfo['username']     = $uname;
    $ownerInfo['display_name'] = $dname ?: $uname;
    $ownerInfo['avatar_url']   = $avatar ?: '/default-avatar.png';
    $ownerInfo['home_mode']    = $homeMode ?: 'page';
    $ownerInfo['home_page']    = $homePage ?: '';
    $ownerInfo['appearance']   = [
        'skin_preset' => $menuSkinPreset ?: 'legacy-dark',
        'pattern_base' => $menuPatternBase ?: 'melody',
        'pattern_size' => max(10, min(100, (int)($menuPatternSize ?: 40))),
        'top_banner_url' => trim((string)($menuTopBannerUrl ?? '')),
        'greeting_text' => trim((string)($menuGreetingText ?? '')),
        'greeting_icon_url' => trim((string)($menuGreetingIconUrl ?? ''))
    ];
}
$stmt->close();

tlog("Loaded owner profile");

/**
 * -------------------------------------------------
 * 14. Build INVITED + FOLLOWED structure (your big block)
 * -------------------------------------------------
 */

/**
 * -------------------------------------------------
 * 14. Build INVITED + FOLLOWED structure (SECURE)
 * -------------------------------------------------
 */
tlog("Building invited/followed hierarchy");

$groupInvitedByOwner = !isset($_GET['flat_invited']) || $_GET['flat_invited'] !== '1';

if (!empty($accessibleLists)) {

    $invitedOnly = array_filter(
        $accessibleLists,
        fn($l) => in_array($l['relationship'], ['invited', 'both'], true)
    );

    $followedOnly = array_filter(
        $accessibleLists,
        fn($l) => $l['relationship'] === 'followed'
    );

    $accessibleLists = [];




    // ===============================
    // INVITED — GROUPED BY OWNER
    // ===============================
    // if ($groupInvitedByOwner && $invitedOnly) {

    //     $byOwner = [];
    //     foreach ($invitedOnly as $l) {
    //         $byOwner[$l['owner_id']][] = $l;
    //     }

    //     $groups = [];
    //     $virtualId = 1000000;

    //     foreach ($byOwner as $ownerIdForGroup => $listsFromOwner) {

    //         $first = reset($listsFromOwner);
    //         $ownerKey  = $first['owner_username'];
    //         $ownerName = $first['owner_display_name'];
    //         $avatar    = $first['owner_avatar_url'];

    //         $virtualId++;
    //         $groups[$ownerKey] = [
    //             'id'                 => $virtualId,
    //             'token'              => 'invited-' . $ownerKey,
    //             'title'              => 'by ' . $ownerName,
    //             'owner_username'     => $ownerKey,
    //             'owner_display_name' => $ownerName,
    //             'owner_avatar_url'   => $avatar,
    //             'relationship'       => 'inviter_group',
    //             'children'           => []
    //         ];

    //         // 🔒 ACCESS-AWARE OWNER TREE
    //         $sql = "
    //             SELECT DISTINCT
    //                 cl.id,
    //                 cl.token,
    //                 cl.name,
    //                 cl.parent_id,
    //                 cl.access_level,
    //                 COALESCE(i.role_rank, 0) AS role_rank
    //             FROM content_lists cl
    //             LEFT JOIN invitations i
    //                   ON i.listToken = cl.token
    //                   AND i.member_id = ?
    //             WHERE cl.owner_id = ?
    //               AND cl.token <> (SELECT username FROM members WHERE id = cl.owner_id)
    //               AND (
    //                     cl.access_level = 'public'
    //                     OR i.member_id IS NOT NULL
    //               )
    //             ORDER BY cl.order_index ASC, cl.created_at ASC
    //         ";

    //         $stmt = $con->prepare($sql);
    //         $stmt->bind_param("ii", $currentUserId, $ownerIdForGroup);
    //         $stmt->execute();
    //         $res = $stmt->get_result();

    //         $ownerFull = [];
    //         while ($row = $res->fetch_assoc()) {
    //             $id = (int)$row['id'];
    //             $ownerFull[$id] = [
    //                 'id'        => $id,
    //                 'token'     => $row['token'],
    //                 'title'     => $row['name'],
    //                 'parent_id' => $row['parent_id'] ? (int)$row['parent_id'] : null,
    //                 'access'    => $row['access_level'],
    //                 'role_rank' => (int)$row['role_rank'],
    //                 'relationship' => 'invited_owner',
    //                 'children'  => [],
    //                 'items'     => []
    //             ];
    //         }
    //         $stmt->close();

    //         // 🔁 propagate inherited permissions
    //         propagateRoleRanks($ownerFull);

    //         // 📎 attach items only to visible lists
    //         attachItemsToLists($con, $ownerFull);

    //         foreach ($ownerFull as &$l) {
    //             $l['item_count'] = count($l['items']);
    //         }
    //         unset($l);

    //         $groups[$ownerKey]['children'] = buildTree($ownerFull);
    //     }

    //     $accessibleLists[] = [
    //         'id'           => 9999999,
    //         'token'        => 'invited-lists',
    //         'title'        => '💬 My Groups',
    //         'relationship' => 'invited_group',
    //         'children'     => array_values($groups)
    //     ];
    // }

// ===============================
// INVITED — OWNER GROUPS (FLAT, SAME SHAPE AS BEFORE)
// ===============================
if ($groupInvitedByOwner && $invitedOnly) {

    $groups = [];
    $virtualId = 1000000;

    foreach ($invitedOnly as $l) {

        $ownerKey = $l['owner_username'];
        if (!$ownerKey) continue;

        // de-dupe owners
        if (isset($groups[$ownerKey])) {
            continue;
        }

        $virtualId++;

        $groups[$ownerKey] = [
            'id'                 => $virtualId,
            'token'              => 'invited-' . $ownerKey,
            'title'              => 'by ' . ($l['owner_display_name'] ?: $ownerKey),

            // 👤 OWNER METADATA — EXACTLY AS BEFORE
            'owner_username'     => $ownerKey,
            'owner_display_name' => $l['owner_display_name'] ?: $ownerKey,
            'owner_avatar_url'   => $l['owner_avatar_url'] ?: '/default-avatar.png',

            // 🔑 THIS is the critical flag
            'relationship'       => 'inviter_group',

            // 🔒 FLAT — NO LISTS
            'children'           => []
        ];
    }

    $accessibleLists[] = [
        'id'           => 9999999,
        'token'        => 'invited-lists',
        'title'        => '💬 My Groups',
        'relationship' => 'invited_group',
        'children'     => array_values($groups)
    ];
}



    // ===============================
    // FOLLOWED LISTS
    // ===============================
    if ($followedOnly) {

        attachItemsToLists($con, $followedOnly);

        foreach ($followedOnly as &$l) {
            $l['item_count'] = count($l['items']);
        }
        unset($l);

        $accessibleLists[] = [
            'id'           => 9999998,
            'token'        => 'followed-lists',
            'title'        => '⭐ Followed Lists',
            'relationship' => 'followed_group',
            'children'     => buildTree($followedOnly)
        ];
    }
}


/**
 * -------------------------------------------------
 * 15. Final JSON
 * -------------------------------------------------
 */
$output = [
    'owner'      => $ownerInfo,
    'owned'      => buildTree($ownedLists),
    'accessible' => $accessibleLists
];

tlog("Building final JSON");

mysqli_close($con);

tlog("DB closed, sending JSON");

echo json_encode($output, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

tlog("DONE");
