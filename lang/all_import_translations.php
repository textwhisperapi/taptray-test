<?php
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);

$langDir = __DIR__;
$csvFile = $langDir . '/translations.csv';

if (!file_exists($csvFile)) {
    die("❌ CSV file not found: $csvFile\n");
}

echo "📂 Reading $csvFile\n";

// Auto-detect delimiter from the header line
$firstLine = fgets(fopen($csvFile, 'r'));
$delimiter = (substr_count($firstLine, ';') > substr_count($firstLine, ',')) ? ';' : ',';
echo "🔍 Using delimiter: $delimiter\n";

$rows = [];
if (($handle = fopen($csvFile, 'r')) !== false) {
    $header = fgetcsv($handle, 0, $delimiter);
    if (!$header) {
        die("❌ CSV header missing.\n");
    }

    $langCodes = array_slice($header, 1); // drop "key"

    // skip description row
    fgetcsv($handle, 0, $delimiter);

    while (($data = fgetcsv($handle, 0, $delimiter)) !== false) {
        $key = $data[0];
        if (!$key) continue;

        foreach ($langCodes as $i => $lang) {
            $value = $data[$i + 1] ?? '';
            $rows[$lang][$key] = $value;
        }
    }
    fclose($handle);
} else {
    die("❌ Could not open CSV file.\n");
}

foreach ($rows as $lang => $translations) {
    $file = $langDir . '/' . $lang . '.php';
    echo "✍️ Writing $file\n";

    $content = "<?php\nreturn [\n";
    foreach ($translations as $k => $v) {
        $k = str_replace("'", "\\'", $k);
        $v = str_replace("'", "\\'", $v);
        $content .= "    '$k' => '$v',\n";
    }
    $content .= "];\n";

    file_put_contents($file, $content);
}

echo "✅ Import complete!\n";
