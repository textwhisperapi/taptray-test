<?php
/**
 * File_downloadOneDrive.php
 *
 * Streams a file from OneDrive via Microsoft Graph to the browser.
 * Used by the Drive Import pipeline (Google / Dropbox parity).
 *
 * GET ?id=<onedrive_item_id>
 */

ini_set('display_errors', 1);
error_reporting(E_ALL);

header("X-Content-Type-Options: nosniff");

/* ================= SESSION ================= */

session_start();

$accessToken = $_SESSION['ONEDRIVE_ACCESS_TOKEN'] ?? null;

if (!$accessToken) {
    http_response_code(401);
    echo "OneDrive not connected";
    exit;
}

/* ================= INPUT ================= */

// $itemId = $_GET['id'] ?? null;
$itemId = $_GET['itemId'] ?? null;

if (!$itemId) {
    http_response_code(400);
    echo "Missing OneDrive item id";
    exit;
}

/* ================= GRAPH DOWNLOAD ================= */

// Microsoft Graph: stream raw file content
$url = "https://graph.microsoft.com/v1.0/me/drive/items/" . rawurlencode($itemId) . "/content";

$ch = curl_init($url);
curl_setopt_array($ch, [
    CURLOPT_RETURNTRANSFER => false,      // stream
    CURLOPT_FOLLOWLOCATION => true,       // Graph redirects to CDN
    CURLOPT_HTTPHEADER => [
        "Authorization: Bearer {$accessToken}"
    ],
    CURLOPT_HEADERFUNCTION => function ($ch, $header) {
        // Forward headers selectively
        if (stripos($header, 'Content-Type:') === 0) {
            header(trim($header));
        }
        if (stripos($header, 'Content-Length:') === 0) {
            header(trim($header));
        }
        if (stripos($header, 'Content-Disposition:') === 0) {
            header(trim($header));
        }
        return strlen($header);
    },
    CURLOPT_WRITEFUNCTION => function ($ch, $data) {
        echo $data;
        return strlen($data);
    }
]);

curl_exec($ch);

if (curl_errno($ch)) {
    http_response_code(500);
    echo "OneDrive download failed: " . curl_error($ch);
}

curl_close($ch);
exit;
