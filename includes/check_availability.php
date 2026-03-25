<?php
require_once 'db_connect.php';
header('Content-Type: application/json');

$response = [
    'email_status' => 'missing',
    'username_status' => 'missing'
];

$email = isset($_GET['email']) ? filter_var($_GET['email'], FILTER_VALIDATE_EMAIL) : null;
$username = isset($_GET['username']) ? trim($_GET['username']) : null;

// Check email
if ($email) {
    $stmt = $mysqli->prepare(
        "SELECT email_verified FROM members WHERE email = ? LIMIT 1"
    );
    $stmt->bind_param('s', $email);
    $stmt->execute();
    $stmt->store_result();

    if ($stmt->num_rows === 0) {
        $response['email_status'] = 'available';
    } else {
        $stmt->bind_result($email_verified);
        $stmt->fetch();
        $response['email_status'] = $email_verified ? 'taken' : 'unverified';
    }

    $stmt->close();
}

// Check username (unchanged)
if ($username && preg_match('/^[a-zA-Z0-9_]+$/', $username)) {
    $stmt = $mysqli->prepare("SELECT id FROM members WHERE username = ? LIMIT 1");
    $stmt->bind_param('s', $username);
    $stmt->execute();
    $stmt->store_result();
    $response['username_status'] = $stmt->num_rows > 0 ? 'taken' : 'available';
    $stmt->close();
}

echo json_encode($response);
