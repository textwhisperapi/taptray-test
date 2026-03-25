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

$categoryUrl = "https://imslp.org/wiki/Category:Beethoven,_Ludwig_van";
$html = fetchHTML($categoryUrl);
if (!$html) die("❌ Failed to fetch category page.");

// Match all links like /wiki/Some_Title
$matches = [];
preg_match_all('/<a href="\/wiki\/([^"#?:]+)"[^>]*>([^<]+)<\/a>/', $html, $matches, PREG_SET_ORDER);

$base = "https://imslp.org";
$works = [];

foreach ($matches as $match) {
    $relativeUrl = $match[1];
    $title = html_entity_decode($match[2], ENT_QUOTES);

    // Skip duplicates, composer categories, special pages, etc.
    if (stripos($title, "Category:") !== false || stripos($relativeUrl, "Special:") !== false) continue;

    $workUrl = "$base/wiki/" . urlencode($relativeUrl);

    $workHtml = fetchHTML($workUrl);
    if (!$workHtml) continue;

    if (preg_match('/href="(https:\/\/imslp\.simssa\.ca\/files\/imglnks\/[^"]+\.pdf)"/i', $workHtml, $pdfMatch)) {
        $works[] = [
            "title" => $title,
            "url" => $workUrl,
            "pdf_url" => html_entity_decode($pdfMatch[1], ENT_QUOTES)
        ];
    }

    if (count($works) >= 5) break;
}

header("Content-Type: application/json");
echo json_encode($works, JSON_PRETTY_PRINT);
