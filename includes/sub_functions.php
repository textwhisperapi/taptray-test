<?php
/**
 * Subscription-related functions
 * (DB updates, provider cancellation, session sync)
 */

require_once __DIR__ . '/db_connect.php';
require_once __DIR__ . '/sub_plans.php';


use Worldline\Connect\Sdk\V1\Domain\RefundRequest as WLRefundRequest;
use Worldline\Connect\Sdk\V1\Domain\AmountOfMoney as WLAmountOfMoney;




/**
 * Change a user's subscription plan.
 *
 * Strategy: cancel old subscription (if providerSubId provided),
 * then activate the new plan in DB + session.
 *
 * @param int    $userId
 * @param string $newPlanKey
 * @param int    $storageAddon
 * @param int    $userAddon
 * @param string $provider stripe|paypal|worldline
 * @param string|null $providerSubId subscription/agreement/token id to cancel
 *
 * @return bool
 */
function changeUserPlan($userId, $newPlanKey, $storageAddon = 0, $userAddon = 0, $provider = 'worldline', $providerSubId = null) {
    global $mysqli, $PLANS;

    if (!isset($PLANS[$newPlanKey])) {
        error_log("Invalid plan: " . $newPlanKey);
        return false;
    }

    // Cancel existing provider subscription if provided
    if ($providerSubId) {
        try {
            if ($provider === 'stripe') {
                \Stripe\Stripe::setApiKey(STRIPE_SECRET_KEY);
                $sub = \Stripe\Subscription::retrieve($providerSubId);
                $sub->cancel();
            } elseif ($provider === 'paypal') {
                // TODO: call PayPal Billing Agreement cancel endpoint
                // cancelPayPalSubscription($providerSubId);
            } elseif ($provider === 'worldline') {
                // For Worldline: just stop using the token
                // optionally revoke token if stored
            }
        } catch (Exception $e) {
            error_log("Error cancelling old subscription for user {$userId}: " . $e->getMessage());
            // Don’t block new subscription
        }
    }

    // Update DB
    $stmt = $mysqli->prepare("
        UPDATE members 
           SET plan = ?, 
               storage_addon = ?, 
               user_addon = ?, 
               subscription_status = 'active', 
               subscribed_at = NOW()
         WHERE id = ?
    ");
    $stmt->bind_param("siii", $newPlanKey, $storageAddon, $userAddon, $userId);
    $stmt->execute();
    $stmt->close();

    // Update session
    $_SESSION['plan']          = $newPlanKey;
    $_SESSION['storage_addon'] = $storageAddon;
    $_SESSION['user_addon']    = $userAddon;

    return true;
}

/**
 * Cancel a user's plan (keep until period end or stop immediately).
 *
 * @param int    $userId
 * @param string $provider
 * @param string|null $providerSubId
 * @param bool   $immediate
 */
function cancelUserPlan($userId, $provider = 'worldline', $providerSubId = null, $immediate = true) {
    global $mysqli;

    if ($providerSubId) {
        try {
            if ($provider === 'stripe') {
                \Stripe\Stripe::setApiKey(STRIPE_SECRET_KEY);
                $sub = \Stripe\Subscription::retrieve($providerSubId);
                $sub->cancel(['invoice_now' => false, 'prorate' => false]);
            } elseif ($provider === 'paypal') {
                // TODO: cancel PayPal Billing Agreement
            } elseif ($provider === 'worldline') {
                // Nothing to do here except mark inactive
            }
        } catch (Exception $e) {
            error_log("Error cancelling subscription for user {$userId}: " . $e->getMessage());
        }
    }

    // Update DB
    $stmt = $mysqli->prepare("
        UPDATE members 
           SET subscription_status = 'canceled'
         WHERE id = ?
    ");
    $stmt->bind_param("i", $userId);
    $stmt->execute();
    $stmt->close();

    return true;
}


use Worldline\Connect\Sdk\V1\Domain\RefundRequest;
use Worldline\Connect\Sdk\V1\Domain\AmountOfMoney;

/**
 * Issue a pro-rata refund for a downgrade.
 *
 * @param int    $userId
 * @param string $provider stripe|paypal|worldline
 * @param string $paymentId the last payment reference
 * @param float  $refundAmount in EUR (not cents)
 * @return bool
 */
function issueRefund($userId, $provider, $paymentId, $refundAmount) {
    global $mysqli;

    if ($refundAmount <= 0) {
        return false;
    }

    try {
        $refundId = null;

        if ($provider === 'worldline') {
            $client = wl_client();

            $refundRequest = new WLRefundRequest();
            $refundRequest->amountOfMoney = new WLAmountOfMoney();
            $refundRequest->amountOfMoney->amount = intval($refundAmount * 100); // cents
            $refundRequest->amountOfMoney->currencyCode = "EUR";

            $refundResponse = $client->v1()
                ->merchant(WL_MERCHANT_ID)
                ->payments()
                ->refund($paymentId, $refundRequest);

            $refundId = $refundResponse->id ?? null;
        }

        // Decide what to log
        $reference = $refundId ?? $paymentId;
        $status    = $refundId ? 'REFUNDED' : 'REFUND_REQUESTED';

        // Log refund in payments_log
        $stmt = $mysqli->prepare("
            INSERT INTO payments_log (user_id, gateway, reference, amount, currency, status, created_at)
            VALUES (?, ?, ?, ?, 'EUR', ?, NOW())
        ");
        $stmt->bind_param("isids", $userId, $provider, $reference, $refundAmount, $status);
        $stmt->execute();
        $stmt->close();

        return true;

    } catch (Exception $e) {
        error_log("Refund failed for user {$userId}: " . $e->getMessage());
        return false;
    }
}



/**
 * Get total storage (GB) and file count for a user's uploads.
 *
 * @param mysqli $db       Database connection
 * @param int    $userId   ID from members table
 * @param string $basePath Uploads base directory
 * @return array           ['gb' => float, 'files' => int]
 */
function getUserStorageStats(mysqli $db, int $userId, string $basePath = "/home1/wecanrec/textwhisper_uploads/"): array {
    $sqlTextBytes = 0;
    $sqlTextItems = 0;
    $stmt = $db->prepare("
        SELECT
            COALESCE(SUM(OCTET_LENGTH(Text)), 0) AS text_bytes,
            COUNT(*) AS item_count
        FROM text
        WHERE owner = (SELECT username FROM members WHERE id = ? LIMIT 1)
          AND (deleted IS NULL OR deleted != 'D')
    ");
    if ($stmt instanceof mysqli_stmt) {
        $stmt->bind_param("i", $userId);
        if ($stmt->execute()) {
            $stmt->bind_result($sqlTextBytes, $sqlTextItems);
            $stmt->fetch();
        }
        $stmt->close();
    }

    $hasMgmtStorageUsage = false;
    $tableRes = $db->query("SHOW TABLES LIKE 'mgmt_storage_usage'");
    if ($tableRes instanceof mysqli_result) {
        $hasMgmtStorageUsage = $tableRes->num_rows > 0;
        $tableRes->close();
    }

    if ($hasMgmtStorageUsage) {
        $hasOrphanColumns = false;
        $colRes = $db->query("SHOW COLUMNS FROM mgmt_storage_usage LIKE 'orphan_file_count'");
        if ($colRes instanceof mysqli_result) {
            $hasOrphanColumns = $colRes->num_rows > 0;
            $colRes->close();
        }

        $sql = "
            SELECT
                COALESCE(msu.gb_used, 0) AS gb_used,
                COALESCE(msu.file_count, 0) AS file_count,
                msu.by_type_json,
                " . ($hasOrphanColumns ? "COALESCE(msu.orphan_gb_used, 0)" : "0") . " AS orphan_gb_used,
                " . ($hasOrphanColumns ? "COALESCE(msu.orphan_file_count, 0)" : "0") . " AS orphan_file_count,
                msu.scanned_at
            FROM members m
            LEFT JOIN mgmt_storage_usage msu
              ON msu.member_id = m.id
             AND msu.source = 'cloudflare'
            WHERE m.id = ?
            LIMIT 1
        ";
        $stmt = $db->prepare($sql);
        if ($stmt instanceof mysqli_stmt) {
            $stmt->bind_param("i", $userId);
            if ($stmt->execute()) {
                $result = $stmt->get_result();
                $row = $result instanceof mysqli_result ? $result->fetch_assoc() : null;
                if ($result instanceof mysqli_result) {
                    $result->close();
                }
                $stmt->close();
                if (is_array($row)) {
                    $byType = json_decode((string)($row['by_type_json'] ?? ''), true);
                    return [
                        'gb' => round((float)($row['gb_used'] ?? 0), 3),
                        'files' => (int)($row['file_count'] ?? 0),
                        'by_type' => is_array($byType) ? $byType : [],
                        'sql_text_gb' => round(((int)$sqlTextBytes) / 1073741824, 3),
                        'sql_text_items' => (int)$sqlTextItems,
                        'orphan_gb' => round((float)($row['orphan_gb_used'] ?? 0), 3),
                        'orphan_files' => (int)($row['orphan_file_count'] ?? 0),
                        'scanned_at' => (string)($row['scanned_at'] ?? ''),
                        'source' => 'cloudflare_cache',
                    ];
                }
            } else {
                $stmt->close();
            }
        }
    }

    // 1. Resolve username
    $stmt = $db->prepare("SELECT username FROM members WHERE id = ?");
    $stmt->bind_param("i", $userId);
    $stmt->execute();
    $stmt->bind_result($username);
    $stmt->fetch();
    $stmt->close();

    if (!$username) {
        return ['gb' => 0.0, 'files' => 0, 'by_type' => [], 'sql_text_gb' => round(((int)$sqlTextBytes) / 1073741824, 3), 'sql_text_items' => (int)$sqlTextItems, 'orphan_gb' => 0.0, 'orphan_files' => 0, 'scanned_at' => '', 'source' => 'none'];
    }

    // 2. Point to user folder
    $userDir = rtrim($basePath, "/") . "/" . $username;

    if (!is_dir($userDir)) {
        return ['gb' => 0.0, 'files' => 0, 'by_type' => [], 'sql_text_gb' => round(((int)$sqlTextBytes) / 1073741824, 3), 'sql_text_items' => (int)$sqlTextItems, 'orphan_gb' => 0.0, 'orphan_files' => 0, 'scanned_at' => '', 'source' => 'filesystem'];
    }

    // 3. Scan recursively
    $bytes = 0;
    $files = 0;
    $iterator = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($userDir, FilesystemIterator::SKIP_DOTS)
    );

    foreach ($iterator as $file) {
        if ($file->isFile()) {
            $bytes += $file->getSize();
            $files++;
        }
    }

    return [
        'gb'    => round($bytes / (1024 * 1024 * 1024), 2),
        'files' => $files,
        'by_type' => [],
        'sql_text_gb' => round(((int)$sqlTextBytes) / 1073741824, 3),
        'sql_text_items' => (int)$sqlTextItems,
        'orphan_gb' => 0.0,
        'orphan_files' => 0,
        'scanned_at' => '',
        'source' => 'filesystem',
    ];
}





/**
 * Count active team members (seats) for a user.
 * Includes owner + invited members with role_rank >= 60 (viewer or higher).
 */
function getTeamMemberCount(mysqli $db, int $userId): int {
    $sql = "
        SELECT COUNT(DISTINCT i.email)
        FROM content_lists cl
        JOIN invitations i ON i.listToken = cl.token
        WHERE cl.owner_id = ?
          AND i.role_rank >= 60
    ";
    $stmt = $db->prepare($sql);
    $stmt->bind_param("i", $userId);
    $stmt->execute();
    $stmt->bind_result($cnt);
    $stmt->fetch();
    $stmt->close();

    return (int)$cnt + 1; // +1 for the owner
}




/**
 * 🔹 Count lists owned by user
 */
function getUserListsCount(mysqli $db, int $userId): int {
    $stmt = $db->prepare("SELECT COUNT(*) FROM content_lists WHERE owner_id = ?");
    $stmt->bind_param("i", $userId);
    $stmt->execute();
    $stmt->bind_result($cnt);
    $stmt->fetch();
    $stmt->close();
    return (int)$cnt;
}


/**
 * 🔹 Count items in all lists owned by user
 */
function getUserItemsCount(mysqli $db, int $userId): int {
    $sql = "
        SELECT COUNT(*)
        FROM content_list_items cli
        JOIN content_lists cl ON cl.id = cli.content_list_id
        WHERE cl.owner_id = ?
    ";
    $stmt = $db->prepare($sql);
    $stmt->bind_param("i", $userId);
    $stmt->execute();
    $stmt->bind_result($cnt);
    $stmt->fetch();
    $stmt->close();
    return (int)$cnt;
}



