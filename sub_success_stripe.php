<?php
require_once __DIR__ . "/includes/functions.php";
require_once __DIR__ . "/includes/db_connect.php";
require_once __DIR__ . "/includes/sub_stripe_config.php";
require_once '/home1/wecanrec/textwhisper_vendor/stripe/stripe-php/init.php';

sec_session_start();
\Stripe\Stripe::setApiKey(STRIPE_SECRET_KEY);

$sessionId = $_GET['sid'] ?? null;

if (!$sessionId) {
    $error = "❌ Missing session ID.";
} else {
    try {
        $session = \Stripe\Checkout\Session::retrieve($sessionId);

        $subscriptionId = $session->subscription;
        $email = $session->customer_email;
        $plan = $session->metadata->plan ?? null;
        $userId = (int)($session->metadata->user_id ?? 0);
        $storageAddon = (int)($session->metadata->storage_upgrade ?? 0);
        $userAddon = (int)($session->metadata->user_upgrade ?? 0);

        if (!$plan || !$userId) {
            throw new Exception("Missing subscription metadata.");
        }

        $stmt = $mysqli->prepare("
            UPDATE members 
            SET plan = ?, 
                stripe_sub_id = ?, 
                subscribed_at = NOW(),
                storage_addon = ?, 
                user_addon = ?
            WHERE id = ?
        ");
        $stmt->bind_param("ssiii", $plan, $subscriptionId, $storageAddon, $userAddon, $userId);
        $stmt->execute();
        $stmt->close();

        $_SESSION['plan'] = $plan;

    } catch (Exception $e) {
        $error = "❌ Error: " . $e->getMessage();
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <title>Subscription Confirmed</title>
  <link rel="stylesheet" href="/sub_settings.css?v=<?= time() ?>">
  <style>

  </style>
</head>
<body>
  <div class="success-container">
    <?php if (isset($error)): ?>
      <h2>❌ Subscription Error</h2>
      <p><?= htmlspecialchars($error) ?></p>
    <?php else: ?>
      <h2>🎉 Subscription Confirmed</h2>
      <p>You are now subscribed to the <strong><?= htmlspecialchars($plan) ?></strong> plan.</p>

      <?php if ($storageAddon > 0 || $userAddon > 0): ?>
        <p>
          Includes:
          <?= $storageAddon > 0 ? " 📦 +{$storageAddon} GB" : '' ?>
          <?= $userAddon > 0 ? " 👥 +{$userAddon} users" : '' ?>
        </p>
      <?php endif; ?>

      <p><a href="/">← Return to dashboard</a></p>
    <?php endif; ?>
  </div>
</body>
</html>
