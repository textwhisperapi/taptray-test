<?php
header('Content-Type: application/json; charset=utf-8');

require_once __DIR__ . "/includes/functions.php";
require_once __DIR__ . "/includes/db_connect.php";

sec_session_start();

if (!login_check($mysqli)) {
    http_response_code(403);
    echo json_encode([
        "status" => "error",
        "message" => "Not logged in",
        "profiles" => []
    ]);
    exit;
}

$sessionUserId = (int)($_SESSION['user_id'] ?? 0);
$sessionUsername = trim((string)($_SESSION['username'] ?? ''));

if ($sessionUserId <= 0 || $sessionUsername === '') {
    http_response_code(400);
    echo json_encode([
        "status" => "error",
        "message" => "Invalid owner session",
        "profiles" => []
    ]);
    exit;
}

$requestedOwner = trim((string)($_GET['owner'] ?? ''));
$ownerUsername = $requestedOwner !== '' ? $requestedOwner : $sessionUsername;

if ($ownerUsername !== $sessionUsername) {
    $roleRank = (int)get_user_list_role_rank($mysqli, $ownerUsername, $sessionUsername);
    if ($roleRank < 80) {
        http_response_code(403);
        echo json_encode([
            "status" => "error",
            "message" => "Permission denied",
            "profiles" => []
        ]);
        exit;
    }
}

$ownerId = 0;
$stmtOwner = $mysqli->prepare("SELECT id FROM members WHERE username = ? LIMIT 1");
$stmtOwner->bind_param("s", $ownerUsername);
$stmtOwner->execute();
$stmtOwner->bind_result($ownerId);
$stmtOwner->fetch();
$stmtOwner->close();

if ($ownerId <= 0) {
    http_response_code(404);
    echo json_encode([
        "status" => "error",
        "message" => "Owner not found",
        "profiles" => []
    ]);
    exit;
}

$itemHoldersByUser = [];

$itemsStmt = $mysqli->prepare("
    SELECT
        holder.username AS holder_username,
        COALESCE(holder.display_name, holder.username) AS holder_display_name,
        COALESCE(holder.avatar_url, '/default-avatar.png') AS holder_avatar_url,
        cl.id AS list_id,
        cl.token AS list_token,
        cl.name AS list_name,
        cl.access_level AS list_access_level,
        cli.added_at AS added_at,
        t.Surrogate AS surrogate,
        t.dataname AS title,
        t.Owner AS owner_username
    FROM content_list_items cli
    JOIN text t ON t.Surrogate = cli.surrogate
    JOIN content_lists cl ON cl.id = cli.content_list_id
    JOIN members holder ON holder.id = cl.owner_id
    WHERE t.Owner = ?
      AND holder.id <> ?
      AND cl.token <> holder.username
      AND (t.deleted IS NULL OR t.deleted != 'D')
    ORDER BY holder_display_name ASC, cl.name ASC, cli.sort_order ASC, t.dataname ASC
");
$itemsStmt->bind_param("si", $ownerUsername, $ownerId);
$itemsStmt->execute();
$itemsRes = $itemsStmt->get_result();

while ($row = $itemsRes->fetch_assoc()) {
    $holderUsername = trim((string)$row['holder_username']);
    if ($holderUsername === '') {
        continue;
    }

    if (!isset($itemHoldersByUser[$holderUsername])) {
        $itemHoldersByUser[$holderUsername] = [
            "username" => $holderUsername,
            "display_name" => $row['holder_display_name'] ?: $holderUsername,
            "avatar_url" => $row['holder_avatar_url'] ?: "/default-avatar.png",
            "lists" => [],
            "list_count" => 0,
            "item_count" => 0
        ];
    }

    $listId = (int)$row['list_id'];
    if (!isset($itemHoldersByUser[$holderUsername]["lists"][$listId])) {
        $itemHoldersByUser[$holderUsername]["lists"][$listId] = [
            "id" => $listId,
            "token" => (string)$row['list_token'],
            "name" => $row['list_name'] ?: (string)$row['list_token'],
            "access_level" => (string)($row['list_access_level'] ?? ''),
            "items" => [],
            "item_count" => 0
        ];
        $itemHoldersByUser[$holderUsername]["list_count"]++;
    }

    $surrogate = (int)($row['surrogate'] ?? 0);
    if ($surrogate <= 0) {
        continue;
    }

    if (!isset($itemHoldersByUser[$holderUsername]["lists"][$listId]["items"][$surrogate])) {
        $itemHoldersByUser[$holderUsername]["lists"][$listId]["items"][$surrogate] = [
            "surrogate" => $surrogate,
            "title" => $row['title'] ?: ("Item " . $surrogate),
            "owner_username" => $row['owner_username'] ?: $ownerUsername,
            "added_at" => $row['added_at'] ?? null
        ];
        $itemHoldersByUser[$holderUsername]["lists"][$listId]["item_count"]++;
        $itemHoldersByUser[$holderUsername]["item_count"]++;
    }
}
$itemsStmt->close();

$itemHolders = [];
foreach ($itemHoldersByUser as $profile) {
    $listsOut = [];
    foreach ($profile["lists"] as $list) {
        $itemsOut = array_values($list["items"]);
        usort($itemsOut, static function ($a, $b) {
            return strcasecmp((string)$a["title"], (string)$b["title"]);
        });
        $list["items"] = $itemsOut;
        $listsOut[] = $list;
    }
    usort($listsOut, static function ($a, $b) {
        return strcasecmp((string)$a["name"], (string)$b["name"]);
    });
    $profile["lists"] = $listsOut;
    $itemHolders[] = $profile;
}

usort($itemHolders, static function ($a, $b) {
    return strcasecmp((string)$a["display_name"], (string)$b["display_name"]);
});

$followersByUser = [];

$followersStmt = $mysqli->prepare("
    SELECT DISTINCT
        follower.username AS follower_username,
        COALESCE(follower.display_name, follower.username) AS follower_display_name,
        COALESCE(follower.avatar_url, '/default-avatar.png') AS follower_avatar_url,
        cl.id AS list_id,
        cl.token AS list_token,
        cl.name AS list_name,
        cl.access_level AS list_access_level
    FROM followed_lists fl
    JOIN members follower ON follower.id = fl.user_id
    JOIN content_lists cl ON cl.token = fl.list_token
    JOIN members owner ON owner.id = cl.owner_id
    WHERE cl.owner_id = ?
      AND follower.id <> ?
      AND cl.token <> owner.username
    ORDER BY follower_display_name ASC, cl.name ASC
");
$followersStmt->bind_param("ii", $ownerId, $ownerId);
$followersStmt->execute();
$followersRes = $followersStmt->get_result();

while ($row = $followersRes->fetch_assoc()) {
    $followerUsername = trim((string)$row['follower_username']);
    if ($followerUsername === '') {
        continue;
    }

    if (!isset($followersByUser[$followerUsername])) {
        $followersByUser[$followerUsername] = [
            "username" => $followerUsername,
            "display_name" => $row['follower_display_name'] ?: $followerUsername,
            "avatar_url" => $row['follower_avatar_url'] ?: "/default-avatar.png",
            "lists" => [],
            "list_count" => 0
        ];
    }

    $listToken = (string)$row['list_token'];
    if (!isset($followersByUser[$followerUsername]["lists"][$listToken])) {
        $followersByUser[$followerUsername]["lists"][$listToken] = [
            "id" => (int)$row['list_id'],
            "token" => $listToken,
            "name" => $row['list_name'] ?: $listToken,
            "access_level" => (string)($row['list_access_level'] ?? '')
        ];
        $followersByUser[$followerUsername]["list_count"]++;
    }
}
$followersStmt->close();

$listFollowers = [];
foreach ($followersByUser as $profile) {
    $listsOut = array_values($profile["lists"]);
    usort($listsOut, static function ($a, $b) {
        return strcasecmp((string)$a["name"], (string)$b["name"]);
    });
    $profile["lists"] = $listsOut;
    $listFollowers[] = $profile;
}

usort($listFollowers, static function ($a, $b) {
    return strcasecmp((string)$a["display_name"], (string)$b["display_name"]);
});

function mywork_safe_token(string $value, string $fallback): string {
    $safe = preg_replace('/[^a-zA-Z0-9_-]/', '_', trim($value));
    return $safe !== '' ? $safe : $fallback;
}

$roots = [
    [
        "token" => "mywork-items",
        "name" => "Who Has My Items",
        "title" => "Who Has My Items",
        "children" => []
    ],
    [
        "token" => "mywork-followers",
        "name" => "Who Follows My Lists",
        "title" => "Who Follows My Lists",
        "children" => []
    ]
];

foreach ($itemHolders as $profile) {
    $userToken = mywork_safe_token((string)$profile["username"], "user");
    $userNode = [
        "token" => "mywork-item-user-" . $userToken,
        "name" => (($profile["display_name"] ?: $profile["username"]) . " [" . $profile["username"] . "]"),
        "title" => (($profile["display_name"] ?: $profile["username"]) . " [" . $profile["username"] . "]"),
        "children" => []
    ];

    foreach (($profile["lists"] ?? []) as $list) {
        $listTokenSafe = mywork_safe_token((string)$list["token"], "list");
        $listNode = [
            "token" => "mywork-item-list-" . $userToken . "-" . $listTokenSafe,
            "name" => (($list["name"] ?: $list["token"]) . " (" . (int)($list["item_count"] ?? 0) . ")"),
            "title" => (($list["name"] ?: $list["token"]) . " (" . (int)($list["item_count"] ?? 0) . ")"),
            "access_level" => (string)($list["access_level"] ?? ""),
            "children" => [],
            "items" => []
        ];

        foreach (($list["items"] ?? []) as $item) {
            $surrogate = (int)($item["surrogate"] ?? 0);
            if ($surrogate <= 0) continue;
            $itemTitle = (string)($item["title"] ?: ("Item " . $surrogate));
            $itemOwner = (string)($item["owner_username"] ?: $ownerUsername);
            $listNode["items"][] = [
                "surrogate" => $surrogate,
                "title" => $itemTitle,
                "owner" => $itemOwner,
                "added_at" => $item["added_at"] ?? null
            ];
        }

        $userNode["children"][] = $listNode;
    }

    $roots[0]["children"][] = $userNode;
}

foreach ($listFollowers as $profile) {
    $userToken = mywork_safe_token((string)$profile["username"], "user");
    $userNode = [
        "token" => "mywork-follower-user-" . $userToken,
        "name" => (($profile["display_name"] ?: $profile["username"]) . " [" . $profile["username"] . "]"),
        "title" => (($profile["display_name"] ?: $profile["username"]) . " [" . $profile["username"] . "]"),
        "children" => []
    ];

    foreach (($profile["lists"] ?? []) as $list) {
        $listTokenSafe = mywork_safe_token((string)$list["token"], "list");
        $userNode["children"][] = [
            "token" => "mywork-follower-list-" . $userToken . "-" . $listTokenSafe,
            "name" => (string)($list["name"] ?: $list["token"]),
            "title" => (string)($list["name"] ?: $list["token"]),
            "children" => []
        ];
    }

    $roots[1]["children"][] = $userNode;
}

echo json_encode([
    "status" => "success",
    "item_holders" => $itemHolders,
    "list_followers" => $listFollowers,
    "profiles" => $itemHolders,
    "roots" => $roots
], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
