<?php
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);

$langDir = __DIR__;

// Only files like en.php, es.php, is.php, zh.php
$files = glob($langDir . '/[a-z][a-z].php');
$files = array_merge($files, glob($langDir . '/[a-z][a-z][a-z].php'));

if (!$files) {
    die("❌ No language files found in $langDir\n");
}

$langData = [];
$langCodes = [];

foreach ($files as $file) {
    $basename = basename($file, '.php');
    echo "📂 Processing: $basename.php\n";

    $translations = include $file;

    if (!is_array($translations)) {
        echo "⚠️ Skipped $basename.php (not returning array)\n";
        continue;
    }

    $langCodes[] = $basename;

    foreach ($translations as $key => $value) {
        $langData[$key][$basename] = $value;
    }
}

if (!$langCodes) {
    die("❌ No valid language files processed.\n");
}

$csvFile = $langDir . '/translations.csv';
echo "✍️ Writing $csvFile\n";

$fp = fopen($csvFile, 'w');
if (!$fp) {
    die("❌ Could not open $csvFile for writing.\n");
}

// BOM for Excel
fwrite($fp, "\xEF\xBB\xBF");

// Header row
$header = array_merge(['key'], $langCodes);
fputcsv($fp, $header, ';');

// Description row
$descRow = ['Description'];
foreach ($langCodes as $code) {
    switch ($code) {
        case 'en': $descRow[] = 'English'; break;
        case 'is': $descRow[] = 'Icelandic'; break;
        case 'es': $descRow[] = 'Spanish'; break;
        case 'pl': $descRow[] = 'Polish'; break;
        case 'it': $descRow[] = 'Italian'; break;
        case 'zh': $descRow[] = 'Chinese'; break;
        case 'de': $descRow[] = 'German'; break;
        case 'fr': $descRow[] = 'French'; break;
        case 'no': $descRow[] = 'Norwegian'; break;
        default:   $descRow[] = strtoupper($code);
    }
}
fputcsv($fp, $descRow, ';');

// Data rows
ksort($langData);
foreach ($langData as $key => $values) {
    $row = [$key];
    foreach ($langCodes as $lang) {
        $row[] = $values[$lang] ?? '';
    }
    fputcsv($fp, $row, ';');
}

fclose($fp);

echo "✅ Export complete: $csvFile\n";
