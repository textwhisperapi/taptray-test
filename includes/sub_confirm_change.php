<?php
require_once __DIR__ . "/includes/functions.php";
require_once __DIR__ . "/includes/db_connect.php";
require_once __DIR__ . "/includes/sub_plans.php";
require_once __DIR__ . "/includes/sub_functions.php";
require_once __DIR__ . "/includes/sub_worldline_config.php";
require_once __DIR__ . "/includes/sub_contract_summary.php";

sec_session_start();

$userId    = $_SESSION['user_id'] ?? null;
$newPlan   = $_POST['plan'] ?? null;
$storageAddonKey = (int)($_POST['storage_addon'] ?? 0);
$userAddonKey    = (int)($_POST['user_addon'] ?? 0);

if (!$userId || !$newPlan || !isset($PLANS[$newPlan])) {
    die("❌ Invalid input");
}

// fetch current subscription
$stmt = $mysqli->prepare("SELECT plan, storage_addon, user_addon, subscribed_at 
                          FROM members WHERE id=? LIMIT 1");
$stmt->bind_param("i", $userId);
$stmt->execute();
$stmt->bind_result($oldPlan, $oldStorage, $oldUsers, $subscribed_at);
$stmt->fetch();
$stmt->close();
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8" />
  <title>Confirm Plan Change</title>
  <link rel="stylesheet" href="/sub_settings.css?v=<?= time() ?>">
  <style>
    table.contract-summary { border-collapse: collapse; margin-top: 1em; width: 100%; }
    table.contract-summary td { padding: 6px 10px; vertical-align: top; }
    table.contract-summary tr:nth-child(even) { background: #f9f9f9; }
    h2 { margin-bottom: 0.2em; }
    .note { font-size: 0.9em; color: #555; }
    .actions { margin-top: 1.5em; }
    .actions button { padding: 10px 20px; margin-right: 1em; }
  </style>
</head>
<body>
  <div class="success-container">
    <h2>Confirm Plan Change</h2>
    <p class="note">Review the details below before continuing.<br>
       All amounts shown in EUR.<br>
       Change effective: <strong><?= date("Y-m-d H:i") ?></strong></p>

    <?php
      echo renderContractSummary(
          $oldPlan, $oldStorage, $oldUsers, $subscribed_at,
          $newPlan, $storageAddonKey, $userAddonKey,
          $PLANS
      );
    ?>

    <div class="actions">
      <form action="/sub_subscribe_worldline.php" method="post" style="display:inline;">
        <input type="hidden" name="plan" value="<?= htmlspecialchars($newPlan) ?>">
        <input type="hidden" name="storage_addon" value="<?= (int)$storageAddonKey ?>">
        <input type="hidden" name="user_addon" value="<?= (int)$userAddonKey ?>">
        <button type="submit">✅ Confirm & Continue</button>
      </form>
      <form action="/sub_settings.php" method="get" style="display:inline;">
        <button type="submit">❌ Cancel</button>
      </form>
    </div>
  </div>
</body>
</html>
