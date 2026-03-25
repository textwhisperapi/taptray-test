<?php
header('Content-Type: application/json; charset=utf-8');
require_once __DIR__ . "/includes/db_connect.php";

$testToken = $_GET['token'] ?? 'ad54df8f6998';


$con = $mysqli;

function getListAndParents($con, $token, &$visited = [], $originalToken = null) {
    if (in_array($token, $visited)) return null; // prevent loops
    $visited[] = $token;

    $sql = "
      SELECT cl.id, cl.token, cl.name, cl.parent_id, cl.owner_id, cl.access_level,
             COUNT(cli.id) AS item_count
      FROM content_lists cl
      LEFT JOIN content_list_items cli ON cli.content_list_id = cl.id
      WHERE cl.token = ?
      GROUP BY cl.id
      LIMIT 1
    ";
    $stmt = $con->prepare($sql);
    $stmt->bind_param("s", $token);
    $stmt->execute();
    $res = $stmt->get_result();
    $row = $res->fetch_assoc();
    $stmt->close();

    if (!$row) return null;

    $node = [
        'id'         => (int)$row['id'],
        'token'      => $row['token'],
        'title'      => $row['name'],
        'parent_id'  => $row['parent_id'] ? (int)$row['parent_id'] : null,
        'owner_id'   => (int)$row['owner_id'],
        'access'     => $row['access_level'],
        'item_count' => (int)$row['item_count'],
        'isTarget'   => ($row['token'] === $originalToken), // mark requested node
        'children'   => []
    ];

    if ($row['parent_id']) {
        $stmt2 = $con->prepare("SELECT token FROM content_lists WHERE id = ?");
        $stmt2->bind_param("i", $row['parent_id']);
        $stmt2->execute();
        $stmt2->bind_result($parentToken);
        $stmt2->fetch();
        $stmt2->close();

        if ($parentToken) {
            $parentChain = getListAndParents($con, $parentToken, $visited, $originalToken ?? $token);
            if ($parentChain) {
                $parentChain['children'][] = $node;
                return $parentChain;
            }
        }
    }

    return $node; // root
}

$chain = getListAndParents($con, $testToken, [], $testToken);

mysqli_close($con);

echo json_encode($chain, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
