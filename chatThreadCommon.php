<?php
require_once __DIR__ . "/includes/db_connect.php";

function ct_json_response(array $payload, int $statusCode = 200): void {
    http_response_code($statusCode);
    echo json_encode($payload);
    exit;
}

function ct_dm_token(int $threadId): string {
    return "dm:" . $threadId;
}

function ct_find_member_by_id(mysqli $mysqli, int $memberId): ?array {
    $stmt = $mysqli->prepare("
        SELECT id, username, display_name
        FROM members
        WHERE id = ?
        LIMIT 1
    ");
    $stmt->bind_param("i", $memberId);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    return $row ?: null;
}

function ct_find_member_by_username(mysqli $mysqli, string $username): ?array {
    $stmt = $mysqli->prepare("
        SELECT id, username, display_name
        FROM members
        WHERE username = ?
        LIMIT 1
    ");
    $stmt->bind_param("s", $username);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    return $row ?: null;
}

function ct_first_name_label(string $value): string {
    $v = trim($value);
    if ($v === "") return "";
    $parts = preg_split('/\s+/', $v);
    return trim((string)($parts[0] ?? $v));
}

function ct_group_title_from_members(mysqli $mysqli, int $threadId, int $limit = 6): string {
    $stmt = $mysqli->prepare("
        SELECT COALESCE(NULLIF(TRIM(m.display_name), ''), m.username) AS label
        FROM chat_thread_members tm
        JOIN members m ON m.id = tm.member_id
        WHERE tm.thread_id = ? AND tm.left_at IS NULL
        ORDER BY tm.joined_at ASC
    ");
    $stmt->bind_param("i", $threadId);
    $stmt->execute();
    $res = $stmt->get_result();

    $names = [];
    while ($row = $res->fetch_assoc()) {
        $name = ct_first_name_label((string)($row["label"] ?? ""));
        if ($name !== "") $names[] = $name;
    }
    $stmt->close();

    if (!$names) return "Group chat";
    if (count($names) <= $limit) return implode(", ", $names);
    $shown = array_slice($names, 0, $limit);
    return implode(", ", $shown) . " +" . (count($names) - $limit);
}

function ct_find_or_create_dm_thread(mysqli $mysqli, int $actorId, int $targetId): int {
    $a = min($actorId, $targetId);
    $b = max($actorId, $targetId);

    $stmt = $mysqli->prepare("
        SELECT id
        FROM chat_threads
        WHERE thread_type = 'dm' AND member_a_id = ? AND member_b_id = ?
        LIMIT 1
    ");
    $stmt->bind_param("ii", $a, $b);
    $stmt->execute();
    $stmt->bind_result($existingId);
    $stmt->fetch();
    $stmt->close();
    if (!empty($existingId)) {
        return (int)$existingId;
    }

    $mysqli->begin_transaction();
    try {
        $stmt = $mysqli->prepare("
            INSERT INTO chat_threads (
                thread_type, member_a_id, member_b_id, created_by_member_id, title
            ) VALUES ('dm', ?, ?, ?, NULL)
        ");
        $stmt->bind_param("iii", $a, $b, $actorId);
        $stmt->execute();
        $threadId = (int)$stmt->insert_id;
        $stmt->close();

        $actorRole = "owner";
        $targetRole = "member";
        $joinedAt = date("Y-m-d H:i:s");
        $stmt = $mysqli->prepare("
            INSERT IGNORE INTO chat_thread_members (thread_id, member_id, role, joined_at)
            VALUES (?, ?, ?, ?), (?, ?, ?, ?)
        ");
        $stmt->bind_param(
            "iissiiss",
            $threadId,
            $actorId,
            $actorRole,
            $joinedAt,
            $threadId,
            $targetId,
            $targetRole,
            $joinedAt
        );
        $stmt->execute();
        $stmt->close();

        $mysqli->commit();
        return $threadId;
    } catch (Throwable $e) {
        $mysqli->rollback();
        throw $e;
    }
}

function ct_user_in_thread(mysqli $mysqli, int $threadId, int $memberId): bool {
    $stmt = $mysqli->prepare("
        SELECT 1
        FROM chat_thread_members
        WHERE thread_id = ? AND member_id = ? AND left_at IS NULL
        LIMIT 1
    ");
    $stmt->bind_param("ii", $threadId, $memberId);
    $stmt->execute();
    $ok = (bool)$stmt->get_result()->fetch_row();
    $stmt->close();
    return $ok;
}

function ct_thread_member_role(mysqli $mysqli, int $threadId, int $memberId): string {
    $stmt = $mysqli->prepare("
        SELECT role
        FROM chat_thread_members
        WHERE thread_id = ? AND member_id = ? AND left_at IS NULL
        LIMIT 1
    ");
    $stmt->bind_param("ii", $threadId, $memberId);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    return strtolower((string)($row["role"] ?? "member"));
}

function ct_thread_manage_info(mysqli $mysqli, int $threadId, int $actorId): array {
    $stmt = $mysqli->prepare("
        SELECT created_by_member_id, thread_type
        FROM chat_threads
        WHERE id = ? AND is_active = 1
        LIMIT 1
    ");
    $stmt->bind_param("i", $threadId);
    $stmt->execute();
    $thread = $stmt->get_result()->fetch_assoc();
    $stmt->close();

    if (!$thread) {
        return [
            "exists" => false,
            "creator_id" => 0,
            "actor_role" => "member",
            "is_creator" => false,
            "can_manage" => false
        ];
    }

    $creatorId = (int)($thread["created_by_member_id"] ?? 0);
    $threadType = strtolower((string)($thread["thread_type"] ?? "group"));
    $actorRole = ct_thread_member_role($mysqli, $threadId, $actorId);
    $isCreator = $actorId > 0 && $creatorId > 0 && $actorId === $creatorId;

    // Self-heal legacy rows: creator exists but role was saved as member.
    if ($isCreator && !in_array($actorRole, ["owner", "admin"], true)) {
        $stmt = $mysqli->prepare("
            UPDATE chat_thread_members
            SET role = 'owner'
            WHERE thread_id = ? AND member_id = ? AND left_at IS NULL
        ");
        $stmt->bind_param("ii", $threadId, $actorId);
        $stmt->execute();
        $stmt->close();
        $actorRole = "owner";
    }

    // If creator_id is missing on older data, treat oldest active member as implicit owner.
    if ($creatorId <= 0) {
        $stmt = $mysqli->prepare("
            SELECT member_id
            FROM chat_thread_members
            WHERE thread_id = ? AND left_at IS NULL
            ORDER BY joined_at ASC
            LIMIT 1
        ");
        $stmt->bind_param("i", $threadId);
        $stmt->execute();
        $row = $stmt->get_result()->fetch_assoc();
        $stmt->close();
        $fallbackCreatorId = (int)($row["member_id"] ?? 0);
        if ($fallbackCreatorId > 0) {
            $creatorId = $fallbackCreatorId;
            $isCreator = $actorId === $creatorId;

            $stmt = $mysqli->prepare("UPDATE chat_threads SET created_by_member_id = ? WHERE id = ?");
            $stmt->bind_param("ii", $creatorId, $threadId);
            $stmt->execute();
            $stmt->close();

            if ($isCreator && !in_array($actorRole, ["owner", "admin"], true)) {
                $stmt = $mysqli->prepare("
                    UPDATE chat_thread_members
                    SET role = 'owner'
                    WHERE thread_id = ? AND member_id = ? AND left_at IS NULL
                ");
                $stmt->bind_param("ii", $threadId, $actorId);
                $stmt->execute();
                $stmt->close();
                $actorRole = "owner";
            }
        }
    }

    $canManage = $isCreator || in_array($actorRole, ["owner", "admin"], true);

    return [
        "exists" => true,
        "creator_id" => $creatorId,
        "thread_type" => $threadType,
        "actor_role" => $actorRole,
        "is_creator" => $isCreator,
        "can_manage" => $canManage
    ];
}

function ct_thread_meta_for_user(mysqli $mysqli, int $threadId, int $memberId): ?array {
    $stmt = $mysqli->prepare("
        SELECT id, thread_type, title, created_at
        FROM chat_threads
        WHERE id = ? AND is_active = 1
        LIMIT 1
    ");
    $stmt->bind_param("i", $threadId);
    $stmt->execute();
    $thread = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    if (!$thread) return null;

    if (($thread["thread_type"] ?? "") === "dm") {
        $stmt = $mysqli->prepare("
            SELECT m.id, m.username, m.display_name
            FROM chat_thread_members tm
            JOIN members m ON m.id = tm.member_id
            WHERE tm.thread_id = ? AND tm.member_id <> ? AND tm.left_at IS NULL
            LIMIT 1
        ");
        $stmt->bind_param("ii", $threadId, $memberId);
        $stmt->execute();
        $other = $stmt->get_result()->fetch_assoc();
        $stmt->close();
        $label = $other["display_name"] ?? $other["username"] ?? ("DM #" . $threadId);
        return [
            "thread_id" => (int)$threadId,
            "thread_type" => "dm",
            "chat_name" => $label,
            "token" => ct_dm_token($threadId),
            "other_member" => $other ?: null
        ];
    }

    $chatName = (string)($thread["title"] ?? "");
    if ($chatName === "" || $chatName === "Group chat") {
        $chatName = ct_group_title_from_members($mysqli, $threadId);
    }

    return [
        "thread_id" => (int)$threadId,
        "thread_type" => $thread["thread_type"] ?? "group",
        "chat_name" => $chatName,
        "token" => ct_dm_token($threadId)
    ];
}

function ct_touch_thread_updated_at(mysqli $mysqli, int $threadId): void {
    $stmt = $mysqli->prepare("UPDATE chat_threads SET updated_at = NOW() WHERE id = ?");
    $stmt->bind_param("i", $threadId);
    $stmt->execute();
    $stmt->close();
}

function ct_mark_read(mysqli $mysqli, string $username, int $threadId): void {
    $token = ct_dm_token($threadId);
    $stmt = $mysqli->prepare("
        UPDATE chat_reads
        SET last_read_at = NOW(), listToken = ?
        WHERE username = ? AND thread_id = ?
    ");
    $stmt->bind_param("ssi", $token, $username, $threadId);
    $stmt->execute();
    $updated = $stmt->affected_rows;
    $stmt->close();

    if ($updated > 0) return;

    $stmt = $mysqli->prepare("
        INSERT INTO chat_reads (username, listToken, thread_id, last_read_at)
        VALUES (?, ?, ?, NOW())
    ");
    $stmt->bind_param("ssi", $username, $token, $threadId);
    $stmt->execute();
    $stmt->close();
}
