<?php
header('Content-Type: application/json');

// Allow longer OMR runs
@set_time_limit(300);
@ini_set('max_execution_time', '300');
@ini_set('memory_limit', '512M');
@ignore_user_abort(true);

$responded = false;
register_shutdown_function(function () use (&$responded) {
    if ($responded) return;
    $err = error_get_last();
    if ($err && in_array($err['type'], [E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR], true)) {
        http_response_code(500);
        echo json_encode([
            'status' => 'error',
            'error' => 'Server error during OMR.',
            'detail' => $err['message'] ?? 'fatal',
        ]);
    }
});

// Basic CORS for local testing
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type');
if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(204);
    exit;
}

function json_error($msg, $code = 400, $detail = null) {
    http_response_code($code);
    $payload = [
        'status' => 'error',
        'error' => $msg,
    ];
    if ($detail) $payload['detail'] = $detail;
    echo json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE);
    exit;
}

function tail_lines_from_file(string $path, int $maxLines = 120): array {
    if (!is_file($path)) return [];
    $raw = @file($path, FILE_IGNORE_NEW_LINES);
    if (!is_array($raw)) return [];
    if (count($raw) <= $maxLines) return $raw;
    return array_slice($raw, -$maxLines);
}

function find_musescore_cli(): ?string {
    $fromEnv = trim((string)(getenv('MSCORE_BIN') ?: ''));
    if ($fromEnv !== '' && @is_executable($fromEnv)) {
        return $fromEnv;
    }
    $candidates = [
        '/usr/bin/mscore',
        '/usr/bin/musescore',
        '/usr/bin/musescore3',
        '/usr/bin/musescore4',
        '/snap/bin/musescore',
    ];
    foreach ($candidates as $bin) {
        if (@is_executable($bin)) return $bin;
    }
    $names = ['mscore', 'musescore', 'musescore3', 'musescore4'];
    foreach ($names as $name) {
        $path = trim((string)@shell_exec('command -v ' . escapeshellarg($name) . ' 2>/dev/null'));
        if ($path !== '' && @is_executable($path)) return $path;
    }
    return null;
}

function force_piano_program_changes(string $midiPath): void {
    $bin = @file_get_contents($midiPath);
    if ($bin === false || strlen($bin) < 14) return;
    if (substr($bin, 0, 4) !== 'MThd') return;

    $headerLen = unpack('N', substr($bin, 4, 4))[1] ?? 0;
    if ($headerLen < 6) return;

    $offset = 8 + $headerLen;
    $total = strlen($bin);

    // Delta-time 0 control defaults:
    // - Program Change -> Acoustic Grand Piano
    // - CC64 sustain pedal off
    // - CC91 reverb send off
    // - CC93 chorus send off
    $prefix = '';
    for ($ch = 0; $ch < 16; $ch++) {
        $prefix .= chr(0x00) . chr(0xC0 | $ch) . chr(0x00);
        $prefix .= chr(0x00) . chr(0xB0 | $ch) . chr(64) . chr(0);
        $prefix .= chr(0x00) . chr(0xB0 | $ch) . chr(91) . chr(0);
        $prefix .= chr(0x00) . chr(0xB0 | $ch) . chr(93) . chr(0);
    }

    $rewriteTrack = static function (string $trackData): string {
        $len = strlen($trackData);
        $pos = 0;
        $out = '';
        $running = null;

        while ($pos < $len) {
            // Copy delta-time VLQ as-is.
            $deltaStart = $pos;
            do {
                if ($pos >= $len) return $out;
                $b = ord($trackData[$pos++]);
            } while (($b & 0x80) !== 0);
            $out .= substr($trackData, $deltaStart, $pos - $deltaStart);
            if ($pos >= $len) break;

            $peek = ord($trackData[$pos]);
            $status = null;
            $explicitStatus = false;

            if ($peek >= 0x80) {
                $status = $peek;
                $explicitStatus = true;
                $pos++;
                $out .= chr($status);
                if ($status < 0xF0) {
                    $running = $status;
                } elseif ($status < 0xF8) {
                    $running = null;
                }
            } else {
                if ($running === null) {
                    // Invalid stream; keep remainder unchanged.
                    $out .= substr($trackData, $pos);
                    break;
                }
                $status = $running;
            }

            if ($status === 0xFF) {
                // Meta event: type + length(VLQ) + payload.
                if ($pos >= $len) break;
                $type = $trackData[$pos++];
                $out .= $type;

                $vlqStart = $pos;
                $valueLen = 0;
                do {
                    if ($pos >= $len) return $out;
                    $vb = ord($trackData[$pos++]);
                    $valueLen = ($valueLen << 7) | ($vb & 0x7F);
                } while (($vb & 0x80) !== 0);
                $out .= substr($trackData, $vlqStart, $pos - $vlqStart);

                if ($pos + $valueLen > $len) $valueLen = max(0, $len - $pos);
                $out .= substr($trackData, $pos, $valueLen);
                $pos += $valueLen;
                continue;
            }

            if ($status === 0xF0 || $status === 0xF7) {
                // SysEx: length(VLQ) + payload.
                $vlqStart = $pos;
                $valueLen = 0;
                do {
                    if ($pos >= $len) return $out;
                    $vb = ord($trackData[$pos++]);
                    $valueLen = ($valueLen << 7) | ($vb & 0x7F);
                } while (($vb & 0x80) !== 0);
                $out .= substr($trackData, $vlqStart, $pos - $vlqStart);

                if ($pos + $valueLen > $len) $valueLen = max(0, $len - $pos);
                $out .= substr($trackData, $pos, $valueLen);
                $pos += $valueLen;
                continue;
            }

            // Channel voice message.
            $hi = $status & 0xF0;
            $dataLen = ($hi === 0xC0 || $hi === 0xD0) ? 1 : 2;
            if ($pos + $dataLen > $len) {
                $out .= substr($trackData, $pos);
                break;
            }

            if ($hi === 0xC0) {
                // Force every Program Change to Acoustic Grand Piano (program 0).
                $out .= chr(0x00);
                $pos += 1;
            } elseif ($hi === 0xB0) {
                // Strip sustain/reverb/chorus controller instructions from OMR MIDI.
                $controller = ord($trackData[$pos]);
                if ($controller === 64 || $controller === 91 || $controller === 93) {
                    $pos += 2;
                    continue;
                }
                $out .= substr($trackData, $pos, $dataLen);
                $pos += $dataLen;
            } else {
                $out .= substr($trackData, $pos, $dataLen);
                $pos += $dataLen;
            }
        }

        return $out;
    };

    $out = substr($bin, 0, $offset);
    while ($offset + 8 <= $total) {
        $chunkId = substr($bin, $offset, 4);
        $chunkLen = unpack('N', substr($bin, $offset + 4, 4))[1] ?? 0;
        $dataStart = $offset + 8;
        $dataEnd = $dataStart + $chunkLen;
        if ($dataEnd > $total) break;

        $chunkData = substr($bin, $dataStart, $chunkLen);
        if ($chunkId === 'MTrk') {
            $chunkData = $prefix . $rewriteTrack($chunkData);
            $chunkLen = strlen($chunkData);
        }

        $out .= $chunkId . pack('N', $chunkLen) . $chunkData;
        $offset = $dataEnd;
    }

    // Append any trailing bytes unchanged (if present).
    if ($offset < $total) {
        $out .= substr($bin, $offset);
    }

    @file_put_contents($midiPath, $out);
}

function force_four_channels_by_pitch(string $midiPath): void {
    $bin = @file_get_contents($midiPath);
    if ($bin === false || strlen($bin) < 14) return;
    if (substr($bin, 0, 4) !== 'MThd') return;

    $headerLen = unpack('N', substr($bin, 4, 4))[1] ?? 0;
    if ($headerLen < 6) return;

    $offset = 8 + $headerLen;
    $total = strlen($bin);
    $out = substr($bin, 0, $offset);

    $pickChannel = static function (int $note): int {
        // SATB-ish split for quick testing:
        // S: >=72 (C5+), A: 60-71, T: 48-59, B: <48
        if ($note >= 72) return 1;
        if ($note >= 60) return 2;
        if ($note >= 48) return 3;
        return 4;
    };

    $readVlv = static function (string $src, int $pos, int $end): array {
        $value = 0;
        $start = $pos;
        while ($pos < $end) {
            $b = ord($src[$pos++]);
            $value = ($value << 7) | ($b & 0x7F);
            if (($b & 0x80) === 0) break;
        }
        return [substr($src, $start, $pos - $start), $value, $pos];
    };

    while ($offset + 8 <= $total) {
        $chunkId = substr($bin, $offset, 4);
        $chunkLen = unpack('N', substr($bin, $offset + 4, 4))[1] ?? 0;
        $dataStart = $offset + 8;
        $dataEnd = $dataStart + $chunkLen;
        if ($dataEnd > $total) break;

        $track = substr($bin, $dataStart, $chunkLen);
        if ($chunkId !== 'MTrk') {
            $out .= $chunkId . pack('N', $chunkLen) . $track;
            $offset = $dataEnd;
            continue;
        }

        $len = strlen($track);
        $pos = 0;
        $rewritten = '';
        $runningIn = null;
        $runningOut = null;
        $activeByNote = []; // note => [channel1, channel2, ...]

        while ($pos < $len) {
            [$deltaRaw, , $pos] = $readVlv($track, $pos, $len);
            $rewritten .= $deltaRaw;
            if ($pos >= $len) break;

            $byte = ord($track[$pos]);
            if ($byte >= 0x80) {
                $status = $byte;
                $pos++;
                if ($status < 0xF0) {
                    $runningIn = $status;
                } elseif ($status < 0xF8) {
                    $runningIn = null;
                }
            } else {
                if ($runningIn === null) {
                    $rewritten .= substr($track, $pos);
                    break;
                }
                $status = $runningIn;
            }

            if ($status === 0xFF) {
                $runningOut = null;
                $rewritten .= chr(0xFF);
                if ($pos >= $len) break;
                $metaType = $track[$pos++];
                $rewritten .= $metaType;
                [$vlqRaw, $metaLen, $pos] = $readVlv($track, $pos, $len);
                $rewritten .= $vlqRaw;
                $metaLen = min($metaLen, max(0, $len - $pos));
                $rewritten .= substr($track, $pos, $metaLen);
                $pos += $metaLen;
                continue;
            }

            if ($status === 0xF0 || $status === 0xF7) {
                $runningOut = null;
                $rewritten .= chr($status);
                [$vlqRaw, $sxLen, $pos] = $readVlv($track, $pos, $len);
                $rewritten .= $vlqRaw;
                $sxLen = min($sxLen, max(0, $len - $pos));
                $rewritten .= substr($track, $pos, $sxLen);
                $pos += $sxLen;
                continue;
            }

            $hi = $status & 0xF0;
            $dataLen = ($hi === 0xC0 || $hi === 0xD0) ? 1 : 2;
            if ($pos + $dataLen > $len) {
                $rewritten .= substr($track, $pos);
                break;
            }
            $d1 = ord($track[$pos]);
            $d2 = $dataLen > 1 ? ord($track[$pos + 1]) : null;
            $pos += $dataLen;

            $outStatus = $status;
            if ($hi === 0x90) {
                // Note on: map to one of 4 SATB channels.
                $target = $pickChannel($d1);
                $outStatus = 0x90 | ($target - 1);
                if (($d2 ?? 0) > 0) {
                    if (!isset($activeByNote[$d1])) $activeByNote[$d1] = [];
                    $activeByNote[$d1][] = $target;
                } else {
                    if (isset($activeByNote[$d1]) && count($activeByNote[$d1]) > 0) {
                        $target = array_pop($activeByNote[$d1]);
                        if (!$activeByNote[$d1]) unset($activeByNote[$d1]);
                        $outStatus = 0x90 | ($target - 1);
                    } else {
                        $outStatus = 0x90 | ($pickChannel($d1) - 1);
                    }
                }
            } elseif ($hi === 0x80) {
                // Note off: follow mapped note-on channel if available.
                if (isset($activeByNote[$d1]) && count($activeByNote[$d1]) > 0) {
                    $target = array_pop($activeByNote[$d1]);
                    if (!$activeByNote[$d1]) unset($activeByNote[$d1]);
                    $outStatus = 0x80 | ($target - 1);
                } else {
                    $outStatus = 0x80 | ($pickChannel($d1) - 1);
                }
            }

            if ($outStatus !== $runningOut) {
                $rewritten .= chr($outStatus);
                $runningOut = $outStatus;
            }
            $rewritten .= chr($d1);
            if ($dataLen > 1) $rewritten .= chr($d2);
        }

        $out .= 'MTrk' . pack('N', strlen($rewritten)) . $rewritten;
        $offset = $dataEnd;
    }

    if ($offset < $total) {
        $out .= substr($bin, $offset);
    }

    @file_put_contents($midiPath, $out);
}

$useMock = isset($_POST['mock']) && $_POST['mock'] === '1';
$forceSatb4 = isset($_POST['force4']) && $_POST['force4'] === '1';
$engineRequested = 'omr4all';

if (!isset($_FILES['image'])) {
    json_error('No image uploaded. Expected field name "image".');
}

$tmpDir = sys_get_temp_dir() . '/omr';
if (!is_dir($tmpDir) && !mkdir($tmpDir, 0777, true)) {
    json_error('Failed to create temp directory.', 500);
}

$upload = $_FILES['image'];
if ($upload['error'] !== UPLOAD_ERR_OK) {
    json_error('Upload failed: ' . $upload['error']);
}

$inPath = $tmpDir . '/omr_' . bin2hex(random_bytes(6)) . '.png';
if (!move_uploaded_file($upload['tmp_name'], $inPath)) {
    json_error('Failed to move uploaded file.', 500);
}

if ($useMock) {
    $responded = true;
    echo json_encode([
        'status' => 'ok',
        'message' => 'Mock MIDI used (no OMR backend).',
        'midiUrl' => '/assets/MidiPlayerJS-master/demo/midi/chopin.mid',
        'engineRequested' => $engineRequested,
        'engineUsed' => 'mock',
    ]);
    exit;
}

// Dedicated OMR4all backend only (no fallback engines here).
// Command may use {input} and {outdir} placeholders.
$cmdTemplate = trim((string)(getenv('OMR_COMMAND_OMR4ALL') ?: ''));
$engineUsed = 'omr4all';
if ($cmdTemplate === '') {
    json_error('OMR4all backend not configured. Set OMR_COMMAND_OMR4ALL.', 501);
}

$outDir = $tmpDir . '/out_' . bin2hex(random_bytes(4));
if (!mkdir($outDir, 0777, true)) {
    json_error('Failed to create output directory.', 500);
}

$envHome = $tmpDir . '/home';
$envCache = $tmpDir . '/xdg/cache';
$envConfig = $tmpDir . '/xdg/config';
$envData = $tmpDir . '/xdg/data';
@mkdir($envHome, 0777, true);
@mkdir($envCache, 0777, true);
@mkdir($envConfig, 0777, true);
@mkdir($envData, 0777, true);

$tessdata = '/usr/share/tesseract-ocr/5/tessdata';
$tessEnv = is_dir($tessdata) ? 'TESSDATA_PREFIX=' . escapeshellarg($tessdata) . ' ' : '';

$cmd = str_replace([
    '{input}',
    '{outdir}'
], [
    escapeshellarg($inPath),
    escapeshellarg($outDir)
], $cmdTemplate);

$envPrefix = 'env ' .
    'HOME=' . escapeshellarg($envHome) . ' ' .
    'XDG_CACHE_HOME=' . escapeshellarg($envCache) . ' ' .
    'XDG_CONFIG_HOME=' . escapeshellarg($envConfig) . ' ' .
    'XDG_DATA_HOME=' . escapeshellarg($envData) . ' ';

$exitCode = 0;
$output = [];
$omrTimeoutSecs = max(10, (int)(getenv('OMR_TIMEOUT_SECS') ?: 25));
error_log("[omr_extract] Engine={$engineUsed} request={$engineRequested} running: " . $envPrefix . $tessEnv . $cmd);
$omrLogFile = $outDir . '/omr_cmd.log';
$wrappedOcr = 'timeout -k 5s ' . $omrTimeoutSecs . 's sh -lc ' . escapeshellarg(
    $envPrefix . $tessEnv . $cmd . ' > ' . escapeshellarg($omrLogFile) . ' 2>&1'
);
@exec($wrappedOcr, $noop, $exitCode);
$output = tail_lines_from_file($omrLogFile, 140);
error_log('[omr_extract] OMR exit code: ' . $exitCode);

if ($exitCode !== 0) {
    if ($exitCode === 124) {
        json_error('OMR command timed out.', 500, 'Engine "' . $engineUsed . '" exceeded timeout of ' . $omrTimeoutSecs . 's. Try a smaller region or lower upscale.');
    }
    error_log("[omr_extract] OMR engine failed: " . implode("\n", $output));
    json_error('OMR command failed: ' . implode("\n", $output), 500);
}

// Find outputs (Audiveris may nest in subfolders)
$midiFile = null;
$xmlFile = null;
error_log('[omr_extract] Scanning output dir: ' . $outDir);
$dirs = [$outDir];
while ($dirs) {
    $dir = array_pop($dirs);
    $entries = @scandir($dir);
    if (!is_array($entries)) continue;
    foreach ($entries as $entry) {
        if ($entry === '.' || $entry === '..') continue;
        $path = $dir . '/' . $entry;
        if (@is_dir($path)) {
            $dirs[] = $path;
            continue;
        }
        if (!@is_file($path)) continue;
        $ext = strtolower(pathinfo($path, PATHINFO_EXTENSION));
        if (!$midiFile && ($ext === 'mid' || $ext === 'midi')) $midiFile = $path;
        if (!$xmlFile && ($ext === 'xml' || $ext === 'musicxml' || $ext === 'mxl')) $xmlFile = $path;
        if ($midiFile && $xmlFile) break 2;
    }
}
error_log('[omr_extract] Found midi=' . ($midiFile ?: 'none') . ' xml=' . ($xmlFile ?: 'none'));

// If no MIDI but MusicXML exists, try MuseScore CLI conversion
$musescoreBin = find_musescore_cli();
if (!$midiFile && $xmlFile && $musescoreBin) {
    $midiOut = $outDir . '/omr.mid';
    $cmd = 'env QT_QPA_PLATFORM=offscreen ' .
        escapeshellarg($musescoreBin) . ' -o ' .
        escapeshellarg($midiOut) . ' ' .
        escapeshellarg($xmlFile);
    $out = [];
    $code = 0;
    $mscoreTimeoutSecs = max(10, (int)(getenv('MSCORE_TIMEOUT_SECS') ?: 30));
    error_log("[omr_extract] Converting MusicXML via MuseScore: " . $cmd);
    $mscoreLog = $outDir . '/mscore_cmd.log';
    $wrappedMscore = 'timeout -k 5s ' . $mscoreTimeoutSecs . 's sh -lc ' . escapeshellarg(
        $cmd . ' > ' . escapeshellarg($mscoreLog) . ' 2>&1'
    );
    @exec($wrappedMscore, $noop2, $code);
    $out = tail_lines_from_file($mscoreLog, 120);
    error_log('[omr_extract] MuseScore exit code: ' . $code);
    if ($code === 0 && file_exists($midiOut)) {
        $midiFile = $midiOut;
    } else {
        if ($code === 124) {
            $out[] = 'MuseScore timed out after ' . $mscoreTimeoutSecs . 's';
        }
        $output[] = "MuseScore convert failed: " . implode("\n", $out);
    }
}
if (!$midiFile && $xmlFile && !$musescoreBin) {
    $output[] = 'MuseScore CLI not found; set MSCORE_BIN or install musescore/mscore to auto-generate MIDI from MusicXML.';
}

if (!$midiFile && !$xmlFile) {
    $hint = null;
    if (!empty($output)) {
        $hint = implode("\n", $output);
    }
    // OMR miss is an expected outcome on difficult crops; return JSON with 200
    // so the frontend can show the message without browser network error noise.
    json_error('OMR produced no MIDI/MusicXML output.', 200, $hint);
}

$res = ['status' => 'ok'];
$res['engineRequested'] = $engineRequested;
$res['engineUsed'] = $engineUsed;
error_log('[omr_extract] Building response payload');
if ($midiFile) {
    // Normalize OMR MIDI control/program events so playback is deterministic
    // and does not inherit accidental sustain/reverb instructions.
    try {
        force_piano_program_changes($midiFile);
    } catch (Throwable $e) {
        $output[] = 'MIDI sanitize warning: ' . $e->getMessage();
    }

    $res['midiName'] = basename($midiFile);

    // Publish a temp URL for playback (works with cache-busting)
    $pubDir = $tmpDir . '/public';
    @mkdir($pubDir, 0777, true);
    $token = bin2hex(random_bytes(8));
    $pubName = "omr-$token.mid";
    $pubPath = $pubDir . '/' . $pubName;
    if (@copy($midiFile, $pubPath)) {
        $res['midiUrl'] = "/api/omr_fetch.php?file=" . urlencode($pubName);
    } else {
        $output[] = 'MIDI publish warning: failed to copy temp MIDI.';
    }
}
if ($xmlFile) {
    $res['musicXmlName'] = basename($xmlFile);

    // Publish temp URL for MusicXML so frontend can build note-location overlays.
    $pubDir = $tmpDir . '/public';
    @mkdir($pubDir, 0777, true);
    $xmlExt = strtolower(pathinfo($xmlFile, PATHINFO_EXTENSION)) ?: 'xml';
    $xmlToken = bin2hex(random_bytes(8));
    $xmlPubName = "omr-$xmlToken.$xmlExt";
    $xmlPubPath = $pubDir . '/' . $xmlPubName;
    if (@copy($xmlFile, $xmlPubPath)) {
        $res['musicXmlUrl'] = "/api/omr_fetch.php?file=" . urlencode($xmlPubName);
    } else {
        $output[] = 'MusicXML publish warning: failed to copy temp MusicXML.';
    }
}

if (!empty($output)) {
    $res['log'] = implode("\n", $output);
}

$responded = true;
echo json_encode($res, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE);
