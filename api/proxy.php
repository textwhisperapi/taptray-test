<?php
// ✅ Allow cross-origin requests
header("Access-Control-Allow-Origin: *");

// ✅ Read target URL from query string
$url = $_GET['url'] ?? '';

if (!$url || !filter_var($url, FILTER_VALIDATE_URL)) {
    http_response_code(400);
    echo "❌ Invalid or missing URL.";
    exit;
}

// ✅ Optional: Restrict to known safe domains
// if (!preg_match('/^https:\/\/(www\.w3\.org|example\.com)/', $url)) {
//     http_response_code(403);
//     echo "❌ Domain not allowed.";
//     exit;
// }

// ✅ Set content type dynamically (you can force PDF if you prefer)
$ext = strtolower(pathinfo(parse_url($url, PHP_URL_PATH), PATHINFO_EXTENSION));
if ($ext === 'pdf') {
    header("Content-Type: application/pdf");
} else {
    header("Content-Type: text/html");
}

// ✅ Fetch file pretending to be a browser
$options = [
    "http" => [
        "header" => "User-Agent: Mozilla/5.0\r\n"
    ]
];
$context = stream_context_create($options);
$response = @file_get_contents($url, false, $context);

if ($response === false) {
    http_response_code(500);
    echo "❌ Failed to fetch resource.";
    exit;
}

echo $response;
?>
