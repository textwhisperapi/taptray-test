<?php
// /api/auth/icloud/icloud-login.php
declare(strict_types=1);

require_once __DIR__ . "/../../../includes/functions.php";

sec_session_start();

function h(string $s): string { return htmlspecialchars($s, ENT_QUOTES, 'UTF-8'); }

$error = null;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
  $appleId = trim((string)($_POST['apple_id'] ?? ''));
  $appPass = trim((string)($_POST['app_password'] ?? ''));

  if ($appleId === '' || $appPass === '') {
    $error = "Apple ID and app-specific password are required.";
  } else {
    // Store in session (read-only PoC).
    // Later you can encrypt-at-rest / store per-user in DB.
    $_SESSION['icloud'] = [
      'apple_id' => $appleId,
      'app_password' => $appPass,
      'connected_at' => time(),
    ];

    // Optionally sanity-check quickly (lightweight): just attempt PROPFIND on root via list endpoint
    // but we keep it simple: let listing show errors if wrong.

    header('Content-Type: text/html; charset=utf-8');
    ?>
    <!doctype html>
    <html>
    <head><meta charset="utf-8"><title>iCloud Connected</title></head>
    <body>
    <script>
      try {
        if (window.opener && window.opener !== window) {
          window.opener.postMessage({ type: "icloud-auth-ok" }, window.location.origin);
        }
      } catch (e) {}
      window.close();
    </script>
    Connected. You can close this window.
    </body>
    </html>
    <?php
    exit;
  }
}

?>
<!doctype html>
<html>
<head>
  <meta charset="utf-8">
  <title>Connect iCloud (Read-only)</title>
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <style>
    body{font-family:system-ui,-apple-system,Segoe UI,Roboto,Arial,sans-serif;margin:0;padding:20px;background:#f6f7f9;}
    .card{max-width:520px;margin:0 auto;background:#fff;border-radius:10px;box-shadow:0 10px 25px rgba(0,0,0,.08);padding:18px 18px 14px;}
    h2{margin:0 0 10px 0;font-size:18px;}
    p{margin:8px 0;color:#444;font-size:13px;line-height:1.35;}
    label{display:block;margin:10px 0 6px;font-size:13px;color:#222;}
    input{width:100%;box-sizing:border-box;padding:9px 10px;border:1px solid #cfd5dd;border-radius:8px;font-size:14px;}
    .row{display:flex;gap:10px;margin-top:12px;justify-content:flex-end;}
    button{padding:8px 12px;border:1px solid #cfd5dd;border-radius:8px;background:#fff;cursor:pointer;font-size:13px;}
    .error{margin:10px 0 0;color:#b00020;font-size:13px;}
    .note{font-size:12px;color:#666;}
    code{font-family:ui-monospace,SFMono-Regular,Menlo,Monaco,Consolas,monospace;font-size:12px;}
  </style>
</head>
<body>
  <div class="card">
    <h2>Connect iCloud Drive (Read-only)</h2>

    <p class="note">
      Use an <strong>App-Specific Password</strong> (Apple ID with 2FA).
      This read-only PoC stores the credentials in server session memory.
    </p>

    <?php if ($error): ?>
      <div class="error"><?= h($error) ?></div>
    <?php endif; ?>

    <form method="post" autocomplete="off">
      <label>Apple ID (email)</label>
      <input name="apple_id" type="email" value="<?= h((string)($_POST['apple_id'] ?? '')) ?>" required>

      <label>App-Specific Password</label>
      <input name="app_password" type="password" placeholder="xxxx-xxxx-xxxx-xxxx" required>

      <div class="row">
        <button type="submit">Connect</button>
      </div>
    </form>

    <p class="note" style="margin-top:10px;">
      Next: the import tree will use server-side WebDAV listing and download.
    </p>
  </div>
</body>
</html>
