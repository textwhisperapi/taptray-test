<?php
include_once './includes/db_connect.php';
include_once './includes/functions.php';
sec_session_start();

$pending = $_SESSION['pending_google_nda'] ?? null;
if (!is_array($pending) || empty($pending['user_id']) || empty($pending['set_at'])) {
    header('Location: /login.php');
    exit;
}

if ((time() - (int)$pending['set_at']) > 1200) {
    unset($_SESSION['pending_google_nda']);
    header('Location: /login.php?error=1');
    exit;
}

$version = time();
$error = isset($_GET['error']) && $_GET['error'] === 'required';
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title>Accept NDA - TextWhisper</title>
  <link rel="stylesheet" href="/login.css?v=<?= $version ?>">
</head>
<body>
  <div class="auth-container">
    <h1>One more step</h1>
    <?php if ($error): ?>
      <div class="error">Please accept the Basic NDA to continue.</div>
    <?php endif; ?>
    <form method="post" action="/includes/process_google_nda.php">
      <label class="checkbox-label">
        <input type="checkbox" name="nda_agree" id="nda_agree" required>
        I agree to the <a href="https://trustagreements.org/basic-v1.html" target="_blank" rel="noopener">Basic NDA v1</a>
      </label>
      <button type="submit">Continue with Google</button>
    </form>
    <p><a href="/login.php">Cancel</a></p>
  </div>
</body>
</html>
