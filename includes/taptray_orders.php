<?php
declare(strict_types=1);

require_once __DIR__ . '/db_connect.php';
require_once __DIR__ . '/functions.php';
require_once __DIR__ . '/../vendor/autoload.php';

use Minishlink\WebPush\Subscription;
use Minishlink\WebPush\WebPush;

function tt_orders_ensure_schema(mysqli $mysqli): void {
    static $done = false;
    if ($done) {
        return;
    }
    $done = true;

    $mysqli->query("
        CREATE TABLE IF NOT EXISTS taptray_orders (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
            order_reference VARCHAR(96) NOT NULL,
            customer_token VARCHAR(64) NOT NULL,
            customer_username VARCHAR(191) DEFAULT NULL,
            merchant_name VARCHAR(191) NOT NULL DEFAULT 'TapTray',
            currency CHAR(3) NOT NULL DEFAULT 'EUR',
            amount_minor INT NOT NULL DEFAULT 0,
            total_quantity INT NOT NULL DEFAULT 0,
            wallet_path VARCHAR(64) NOT NULL DEFAULT 'Phone wallet',
            status VARCHAR(32) NOT NULL DEFAULT 'in_process',
            items_json LONGTEXT NOT NULL,
            worldline_payment_id VARCHAR(96) DEFAULT NULL,
            worldline_checkout_id VARCHAR(96) DEFAULT NULL,
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            ready_at DATETIME DEFAULT NULL,
            closed_at DATETIME DEFAULT NULL,
            UNIQUE KEY uniq_taptray_order_reference (order_reference),
            KEY idx_taptray_customer_status (customer_token, status),
            KEY idx_taptray_status_created (status, created_at)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
    ");

    $mysqli->query("
        CREATE TABLE IF NOT EXISTS taptray_order_push_subscriptions (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
            order_reference VARCHAR(96) NOT NULL,
            customer_token VARCHAR(64) NOT NULL,
            endpoint TEXT NOT NULL,
            p256dh TEXT NOT NULL,
            auth TEXT NOT NULL,
            env VARCHAR(191) NOT NULL DEFAULT 'taptray.com',
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            UNIQUE KEY uniq_taptray_order_endpoint (order_reference, endpoint(255)),
            KEY idx_taptray_order_push_ref (order_reference),
            KEY idx_taptray_order_push_token (customer_token)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
    ");
}

function tt_orders_customer_token(): string {
    $existing = trim((string) ($_COOKIE['taptray_customer_token'] ?? ''));
    if (preg_match('/^[a-f0-9]{32,64}$/', $existing)) {
        return $existing;
    }

    $token = bin2hex(random_bytes(24));
    setcookie('taptray_customer_token', $token, twCookieOptions([
        'expires' => time() + (365 * 24 * 60 * 60),
        'httponly' => false,
    ]));
    $_COOKIE['taptray_customer_token'] = $token;
    return $token;
}

function tt_orders_normalize_order(array $order): array {
    return [
        'reference' => trim((string) ($order['reference'] ?? '')),
        'merchant_name' => trim((string) ($order['merchant_name'] ?? 'TapTray')) ?: 'TapTray',
        'currency' => strtoupper(trim((string) ($order['currency'] ?? 'EUR'))) ?: 'EUR',
        'amount_minor' => (int) ($order['totals']['amount_minor'] ?? 0),
        'quantity' => (int) ($order['totals']['quantity'] ?? 0),
        'wallet_path' => trim((string) ($order['wallet']['requested_path'] ?? $order['wallet']['requestedPath'] ?? 'Phone wallet')) ?: 'Phone wallet',
        'items_json' => json_encode(is_array($order['items'] ?? null) ? $order['items'] : [], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
        'worldline_payment_id' => trim((string) ($order['worldline']['payment_id'] ?? '')),
        'worldline_checkout_id' => trim((string) ($order['worldline']['hosted_checkout_id'] ?? '')),
    ];
}

function tt_orders_upsert_paid_order(mysqli $mysqli, array $order): ?array {
    tt_orders_ensure_schema($mysqli);
    $normalized = tt_orders_normalize_order($order);
    if ($normalized['reference'] === '' || $normalized['amount_minor'] < 1) {
        return null;
    }

    $customerToken = tt_orders_customer_token();
    $customerUsername = isset($_SESSION['username']) ? trim((string) $_SESSION['username']) : '';

    $stmt = $mysqli->prepare("
        INSERT INTO taptray_orders
            (order_reference, customer_token, customer_username, merchant_name, currency, amount_minor, total_quantity, wallet_path, status, items_json, worldline_payment_id, worldline_checkout_id)
        VALUES (?, ?, NULLIF(?, ''), ?, ?, ?, ?, ?, 'in_process', ?, NULLIF(?, ''), NULLIF(?, ''))
        ON DUPLICATE KEY UPDATE
            customer_token = VALUES(customer_token),
            customer_username = VALUES(customer_username),
            merchant_name = VALUES(merchant_name),
            currency = VALUES(currency),
            amount_minor = VALUES(amount_minor),
            total_quantity = VALUES(total_quantity),
            wallet_path = VALUES(wallet_path),
            items_json = VALUES(items_json),
            worldline_payment_id = VALUES(worldline_payment_id),
            worldline_checkout_id = VALUES(worldline_checkout_id),
            status = IF(status = 'closed', status, 'in_process')
    ");
    if (!$stmt) {
        return null;
    }
    $stmt->bind_param(
        'ssssiisssss',
        $normalized['reference'],
        $customerToken,
        $customerUsername,
        $normalized['merchant_name'],
        $normalized['currency'],
        $normalized['amount_minor'],
        $normalized['quantity'],
        $normalized['wallet_path'],
        $normalized['items_json'],
        $normalized['worldline_payment_id'],
        $normalized['worldline_checkout_id']
    );
    $stmt->execute();
    $stmt->close();

    return tt_orders_get_by_reference($mysqli, $normalized['reference']);
}

function tt_orders_get_by_reference(mysqli $mysqli, string $orderReference): ?array {
    tt_orders_ensure_schema($mysqli);
    $stmt = $mysqli->prepare("SELECT * FROM taptray_orders WHERE order_reference = ? LIMIT 1");
    if (!$stmt) {
        return null;
    }
    $stmt->bind_param('s', $orderReference);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc() ?: null;
    $stmt->close();
    if (!$row) {
        return null;
    }
    $row['items'] = json_decode((string) ($row['items_json'] ?? '[]'), true) ?: [];
    return $row;
}

function tt_orders_list_active_for_customer(mysqli $mysqli, string $customerToken): array {
    tt_orders_ensure_schema($mysqli);
    $stmt = $mysqli->prepare("
        SELECT * FROM taptray_orders
        WHERE customer_token = ?
          AND status IN ('in_process', 'ready')
        ORDER BY created_at DESC
    ");
    if (!$stmt) {
        return [];
    }
    $stmt->bind_param('s', $customerToken);
    $stmt->execute();
    $result = $stmt->get_result();
    $stmt->close();
    $rows = [];
    while ($row = $result->fetch_assoc()) {
        $row['items'] = json_decode((string) ($row['items_json'] ?? '[]'), true) ?: [];
        $rows[] = $row;
    }
    return $rows;
}

function tt_orders_get_active_for_customer(mysqli $mysqli, string $customerToken): ?array {
    $rows = tt_orders_list_active_for_customer($mysqli, $customerToken);
    return $rows[0] ?? null;
}

function tt_orders_list_past_for_customer(mysqli $mysqli, string $customerToken, int $limit = 10): array {
    tt_orders_ensure_schema($mysqli);
    $limit = max(1, min(50, $limit));
    $stmt = $mysqli->prepare("
        SELECT * FROM taptray_orders
        WHERE customer_token = ?
          AND status = 'closed'
        ORDER BY COALESCE(closed_at, updated_at, created_at) DESC
        LIMIT {$limit}
    ");
    if (!$stmt) {
        return [];
    }
    $stmt->bind_param('s', $customerToken);
    $stmt->execute();
    $result = $stmt->get_result();
    $stmt->close();
    $rows = [];
    while ($row = $result->fetch_assoc()) {
        $row['items'] = json_decode((string) ($row['items_json'] ?? '[]'), true) ?: [];
        $rows[] = $row;
    }
    return $rows;
}

function tt_orders_list_open(mysqli $mysqli): array {
    tt_orders_ensure_schema($mysqli);
    $result = $mysqli->query("
        SELECT *
        FROM taptray_orders
        WHERE status IN ('in_process', 'ready')
        ORDER BY created_at ASC
    ");
    if (!$result) {
        return [];
    }
    $rows = [];
    while ($row = $result->fetch_assoc()) {
        $row['items'] = json_decode((string) ($row['items_json'] ?? '[]'), true) ?: [];
        $rows[] = $row;
    }
    return $rows;
}

function tt_orders_register_push_subscription(mysqli $mysqli, string $orderReference, array $subscription, string $env): bool {
    tt_orders_ensure_schema($mysqli);
    $customerToken = tt_orders_customer_token();
    $endpoint = trim((string) ($subscription['endpoint'] ?? ''));
    $p256dh = trim((string) ($subscription['keys']['p256dh'] ?? ''));
    $auth = trim((string) ($subscription['keys']['auth'] ?? ''));
    if ($orderReference === '' || $endpoint === '' || $p256dh === '' || $auth === '') {
        return false;
    }

    $stmt = $mysqli->prepare("
        INSERT INTO taptray_order_push_subscriptions
            (order_reference, customer_token, endpoint, p256dh, auth, env)
        VALUES (?, ?, ?, ?, ?, ?)
        ON DUPLICATE KEY UPDATE
            customer_token = VALUES(customer_token),
            p256dh = VALUES(p256dh),
            auth = VALUES(auth),
            env = VALUES(env)
    ");
    if (!$stmt) {
        return false;
    }
    $stmt->bind_param('ssssss', $orderReference, $customerToken, $endpoint, $p256dh, $auth, $env);
    $ok = $stmt->execute();
    $stmt->close();
    return $ok;
}

function tt_orders_send_ready_push(mysqli $mysqli, array $order): void {
    $orderReference = trim((string) ($order['order_reference'] ?? ''));
    if ($orderReference === '') {
        return;
    }

    $stmt = $mysqli->prepare("
        SELECT endpoint, p256dh, auth, env
        FROM taptray_order_push_subscriptions
        WHERE order_reference = ?
    ");
    if (!$stmt) {
        return;
    }
    $stmt->bind_param('s', $orderReference);
    $stmt->execute();
    $subs = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
    $stmt->close();
    if (!$subs) {
        return;
    }

    $webPush = new WebPush([
        'VAPID' => [
            'subject' => getenv('VAPID_SUBJECT'),
            'publicKey' => getenv('VAPID_PUBLIC_KEY'),
            'privateKey' => getenv('VAPID_PRIVATE_KEY'),
        ]
    ]);

    foreach ($subs as $sub) {
        try {
            $env = preg_replace('/[^a-z0-9.\-]/i', '', (string) ($sub['env'] ?? 'taptray.com'));
            if ($env === '') {
                $env = 'taptray.com';
            }
            $payload = json_encode([
                'title' => 'Order ready',
                'body' => 'Your TapTray order is ready.',
                'url' => "https://{$env}/?taptray_order=" . rawurlencode($orderReference),
                'sound' => 'ding',
            ], JSON_UNESCAPED_SLASHES);
            $webPush->queueNotification(Subscription::create([
                'endpoint' => $sub['endpoint'],
                'keys' => [
                    'p256dh' => $sub['p256dh'],
                    'auth' => $sub['auth'],
                ]
            ]), $payload);
        } catch (Throwable $e) {
            error_log('TapTray ready push queue failed: ' . $e->getMessage());
        }
    }

    foreach ($webPush->flush() as $report) {
        if (!$report->isSuccess() && $report->isSubscriptionExpired()) {
            $endpoint = $report->getEndpoint();
            $del = $mysqli->prepare("DELETE FROM taptray_order_push_subscriptions WHERE endpoint = ?");
            if ($del) {
                $del->bind_param('s', $endpoint);
                $del->execute();
                $del->close();
            }
        }
    }
}

function tt_orders_update_status(mysqli $mysqli, string $orderReference, string $status): ?array {
    tt_orders_ensure_schema($mysqli);
    $status = in_array($status, ['in_process', 'ready', 'closed'], true) ? $status : 'in_process';
    $stmt = $mysqli->prepare("
        UPDATE taptray_orders
        SET status = ?,
            ready_at = IF(? = 'ready', NOW(), ready_at),
            closed_at = IF(? = 'closed', NOW(), closed_at)
        WHERE order_reference = ?
    ");
    if (!$stmt) {
        return null;
    }
    $stmt->bind_param('ssss', $status, $status, $status, $orderReference);
    $stmt->execute();
    $stmt->close();

    $order = tt_orders_get_by_reference($mysqli, $orderReference);
    if ($order && $status === 'ready') {
        tt_orders_send_ready_push($mysqli, $order);
    }
    return $order;
}
