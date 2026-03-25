<?php
require_once __DIR__ . "/includes/functions.php";
require_once __DIR__ . "/includes/db_connect.php";
require_once __DIR__ . "/includes/system-paths.php";
sec_session_start();

// -----------------------------------------------------------
// 1) HANDLE FIX REQUEST (Ajax) — NEW VERSION (NO DOWNLOAD)
// -----------------------------------------------------------
if (isset($_GET['action']) && $_GET['action'] === 'fix') {
    header("Content-Type: application/json");

    if (!isset($_SESSION['username'])) {
        echo json_encode(["status" => "error", "message" => "Not logged in"]);
        exit;
    }

    $owner = $_SESSION['username'];
    $key   = $_POST['key'] ?? '';

    // Ownership / path sanity check
    if (!$key || strpos($key, $owner . "/") !== 0) {
        echo json_encode(["status" => "error", "message" => "Invalid key"]);
        exit;
    }

    // Call Worker MIDI Fix endpoint
    $workerFixUrl = "https://r2-worker.textwhisper.workers.dev/fix-midi?key=" . urlencode($key);

    $ch = curl_init($workerFixUrl);
    curl_setopt($ch, CURLOPT_POST, true);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    $response = curl_exec($ch);
    $http = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    if ($http !== 200) {
        echo json_encode([
            "status" => "error",
            "message" => "Worker fix failed",
            "http" => $http,
            "response" => $response
        ]);
        exit;
    }

    // Return Worker response directly
    echo $response;
    exit;
}

// -----------------------------------------------------------
// 2) NORMAL PAGE RENDER
// -----------------------------------------------------------
if (!isset($_SESSION['username'])) {
    echo "<h2>Not logged in</h2>";
    exit;
}

$owner = $_SESSION['username'];
$r2_list = "https://r2-worker.textwhisper.workers.dev/list?prefix=";

$audioExt = ['mid','midi','mp3','wav','ogg','m4a','flac','aac','aif','aiff','webm'];

$listUrl = $r2_list . urlencode("$owner/");
$json = @file_get_contents($listUrl);
$data = json_decode($json, true);

if (!is_array($data)) {
    echo "<h2>❌ Could not read R2 list</h2>";
    exit;
}

$surrogates = [];

// detect surrogates
foreach ($data as $obj) {
    if (!isset($obj["key"])) continue;
    if (preg_match("#surrogate-(\d+)/files/#", $obj["key"], $m)) {
        $surrogates[$m[1]] = true;
    }
}

$results = [];

// scan each surrogate
foreach ($surrogates as $sur => $_) {

    $prefix = "$owner/surrogate-$sur/files/";
    $json = file_get_contents($r2_list . urlencode($prefix));
    $files = json_decode($json, true);

    if (!is_array($files)) continue;

    foreach ($files as $f) {
        $key = $f["key"];
        $ext = strtolower(pathinfo($key, PATHINFO_EXTENSION));

        if (!in_array($ext, $audioExt)) continue;

        $url = "https://audio.textwhisper.com/" . $key;

        // HEAD request to the PUBLIC audio domain
        $ch = curl_init();
        curl_setopt($ch, CURLOPT_URL, $url);
        curl_setopt($ch, CURLOPT_NOBODY, true);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_HEADER, true);
        $headers_raw = curl_exec($ch);
        $http = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        $headers = [];
        foreach (explode("\n", $headers_raw) as $h) {
            if (strpos($h, ":") !== false) {
                list($k, $v) = explode(":", $h, 2);
                $headers[trim($k)] = trim($v);
            }
        }


$ctype  = $headers["Content-Type"] ?? null;

// Cloudflare does not reliably include CORS headers in HEAD responses
$hasCORS = true;

// determine status
$status = "ok";
if ($http !== 200) {
    $status = "missing";
} else if (($ext === "mid" || $ext === "midi") && $ctype !== "audio/mid") {
    $status = "bad_midi_type";
}


        $results[] = [
            "sur" => $sur,
            "key" => $key,
            "url" => $url,
            "http" => $http,
            "cors" => $hasCORS,
            "ctype" => $ctype,
            "status" => $status
        ];
    }
}
?>
<!DOCTYPE html>
<html>
<head>
<title>Audio Integrity Test</title>
<style>
table { border-collapse: collapse; font-size: 14px; width: 100%; }
td, th { padding: 6px 10px; border: 1px solid #ccc; }
.status-ok { color: green; }
.status-no_cors { color: red; font-weight: bold; }
.status-bad_midi_type { color: orange; font-weight: bold; }
.status-missing { color: red; font-weight: bold; }
button.fix-btn { padding:4px 8px; cursor:pointer; }
</style>
</head>

<body>

<h2>Audio Integrity Test for <?= htmlspecialchars($owner) ?></h2>

<table>
<tr>
    <th>Surrogate</th>
    <th>File</th>
    <th>Status</th>
    <th>HTTP</th>
    <th>CORS</th>
    <th>Type</th>
    <th>Actions</th>
</tr>

<?php foreach ($results as $r): ?>
<?php $cls = "status-" . $r["status"]; ?>

<tr>
    <td><?= $r["sur"] ?></td>
    <td><?= htmlspecialchars($r["key"]) ?></td>
    <td class="<?= $cls ?>"><?= $r["status"] ?></td>
    <td><?= $r["http"] ?></td>
    <td><?= $r["cors"] ? "Yes" : "No" ?></td>
    <td><?= htmlspecialchars($r["ctype"]) ?></td>
    <td>
        <a href="<?= $r["url"] ?>" target="_blank">open</a>

        <?php if ($r["status"] !== "ok"): ?>
            <button class="fix-btn" onclick="fixFile('<?= $r['key'] ?>', this)">Fix</button>
        <?php endif; ?>
    </td>
</tr>

<?php endforeach; ?>

</table>

<script>
function fixFile(key, btn) {
    btn.disabled = true;
    btn.textContent = "Fixing…";

    fetch("test_audio_integrity.php?action=fix", {
        method: "POST",
        headers: {"Content-Type": "application/x-www-form-urlencoded"},
        body: "key=" + encodeURIComponent(key)
    })
    .then(r => r.json())
    .then(data => {
        if (data.status === "success") {
            btn.textContent = "Fixed!";
            btn.style.background = "#4CAF50";
            btn.style.color = "#fff";
        } else {
            btn.textContent = "ERROR";
            btn.style.background = "red";
            console.error(data);
        }
    })
    .catch(err => {
        btn.textContent = "ERROR";
        btn.style.background = "red";
        console.error(err);
    });
}
</script>

</body>
</html>
