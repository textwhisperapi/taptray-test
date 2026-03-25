<?php
include_once 'db_connect.php';
include_once 'functions.php';

sec_session_start();

if (isset($_SESSION['user_id'])) {
    $user_id = $_SESSION['user_id'];

    // Invalidate all active PHP sessions immediately.
    $bump = $mysqli->prepare("UPDATE members SET session_version = session_version + 1 WHERE id = ?");
    if ($bump) {
        $bump->bind_param("i", $user_id);
        $bump->execute();
        $bump->close();
    }

    // Legacy cleanup (kept for backward compatibility)
    $stmt = $mysqli->prepare("UPDATE members SET remember_token = NULL, token_expires = NULL WHERE id = ?");
    if ($stmt) {
        $stmt->bind_param("i", $user_id);
        $stmt->execute();
        $stmt->close();
    }

    // Revoke all persistent/session tokens used by current auth flow
    $stmtTokens = $mysqli->prepare("DELETE FROM member_tokens WHERE user_id = ?");
    if ($stmtTokens) {
        $stmtTokens->bind_param("i", $user_id);
        $stmtTokens->execute();
        $stmtTokens->close();
    }

    // Destroy session
    $_SESSION = [];
    $params = session_get_cookie_params();
    setcookie(
        session_name(),
        '',
        time() - 42000,
        $params['path'] ?? '/',
        $params['domain'] ?? '',
        $params['secure'] ?? false,
        $params['httponly'] ?? true
    );
    session_destroy();

    // Clear persistent cookie (all domain variants)
    twClearRememberToken();
}

header("Location: /login.php");
exit;
