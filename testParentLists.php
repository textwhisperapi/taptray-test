<?php
header('Content-Type: application/json; charset=utf-8');
require_once __DIR__ . "/includes/db_connect.php";

$testToken = $_GET['token'] ?? 'ad54df8f6998';

function getParentChain($con, $token, $originalToken, &$visited = []) {
    if (in_array($token, $visited)) return null; // avoid loops
    $visited[] = $token;

    $stmt = $con->prepare("
        SELECT id, token, name, parent_id, owner_id, access_level,
               (SELECT COUNT(*) FROM content_list_items WHERE content_list_id = cl.id) AS item_count
        FROM content_lists cl
        WHERE token = ?
        LIMIT 1
    ");
    if (!$stmt) {
        echo json_encode(["error" => $con->error]);
        exit;
    }
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
        'children'   => []
    ];

    if ($row['token'] === $originalToken) {
        $node['isTarget'] = true;
    }

    if ($row['parent_id']) {
        // lookup parent’s token
        $stmt2 = $con->prepare("SELECT token FROM content_lists WHERE id = ?");
        $stmt2->bind_param("i", $row['parent_id']);
        $stmt2->execute();
        $stmt2->bind_result($parentToken);
        $stmt2->fetch();
        $stmt2->close();

        if ($parentToken) {
            $parentChain = getParentChain($con, $parentToken, $originalToken, $visited);
            if ($parentChain) {
                $parentChain['children'][] = $node; // attach properly
                return $parentChain;
            }
        }
    }

    return $node; // root reached
}

$chain = getParentChain($con, $testToken, $testToken);

mysqli_close($con);

if ($chain === null) {
    http_response_code(404);
    echo json_encode(["error" => "List not found", "token" => $testToken]);
} else {
    echo json_encode($chain, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
}
