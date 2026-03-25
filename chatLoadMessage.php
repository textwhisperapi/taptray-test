<?php
require_once __DIR__ . "/includes/functions.php";
require_once __DIR__ . "/includes/db_connect.php";

sec_session_start();
header('Content-Type: application/json');

$mysqli->set_charset("utf8mb4");
$mysqli->query("SET collation_connection = 'utf8mb4_unicode_ci'");

// ✅ Require login
if (!login_check($mysqli)) {
    http_response_code(403);
    echo json_encode(["error" => "Not logged in"]);
    exit;
}

$user_id  = $_SESSION['user_id'];
$username = $_SESSION['username'];
$token    = $_GET['token'] ?? '';

if (!$token) {
    http_response_code(400);
    echo json_encode(["error" => "Missing token"]);
    exit;
}

/* =====================================================
   ✅ CHAT META (FETCH FIRST, INCLUDE IDS)
   ===================================================== */
$stmt = $mysqli->prepare("
    SELECT
      cl.id,
      cl.parent_id,
      cl.token,
      cl.name AS list_name,
      u.username AS owner_username,
      u.display_name AS owner_display_name,
      CASE
        WHEN cl.token = u.username THEN u.display_name
        ELSE cl.name
      END AS chat_name
    FROM content_lists cl
    JOIN members u ON u.id = cl.owner_id
    WHERE cl.token = ?
    LIMIT 1
");
$stmt->bind_param("s", $token);
$stmt->execute();
$meta = $stmt->get_result()->fetch_assoc();
$stmt->close();

if (!$meta) {
    http_response_code(404);
    echo json_encode(["error" => "List not found"]);
    exit;
}

/* =====================================================
   ✅ ACCESS CHECK HELPERS
   ===================================================== */
$minRankToReadChat = 60;

function hasDirectAccess($mysqli, $token, $user_id, $username, $minRank) {
    // Owner
    $stmt = $mysqli->prepare("SELECT 1 FROM content_lists WHERE token = ? AND owner_id = ? LIMIT 1");
    $stmt->bind_param("si", $token, $user_id);
    $stmt->execute();
    $ok = $stmt->fetch();
    $stmt->close();
    if ($ok) return true;

    // Personal list (All Content)
    if ($username === $token) return true;

    // Invitation
    $stmt = $mysqli->prepare("
        SELECT role_rank
        FROM invitations i
        JOIN members m ON m.email = i.email
        WHERE i.listToken = ? AND m.id = ?
        LIMIT 1
    ");
    $stmt->bind_param("si", $token, $user_id);
    $stmt->execute();
    $stmt->bind_result($rank);
    $ok = $stmt->fetch();
    $stmt->close();
    if ($ok && (int)$rank >= $minRank) return true;

    // Above access via owner's root list token (owner username / All Content).
    $stmt = $mysqli->prepare("
        SELECT owner.username
        FROM content_lists cl
        JOIN members owner ON owner.id = cl.owner_id
        WHERE cl.token = ?
        LIMIT 1
    ");
    $stmt->bind_param("s", $token);
    $stmt->execute();
    $stmt->bind_result($ownerUsername);
    $stmt->fetch();
    $stmt->close();
    $ownerUsername = (string)($ownerUsername ?? "");
    if ($ownerUsername === "") return false;

    $stmt = $mysqli->prepare("
        SELECT i.role_rank
        FROM invitations i
        JOIN members m ON m.email = i.email
        WHERE i.listToken = ? AND m.id = ?
        LIMIT 1
    ");
    $stmt->bind_param("si", $ownerUsername, $user_id);
    $stmt->execute();
    $stmt->bind_result($rootRank);
    $ok = $stmt->fetch();
    $stmt->close();

    return $ok && (int)$rootRank >= $minRank;
}

/* =====================================================
   ✅ PERMISSION CHECK (INHERITS FROM PARENTS)
   ===================================================== */
$granted = false;

// 1) Direct access
if (hasDirectAccess($mysqli, $token, $user_id, $username, $minRankToReadChat)) {
    $granted = true;
}

// 2) Inherit from parents
if (!$granted) {
    $currentId = (int)$meta['id'];
    $safety = 0;

    while ($currentId && $safety++ < 30) {
        $stmt = $mysqli->prepare("SELECT parent_id, token FROM content_lists WHERE id = ? LIMIT 1");
        $stmt->bind_param("i", $currentId);
        $stmt->execute();
        $stmt->bind_result($parentId, $parentToken);
        $found = $stmt->fetch();
        $stmt->close();

        if (!$found || !$parentId) break;

        if (hasDirectAccess($mysqli, $parentToken, $user_id, $username, $minRankToReadChat)) {
            $granted = true;
            break;
        }

        $currentId = (int)$parentId;
    }
}

// 🚫 No permission → meta only, empty messages
if (!$granted) {
    echo json_encode([
        'meta' => $meta,
        'messages' => []
    ]);
    exit;
}

/* =====================================================
   ✅ FETCH MESSAGES
   ===================================================== */
$stmt = $mysqli->prepare("
    SELECT
      m.id,
      m.username,
      d.display_name,
      d.avatar_url,
      m.message,
      m.created_at,
      CASE
        WHEN m.username = ? AND m.created_at >= (NOW() - INTERVAL 60 MINUTE) THEN 1
        ELSE 0
      END AS can_delete
    FROM chat_messages m
    LEFT JOIN members d ON d.username = m.username
    WHERE m.listToken = ?
    ORDER BY m.id DESC
    LIMIT 50
");
$stmt->bind_param("ss", $username, $token);
$stmt->execute();
$result = $stmt->get_result();

$messages = [];
$messageIds = [];

while ($row = $result->fetch_assoc()) {
    $id = (int)$row['id'];
    $messageIds[] = $id;
    $messages[$id] = [
        'id' => $id,
        'username' => $row['username'],
        'display_name' => $row['display_name'],
        'avatar_url' => $row['avatar_url'] ?? '/default-avatar.png',
        'message' => $row['message'],
        'created_at' => $row['created_at'],
        'can_delete' => (int)($row['can_delete'] ?? 0) === 1,
        'reactions_detailed' => []
    ];
}
$stmt->close();

/* =====================================================
   ✅ FETCH REACTIONS
   ===================================================== */
if (!empty($messageIds)) {
    $placeholders = implode(',', array_fill(0, count($messageIds), '?'));
    $types = str_repeat('i', count($messageIds));

    $stmt = $mysqli->prepare("
        SELECT r.message_id, r.emoji, m.display_name
        FROM chat_reactions r
        JOIN members m ON m.username = r.username
        WHERE r.message_id IN ($placeholders)
    ");
    $stmt->bind_param($types, ...$messageIds);
    $stmt->execute();
    $res = $stmt->get_result();

    while ($row = $res->fetch_assoc()) {
        $mid = (int)$row['message_id'];
        $messages[$mid]['reactions_detailed'][$row['emoji']][] =
            $row['display_name'] ?? '?';
    }
    $stmt->close();
}

// Build reaction counts
foreach ($messages as &$msg) {
    $msg['reactions'] = [];
    foreach ($msg['reactions_detailed'] as $emoji => $users) {
        $msg['reactions'][] = [
            'emoji' => $emoji,
            'count' => count($users)
        ];
    }
}
unset($msg);

/* =====================================================
   ✅ FINAL OUTPUT
   ===================================================== */
echo json_encode([
    'meta' => $meta,
    'messages' => array_values(array_reverse($messages))
]);

$mysqli->close();
