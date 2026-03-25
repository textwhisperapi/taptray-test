<?php
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);

$langDir = __DIR__;
$csvFile = $langDir . '/translations.csv';

// Google Sheet config
$googleSheetId  = "1mhROIdN1gw8qHE8Euj9brRWyqW17zwnAAnx6DFSwDK8";
$googleCsvUrl   = "https://docs.google.com/spreadsheets/d/$googleSheetId/export?format=csv";
$googleSheetUrl = "https://docs.google.com/spreadsheets/d/$googleSheetId/edit?usp=sharing";

function getCsvDelimiter($csvFile) {
    $handle = fopen($csvFile, 'r');
    if (!$handle) {
        return ',';
    }
    $firstLine = fgets($handle);
    fclose($handle);
    if ($firstLine === false) {
        return ',';
    }
    return (substr_count($firstLine, ';') > substr_count($firstLine, ',')) ? ';' : ',';
}

function readExistingDateAddedMap($csvFile, $delimiter) {
    $dates = [];
    if (!file_exists($csvFile)) {
        return $dates;
    }
    $handle = fopen($csvFile, 'r');
    if (!$handle) {
        return $dates;
    }
    $header = fgetcsv($handle, 0, $delimiter);
    if (!$header) {
        fclose($handle);
        return $dates;
    }
    $dateIndex = array_search('date_added', $header, true);
    $keyIndex = array_search('key', $header, true);
    fgetcsv($handle, 0, $delimiter);
    while (($data = fgetcsv($handle, 0, $delimiter)) !== false) {
        $key = $keyIndex !== false ? ($data[$keyIndex] ?? '') : '';
        if ($key === '') {
            continue;
        }
        $dates[$key] = $dateIndex !== false ? ($data[$dateIndex] ?? '') : '';
    }
    fclose($handle);
    return $dates;
}

// --- helper: parse and convert CSV to PHP files ---
function convertCsvToPhpFiles($csvFile, $langDir) {
    if (!file_exists($csvFile)) {
        echo "<p>❌ CSV file not found: $csvFile</p>";
        return;
    }

    // auto-detect delimiter
    $delimiter = getCsvDelimiter($csvFile);
    echo "<p>🔍 Using delimiter: $delimiter</p>";

    $rows = [];
    if (($handle = fopen($csvFile, 'r')) !== false) {
        $header = fgetcsv($handle, 0, $delimiter);
        if (!$header) {
            die("❌ CSV header missing.\n");
        }
        
        $langOffset = 2;
        if (($header[2] ?? '') === 'date_added') {
            $langOffset = 3;
        }

        // Expect first columns: source, key, optional date_added
        $langCodes = array_slice($header, $langOffset);
        
        // skip description row
        fgetcsv($handle, 0, $delimiter);
        
        while (($data = fgetcsv($handle, 0, $delimiter)) !== false) {
            $key = $data[1]; // <-- 2nd column = the real key
            if (!$key) continue;
        
            foreach ($langCodes as $i => $lang) {
                $value = $data[$i + $langOffset] ?? '';
                $rows[$lang][$key] = $value;
            }
        }

        fclose($handle);
    }

    foreach ($rows as $lang => $translations) {
        $file = $langDir . '/' . $lang . '.php';
        echo "<p>✍️ Writing $file</p>";
        // $content = "<?php\nreturn [\n";
        // foreach ($translations as $k => $v) {
        //     $k = str_replace("'", "\\'", $k);
        //     $v = str_replace("'", "\\'", $v);
        //     $content .= "    '$k' => '$v',\n";
        // }
        // $content .= "];\n";
        // file_put_contents($file, $content);
        
        $content = "<?php
        // Auto-generated translation file
        \$translations = [\n";
        foreach ($translations as $k => $v) {
            $k = str_replace("'", "\\'", $k);
            $v = str_replace("'", "\\'", $v);
            $content .= "    '$k' => '$v',\n";
        }
        $content .= "];
        
        // --- Dual-mode output ---
        // If accessed via browser (not included by PHP), output JSON.
        if (php_sapi_name() !== 'cli' && !defined('TEXTWHISPER_INCLUDE')) {
            if (
                isset(\$_SERVER['HTTP_ACCEPT']) &&
                str_contains(\$_SERVER['HTTP_ACCEPT'], 'application/json')
            ) {
                header('Content-Type: application/json; charset=utf-8');
            }
            echo json_encode(\$translations, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
            return;
        }
        
        // Otherwise, return array for PHP include
        return \$translations;
        ";
        file_put_contents($file, $content);
        
        
    }

    echo "<p>✅ Conversion complete! Language files updated.</p>";
}

// --- actions ---
if (isset($_GET['import_google'])) {
    echo "<p>🌍 Fetching Google Sheets CSV...</p>";
    $csvData = file_get_contents($googleCsvUrl);
    if ($csvData === false) {
        echo "<p>❌ Failed to fetch Google Sheets CSV.</p>";
    } else {
        file_put_contents($csvFile, $csvData);
        echo "<p>✅ Downloaded Google Sheet and saved as $csvFile</p>";
    }
}

if (isset($_GET['convert_csv'])) {
    convertCsvToPhpFiles($csvFile, $langDir);
}

if (isset($_POST['import']) && isset($_FILES['csv_file'])) {
    $upload = $_FILES['csv_file']['tmp_name'];
    if (is_uploaded_file($upload)) {
        $csvData = file_get_contents($upload);
        file_put_contents($csvFile, $csvData);
        echo "<p>✅ Uploaded CSV saved as $csvFile</p>";
        convertCsvToPhpFiles($csvFile, $langDir);
    } else {
        echo "<p>❌ Upload failed.</p>";
    }
}

if (isset($_GET['export'])) {
    $files = glob($langDir . '/[a-z][a-z].php');
    $files = array_merge($files, glob($langDir . '/[a-z][a-z][a-z].php'));
    // Exclude helper map file; it's not a language pack.
    $files = array_values(array_filter($files, static function ($file) {
        return basename($file) !== 'key.php';
    }));
    // Ensure translation files return arrays when included from web requests.
    if (!defined('TEXTWHISPER_INCLUDE')) {
        define('TEXTWHISPER_INCLUDE', true);
    }
    $langData = [];
    $langCodes = [];
    $delimiter = ';';
    $dateAddedByKey = readExistingDateAddedMap($csvFile, getCsvDelimiter($csvFile));

    foreach ($files as $file) {
        $basename = basename($file, '.php');
        $translations = include $file;
        if (!is_array($translations)) continue;
        $langCodes[] = $basename;
        foreach ($translations as $key => $value) {
            $langData[$key][$basename] = $value;
        }
    }

    $fp = fopen($csvFile, 'w');
    fwrite($fp, "\xEF\xBB\xBF");
    fputcsv($fp, array_merge(['source', 'key', 'date_added'], $langCodes), $delimiter);

    $descRow = ['Source', 'Description', 'Date Added'];
    $names = [
        'en'=>'English','de'=>'German','es'=>'Spanish','fr'=>'French',
        'is'=>'Icelandic','it'=>'Italian','no'=>'Norwegian','pl'=>'Polish','zh'=>'Chinese'
    ];
    foreach ($langCodes as $code) {
        $descRow[] = $names[$code] ?? strtoupper($code);
    }
    fputcsv($fp, $descRow, $delimiter);

    ksort($langData);
    foreach ($langData as $key => $values) {
        $row = ['', $key, $dateAddedByKey[$key] ?? ''];
        foreach ($langCodes as $lang) {
            $row[] = $values[$lang] ?? '';
        }
        fputcsv($fp, $row, $delimiter);
    }
    fclose($fp);

    header('Content-Type: text/csv; charset=UTF-8');
    header('Content-Disposition: attachment; filename="translations.csv"');
    readfile($csvFile);
    exit;
}

// --- UI ---
echo "<!DOCTYPE html><html><head><meta charset='UTF-8'>
<title>Translations Manager</title>
<style>
body { font-family: sans-serif; max-width: 800px; margin: 2rem auto; }
h1 { color: #333; }
button { padding: 0.5rem 1rem; margin: 0.5rem 0; }
</style></head><body>";

echo "<h1>Translations Manager</h1>";

echo <<<HTML
<h2>Export</h2>
<a href="?export=1"><button>⬇️ Export CSV</button></a>

<h2>Import CSV (upload)</h2>
<form method="post" enctype="multipart/form-data">
  <input type="file" name="csv_file" accept=".csv" required>
  <button type="submit" name="import" value="1">⬆️ Import CSV</button>
</form>

<h2>Google Sheets</h2>
<a href="?import_google=1"><button>🌍 Import from Google Sheets (save CSV)</button></a>
<a href="$googleSheetUrl" target="_blank"><button>📄 Open Google Sheet</button></a>

<h2>Convert</h2>
<a href="?convert_csv=1"><button>🔄 Convert translations.csv → PHP language files</button></a>
HTML;

echo "</body></html>";
