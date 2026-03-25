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

// --- Inputs ---
$type      = strtolower($_POST['type'] ?? 'music');
$surrogate = $_POST['surrogate'] ?? null;
$filename  = $_POST['filename'] ?? null;

$musicExts = ['mp3','wav','ogg','m4a','flac','aac','aif','aiff','mid','midi'];

// Get existing Cloudflare keys once so we only migrate files not yet uploaded.
$existingCloudflareKeys = listCloudflareKeys($username);

// --- Single-file migration mode ---
if ($surrogate && $filename) {
    $surrogate = preg_replace('/[^0-9]/', '', $surrogate);
    $filename  = basename($filename);

    $fullPath = null;
    $key      = null;

    if ($type === "music") {
        $fullPath = "$baseDir/$username/surrogate-$surrogate/files/$filename";
        $key      = "$username/surrogate-$surrogate/files/$filename";
    } elseif ($type === "pdf") {
        $fullPath = "$baseDir/$username/pdf/$filename";
        $key      = "$username/pdf/$filename";
    } elseif ($type === "annotation" || $type === "annotation_user") {
        $fullPath = "$baseDir/$username/annotations/$filename";
        if ($type === "annotation_user") {
            // find in any user folder
            $matches = glob("$baseDir/$username/annotations/users/*/$filename");
            if ($matches) $fullPath = $matches[0];
        }
        $key = str_replace("$baseDir/", "", $fullPath);
    }

    if ($fullPath && file_exists($fullPath)) {
        if (isset($existingCloudflareKeys[$key])) {
            echo json_encode(["status" => "success", "migrated" => 0, "skipped" => "already_on_cloudflare"]);
            exit;
        }

        if (uploadToR2($fullPath, $key)) {
            echo json_encode(["status" => "success", "migrated" => 1]);
        } else {
            echo json_encode(["status" => "error", "error" => "upload_failed"]);
        }
    } else {
        echo json_encode(["status" => "error", "error" => "file_not_found"]);
    }
    exit;
}

// --- (Fallback) Batch migration mode ---
$migrated = 0;
$skipped  = 0;
$errors   = [];

// Get all surrogates
$stmt = $mysqli->prepare("SELECT surrogate FROM text WHERE owner = ?");
$stmt->bind_param("s", $username);
$stmt->execute();
$res = $stmt->get_result();

while ($row = $res->fetch_assoc()) {
    $surr = $row['surrogate'];

    // Music
    if ($type === "music" || $type === "all") {
        $musicPath = "$baseDir/$username/surrogate-$surr/files";
        if (is_dir($musicPath)) {
            foreach (scandir($musicPath) as $file) {
                if ($file === '.' || $file === '..') continue;
                $ext = strtolower(pathinfo($file, PATHINFO_EXTENSION));
                if (!in_array($ext, $musicExts)) continue;

                $fullPath = "$musicPath/$file";
                $key      = "$username/surrogate-$surr/files/$file";
                if (file_exists($fullPath)) {
                    if (isset($existingCloudflareKeys[$key])) {
                        $skipped++;
                        continue;
                    }
                    if (uploadToR2($fullPath, $key)) $migrated++;
                    else $errors[] = $file;
                }
            }
        }
    }

    // PDF + annotations
    if ($type === "pdf" || $type === "all") {
        $pdfPath = "$baseDir/$username/pdf/temp_pdf_surrogate-$surr.pdf";
        if (file_exists($pdfPath)) {
            $key = "$username/pdf/temp_pdf_surrogate-$surr.pdf";
            if (isset($existingCloudflareKeys[$key])) {
                $skipped++;
            } else
            if (uploadToR2($pdfPath, $key)) $migrated++;
            else $errors[] = basename($pdfPath);
        }

        foreach (glob("$baseDir/$username/annotations/annotation-$surr-*.png") as $annPath) {
            $rel = str_replace("$baseDir/", "", $annPath);
            if (isset($existingCloudflareKeys[$rel])) {
                $skipped++;
                continue;
            }
            if (uploadToR2($annPath, $rel)) $migrated++;
            else $errors[] = basename($annPath);
        }

        foreach (glob("$baseDir/$username/annotations/users/*/annotation-$surr-*.png") as $annPath) {
            $rel = str_replace("$baseDir/", "", $annPath);
            if (isset($existingCloudflareKeys[$rel])) {
                $skipped++;
                continue;
            }
            if (uploadToR2($annPath, $rel)) $migrated++;
            else $errors[] = basename($annPath);
        }
    }
}

echo json_encode([
    "status"   => empty($errors) ? "success" : "partial",
    "migrated" => $migrated,
    "skipped"  => $skipped,
    "errors"   => $errors
]);

// --- Helper ---
function uploadToR2($fullPath, $key) {
    $uploadUrl = "https://r2-worker.textwhisper.workers.dev/?key=" . urlencode($key);

    $ch = curl_init($uploadUrl);
    curl_setopt($ch, CURLOPT_POST, true);
    curl_setopt($ch, CURLOPT_HTTPHEADER, [
        "Content-Type: " . (mime_content_type($fullPath) ?: "application/octet-stream")
    ]);
    curl_setopt($ch, CURLOPT_POSTFIELDS, file_get_contents($fullPath));
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);

    $response = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $error    = curl_error($ch);
    curl_close($ch);

    // Optional debug
    if ($httpCode !== 200) {
        file_put_contents('/tmp/migration_debug.log',
            "Upload $key: HTTP $httpCode, CurlErr: $error, Resp: $response\n",
            FILE_APPEND
        );
    }

    return $httpCode === 200;
}

function listCloudflareKeys($username) {
    $url = "https://r2-worker.textwhisper.workers.dev/list?prefix=" . urlencode($username . "/");
    $json = @file_get_contents($url);
    if (!$json) return [];

    $data = json_decode($json, true);
    if (!is_array($data)) return [];

    $keys = [];
    foreach ($data as $obj) {
        if (!empty($obj['key'])) {
            $keys[$obj['key']] = true;
        }
    }
    return $keys;
}

