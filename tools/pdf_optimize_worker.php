<?php
declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    fwrite(STDERR, "CLI only\n");
    exit(1);
}

function argValue(array $argv, string $name): ?string
{
    $prefix = "--{$name}=";
    foreach ($argv as $arg) {
        if (strpos($arg, $prefix) === 0) {
            return substr($arg, strlen($prefix));
        }
    }
    return null;
}

function write_json_atomic(string $path, array $data): void
{
    $tmp = $path . '.tmp';
    file_put_contents($tmp, json_encode($data, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
    @rename($tmp, $path);
}

function curl_download(string $url, string $targetPath): array
{
    if (!function_exists('curl_init')) {
        return ['ok' => false, 'error' => 'curl unavailable', 'http' => 0];
    }
    $fp = fopen($targetPath, 'wb');
    if (!$fp) {
        return ['ok' => false, 'error' => 'could not open target file', 'http' => 0];
    }

    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_FILE => $fp,
        CURLOPT_FOLLOWLOCATION => true,
        CURLOPT_CONNECTTIMEOUT => 8,
        CURLOPT_TIMEOUT => 45,
        CURLOPT_SSL_VERIFYPEER => true,
    ]);
    curl_exec($ch);
    $http = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $err = curl_error($ch);
    curl_close($ch);
    fclose($fp);

    return ['ok' => ($http >= 200 && $http < 300 && is_file($targetPath) && filesize($targetPath) > 0), 'error' => $err, 'http' => $http];
}

function curl_upload_pdf(string $url, string $sourcePath): array
{
    if (!function_exists('curl_init')) {
        return ['ok' => false, 'error' => 'curl unavailable', 'http' => 0];
    }
    $blob = file_get_contents($sourcePath);
    if ($blob === false) {
        return ['ok' => false, 'error' => 'could not read source file', 'http' => 0];
    }

    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_POST => true,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_HTTPHEADER => ['Content-Type: application/pdf'],
        CURLOPT_POSTFIELDS => $blob,
        CURLOPT_CONNECTTIMEOUT => 8,
        CURLOPT_TIMEOUT => 45,
        CURLOPT_SSL_VERIFYPEER => true,
    ]);
    curl_exec($ch);
    $http = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $err = curl_error($ch);
    curl_close($ch);
    return ['ok' => ($http >= 200 && $http < 300), 'error' => $err, 'http' => $http];
}

function run_gs_profile(string $inputPath, string $outputPath, string $profile): array
{
    $common = [
        'gs',
        '-sDEVICE=pdfwrite',
        '-dCompatibilityLevel=1.5',
        '-dNOPAUSE',
        '-dQUIET',
        '-dBATCH',
        '-dDetectDuplicateImages=true',
        '-dCompressFonts=true',
        '-dSubsetFonts=true',
        '-dAutoRotatePages=/None',
    ];

    // Quality-first default: keeps score text/staff lines sharper than /ebook.
    $sharp = [
        '-dPDFSETTINGS=/printer',
        '-dDownsampleColorImages=true',
        '-dDownsampleGrayImages=true',
        '-dDownsampleMonoImages=true',
        '-dColorImageDownsampleType=/Bicubic',
        '-dGrayImageDownsampleType=/Bicubic',
        '-dMonoImageDownsampleType=/Subsample',
        '-dColorImageResolution=220',
        '-dGrayImageResolution=300',
        '-dMonoImageResolution=600',
        '-dJPEGQ=82',
    ];

    // Scan-clean profile: grayscale + stronger JPEG compression for noisy scans.
    $scanClean = [
        '-dPDFSETTINGS=/printer',
        '-sColorConversionStrategy=Gray',
        '-dProcessColorModel=/DeviceGray',
        '-dOverrideICC=true',
        '-dDownsampleColorImages=true',
        '-dDownsampleGrayImages=true',
        '-dDownsampleMonoImages=true',
        '-dColorImageDownsampleType=/Bicubic',
        '-dGrayImageDownsampleType=/Bicubic',
        '-dMonoImageDownsampleType=/Subsample',
        '-dColorImageResolution=200',
        '-dGrayImageResolution=240',
        '-dMonoImageResolution=500',
        '-dJPEGQ=58',
    ];

    $tune = ($profile === 'scan_clean') ? $scanClean : $sharp;
    $cmd = implode(' ', array_merge(
        $common,
        $tune,
        ['-sOutputFile=' . escapeshellarg($outputPath), escapeshellarg($inputPath)]
    ));

    $output = [];
    $code = 1;
    exec($cmd . ' 2>&1', $output, $code);

    return [
        'ok' => ($code === 0 && is_file($outputPath) && filesize($outputPath) > 0),
        'code' => $code,
        'tail' => trim(implode("\n", array_slice($output, -4))),
    ];
}

function get_pdf_page_count(string $path): int
{
    $cmd = "gs -q -dNODISPLAY -c " .
        escapeshellarg("(" . $path . ") (r) file runpdfbegin pdfpagecount = quit");
    $out = [];
    $code = 1;
    exec($cmd . " 2>&1", $out, $code);
    if ($code !== 0 || !$out) return 0;
    $last = trim((string)end($out));
    $n = (int)$last;
    return $n > 0 ? $n : 0;
}

function pdf_has_visible_ink(string $path, int $maxPages = 3): bool
{
    $maxPages = max(1, min(10, $maxPages));
    $cmd = "gs -q -o - -sDEVICE=inkcov -dFirstPage=1 -dLastPage={$maxPages} " . escapeshellarg($path);
    $out = [];
    $code = 1;
    exec($cmd . " 2>&1", $out, $code);
    if ($code !== 0 || !$out) return false;

    $ink = 0.0;
    foreach ($out as $line) {
        // inkcov lines: C M Y K CMYK
        if (preg_match('/^\s*([0-9]*\.?[0-9]+)\s+([0-9]*\.?[0-9]+)\s+([0-9]*\.?[0-9]+)\s+([0-9]*\.?[0-9]+)/', trim($line), $m)) {
            $ink += (float)$m[1] + (float)$m[2] + (float)$m[3] + (float)$m[4];
        }
    }
    // Keep threshold very low so light grayscale pages are not misclassified as blank.
    return $ink > 0.0001;
}

function run_convert_ink_clean_profile(string $inputPath, string $outputPath): array
{
    $convertBin = trim((string)shell_exec('command -v convert'));
    if ($convertBin === '') {
        return ['ok' => false, 'code' => 127, 'tail' => 'convert not available'];
    }

    // Ink-clean profile (line-preserving):
    // - grayscale normalization
    // - mild deskew for scanned pages
    // - light median denoise (remove scan grain)
    // - contrast boost in dark tones
    // - unsharp mask to clarify notation edges
    // - keep grayscale (no hard threshold) to preserve faint bar/staff lines
    $cmd = implode(' ', [
        escapeshellarg($convertBin),
        '-quiet',
        '-density', '260',
        escapeshellarg($inputPath),
        '-colorspace', 'Gray',
        '-deskew', '35%',
        '-statistic', 'Median', '2',
        '-contrast-stretch', '0.8%x0.8%',
        '-sigmoidal-contrast', '5x47%',
        '-unsharp', '0x0.9+0.8+0.02',
        '-quality', '72',
        escapeshellarg($outputPath),
    ]);

    $output = [];
    $code = 1;
    exec($cmd . ' 2>&1', $output, $code);

    return [
        'ok' => ($code === 0 && is_file($outputPath) && filesize($outputPath) > 0),
        'code' => $code,
        'tail' => trim(implode("\n", array_slice($output, -6))),
    ];
}

function parse_trim_geometry(string $raw): ?array
{
    // Expected format: WxH+X+Y
    if (!preg_match('/^\s*(\d+)x(\d+)\+(\d+)\+(\d+)\s*$/', trim($raw), $m)) {
        return null;
    }
    return [
        'w' => (int)$m[1],
        'h' => (int)$m[2],
        'x' => (int)$m[3],
        'y' => (int)$m[4],
    ];
}

function median_int(array $values): int
{
    if (!$values) return 0;
    sort($values, SORT_NUMERIC);
    $n = count($values);
    $mid = intdiv($n, 2);
    if (($n % 2) === 1) return (int)$values[$mid];
    return (int)round(((int)$values[$mid - 1] + (int)$values[$mid]) / 2);
}

function run_convert_offset_fix_profile(string $inputPath, string $outputPath, string $strength = 'low'): array
{
    $convertBin = trim((string)shell_exec('command -v convert'));
    if ($convertBin === '') {
        return ['ok' => false, 'code' => 127, 'tail' => 'convert not available'];
    }

    $density = 230;
    $fuzz = '18%';
    $shiftCap = 14;
    if ($strength === 'medium') $shiftCap = 22;
    if ($strength === 'high') $shiftCap = 30;

    $pages = get_pdf_page_count($inputPath);
    if ($pages <= 0) {
        return ['ok' => false, 'code' => 2, 'tail' => 'page count unavailable'];
    }

    $tmpDir = sys_get_temp_dir() . '/tw_pdf_offset_fix_' . md5($inputPath . microtime(true));
    if (!@mkdir($tmpDir, 0775, true) && !is_dir($tmpDir)) {
        return ['ok' => false, 'code' => 3, 'tail' => 'could not create temp dir'];
    }

    $pageFiles = [];
    $tailLog = [];
    $pageMeta = [];
    $oddRawShifts = [];
    $evenRawShifts = [];

    // Pass 1: estimate raw horizontal shifts from content bounds.
    for ($i = 0; $i < $pages; $i++) {
        $src = escapeshellarg($inputPath . '[' . $i . ']');
        $dimOut = [];
        $dimCode = 1;
        exec(
            escapeshellarg($convertBin) . " -quiet -density {$density} {$src} -colorspace Gray -alpha off -format '%w %h' info:",
            $dimOut,
            $dimCode
        );
        if ($dimCode !== 0 || empty($dimOut)) {
            $tailLog[] = "dim-fail-p{$i}";
            continue;
        }

        $dim = preg_split('/\s+/', trim((string)end($dimOut)));
        $pageW = (int)($dim[0] ?? 0);
        $pageH = (int)($dim[1] ?? 0);
        if ($pageW <= 0 || $pageH <= 0) {
            $tailLog[] = "bad-dim-p{$i}";
            continue;
        }

        $trimOut = [];
        $trimCode = 1;
        exec(
            escapeshellarg($convertBin) . " -quiet -density {$density} {$src} -colorspace Gray -alpha off -fuzz {$fuzz} -trim -format '%@' info:",
            $trimOut,
            $trimCode
        );
        $geom = ($trimCode === 0 && !empty($trimOut)) ? parse_trim_geometry((string)end($trimOut)) : null;

        $rawShift = 0;
        if ($geom) {
            $contentCenter = $geom['x'] + ($geom['w'] / 2.0);
            $pageCenter = $pageW / 2.0;
            $rawShift = (int)round($pageCenter - $contentCenter);
            if ($rawShift > $shiftCap) $rawShift = $shiftCap;
            if ($rawShift < -$shiftCap) $rawShift = -$shiftCap;
            if (abs($rawShift) < 2) $rawShift = 0;
        }
        $pageNo = $i + 1;
        if (($pageNo % 2) === 1) $oddRawShifts[] = $rawShift;
        else $evenRawShifts[] = $rawShift;
        $pageMeta[] = ['index' => $i, 'raw_shift' => $rawShift];
    }

    // Use parity medians to remove zig-zag jitter.
    $allRaw = array_merge($oddRawShifts, $evenRawShifts);
    $fallbackShift = median_int($allRaw);
    $oddShift = $oddRawShifts ? median_int($oddRawShifts) : $fallbackShift;
    $evenShift = $evenRawShifts ? median_int($evenRawShifts) : $fallbackShift;

    // Pass 2: render pages with stable odd/even offsets.
    foreach ($pageMeta as $meta) {
        $i = (int)$meta['index'];
        $src = escapeshellarg($inputPath . '[' . $i . ']');
        $pageNo = $i + 1;
        $shiftPx = (($pageNo % 2) === 1) ? $oddShift : $evenShift;
        if ($shiftPx > $shiftCap) $shiftPx = $shiftCap;
        if ($shiftPx < -$shiftCap) $shiftPx = -$shiftCap;
        if (abs($shiftPx) < 2) $shiftPx = 0;

        $outPath = $tmpDir . '/p' . str_pad((string)$i, 5, '0', STR_PAD_LEFT) . '.jpg';
        $shiftCmd = '';
        if ($shiftPx > 0) {
            $shiftCmd = " -background white -gravity West -splice {$shiftPx}x0 -gravity East -chop {$shiftPx}x0";
        } elseif ($shiftPx < 0) {
            $a = abs($shiftPx);
            $shiftCmd = " -background white -gravity East -splice {$a}x0 -gravity West -chop {$a}x0";
        }

        $cmd = escapeshellarg($convertBin) .
            " -quiet -density {$density} {$src}" .
            " -colorspace Gray -alpha off" .
            " -deskew 35%" .
            " -statistic Median 1" .
            " -sigmoidal-contrast 4x50%" .
            " -unsharp 0x0.8+0.7+0.02" .
            $shiftCmd .
            " -quality 74 " . escapeshellarg($outPath);

        $pageExec = [];
        $pageCode = 1;
        exec($cmd . ' 2>&1', $pageExec, $pageCode);
        if ($pageCode === 0 && is_file($outPath) && filesize($outPath) > 0) {
            $pageFiles[] = $outPath;
        } else {
            $tailLog[] = "page-fail-p{$i}";
        }
    }

    if (count($pageFiles) !== $pages) {
        foreach ($pageFiles as $f) @unlink($f);
        @rmdir($tmpDir);
        return ['ok' => false, 'code' => 4, 'tail' => implode(',', array_slice($tailLog, -8))];
    }

    $mergeCmd = escapeshellarg($convertBin) . ' -quiet ' .
        implode(' ', array_map('escapeshellarg', $pageFiles)) .
        ' -quality 74 ' . escapeshellarg($outputPath);
    $mergeOut = [];
    $mergeCode = 1;
    exec($mergeCmd . ' 2>&1', $mergeOut, $mergeCode);

    foreach ($pageFiles as $f) @unlink($f);
    @rmdir($tmpDir);

    return [
        'ok' => ($mergeCode === 0 && is_file($outputPath) && filesize($outputPath) > 0),
        'code' => $mergeCode,
        'tail' => trim(implode("\n", array_slice($mergeOut, -6))),
    ];
}

$jobFile = argValue($argv, 'job');
if (!$jobFile || !is_file($jobFile)) {
    fwrite(STDERR, "Missing --job\n");
    exit(1);
}

$job = json_decode((string)file_get_contents($jobFile), true);
if (!is_array($job)) {
    fwrite(STDERR, "Invalid job\n");
    exit(1);
}

$pdfPath = (string)($job['pdf_path'] ?? '');
$sourceUrl = (string)($job['source_url'] ?? '');
$uploadUrl = (string)($job['upload_url'] ?? '');
$backupPath = (string)($job['backup_path'] ?? '');
$backupUploadUrl = (string)($job['backup_upload_url'] ?? '');
$storage = (string)($job['storage'] ?? '');
$statusFile = (string)($job['status_file'] ?? '');
$lockFile = (string)($job['lock_file'] ?? '');
$surrogate = (string)($job['surrogate'] ?? '');
$owner = (string)($job['owner'] ?? '');
$oldSizeHint = (int)($job['old_size_bytes'] ?? 0);
$revertExpiresAt = (int)($job['revert_expires_at'] ?? 0);
$fixOffset = ((int)($job['fix_offset'] ?? 0) === 1);
$offsetStrengthRaw = strtolower(trim((string)($job['offset_strength'] ?? 'low')));
$offsetStrength = in_array($offsetStrengthRaw, ['low', 'medium', 'high'], true) ? $offsetStrengthRaw : 'low';

if ($statusFile === '' || $lockFile === '' || $surrogate === '' || $owner === '') {
    fwrite(STDERR, "Incomplete job\n");
    exit(1);
}

$isLocal = ($storage === 'local' && $pdfPath !== '' && is_file($pdfPath));
$isRemote = (!$isLocal && $sourceUrl !== '' && $uploadUrl !== '');

$sourcePath = $pdfPath;
if ($isRemote) {
    $sourcePath = sys_get_temp_dir() . '/tw_pdf_src_' . md5($sourceUrl . microtime(true)) . '.pdf';
    $dl = curl_download($sourceUrl, $sourcePath);
    if (!$dl['ok']) {
        $fail = [
            'status' => 'failed',
            'surrogate' => $surrogate,
            'owner' => $owner,
            'storage' => 'r2',
            'started_at' => time(),
            'finished_at' => time(),
            'message' => 'Could not download source PDF',
            'error' => trim((string)($dl['error'] ?? '')),
            'http_code' => (int)($dl['http'] ?? 0)
        ];
        write_json_atomic($statusFile, $fail);
        @unlink($lockFile);
        @unlink($jobFile);
        @unlink($sourcePath);
        exit(0);
    }
}

if (!$isLocal && !$isRemote) {
    $fail = [
        'status' => 'failed',
        'surrogate' => $surrogate,
        'owner' => $owner,
        'started_at' => time(),
        'finished_at' => time(),
        'message' => 'No valid optimization source'
    ];
    write_json_atomic($statusFile, $fail);
    @unlink($lockFile);
    @unlink($jobFile);
    exit(0);
}

$oldSize = is_file($sourcePath) ? (int)filesize($sourcePath) : max(0, $oldSizeHint);
$sourcePages = get_pdf_page_count($sourcePath);
$state = [
    'status' => 'running',
    'surrogate' => $surrogate,
    'owner' => $owner,
    'storage' => $isLocal ? 'local' : 'r2',
    'started_at' => time(),
    'old_size_bytes' => $oldSize,
    'old_pages' => $sourcePages
];
write_json_atomic($statusFile, $state);
@file_put_contents($lockFile, (string)getmypid());

$tmpSharp = sys_get_temp_dir() . '/tw_pdf_opt_sharp_' . md5($surrogate . $owner . microtime(true)) . '.pdf';
$tmpScan = sys_get_temp_dir() . '/tw_pdf_opt_scan_' . md5($surrogate . $owner . microtime(true)) . '.pdf';
$tmpInk = sys_get_temp_dir() . '/tw_pdf_opt_ink_' . md5($surrogate . $owner . microtime(true)) . '.pdf';
$tmpOffset = sys_get_temp_dir() . '/tw_pdf_opt_offset_' . md5($surrogate . $owner . microtime(true)) . '.pdf';
$runSharp = run_gs_profile($sourcePath, $tmpSharp, 'sharp_text');
$runScan = run_gs_profile($sourcePath, $tmpScan, 'scan_clean');
$runInk = run_convert_ink_clean_profile($sourcePath, $tmpInk);
$runOffset = $fixOffset ? run_convert_offset_fix_profile($sourcePath, $tmpOffset, $offsetStrength) : ['ok' => false, 'code' => 0, 'tail' => 'offset fix disabled'];

$finalStatus = [
    'status' => 'failed',
    'surrogate' => $surrogate,
    'owner' => $owner,
    'started_at' => $state['started_at'],
    'finished_at' => time(),
    'old_size_bytes' => $oldSize,
    'message' => 'Optimization failed'
];

$candidates = [];
if ($runSharp['ok']) {
    $pages = get_pdf_page_count($tmpSharp);
    if ($sourcePages <= 0 || $pages === $sourcePages) {
        $candidates[] = ['profile' => 'sharp_text', 'path' => $tmpSharp, 'size' => (int)filesize($tmpSharp), 'pages' => $pages];
    }
}
if ($runScan['ok']) {
    $pages = get_pdf_page_count($tmpScan);
    if ($sourcePages <= 0 || $pages === $sourcePages) {
        $candidates[] = ['profile' => 'scan_clean', 'path' => $tmpScan, 'size' => (int)filesize($tmpScan), 'pages' => $pages];
    }
}
if ($runInk['ok']) {
    $pages = get_pdf_page_count($tmpInk);
    if ($sourcePages <= 0 || $pages === $sourcePages) {
        $candidates[] = ['profile' => 'ink_clean', 'path' => $tmpInk, 'size' => (int)filesize($tmpInk), 'pages' => $pages];
    }
}
if ($runOffset['ok']) {
    $pages = get_pdf_page_count($tmpOffset);
    if ($sourcePages <= 0 || $pages === $sourcePages) {
        $candidates[] = ['profile' => 'offset_fix', 'path' => $tmpOffset, 'size' => (int)filesize($tmpOffset), 'pages' => $pages];
    }
}

// Hard rejection rules to avoid "optimized to blank/tiny" outputs.
if ($candidates) {
    $minBytes = max(64 * 1024, (int)floor($oldSize * 0.05)); // never accept <64KB or <5% of original
    $minBytesPerPage = 10 * 1024; // 10KB/page floor
    $candidates = array_values(array_filter($candidates, static function (array $c) use ($minBytes, $minBytesPerPage): bool {
        $size = (int)($c['size'] ?? 0);
        $pages = max(1, (int)($c['pages'] ?? 1));
        return $size >= $minBytes && ($size / $pages) >= $minBytesPerPage;
    }));
}

if ($candidates) {
    usort($candidates, static function (array $a, array $b): int {
        return $a['size'] <=> $b['size'];
    });
    $picked = $candidates[0];

    $byProfile = [];
    foreach ($candidates as $candidate) {
        $byProfile[$candidate['profile']] = $candidate;
    }

    // Guardrails:
    // 1) keep sharp text unless alternatives are meaningfully smaller
    // 2) ink_clean must beat sharp_text by >= 10% (quality tradeoff)
    // 3) offset_fix must beat sharp_text by >= 6% unless explicitly requested
    if (isset($byProfile['sharp_text'])) {
        $sharpCandidate = $byProfile['sharp_text'];
        if (
            isset($byProfile['scan_clean']) &&
            $byProfile['scan_clean']['size'] >= (int)floor($sharpCandidate['size'] * 0.88) &&
            $picked['profile'] === 'scan_clean'
        ) {
            $picked = $sharpCandidate;
        }

        if (
            isset($byProfile['ink_clean']) &&
            $byProfile['ink_clean']['size'] >= (int)floor($sharpCandidate['size'] * 0.90) &&
            $picked['profile'] === 'ink_clean'
        ) {
            $picked = $sharpCandidate;
        }

        if (
            isset($byProfile['offset_fix']) &&
            $byProfile['offset_fix']['size'] >= (int)floor($sharpCandidate['size'] * 0.94) &&
            !$fixOffset &&
            $picked['profile'] === 'offset_fix'
        ) {
            $picked = $sharpCandidate;
        }
    }

    // If caller explicitly asked for odd/even offset fixing, prioritize that profile.
    if ($fixOffset && isset($byProfile['offset_fix'])) {
        $picked = $byProfile['offset_fix'];
    }

    $tmpPath = (string)$picked['path'];
    $newSize = (int)$picked['size'];
    $newPages = (int)($picked['pages'] ?? 0);
    $chosenProfile = (string)$picked['profile'];
    $mime = (new finfo(FILEINFO_MIME_TYPE))->file($tmpPath);

    if ($mime !== 'application/pdf') {
        @unlink($tmpPath);
        $finalStatus['message'] = 'Optimizer output was not a PDF';
    } elseif (!pdf_has_visible_ink($tmpPath, min(5, max(1, $sourcePages)))) {
        @unlink($tmpPath);
        $finalStatus['message'] = 'Optimization rejected: output appears blank';
    } elseif (
        ($oldSize > 0 && $newSize < (int)floor($oldSize * 0.98)) ||
        ($fixOffset && $chosenProfile === 'offset_fix' && $newPages > 0 && ($sourcePages <= 0 || $newPages === $sourcePages))
    ) {
        if ($isLocal) {
            $backupReady = false;
            if ($backupPath !== '' && is_file($pdfPath)) {
                @mkdir(dirname($backupPath), 0775, true);
                $backupReady = @copy($pdfPath, $backupPath);
            }
            if (!$backupReady) {
                @unlink($tmpPath);
                $finalStatus['message'] = 'Could not create local revert backup';
                write_json_atomic($statusFile, $finalStatus);
                @unlink($lockFile);
                @unlink($jobFile);
                if ($isRemote) @unlink($sourcePath);
                exit(0);
            }

            if (@rename($tmpPath, $pdfPath)) {
                $saved = $oldSize - $newSize;
                $finalStatus = [
                    'status' => 'success',
                    'surrogate' => $surrogate,
                    'owner' => $owner,
                    'storage' => 'local',
                    'started_at' => $state['started_at'],
                    'finished_at' => time(),
                    'old_size_bytes' => $oldSize,
                    'new_size_bytes' => $newSize,
                    'saved_bytes' => $saved,
                    'saved_percent' => round(($saved / max(1, $oldSize)) * 100, 2),
                    'old_pages' => $sourcePages,
                    'new_pages' => $newPages,
                    'profile' => $chosenProfile,
                    'revert_available' => true,
                    'revert_expires_at' => $revertExpiresAt,
                    'message' => 'PDF optimized'
                ];
            } else {
                @unlink($tmpPath);
                $finalStatus['message'] = 'Could not replace original PDF';
            }
        } else {
            $backupReady = false;
            if ($backupUploadUrl !== '' && is_file($sourcePath)) {
                $backup = curl_upload_pdf($backupUploadUrl, $sourcePath);
                $backupReady = !empty($backup['ok']);
            }
            if (!$backupReady) {
                @unlink($tmpPath);
                $finalStatus['message'] = 'Could not create remote revert backup';
                write_json_atomic($statusFile, $finalStatus);
                @unlink($lockFile);
                @unlink($jobFile);
                if ($isRemote) @unlink($sourcePath);
                exit(0);
            }

            $up = curl_upload_pdf($uploadUrl, $tmpPath);
            @unlink($tmpPath);
            if ($up['ok']) {
                $saved = $oldSize - $newSize;
                $finalStatus = [
                    'status' => 'success',
                    'surrogate' => $surrogate,
                    'owner' => $owner,
                    'storage' => 'r2',
                    'started_at' => $state['started_at'],
                    'finished_at' => time(),
                    'old_size_bytes' => $oldSize,
                    'new_size_bytes' => $newSize,
                    'saved_bytes' => $saved,
                    'saved_percent' => round(($saved / max(1, $oldSize)) * 100, 2),
                    'old_pages' => $sourcePages,
                    'new_pages' => $newPages,
                    'profile' => $chosenProfile,
                    'revert_available' => true,
                    'revert_expires_at' => $revertExpiresAt,
                    'message' => 'PDF optimized'
                ];
            } else {
                $finalStatus['message'] = 'Could not upload optimized PDF';
                $finalStatus['error'] = trim((string)($up['error'] ?? ''));
                $finalStatus['http_code'] = (int)($up['http'] ?? 0);
            }
        }
    } else {
        @unlink($tmpPath);
        $saved = max(0, $oldSize - $newSize);
        $finalStatus = [
            'status' => 'skipped',
            'surrogate' => $surrogate,
            'owner' => $owner,
            'started_at' => $state['started_at'],
            'finished_at' => time(),
            'old_size_bytes' => $oldSize,
            'new_size_bytes' => $oldSize,
            'saved_bytes' => $saved,
            'saved_percent' => $oldSize > 0 ? round(($saved / $oldSize) * 100, 2) : 0.0,
            'old_pages' => $sourcePages,
            'new_pages' => $newPages,
            'profile' => $chosenProfile,
            'revert_available' => false,
            'message' => 'No meaningful size reduction'
        ];
    }
} else {
    $tail = trim(implode("\n", array_filter([$runSharp['tail'] ?? '', $runScan['tail'] ?? '', $runInk['tail'] ?? '', $runOffset['tail'] ?? ''])));
    if ($tail !== '') {
        $finalStatus['error'] = $tail;
    }
    if ($sourcePages > 0) {
        $finalStatus['message'] = 'Optimization rejected by safeguards (page/size/blank checks)';
        $finalStatus['old_pages'] = $sourcePages;
    }
}

@unlink($tmpSharp);
@unlink($tmpScan);
@unlink($tmpInk);
@unlink($tmpOffset);

write_json_atomic($statusFile, $finalStatus);
@unlink($lockFile);
@unlink($jobFile);
if ($isRemote) {
    @unlink($sourcePath);
}
