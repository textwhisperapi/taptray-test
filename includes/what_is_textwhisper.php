<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title>What is TapTray?</title>
  <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
</head>
<body>
  <div class="container mt-5">
    <p>
      <button class="btn btn-secondary" onclick="
        if (document.referrer && document.referrer.includes(location.hostname)) {
          history.back();
        } else {
          location.href = '/';
        }
      ">⬅️ Back to TapTray</button>
    </p>

    <h1 class="text-center">What is TapTray?</h1>
    <p class="lead text-center">
      TapTray is a QR-first restaurant menu and ordering platform. Guests scan a code, browse the live menu, choose items, and move toward fast wallet-based payment, while restaurants manage menus, availability, and service flow from one shared system.
    </p>
  </div>
</body>
</html>
