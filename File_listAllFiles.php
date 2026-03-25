<?php
require_once __DIR__ . '/includes/functions.php';
require_once __DIR__ . '/includes/db_connect.php';
sec_session_start();

header('Content-Type: application/json');

if (!isset($_SESSION['user_id']) || !isset($_SESSION['username'])) {
    echo json_encode(["status" => "error", "error" => "not_logged_in"]);
    exit;
}

$username = $_SESSION['username'];
$userId   = $_SESSION['user_id'];

$baseDir = "/home1/wecanrec/textwhisper_uploads";

// --- Input ---
$type = strtolower($_GET['type'] ?? 'music');
$musicExts = ['mp3','wav','ogg','m4a','flac','aac','aif','aiff','mid','midi'];

// --- 1. Get all Cloudflare files ---
function listCloudflareFiles($username) {
    $url = "https://r2-worker.textwhisper.workers.dev/list?prefix=" . urlencode($username . "/");
    $json = @file_get_contents($url);
    if (!$json) return [];
    $data = json_decode($json, true);
    if (!is_array($data)) return [];
    $map = [];
    foreach ($data as $o) {
        $map[$o['key']] = $o; // keep size, uploaded, etc
    }
    return $map;
}
$cfFiles = listCloudflareFiles($username);

// --- 2. Build JustHost list ---
$localFiles = [];

$stmt = $mysqli->prepare("SELECT surrogate FROM text WHERE owner = ?");
$stmt->bind_param("s", $username);
$stmt->execute();
$res = $stmt->get_result();

while ($row = $res->fetch_assoc()) {
    $surrogate = $row['surrogate'];

    // 🎵 Music
    if ($type === "music" || $type === "all") {
        $musicPath = "$baseDir/$username/surrogate-$surrogate/files";
        if (is_dir($musicPath)) {
            foreach (scandir($musicPath) as $file) {
                if ($file === '.' || $file === '..') continue;
                $fullPath = "$musicPath/$file";
                if (!is_file($fullPath)) continue;

                $ext = strtolower(pathinfo($file, PATHINFO_EXTENSION));
                if (!in_array($ext, $musicExts)) continue;

                $key = "$username/surrogate-$surrogate/files/$file";
                $localFiles[$key] = [
                    "surrogate" => $surrogate,
                    "name"      => $file,
                    "type"      => "music",
                    "size_local" => filesize($fullPath)
                ];
            }
        }
    }

    // 📄 PDF + annotations
    if ($type === "pdf" || $type === "all") {
        $pdfPath = "$baseDir/$username/pdf/temp_pdf_surrogate-$surrogate.pdf";
        if (file_exists($pdfPath)) {
            $key = "$username/pdf/temp_pdf_surrogate-$surrogate.pdf";
            $localFiles[$key] = [
                "surrogate" => $surrogate,
                "name"      => basename($pdfPath),
                "type"      => "pdf",
                "size_local" => filesize($pdfPath)
            ];
        }

        foreach (glob("$baseDir/$username/annotations/annotation-$surrogate-*.png") as $annPath) {
            $rel = str_replace("$baseDir/", "", $annPath);
            $localFiles[$rel] = [
                "surrogate" => $surrogate,
                "name"      => basename($annPath),
                "type"      => "annotation",
                "size_local" => filesize($annPath)
            ];
        }

        foreach (glob("$baseDir/$username/annotations/users/*/annotation-$surrogate-*.png") as $annPath) {
            $rel = str_replace("$baseDir/", "", $annPath);
            $localFiles[$rel] = [
                "surrogate" => $surrogate,
                "name"      => basename($annPath),
                "type"      => "annotation_user",
                "size_local" => filesize($annPath)
            ];
        }
    }
}

// --- 3. Merge (outer join) ---
// --- 3. Merge (outer join) ---
$allKeys = array_unique(array_merge(array_keys($localFiles), array_keys($cfFiles)));
$files = [];

foreach ($allKeys as $key) {
    $local = $localFiles[$key] ?? null;
    $cf    = $cfFiles[$key] ?? null;

    // Determine type
    $type = $local['type'] ?? null;
    if (!$type && $cf && !$local) {
        $type = 'cloudflare_only';
    }

    $files[] = [
        "key"       => $key,
        "surrogate" => $local['surrogate'] ?? null,
        "name"      => $local['name'] ?? basename($key),
        "type"      => $type,
        "size_local" => $local['size_local'] ?? null,
        "size_cf"   => $cf['size'] ?? null,
        "exists_local" => (bool)$local,
        "exists_on_cloudflare" => (bool)$cf
    ];
}


// --- 4. Output ---
echo json_encode([
    "status" => "success",
    "files"  => $files
]);
