<?php
header('Content-Type: application/json');

require_once __DIR__ . "/includes/functions.php";
require_once __DIR__ . "/includes/db_connect.php";

sec_session_start();

$username = $_SESSION['username'] ?? null;
$userId   = $_SESSION['user_id'] ?? null;

if (!$username || !$userId) {
    echo json_encode([
        "status"   => "error",
        "message"  => "Not logged in",
        "lists"    => []
    ]);
    exit;
}

$lists = [];

// 💬 Invited lists where I am admin (skip owner root token)
$stmt = $mysqli->prepare("
    SELECT cl.name, cl.token, i.role_rank, mo.username AS owner_username
    FROM invitations i
    JOIN members m  ON i.email = m.email
    JOIN content_lists cl ON cl.token = i.listToken
    JOIN members mo ON cl.owner_id = mo.id
    WHERE m.username = ?
      AND cl.token <> mo.username
    ORDER BY cl.id DESC
");
$stmt->bind_param("s", $username);
$stmt->execute();
$res = $stmt->get_result();

while ($row = $res->fetch_assoc()) {
    // ✅ Only include Admin role
    if ((int)$row['role_rank'] >= 90) {
        $lists[] = [
            "token"          => $row['token'],
            "name"           => $row['name'],
            "role_rank"      => (int)$row['role_rank'],
            "owner_username" => $row['owner_username']
        ];
    }
}
$stmt->close();

echo json_encode([
    "status" => "success",
    "lists"  => $lists
]);
