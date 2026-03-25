<?php
header('Content-Type: application/json; charset=utf-8');
require_once __DIR__ . "/includes/db_connect.php";

// ⚠️ Hardcode a user_id for testing (change to your real user id)
$ownerId = 45; // e.g. the ID of your account in `members` table

$con = mysqli_connect("localhost", "wecanrec_text", "gotext", "wecanrec_text");
if (!$con) {
    http_response_code(500);
    echo json_encode(["error" => "DB connection failed"]);
    exit;
}
mysqli_set_charset($con, "utf8mb4");

$sql = "
  SELECT cl.id, cl.token, cl.name, cl.parent_id, cl.owner_id, cl.access_level, 
         cl.created_at, cl.order_index,
         COUNT(cli.id) AS item_count
  FROM content_lists cl
  LEFT JOIN content_list_items cli ON cli.content_list_id = cl.id
  WHERE cl.owner_id = ?
  GROUP BY cl.id
  ORDER BY cl.order_index ASC, cl.created_at ASC
";

$stmt = $con->prepare($sql);
$stmt->bind_param("i", $ownerId);
$stmt->execute();
$res = $stmt->get_result();

$lookup = [];
$root = [];

while ($row = $res->fetch_assoc()) {
    $node = [
        'id'           => (int)$row['id'],
        'token'        => $row['token'],
        'title'        => $row['name'],
        'parent_id'    => $row['parent_id'] ? (int)$row['parent_id'] : null,
        'owner_id'     => (int)$row['owner_id'],
        'access_level' => $row['access_level'],
        'item_count'   => (int)$row['item_count'],
        'children'     => []
    ];

    $lookup[$row['id']] = $node;
}

foreach ($lookup as $id => &$node) {
    if ($node['parent_id'] !== null && isset($lookup[$node['parent_id']])) {
        $lookup[$node['parent_id']]['children'][] = &$node;
    } else {
        $root[] = &$node;
    }
}

$stmt->close();
mysqli_close($con);

echo json_encode($root, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT);
