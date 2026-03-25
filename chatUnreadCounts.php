<?php
require_once __DIR__ . "/includes/functions.php";
require_once __DIR__ . "/includes/db_connect.php";

sec_session_start();
header("Content-Type: application/json");

if (!isset($_SESSION["username"])) {
    echo json_encode(["unread" => 0, "unread_lists" => 0, "unread_threads" => 0]);
    exit;
}

$username = (string)$_SESSION["username"];
$memberId = (int)($_SESSION["user_id"] ?? 0);
$unreadCounts = [];
$threadUnreadTotal = 0;

try {
    // 1) Accessible list tokens (owned, invited, followed, favorited)
    $tokenStmt = $mysqli->prepare("
        SELECT DISTINCT cl.token
        FROM content_lists cl
        JOIN members me ON me.username = ?
        LEFT JOIN invitations i ON i.listToken = cl.token AND i.email = me.email
        LEFT JOIN favorite_lists f ON f.list_token = cl.token AND f.user_id = me.id
        LEFT JOIN followed_lists fl ON fl.list_token = cl.token AND fl.user_id = me.id
        WHERE cl.owner_id = me.id
           OR i.listToken IS NOT NULL
           OR f.user_id IS NOT NULL
           OR fl.user_id IS NOT NULL
           OR cl.owner_id IN (
               SELECT owner.id
               FROM invitations ir
               JOIN members owner ON owner.username = ir.listToken
               WHERE ir.email = me.email
                 AND COALESCE(ir.role_rank, 0) >= 60
           )
    ");
    $tokenStmt->bind_param("s", $username);
    $tokenStmt->execute();
    $tokenResult = $tokenStmt->get_result();

    $tokens = [];
    while ($row = $tokenResult->fetch_assoc()) {
        $tok = (string)($row["token"] ?? "");
        if ($tok !== "") $tokens[] = $tok;
    }
    $tokenStmt->close();

    // 2) Per-list unread counts (exclude own messages)
    if (!empty($tokens)) {
        $inClause = implode(",", array_fill(0, count($tokens), "?"));
        $types = str_repeat("s", count($tokens) + 3);
        $params = array_merge([$username, $username], $tokens, [$username]);

        $sql = "
            SELECT cm.listToken, COUNT(*) AS unread_count
            FROM chat_messages cm
            LEFT JOIN chat_reads cr
              ON cr.listToken = cm.listToken
             AND cr.username = ?
            WHERE cm.username <> ?
              AND cm.listToken IN ($inClause)
              AND cm.created_at >= (NOW() - INTERVAL 7 DAY)
              AND cm.created_at > COALESCE(
                    cr.last_read_at,
                    (
                      SELECT i.created_at
                      FROM invitations i
                      JOIN members me2 ON me2.username = ?
                      WHERE i.email = me2.email
                        AND i.listToken = cm.listToken
                      ORDER BY i.created_at ASC
                      LIMIT 1
                    ),
                    '1970-01-01 00:00:00'
                  )
            GROUP BY cm.listToken
        ";

        $stmt = $mysqli->prepare($sql);
        $stmt->bind_param($types, ...$params);
        $stmt->execute();
        $result = $stmt->get_result();
        while ($row = $result->fetch_assoc()) {
            $tok = (string)($row["listToken"] ?? "");
            if ($tok === "") continue;
            $unreadCounts[$tok] = (int)($row["unread_count"] ?? 0);
        }
        $stmt->close();
    }

    // 3) Total unread in thread chats (dm/group) for footer badge
    if ($memberId > 0) {
        $threadSql = "
            SELECT COUNT(*) AS unread_total
            FROM chat_messages cm
            JOIN chat_threads t
              ON t.id = cm.thread_id
             AND t.is_active = 1
             AND t.thread_type IN ('dm','group')
            JOIN chat_thread_members tm
              ON tm.thread_id = t.id
             AND tm.member_id = ?
             AND tm.left_at IS NULL
            LEFT JOIN (
                SELECT thread_id, MAX(last_read_at) AS last_read_at
                FROM chat_reads
                WHERE username = ? AND thread_id IS NOT NULL
                GROUP BY thread_id
            ) cr ON cr.thread_id = t.id
            WHERE cm.username <> ?
              AND cm.created_at >= (NOW() - INTERVAL 7 DAY)
              AND cm.created_at > COALESCE(cr.last_read_at, tm.joined_at, '1970-01-01 00:00:00')
        ";
        $stmt = $mysqli->prepare($threadSql);
        $stmt->bind_param("iss", $memberId, $username, $username);
        $stmt->execute();
        $row = $stmt->get_result()->fetch_assoc();
        $stmt->close();
        $threadUnreadTotal = (int)($row["unread_total"] ?? 0);
    }

    $listUnreadTotal = array_sum($unreadCounts);
    $unreadCounts["unread_lists"] = $listUnreadTotal;
    $unreadCounts["unread_threads"] = $threadUnreadTotal;
    $unreadCounts["unread"] = $listUnreadTotal + $threadUnreadTotal;

    echo json_encode($unreadCounts);
} catch (Throwable $e) {
    http_response_code(500);
    echo json_encode([
        "error" => "Server error",
        "message" => $e->getMessage()
    ]);
}
