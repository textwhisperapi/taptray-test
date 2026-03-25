<?php
require_once __DIR__ . "/includes/functions.php";
require_once __DIR__ . "/includes/db_connect.php";

sec_session_start();

if (!isset($_SESSION['username'])) {
    http_response_code(401);
    echo json_encode(['error' => 'Not logged in']);
    exit;
}

$username = $_SESSION['username'];
$userId = $_SESSION['user_id'] ?? null;
$email = null;

// Get user email
$stmt = $mysqli->prepare("SELECT email FROM members WHERE username = ?");
$stmt->bind_param("s", $username);
$stmt->execute();
$stmt->bind_result($email);
$stmt->fetch();
$stmt->close();

if (!$email) {
    echo json_encode(['lists' => []]);
    exit;
}

// Step 1: Collect all accessible list tokens and names
$accessibleLists = [];

$query = "
    SELECT cl.token, 
    CASE when cl.token = u.username then u.display_name else cl.name end as name
    FROM content_lists cl
    LEFT JOIN members m ON m.username = ?
    LEFT JOIN members u ON u.id = cl.owner_id
    LEFT JOIN favorite_lists f ON f.list_token = cl.token AND f.user_id = m.id
    LEFT JOIN followed_lists fl ON fl.list_token = cl.token AND fl.user_id = m.id
    WHERE
      cl.owner_id = m.id OR
      cl.token IN (
        SELECT listToken
        FROM invitations i where i.email = m.email
      ) OR
      f.user_id IS NOT NULL OR
      fl.user_id IS NOT NULL OR
      cl.owner_id IN (
        SELECT owner.id
        FROM invitations ir
        JOIN members owner ON owner.username = ir.listToken
        WHERE ir.email = m.email
          AND COALESCE(ir.role_rank, 0) >= 60
      )
    GROUP BY cl.token
";


$stmt = $mysqli->prepare($query);
$stmt->bind_param("s", $username);
$stmt->execute();
$result = $stmt->get_result();

while ($row = $result->fetch_assoc()) {
    $accessibleLists[$row['token']] = [
        'token' => $row['token'],
        'name' => $row['name'],
        'unread' => 0,
        'last' => null
    ];
}
$stmt->close();

if (empty($accessibleLists)) {
    echo json_encode(['lists' => []]);
    exit;
}

// Step 2: Fetch unread counts and latest timestamps
$tokens = array_keys($accessibleLists);
$inClause = implode(",", array_fill(0, count($tokens), "?"));
$params = array_merge([$username, $username], $tokens, [$username]);
$types = str_repeat("s", count($params));


$sql = "
    SELECT cm.listToken,
           COUNT(CASE WHEN cm.created_at > COALESCE(
               (SELECT last_read_at FROM chat_reads WHERE username = ? AND listToken = cm.listToken),
               (SELECT created_at FROM invitations WHERE email = (
                   SELECT email FROM members WHERE username = ?
               ) AND listToken = cm.listToken LIMIT 1)
           ) THEN 1 END) AS unread_count,
           MAX(cm.created_at) AS last_message_time
    FROM chat_messages cm
    WHERE cm.listToken IN ($inClause)
      AND cm.username <> ?
    GROUP BY cm.listToken
";

$stmt = $mysqli->prepare($sql);
$stmt->bind_param($types, ...$params);
$stmt->execute();
$result = $stmt->get_result();

while ($row = $result->fetch_assoc()) {
    $token = $row['listToken'];
    if (isset($accessibleLists[$token])) {
        $accessibleLists[$token]['unread'] = (int)$row['unread_count'];
        $accessibleLists[$token]['last'] = $row['last_message_time'];
    }
}

$stmt->close();
$mysqli->close();

// Sort by last message descending
usort($accessibleLists, function ($a, $b) {
    return strtotime($b['last'] ?? '1970-01-01') - strtotime($a['last'] ?? '1970-01-01');
});

echo json_encode(['lists' => array_values($accessibleLists)]);
