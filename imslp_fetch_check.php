<?php
$url = "https://imslp.org/wiki/Category:Beethoven,_Ludwig_van";

// Try file_get_contents first
$html = @file_get_contents($url);

if (!$html) {
    // Try curl if file_get_contents fails
    $ch = curl_init($url);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_USERAGENT, "Mozilla/5.0");
    $html = curl_exec($ch);
    curl_close($ch);
}

if (!$html) {
    echo "❌ Nothing fetched. Likely blocked.";
} else {
    echo "✅ HTML fetched successfully. Length: " . strlen($html);
}
