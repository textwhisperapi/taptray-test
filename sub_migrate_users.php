<?php
require_once __DIR__ . "/includes/functions.php";
require_once __DIR__ . "/includes/db_connect.php";
sec_session_start();

if (!isset($_SESSION['user_id'])) {
    header("Location: /login.php");
    exit;
}

$currentUsername = trim((string)($_SESSION['username'] ?? ''));
$isMigrationAdmin = ($currentUsername === 'grimmi');
if (!$isMigrationAdmin) {
    http_response_code(403);
    if (isset($_GET['ajax'])) {
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode(['status' => 'error', 'error' => 'forbidden']);
    } else {
        echo "Forbidden";
    }
    exit;
}

header_remove("X-Powered-By");

$version = time();
$baseDirCandidates = [
    "/var/textwhisper_uploads",
    "/home1/wecanrec/textwhisper_uploads",
];
$baseDir = $baseDirCandidates[0];
foreach ($baseDirCandidates as $candidate) {
    if (is_dir($candidate)) {
        $baseDir = $candidate;
        break;
    }
}
$r2Base  = "https://r2-worker.textwhisper.workers.dev";
$musicExts = ['mp3', 'wav', 'ogg', 'm4a', 'flac', 'aac', 'aif', 'aiff', 'mid', 'midi'];

$langFile = __DIR__ . "/lang/en.php";
$lang = file_exists($langFile) ? include $langFile : [];
$t = static fn(string $k, string $fallback): string => $lang[$k] ?? $fallback;

if (isset($_GET['ajax'])) {
    header('Content-Type: application/json; charset=utf-8');

    $action = $_POST['action'] ?? '';

    if ($action === 'scan_users') {
        $users = listUsersNeedingMigrationFromDb($mysqli);
        $out = [];

        foreach ($users as $username) {
            $localKeys = getUserLocalKeys($baseDir, $username, $musicExts);

            $cfKeys = listCloudflareKeys($r2Base, $username);
            $pending = 0;
            foreach ($localKeys as $key => $_meta) {
                if (!isset($cfKeys[$key])) {
                    $pending++;
                }
            }

            $out[] = [
                'username' => $username,
                'local_count' => count($localKeys),
                'pending_count' => $pending,
                'migrated_count' => max(0, count($localKeys) - $pending),
            ];
        }

        usort($out, static function(array $a, array $b): int {
            if ($a['pending_count'] === $b['pending_count']) {
                return strcasecmp($a['username'], $b['username']);
            }
            return $b['pending_count'] <=> $a['pending_count'];
        });

        echo json_encode([
            'status' => 'success',
            'users' => $out,
        ]);
        exit;
    }

    if ($action === 'migrate_user') {
        $username = trim((string)($_POST['username'] ?? ''));
        if ($username === '' || !preg_match('/^[a-zA-Z0-9_.-]+$/', $username)) {
            echo json_encode(['status' => 'error', 'error' => 'invalid_username']);
            exit;
        }

        $localKeys = getUserLocalKeys($baseDir, $username, $musicExts);
        if (!$localKeys) {
            setUserFileserverCloudflare($mysqli, $username);
            echo json_encode([
                'status' => 'success',
                'username' => $username,
                'migrated' => 0,
                'skipped' => 0,
                'failed' => 0,
                'failed_keys' => [],
                'fileserver_updated' => true,
            ]);
            exit;
        }

        $cfKeys = listCloudflareKeys($r2Base, $username);

        $migrated = 0;
        $skipped = 0;
        $failed = 0;
        $failedKeys = [];

        foreach ($localKeys as $key => $fullPath) {
            if (isset($cfKeys[$key])) {
                $skipped++;
                continue;
            }

            if (!is_file($fullPath)) {
                $failed++;
                $failedKeys[] = $key;
                continue;
            }

            if (uploadToR2($r2Base, $fullPath, $key)) {
                $migrated++;
            } else {
                $failed++;
                $failedKeys[] = $key;
            }
        }

        echo json_encode([
            'status' => $failed > 0 ? 'partial' : 'success',
            'username' => $username,
            'migrated' => $migrated,
            'skipped' => $skipped,
            'failed' => $failed,
            'failed_keys' => $failedKeys,
            'fileserver_updated' => ($failed === 0) ? setUserFileserverCloudflare($mysqli, $username) : false,
        ]);
        exit;
    }

    echo json_encode(['status' => 'error', 'error' => 'unknown_action']);
    exit;
}

function listUsersNeedingMigrationFromDb(mysqli $mysqli): array {
    $users = [];

    $sql = "
        SELECT username
        FROM members
        WHERE username IS NOT NULL
          AND username <> ''
          AND (
            LOWER(COALESCE(fileserver, '')) IN ('justhost', 'php')
            OR COALESCE(fileserver, '') = ''
          )
        ORDER BY username ASC
    ";
    $res = $mysqli->query($sql);
    if ($res) {
        while ($row = $res->fetch_assoc()) {
            $u = trim((string)($row['username'] ?? ''));
            if ($u === '') continue;
            if (!preg_match('/^[a-zA-Z0-9_.-]+$/', $u)) continue;
            $users[] = $u;
        }
        $res->close();
    }

    return $users;
}

function setUserFileserverCloudflare(mysqli $mysqli, string $username): bool {
    $stmt = $mysqli->prepare("UPDATE members SET fileserver = 'cloudflare' WHERE username = ? LIMIT 1");
    if (!$stmt) {
        return false;
    }
    $stmt->bind_param("s", $username);
    $ok = $stmt->execute();
    $stmt->close();
    return $ok;
}

function getUserLocalKeys(string $baseDir, string $username, array $musicExts): array {
    $keys = [];

    $userRoot = $baseDir . '/' . $username;
    if (!is_dir($userRoot)) {
        return [];
    }

    foreach (glob($userRoot . '/surrogate-*/files/*') ?: [] as $path) {
        if (!is_file($path)) {
            continue;
        }
        $ext = strtolower(pathinfo($path, PATHINFO_EXTENSION));
        if (!in_array($ext, $musicExts, true)) {
            continue;
        }
        $key = ltrim(str_replace($baseDir, '', $path), '/');
        $keys[$key] = $path;
    }

    foreach (glob($userRoot . '/pdf/temp_pdf_surrogate-*.pdf') ?: [] as $path) {
        if (!is_file($path)) {
            continue;
        }
        $key = ltrim(str_replace($baseDir, '', $path), '/');
        $keys[$key] = $path;
    }

    foreach (glob($userRoot . '/annotations/annotation-*.png') ?: [] as $path) {
        if (!is_file($path)) {
            continue;
        }
        $key = ltrim(str_replace($baseDir, '', $path), '/');
        $keys[$key] = $path;
    }

    foreach (glob($userRoot . '/annotations/users/*/annotation-*.png') ?: [] as $path) {
        if (!is_file($path)) {
            continue;
        }
        $key = ltrim(str_replace($baseDir, '', $path), '/');
        $keys[$key] = $path;
    }

    return $keys;
}

function listCloudflareKeys(string $r2Base, string $username): array {
    $url = $r2Base . '/list?prefix=' . urlencode($username . '/');
    $json = @file_get_contents($url);
    if (!$json) {
        return [];
    }

    $data = json_decode($json, true);
    if (!is_array($data)) {
        return [];
    }

    $keys = [];
    foreach ($data as $obj) {
        if (!empty($obj['key'])) {
            $keys[$obj['key']] = true;
        }
    }

    return $keys;
}

function uploadToR2(string $r2Base, string $fullPath, string $key): bool {
    $uploadUrl = $r2Base . '/?key=' . urlencode($key);
    $mime = mime_content_type($fullPath) ?: 'application/octet-stream';
    $body = @file_get_contents($fullPath);
    if ($body === false) {
        return false;
    }

    $ch = curl_init($uploadUrl);
    curl_setopt($ch, CURLOPT_POST, true);
    curl_setopt($ch, CURLOPT_HTTPHEADER, [
        'Content-Type: ' . $mime,
    ]);
    curl_setopt($ch, CURLOPT_POSTFIELDS, $body);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);

    $response = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $error = curl_error($ch);
    curl_close($ch);

    if ($httpCode !== 200) {
        @file_put_contents(
            '/tmp/migration_debug.log',
            sprintf("[%s] Upload %s => HTTP %s, curl=%s, resp=%s\n", date('c'), $key, (string)$httpCode, $error, (string)$response),
            FILE_APPEND
        );
    }

    return $httpCode === 200;
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <title><?= htmlspecialchars($t('migrate_users_cloudflare', 'Manage user migration to Cloudflare'), ENT_QUOTES, 'UTF-8') ?></title>
  <link rel="stylesheet" href="/sub_settings.css?v=<?= $version ?>">
  <style>
    .migration-wrapper { max-width: 1000px; margin: 0 auto; padding: 20px; }
    .controls { display: flex; gap: 10px; align-items: center; margin-bottom: 12px; }
    table { width: 100%; border-collapse: collapse; }
    th, td { border: 1px solid #d8d8d8; padding: 8px; text-align: left; font-size: 14px; }
    .status { font-weight: 600; }
    .status-pending { color: #666; }
    .status-running { color: #0058cc; }
    .status-done { color: #118a11; }
    .status-failed { color: #b00020; }
    button[disabled] { opacity: .6; cursor: not-allowed; }
    .mono { font-family: ui-monospace, SFMono-Regular, Menlo, Consolas, monospace; }
  </style>
</head>
<body>
<div class="migration-wrapper">
  <a href="javascript:history.back()" class="back-link">← Back</a>
  <h2>☁️ <?= htmlspecialchars($t('migrate_users_cloudflare', 'Manage user migration to Cloudflare'), ENT_QUOTES, 'UTF-8') ?></h2>
  <p><?= htmlspecialchars($t('migrate_users_help', 'Scan all users and migrate only files that are still missing on Cloudflare.'), ENT_QUOTES, 'UTF-8') ?></p>

  <div class="controls">
    <button id="scanBtn"><?= htmlspecialchars($t('scan_users', 'Scan users'), ENT_QUOTES, 'UTF-8') ?></button>
    <span id="summary" class="status status-pending"></span>
  </div>

  <table>
    <thead>
      <tr>
        <th>User</th>
        <th>Local files</th>
        <th>Already on Cloudflare</th>
        <th>Pending migration</th>
        <th>Status</th>
        <th>Action</th>
      </tr>
    </thead>
    <tbody id="rows">
      <tr><td colspan="6">No scan yet.</td></tr>
    </tbody>
  </table>
</div>

<script>
const scanBtn = document.getElementById('scanBtn');
const rows = document.getElementById('rows');
const summary = document.getElementById('summary');

let users = [];

scanBtn.addEventListener('click', async () => {
  scanBtn.disabled = true;
  summary.textContent = 'Scanning users...';
  rows.innerHTML = '<tr><td colspan="6">Scanning...</td></tr>';

  const fd = new FormData();
  fd.append('action', 'scan_users');

  try {
    const res = await fetch('?ajax=1', { method: 'POST', body: fd });
    const data = await res.json();

    if (!data || data.status !== 'success') {
      throw new Error(data?.error || 'scan_failed');
    }

    users = data.users || [];
    const usersWithPending = users.filter(u => Number(u.pending_count || 0) > 0).length;
    renderRows();
    summary.textContent = users.length
      ? `Found ${users.length} users on justhost/php (${usersWithPending} with pending files).`
      : 'No users found with justhost/php fileserver.';
  } catch (err) {
    rows.innerHTML = `<tr><td colspan="6">Error: ${escapeHtml(err.message || 'scan_failed')}</td></tr>`;
    summary.textContent = 'Scan failed.';
  } finally {
    scanBtn.disabled = false;
  }
});

function renderRows() {
  if (!users.length) {
    rows.innerHTML = '<tr><td colspan="6">No users found for migration.</td></tr>';
    return;
  }

  rows.innerHTML = '';

  users.forEach((u, idx) => {
    const tr = document.createElement('tr');
    tr.dataset.index = String(idx);
    const pending = Number(u.pending_count || 0);
    const canMigrate = true;
    const initialStatus = pending > 0 ? 'Pending' : 'Ready (no pending files)';
    tr.innerHTML = `
      <td class="mono">${escapeHtml(u.username)}</td>
      <td>${Number(u.local_count || 0)}</td>
      <td>${Number(u.migrated_count || 0)}</td>
      <td>${pending}</td>
      <td class="status ${pending > 0 ? 'status-pending' : 'status-done'}">${initialStatus}</td>
      <td><button data-action="migrate" data-index="${idx}" ${canMigrate ? '' : 'disabled'}>Migrate user</button></td>
    `;
    rows.appendChild(tr);
  });
}

rows.addEventListener('click', async (e) => {
  const btn = e.target.closest('button[data-action="migrate"]');
  if (!btn) return;

  const idx = Number(btn.dataset.index);
  const user = users[idx];
  if (!user) return;

  const tr = rows.querySelector(`tr[data-index="${idx}"]`);
  const statusCell = tr?.querySelector('.status');
  if (!tr || !statusCell) return;

  btn.disabled = true;
  statusCell.className = 'status status-running';
  statusCell.textContent = 'Migrating...';

  const fd = new FormData();
  fd.append('action', 'migrate_user');
  fd.append('username', user.username);

  try {
    const res = await fetch('?ajax=1', { method: 'POST', body: fd });
    const data = await res.json();

    if (!data || (data.status !== 'success' && data.status !== 'partial')) {
      throw new Error(data?.error || 'migration_failed');
    }

    if (data.failed > 0) {
      statusCell.className = 'status status-failed';
      statusCell.textContent = `Partial: ${data.migrated} migrated, ${data.failed} failed`;
    } else {
      statusCell.className = 'status status-done';
      statusCell.textContent = `Done: ${data.migrated} migrated`;
    }

    const pendingLeft = Math.max(0, Number(user.pending_count || 0) - Number(data.migrated || 0));
    tr.children[2].textContent = String(Number(user.migrated_count || 0) + Number(data.migrated || 0));
    tr.children[3].textContent = String(pendingLeft);

    if (pendingLeft === 0 && data.failed === 0) {
      btn.remove();
      statusCell.className = 'status status-done';
      statusCell.textContent = 'Done';
    } else {
      btn.disabled = false;
    }
  } catch (err) {
    statusCell.className = 'status status-failed';
    statusCell.textContent = `Failed: ${escapeHtml(err.message || 'migration_failed')}`;
    btn.disabled = false;
  }
});

function escapeHtml(value) {
  return String(value)
    .replaceAll('&', '&amp;')
    .replaceAll('<', '&lt;')
    .replaceAll('>', '&gt;')
    .replaceAll('"', '&quot;')
    .replaceAll("'", '&#039;');
}
</script>
</body>
</html>
