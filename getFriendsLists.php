<?php
require_once __DIR__ . "/includes/functions.php";
require_once __DIR__ . "/includes/db_connect.php";
require_once __DIR__ . '/includes/translate.php';

sec_session_start();

$locale = $_SESSION['locale'] ?? 'en';
$langFile = __DIR__ . "/lang/{$locale}.php";
$lang = file_exists($langFile) ? include $langFile : [];

$mysqli->set_charset("utf8mb4");

if (!isset($_SESSION['username'])) {
    echo '<p class="text-danger">' . ($lang['must_login'] ?? 'You must be logged in to view friends.') . '</p>';
    exit;
}

$currentUser = $_SESSION['username'];

// 🔹 Get current user ID
$stmt = $mysqli->prepare("SELECT id FROM members WHERE username = ?");
$stmt->bind_param("s", $currentUser);
$stmt->execute();
$stmt->bind_result($userId);
$stmt->fetch();
$stmt->close();

if (!$userId) {
    http_response_code(403);
    echo json_encode(['error' => $lang['user_not_found'] ?? 'User not found']);
    exit;
}

//
// Step 1: Fetch all distinct friends (owners + members of lists I’m in)
//
$friendQuery = "
SELECT DISTINCT m.id, m.username, m.display_name,
       COALESCE(m.avatar_url, '/default-avatar.png') AS avatar_url
FROM (
    -- Friends I invited to my lists
    SELECT i.member_id AS friend_id
    FROM invitations i
    JOIN content_lists cl ON cl.token = i.listToken
    WHERE cl.owner_id = ?
      AND i.member_id IS NOT NULL
      AND i.role NOT IN ('paused','request')

    UNION ALL

    -- Owners of lists I was invited to
    SELECT cl.owner_id AS friend_id
    FROM invitations i
    JOIN content_lists cl ON cl.token = i.listToken
    WHERE i.member_id = ?
      AND i.role NOT IN ('paused','request')

    UNION ALL

    -- Other members of lists I was invited to
    SELECT i2.member_id AS friend_id
    FROM invitations i
    JOIN content_lists cl ON cl.token = i.listToken
    JOIN invitations i2 ON i2.listToken = cl.token
    WHERE i.member_id = ?
      AND i2.member_id IS NOT NULL
      AND i2.role NOT IN ('paused','request')
) f
JOIN members m ON m.id = f.friend_id
WHERE m.id <> ?
ORDER BY m.display_name ASC
";

$friendStmt = $mysqli->prepare($friendQuery);
$friendStmt->bind_param("iiii", $userId, $userId, $userId, $userId);
$friendStmt->execute();
$friendsResult = $friendStmt->get_result();

$friends = [];
while ($row = $friendsResult->fetch_assoc()) {
    $friends[] = $row;
}
$friendStmt->close();

//
// Step 2: Render My Friends group
//
echo '<div class="friends-group-wrapper">
  <div class="friends-group-item friends-group-expanded" data-group="all-friends">
    <div class="friends-list-header-row" onclick="toggleUserGroup(this)">
      <span class="arrow friends-arrow-expanded">▼</span>
      <span class="list-title">' . ($lang['my_friends'] ?? 'My Friends') . ' (' . count($friends) . ')</span>
    </div>
    <div class="list-contents friends-content-visible" id="list-all-friends">';

foreach ($friends as $user) {
    $memberId = (int)($user['id'] ?? 0);
    $username = htmlspecialchars($user['username'], ENT_QUOTES, 'UTF-8');
    $display  = htmlspecialchars($user['display_name'], ENT_QUOTES, 'UTF-8');
    $avatar   = htmlspecialchars($user['avatar_url'], ENT_QUOTES, 'UTF-8');

    echo '
      <a href="#" class="user-item friends-user-item" data-user="' . $username . '" data-member-id="' . $memberId . '" data-display-name="' . $display . '">
        <img src="' . $avatar . '" class="user-avatar" alt="' . ($lang['avatar'] ?? 'Avatar') . '" />
        <span>' . $display . ' [' . $username . ']</span>
      </a>';
}

echo '</div></div></div>'; // close My Friends group


//
// Step 3: Fetch all lists I own or am a member of
//
$listQuery = "
SELECT DISTINCT cl.token, cl.name,
       cl.owner_id, owner.username AS owner_username, owner.display_name AS owner_display_name
FROM content_lists cl
JOIN members owner ON cl.owner_id = owner.id
WHERE cl.owner_id = ?
   OR cl.token IN (
       SELECT listToken
       FROM invitations
       WHERE member_id = ? AND role NOT IN ('paused','request')
   )
ORDER BY cl.name ASC
";

$listStmt = $mysqli->prepare($listQuery);
$listStmt->bind_param("ii", $userId, $userId);
$listStmt->execute();
$listResult = $listStmt->get_result();

$lists = [];
while ($row = $listResult->fetch_assoc()) {
    $lists[] = $row;
}
$listStmt->close();

//
// Step 4: Render My Lists Members group
//
echo '<div class="friends-group-wrapper">
  <div class="friends-group-item friends-group-expanded" data-group="my-lists-members">
    <div class="friends-list-header-row" onclick="toggleUserGroup(this)">
      <span class="arrow friends-arrow-expanded">▼</span>
      <span class="list-title">' . ($lang['lists_members'] ?? 'Lists Chat Members') . '</span>
    </div>
    <div class="list-contents friends-content-visible" id="friends-lists-container">';

foreach ($lists as $list) {
    $token = htmlspecialchars($list['token'], ENT_QUOTES);
    $owner = htmlspecialchars($list['owner_username'], ENT_QUOTES);
    $ownerDisplay = htmlspecialchars($list['owner_display_name'] ?: $list['owner_username'], ENT_QUOTES);
    $name  = htmlspecialchars(
        ($list['token'] === $list['owner_username']) ? ($list['owner_display_name'] ?: $list['owner_username']) : $list['name'],
        ENT_QUOTES
    );

    // ✅ Preload member count (excluding paused/request)
    $countStmt = $mysqli->prepare("
        SELECT COUNT(DISTINCT i.member_id)
        FROM invitations i
        WHERE i.listToken = ?
          AND i.member_id IS NOT NULL
          AND i.role NOT IN ('paused','request')
    ");
    $countStmt->bind_param("s", $token);
    $countStmt->execute();
    $countStmt->bind_result($invitedCount);
    $countStmt->fetch();
    $countStmt->close();

    $memberCount = $invitedCount + 1; // include owner

    echo '
      <div class="friends-list-subgroup" data-token="' . $token . '">
        <div class="friends-list-subgroup-header" onclick="toggleUserGroup(this)" data-load="true" data-token="' . $token . '">
          <span class="arrow">▶</span>
          <span class="list-title">' . $name . ' (' . $memberCount . ') [' . $ownerDisplay . ']</span>
        </div>
        <div class="list-contents friends-content-hidden" id="friends-list-members-' . $token . '" data-token="' . $token . '"></div>
      </div>';
}

echo '</div></div></div>'; // close Lists Members group
?>
