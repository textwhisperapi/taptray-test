<?php
header('Content-Type: application/json; charset=utf-8');

require_once __DIR__ . '/includes/functions.php';
require_once __DIR__ . '/includes/db_connect.php';

sec_session_start();

$sql = "
    SELECT
        username,
        COALESCE(NULLIF(display_name, ''), username) AS display_name,
        COALESCE(NULLIF(avatar_url, ''), '') AS avatar_url,
        COALESCE(NULLIF(menu_top_banner_url, ''), '') AS banner_url,
        COALESCE(NULLIF(menu_greeting_text, ''), '') AS greeting_text
    FROM members
    WHERE COALESCE(profile_type, 'person') = 'group'
    ORDER BY COALESCE(NULLIF(display_name, ''), username) ASC
";

$result = $mysqli->query($sql);
$rows = [];

if ($result) {
    while ($row = $result->fetch_assoc()) {
        $rows[] = [
            'token' => (string)($row['username'] ?? ''),
            'name' => (string)($row['display_name'] ?? $row['username'] ?? ''),
            'avatar_url' => (string)($row['avatar_url'] ?? ''),
            'banner_url' => (string)($row['banner_url'] ?? ''),
            'greeting_text' => (string)($row['greeting_text'] ?? ''),
            'link' => '/' . rawurlencode((string)($row['username'] ?? ''))
        ];
    }
    $result->close();
}

echo json_encode([
    'status' => 'OK',
    'restaurants' => $rows,
], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
