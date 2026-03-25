<?php
/**
 * cloudflare_r2_list.php
 * --------------------------------------------
 * Lists files from your Cloudflare R2 bucket.
 * Uses AWS Signature V4 to authenticate (R2 S3 API).
 * Query:
 *     ?prefix=<folder/path/>
 * Output:
 *     JSON array of: key, size, last_modified
 */

// ==================================================
// CORS HEADERS
// ==================================================
header("Access-Control-Allow-Origin: *");
header("Access-Control-Allow-Headers: *");
header("Access-Control-Allow-Methods: GET, OPTIONS");
header("Content-Type: application/json");

// Preflight
if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    exit(0);
}

// ==================================================
// LOAD CONFIG (CONTAINS KEYS)
// ==================================================
require_once __DIR__ . "/config_cloudflare.php";

// Validate config
if (empty($accessKey) || empty($secretKey) || empty($bucket) || empty($endpoint)) {
    http_response_code(500);
    echo json_encode(["error" => "R2 config missing (keys/bucket/endpoint)."]);
    exit;
}

// ==================================================
// INPUT VALIDATION
// ==================================================
if (!isset($_GET['prefix'])) {
    http_response_code(400);
    echo json_encode(["error" => "Missing ?prefix parameter"]);
    exit;
}

$prefix = $_GET['prefix'];

// ==================================================
// BUILD REQUEST
// ==================================================
$method = "GET";
$host = parse_url($endpoint, PHP_URL_HOST);
$canonicalUri = "/" . $bucket;

// R2 query parameters for listing
$canonicalQuery = "list-type=2&prefix=" . rawurlencode($prefix);

// Complete URL
$url = $endpoint . "/" . $bucket . "?" . $canonicalQuery;

$amzDate = gmdate("Ymd\THis\Z");
$dateStamp = gmdate("Ymd");

$region = "auto";   // required for Cloudflare R2
$service = "s3";
$credentialScope = "$dateStamp/$region/$service/aws4_request";

// ==================================================
// CANONICAL REQUEST WITH UNSIGNED PAYLOAD
// ==================================================
$canonicalHeaders =
    "host:$host\n" .
    "x-amz-content-sha256:UNSIGNED-PAYLOAD\n" .
    "x-amz-date:$amzDate\n";

$signedHeaders = "host;x-amz-content-sha256;x-amz-date";

$canonicalRequest =
    "$method\n" .
    "$canonicalUri\n" .
    "$canonicalQuery\n" .
    $canonicalHeaders . "\n" .
    "$signedHeaders\n" .
    "UNSIGNED-PAYLOAD";

// ==================================================
// STRING-TO-SIGN
// ==================================================
$algorithm = "AWS4-HMAC-SHA256";
$stringToSign =
    "$algorithm\n" .
    "$amzDate\n" .
    "$credentialScope\n" .
    hash("sha256", $canonicalRequest);

// ==================================================
// SIGNING KEY
// ==================================================
function sign($key, $msg) {
    return hash_hmac("sha256", $msg, $key, true);
}

$kDate    = sign("AWS4" . $secretKey, $dateStamp);
$kRegion  = sign($kDate, $region);
$kService = sign($kRegion, $service);
$kSigning = sign($kService, "aws4_request");

$signature = hash_hmac("sha256", $stringToSign, $kSigning);

// ==================================================
// AUTHORIZATION HEADER
// ==================================================
$authorizationHeader =
    "$algorithm " .
    "Credential=$accessKey/$credentialScope, " .
    "SignedHeaders=$signedHeaders, " .
    "Signature=$signature";

// ==================================================
// EXECUTE REQUEST
// ==================================================
$headers = [
    "x-amz-date: $amzDate",
    "x-amz-content-sha256: UNSIGNED-PAYLOAD",
    "Authorization: $authorizationHeader"
];

$ch = curl_init($url);
curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);

$response = curl_exec($ch);
$httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
curl_close($ch);

// ==================================================
// ERROR HANDLING
// ==================================================
if ($httpCode !== 200) {
    http_response_code($httpCode);
    echo json_encode([
        "error"  => "R2 request failed",
        "status" => $httpCode,
        "body"   => $response
    ]);
    exit;
}

// ==================================================
// PARSE XML → JSON
// ==================================================
$xml = simplexml_load_string($response);
$result = [];

if (isset($xml->Contents)) {
    foreach ($xml->Contents as $item) {
        $result[] = [
            "key"           => (string)$item->Key,
            "size"          => (int)$item->Size,
            "last_modified" => (string)$item->LastModified
        ];
    }
}

// ==================================================
// OUTPUT JSON
// ==================================================
echo json_encode($result, JSON_PRETTY_PRINT);
