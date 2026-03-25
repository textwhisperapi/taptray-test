<?php
include_once 'db_connect.php';
include_once 'functions.php';
sec_session_start();

if (!isset($_SESSION['user_id'])) {
    header("Location: /login.php");
    exit;
}

$user_id = $_SESSION['user_id'];
$currentSelector = strtolower(trim((string)($_SESSION['active_selector'] ?? '')));
if ($currentSelector === '' && !empty($_COOKIE['remember_token'])) {
    $parts = explode(':', (string)$_COOKIE['remember_token'], 2);
    $maybeSelector = strtolower(trim((string)($parts[0] ?? '')));
    if (preg_match('/^[a-f0-9]{12}$/', $maybeSelector)) {
        $currentSelector = $maybeSelector;
    }
}

// 🧹 Clean selected sessions
if (isset($_POST['action'])) {
    if ($_POST['action'] === 'revoke_selected' && !empty($_POST['selectors']) && is_array($_POST['selectors'])) {
        $selectors = array_values(array_filter(array_map(function ($s) {
            $s = strtolower(trim((string)$s));
            return preg_match('/^[a-f0-9]{12}$/', $s) ? $s : null;
        }, $_POST['selectors'])));

        if ($currentSelector !== '') {
            $selectors = array_values(array_filter($selectors, fn($s) => $s !== $currentSelector));
        }

        if (!empty($selectors)) {
            $placeholders = implode(',', array_fill(0, count($selectors), '?'));
            $types = str_repeat('s', count($selectors));
            $sql = "DELETE FROM member_tokens WHERE user_id = ? AND selector IN ($placeholders)";

            $stmt = $mysqli->prepare($sql);
            if ($stmt) {
                $params = array_merge([$user_id], $selectors);
                $bindTypes = 'i' . $types;
                $bindValues = [];
                $bindValues[] = &$bindTypes;
                foreach ($params as &$val) {
                    $bindValues[] = &$val;
                }
                call_user_func_array([$stmt, 'bind_param'], $bindValues);
                $stmt->execute();
                $stmt->close();
            }
        }
    }

    // 🔁 Logout all except current browser
    if ($_POST['action'] === 'revoke_all') {
        // Invalidate active PHP sessions on other browsers/devices.
        // We keep current session alive by refreshing its in-memory version below.
        $bump = $mysqli->prepare("UPDATE members SET session_version = session_version + 1 WHERE id = ?");
        if ($bump) {
            $bump->bind_param("i", $user_id);
            $bump->execute();
            $bump->close();
        }

        if ($currentSelector !== '') {
            $stmt = $mysqli->prepare("DELETE FROM member_tokens WHERE user_id = ? AND selector != ?");
            if ($stmt) {
                $stmt->bind_param("is", $user_id, $currentSelector);
                $stmt->execute();
                $stmt->close();
            }
        } else {
            $stmt = $mysqli->prepare("DELETE FROM member_tokens WHERE user_id = ?");
            if ($stmt) {
                $stmt->bind_param("i", $user_id);
                $stmt->execute();
                $stmt->close();
            }
        }

        // Keep current browser session valid after version bump.
        $_SESSION['session_version'] = getCurrentSessionVersion($user_id, $mysqli);
    }
}

header("Location: /login.php");
exit;
