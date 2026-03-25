<?php
require_once "/includes/functions.php";
sec_session_start();

if (!isset($_SESSION['user_id'])) {
    die("❌ Not logged in.");
}
?>
<!DOCTYPE html>
<html>
<head>
  <title>Payment Cancelled</title>
</head>
<body>
  <h1>❌ Payment Cancelled</h1>
  <p>Your Worldline checkout was cancelled. No changes were made to your subscription.</p>
  <p><a href="/sub_pricing.php">Return to subscription plans</a></p>
</body>
</html>
