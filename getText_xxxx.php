<?php
require_once __DIR__ . '/includes/db_connect.php';
header('Content-Type: text/html; charset=utf-8');

$surrogate = intval($_GET['q'] ?? 0);
if (!$surrogate) {
    http_response_code(400);
    exit("Missing surrogate ID");
}

$annotatorsParam = trim($_GET['annotators'] ?? '');
$annotators = array_filter(array_map('trim', explode(',', $annotatorsParam)));
$mergeComments = intval($_GET['mergeComments'] ?? 0);

// 1️⃣ Get base text
$stmt = $mysqli->prepare("SELECT Text FROM text WHERE surrogate=?");
$stmt->bind_param("i", $surrogate);
$stmt->execute();
$stmt->bind_result($textHTML);
$stmt->fetch();
$stmt->close();

if (!$textHTML) {
    http_response_code(404);
    exit("No text found");
}

// 2️⃣ If mergeComments=0 → return plain text
if (!$mergeComments || empty($annotators)) {
    echo $textHTML;
    exit;
}

// 3️⃣ Fetch unified JSON comments for each annotator
$placeholders = implode(',', array_fill(0, count($annotators), '?'));
$types = str_repeat('s', count($annotators));
$query = "
    SELECT annotator, comments
    FROM text_comments
    WHERE surrogate=? AND annotator IN ($placeholders)
";
$stmt = $mysqli->prepare($query);

if (!$stmt) {
    http_response_code(500);
    exit("DB prepare failed: " . $mysqli->error);
}

// bind surrogate + annotators
$bindTypes = 'i' . $types;
$bindValues = array_merge([$surrogate], $annotators);
$stmt->bind_param($bindTypes, ...$bindValues);

$stmt->execute();
$res = $stmt->get_result();
$rows = $res->fetch_all(MYSQLI_ASSOC);
$stmt->close();

// 4️⃣ Build color map per annotator
$colors = ['#ffd54f', '#4fc3f7', '#aed581', '#ff8a65', '#ce93d8'];
$colorMap = [];
foreach ($annotators as $i => $a) {
    $colorMap[$a] = $colors[$i % count($colors)];
}

// 5️⃣ Inject highlights into text
foreach ($rows as $r) {
    $annotator = $r['annotator'];
    $color = $colorMap[$annotator] ?? '#ffcc80';
    $commentArr = json_decode($r['comments'], true);

    if (!is_array($commentArr)) continue;

    foreach ($commentArr as $c) {
        $anchor = $c['anchor'] ?? [];
        $quote = $anchor['quote'] ?? '';
        if (!$quote) continue;

        // sanitize & escape
        $escapedQuote = htmlspecialchars($quote, ENT_QUOTES, 'UTF-8');
        $escapedAnnotator = htmlspecialchars($annotator, ENT_QUOTES, 'UTF-8');
        $id = htmlspecialchars($c['id'] ?? uniqid('c'), ENT_QUOTES);

        // create highlighted span
        $span = "<span class='hl' data-id='{$id}' data-user='{$escapedAnnotator}' " .
                "style='background-color:{$color};'>{$escapedQuote}</span>";

        // Replace only first occurrence
        $textHTML = preg_replace('/' . preg_quote($quote, '/') . '/u', $span, $textHTML, 1);
    }
}

// 6️⃣ Output merged HTML
echo $textHTML;
?>
