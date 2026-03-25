<?php
header('Content-Type: application/json');
ini_set('display_errors', 0);
error_reporting(0);

require_once __DIR__ . '/includes/db_connect.php';
require_once __DIR__ . '/includes/functions.php';

sec_session_start();

if (!login_check($mysqli)) {
    http_response_code(403);
    echo json_encode(['error' => 'Not logged in']);
    exit;
}

$token = $_GET['token'] ?? '';
$currentUser = $_SESSION['username'] ?? '';

if (!$token || !$currentUser) {
    http_response_code(400);
    echo json_encode(['error' => 'Missing token or user']);
    exit;
}

// ✅ Get current user email + ID
$stmt = $mysqli->prepare("SELECT email, id FROM members WHERE username = ?");
$stmt->bind_param("s", $currentUser);
$stmt->execute();
$stmt->bind_result($userEmail, $userId);
$stmt->fetch();
$stmt->close();

if (!$userEmail || !$userId) {
    http_response_code(403);
    echo json_encode(['error' => 'User not found']);
    exit;
}

// ✅ Check if user is list owner
$stmt = $mysqli->prepare("SELECT 1 FROM content_lists WHERE token = ? AND owner_id = ?");
$stmt->bind_param("si", $token, $userId);
$stmt->execute();
$stmt->store_result();
$isOwner = $stmt->num_rows > 0;
$stmt->close();

// ✅ Check invitation role (if not owner)
$stmt = $mysqli->prepare("SELECT role FROM invitations WHERE listToken = ? AND email = ?");
$stmt->bind_param("ss", $token, $userEmail);
$stmt->execute();
$stmt->bind_result($userRole);
$stmt->fetch();
$stmt->close();

$userRole = $userRole ?? null;

// ✅ Deny access if neither owner nor invited
if (!$isOwner && !$userRole) {
    http_response_code(403);
    echo json_encode(['error' => 'Access denied']);
    exit;
}

// ✅ Only return self if role is "request"
if ($userRole === 'request') {
    echo json_encode([[
        'member_id' => (int)$userId,
        'email' => $userEmail,
        'username' => $currentUser,
        'display_name' => null,
        'role' => 'request',
        'avatar_url' => '/default-avatar.png'
    ]]);
    exit;
}

// ✅ Fetch full list of members
$stmt = $mysqli->prepare("
    SELECT DISTINCT i.email, i.role, m.id, m.display_name, m.username, COALESCE(m.avatar_url, '/default-avatar.png') AS avatar_url
    FROM invitations i
    LEFT JOIN members m ON i.email = m.email
    WHERE i.listToken = ?
");
$stmt->bind_param("s", $token);
$stmt->execute();
$stmt->bind_result($email, $role, $memberId, $displayName, $username, $avatarUrl);

$members = [];
$foundOwner = false;

while ($stmt->fetch()) {
    if ($email === $userEmail && $role === 'owner') {
        $foundOwner = true;
    }

    $members[] = [
        'member_id' => (int)($memberId ?? 0),
        'email' => $email,
        'username' => $username ?? $email,
        'display_name' => $displayName,
        'role' => $role,
        'avatar_url' => $avatarUrl ?: '/default-avatar.png'
    ];
}
$stmt->close();

// ✅ Append owner if not already listed
if ($isOwner && !$foundOwner) {
    $members[] = [
        'member_id' => (int)$userId,
        'email' => $userEmail,
        'username' => $currentUser,
        'display_name' => null,
        'role' => 'owner',
        'avatar_url' => '/default-avatar.png'
    ];
}

echo json_encode($members);
