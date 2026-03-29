<?php
declare(strict_types=1);

require_once __DIR__ . '/includes/taptray_orders.php';

sec_session_start();
header('Content-Type: application/json; charset=utf-8');

tt_orders_ensure_schema($mysqli);
$customerToken = tt_orders_customer_token();
$draftOrder = tt_orders_get_draft_for_customer($mysqli, $customerToken);
$orders = tt_orders_list_active_for_customer($mysqli, $customerToken);
$pastOrders = tt_orders_list_past_for_customer($mysqli, $customerToken, 10);
$order = tt_orders_get_active_for_customer($mysqli, $customerToken);

echo json_encode([
    'ok' => true,
    'customer_token' => $customerToken,
    'draft_order' => $draftOrder,
    'orders' => $orders,
    'past_orders' => $pastOrders,
    'order' => $order,
], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
