<?php
include_once __DIR__ . '/includes/db_connect.php';
include_once __DIR__ . '/includes/functions.php';
header('Content-Type: text/html; charset=utf-8');

sec_session_start();
$mysqli->set_charset("utf8mb4");

$users = [];

if ($stmt = $mysqli->prepare("SELECT username, display_name, avatar_url FROM members ORDER BY username ASC")) {
    $stmt->execute();
    $result = $stmt->get_result();

    while ($row = $result->fetch_assoc()) {
        $users[] = [
            'username' => $row['username'],
            'display_name' => $row['display_name'],
            'avatar_url' => $row['avatar_url'] ?? '/default-avatar.png'
        ];
    }

    $stmt->close();
}

if (!empty($users)) {
    foreach ($users as $user) {
        $username = htmlspecialchars($user['username'], ENT_QUOTES, 'UTF-8');
        $displayName = htmlspecialchars($user['display_name'], ENT_QUOTES, 'UTF-8');
        $avatar = htmlspecialchars($user['avatar_url'], ENT_QUOTES, 'UTF-8');

        echo '
        <a href="#" class="list-group-item user-item" data-user="' . $username . '">
            <img src="' . $avatar . '" class="user-avatar" alt="Avatar" />
            <span>' . $displayName . ' [' . $username . ']</span>
        </a>';
    }
} else {
    echo '<p class="text-light">No users found.</p>';
}
?>
