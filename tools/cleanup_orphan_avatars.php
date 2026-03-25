<?php
require_once __DIR__ . '/../includes/db_connect.php';
require_once __DIR__ . '/../includes/system-paths.php';

// Usage:
//   php tools/cleanup_orphan_avatars.php          # dry-run
//   php tools/cleanup_orphan_avatars.php --apply  # move orphan files to recycle/

$apply = in_array('--apply', $argv, true);
$avatarDir = rtrim((string)UPLOAD_PATH, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR . 'avatars';
$trashDir = $avatarDir . DIRECTORY_SEPARATOR . 'recycle';

if (!is_dir($avatarDir)) {
    fwrite(STDERR, "Avatar directory not found: {$avatarDir}\n");
    exit(1);
}

function avatarNameFromUrl(?string $avatarUrl): ?string {
    if (!is_string($avatarUrl) || trim($avatarUrl) === '') return null;
    $parts = parse_url($avatarUrl);
    $path = $parts['path'] ?? '';
    if ($path !== '/avatar-file.php') return null;
    $query = [];
    parse_str((string)($parts['query'] ?? ''), $query);
    $name = trim((string)($query['name'] ?? ''));
    if (!preg_match('/^avatar_[0-9]+_[0-9]+_[a-f0-9]{8}\.(jpg|png|gif|webp)$/i', $name)) {
        return null;
    }
    return $name;
}

$inUse = [];
$res = $mysqli->query("SELECT avatar_url FROM members WHERE avatar_url IS NOT NULL AND avatar_url <> ''");
if (!$res) {
    fwrite(STDERR, "DB query failed.\n");
    exit(1);
}
while ($row = $res->fetch_assoc()) {
    $name = avatarNameFromUrl($row['avatar_url'] ?? null);
    if ($name !== null) {
        $inUse[strtolower($name)] = true;
    }
}
$res->free();

$allFiles = glob($avatarDir . DIRECTORY_SEPARATOR . 'avatar_*');
if ($allFiles === false) {
    fwrite(STDERR, "Failed to read avatar directory.\n");
    exit(1);
}

$orphans = [];
foreach ($allFiles as $path) {
    if (!is_file($path)) continue;
    $name = basename($path);
    if (!preg_match('/^avatar_[0-9]+_[0-9]+_[a-f0-9]{8}\.(jpg|png|gif|webp)$/i', $name)) continue;
    if (!isset($inUse[strtolower($name)])) {
        $orphans[] = $path;
    }
}

echo "Avatar dir: {$avatarDir}\n";
echo "Referenced avatar files: " . count($inUse) . "\n";
echo "Orphan files: " . count($orphans) . "\n";

if (!$apply) {
    echo "Dry-run mode. Pass --apply to move to recycle.\n";
    foreach (array_slice($orphans, 0, 50) as $path) {
        echo "  ORPHAN {$path}\n";
    }
    if (count($orphans) > 50) {
        echo "  ... and " . (count($orphans) - 50) . " more\n";
    }
    exit(0);
}

$moved = 0;
$failed = 0;
foreach ($orphans as $path) {
    if (!is_dir($trashDir) && !@mkdir($trashDir, 0755, true)) {
        $failed++;
        fwrite(STDERR, "Failed create recycle dir: {$trashDir}\n");
        break;
    }
    $name = basename($path);
    $target = $trashDir . DIRECTORY_SEPARATOR . date('YmdHis') . '_' . bin2hex(random_bytes(3)) . '_' . $name;
    if (@rename($path, $target) || @copy($path, $target) && @unlink($path)) {
        $moved++;
    } else {
        $failed++;
        fwrite(STDERR, "Failed move: {$path}\n");
    }
}

echo "Moved to recycle: {$moved}\n";
echo "Failed: {$failed}\n";
exit($failed > 0 ? 2 : 0);
