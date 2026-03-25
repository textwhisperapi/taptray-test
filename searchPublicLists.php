<?php
require_once __DIR__ . "/includes/db_connect.php";
$q = $_GET['q'] ?? '';
if (strlen($q) < 2) exit;

$con = $mysqli;

$qLike = "%" . $q . "%";
$stmt = $con->prepare("
    SELECT cl.id, cl.name, cl.token, cl.parent_id, m.username
    FROM content_lists cl
    JOIN members m ON cl.owner_id = m.id
    WHERE cl.access_level = 'public'
      AND (cl.name LIKE ? OR m.username LIKE ?)
    ORDER BY cl.updated_at DESC
    LIMIT 30
");
$stmt->bind_param("ss", $qLike, $qLike);
$stmt->execute();
$result = $stmt->get_result();

function hasPrivateAncestor($con, $parentId, &$cache = []) {
    $currentId = $parentId ? (int)$parentId : 0;
    $seen = [];

    while ($currentId > 0) {
        if (isset($cache[$currentId])) {
            return $cache[$currentId];
        }
        if (isset($seen[$currentId])) {
            return false;
        }
        $seen[$currentId] = true;

        $pstmt = $con->prepare("SELECT parent_id, access_level FROM content_lists WHERE id = ? LIMIT 1");
        $pstmt->bind_param("i", $currentId);
        $pstmt->execute();
        $pres = $pstmt->get_result();
        $row = $pres->fetch_assoc();
        $pstmt->close();

        if (!$row) {
            break;
        }

        if (($row['access_level'] ?? '') === 'private') {
            foreach (array_keys($seen) as $seenId) {
                $cache[$seenId] = true;
            }
            return true;
        }

        $currentId = (int)($row['parent_id'] ?? 0);
    }

    foreach (array_keys($seen) as $seenId) {
        $cache[$seenId] = false;
    }
    return false;
}

$ancestorCache = [];
while ($row = $result->fetch_assoc()) {
    if (hasPrivateAncestor($con, (int)($row['parent_id'] ?? 0), $ancestorCache)) {
        continue;
    }

    $token = htmlspecialchars($row['token']);
    $name = htmlspecialchars($row['name']);
    $owner = htmlspecialchars($row['username']);

    echo "<div class='list-group-item group-item' data-group='$token'>";
    echo "<div class='list-header-row'>";
    echo "<span class='arrow'>▶</span>";
    echo "<span class='list-title'>$name</span>";
    echo "<span class='list-count'>(by $owner)</span>";
    echo "</div>";
    echo "</div>";
}
$stmt->close();
$con->close();
?>
