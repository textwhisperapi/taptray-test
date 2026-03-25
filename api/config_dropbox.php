<?php
/**
 * /api/config_dropbox.php
 * Dropbox configuration.
 * - When INCLUDED: exposes variables
 * - When CALLED directly: returns public JSON (NO secret)
 */

// App credentials
// $dropboxAppKey    = "0cc3ltg6cq7pkt9";
// $dropboxAppSecret = "8ybphwi4713zees"; // server-side only

$dropboxAppKey    = "azck6776eag00id";
$dropboxAppSecret = "swfzw6jk2z12jcj"; // server-side only
	


// Environment detection
$domain = $_SERVER['HTTP_HOST'] ?? '';
if (strpos($domain, 'geirigrimmi.com') !== false) {
    $env = "test";
} elseif (strpos($domain, 'textwhisper.com') !== false) {
    $env = "production";
} else {
    $env = "local";
}

/**
 * Only output JSON when this file is accessed directly,
 * NEVER when it is included by another PHP script.
 */
if (basename(__FILE__) === basename($_SERVER['SCRIPT_FILENAME'])) {
    header("Content-Type: application/json; charset=UTF-8");
    echo json_encode([
        "status" => "ok",
        "env" => $env,
        "appKey" => $dropboxAppKey,
        "chooserDomain" => $domain,
    ]);
}
