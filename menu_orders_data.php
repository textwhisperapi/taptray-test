<?php
declare(strict_types=1);

require_once __DIR__ . '/includes/taptray_orders.php';

sec_session_start();
header('Content-Type: application/json; charset=utf-8');

if (!login_check($mysqli) || empty($_SESSION['username'])) {
    http_response_code(403);
    echo json_encode(['ok' => false, 'error' => 'Not logged in.']);
    exit;
}

$sessionUsername = trim((string) $_SESSION['username']);
$requestData = null;
if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
    $decoded = json_decode((string) file_get_contents('php://input'), true);
    if (is_array($decoded)) {
        $requestData = $decoded;
    }
}
$requestedOwner = trim((string) (($requestData['owner'] ?? null) ?? ($_GET['owner'] ?? $sessionUsername)));
if ($requestedOwner === '') {
    $requestedOwner = $sessionUsername;
}

if ($requestedOwner !== $sessionUsername && get_user_list_role_rank($mysqli, $requestedOwner, $sessionUsername) < 80) {
    http_response_code(403);
    echo json_encode(['ok' => false, 'error' => 'No access to this owner kitchen.']);
    exit;
}

echo json_encode([
    'ok' => true,
    'orders' => tt_orders_list_open_for_owner($mysqli, $requestedOwner),
    'past_orders' => filter_var(($requestData['include_past'] ?? ($_GET['include_past'] ?? false)), FILTER_VALIDATE_BOOLEAN)
        ? tt_orders_list_recent_closed_for_owner($mysqli, $requestedOwner, (int) (($requestData['past_limit'] ?? null) ?? ($_GET['past_limit'] ?? 8)))
        : [],
], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
