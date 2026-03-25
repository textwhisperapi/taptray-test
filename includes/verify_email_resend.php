<?php
// ini_set('display_errors', 1);
// error_reporting(E_ALL);

include_once 'db_connect.php';
include_once 'functions.php'; // assumes sendVerificationEmail() exists

sec_session_start();

// $email = $_SESSION['last_email_sent_to'] ?? '';
// $email = trim(strtolower($email));


$input = json_decode(file_get_contents('php://input'), true) ?? [];

$email =
    $input['email']
    ?? $_SESSION['pending_verification_email']
    ?? '';

$email = trim(strtolower($email));




error_log("🔁 Resend triggered. Email from session: " . $email);



if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
    http_response_code(400);
    exit;
}

// Look up user by email
$stmt = $mysqli->prepare("SELECT id, display_name, email_verified FROM members WHERE email = ? LIMIT 1");
$stmt->bind_param('s', $email);
$stmt->execute();
$stmt->store_result();

if ($stmt->num_rows !== 1) {
    http_response_code(404); // user not found
    exit;
}

$stmt->bind_result($user_id, $display_name, $email_verified);
$stmt->fetch();

if ($email_verified) {
    http_response_code(409); // already verified
    exit;
}

// Generate new token and update DB
$token = bin2hex(random_bytes(32));
$update = $mysqli->prepare("UPDATE members SET verify_token = ? WHERE id = ?");
$update->bind_param('si', $token, $user_id);
$update->execute();

// Send the email again
if (sendVerificationEmail($email, $display_name, $token)) {
    http_response_code(200); // success
} else {
    error_log("❌ Failed to resend verification email to $email");
    http_response_code(500);
}
