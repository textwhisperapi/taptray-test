<?php
function fetchHTML($url) {
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_USERAGENT => "Mozilla/5.0"
    ]);
    $html = curl_exec($ch);
    curl_close($ch);
    return $html ?: null;
}

$html = fetchHTML("https://imslp.org/wiki/Category:Beethoven,_Ludwig_van");
if (!$html) die("❌ Failed to fetch.");

preg_match_all('/<a href="\/wiki\/([^"#?:]+)"[^>]*>([^<]+)<\/a>/', $html, $matches, PREG_SET_ORDER);

echo "<pre>\n";
foreach (array_slice($matches, 0, 100) as $match) {
    echo "• " . $match[2] . " → /wiki/" . $match[1] . "\n";
}
echo "</pre>";
