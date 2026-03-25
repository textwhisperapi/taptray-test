<?php
// Migrate legacy avatar files out of product folder and rewrite DB URLs.
// Usage:
//   php tools/migrate_legacy_avatars.php                 # dry-run
//   php tools/migrate_legacy_avatars.php --apply         # move files + update DB
//   php tools/migrate_legacy_avatars.php --apply --target=/var/textwhisper_uploads/avatars

require_once __DIR__ . '/../includes/db_connect.php';

$apply = in_array('--apply', $argv, true);
$targetArg = null;
foreach ($argv as $arg) {
  if (strpos($arg, '--target=') === 0) {
    $targetArg = substr($arg, strlen('--target='));
    break;
  }
}

$legacyDir = __DIR__ . '/../uploads/avatars';
$targetDir = $targetArg ?: '/var/textwhisper_uploads/avatars';

if (!is_dir($legacyDir)) {
  fwrite(STDERR, "Legacy directory not found: {$legacyDir}\n");
  exit(1);
}

$files = array_values(array_filter(scandir($legacyDir), function ($f) use ($legacyDir) {
  return $f !== '.' && $f !== '..' && is_file($legacyDir . DIRECTORY_SEPARATOR . $f);
}));

echo "Mode: " . ($apply ? "APPLY" : "DRY-RUN") . PHP_EOL;
echo "Legacy: {$legacyDir}" . PHP_EOL;
echo "Target: {$targetDir}" . PHP_EOL;
echo "Files found: " . count($files) . PHP_EOL;

if (!$apply) {
  foreach ($files as $f) {
    echo "[dry-run] move {$legacyDir}/{$f} -> {$targetDir}/{$f}" . PHP_EOL;
  }
} else {
  if (!is_dir($targetDir) && !mkdir($targetDir, 0755, true)) {
    fwrite(STDERR, "Failed to create target directory: {$targetDir}\n");
    exit(1);
  }

  $moved = 0;
  $failed = 0;
  foreach ($files as $f) {
    $src = $legacyDir . DIRECTORY_SEPARATOR . $f;
    $dst = $targetDir . DIRECTORY_SEPARATOR . $f;
    if (is_file($dst)) {
      echo "[skip] exists: {$dst}" . PHP_EOL;
      continue;
    }
    if (@rename($src, $dst) || @copy($src, $dst)) {
      if (is_file($src) && is_file($dst)) {
        @unlink($src);
      }
      $moved++;
      echo "[ok] {$src} -> {$dst}" . PHP_EOL;
    } else {
      $failed++;
      echo "[fail] {$src}" . PHP_EOL;
    }
  }
  echo "Moved: {$moved}, Failed: {$failed}" . PHP_EOL;
}

// DB URL migration: /uploads/avatars/<file> -> /avatar-file.php?name=<file>
$countSql = "SELECT COUNT(*) AS c FROM members WHERE avatar_url LIKE '/uploads/avatars/%'";
$countRes = $mysqli->query($countSql);
$row = $countRes ? $countRes->fetch_assoc() : ['c' => 0];
$toRewrite = (int)($row['c'] ?? 0);
echo "DB rows to rewrite: {$toRewrite}" . PHP_EOL;

if ($apply && $toRewrite > 0) {
  $sql = "UPDATE members
          SET avatar_url = CONCAT('/avatar-file.php?name=', SUBSTRING_INDEX(avatar_url, '/', -1))
          WHERE avatar_url LIKE '/uploads/avatars/%'";
  if ($mysqli->query($sql)) {
    echo "DB rewrite complete: " . $mysqli->affected_rows . " row(s)." . PHP_EOL;
  } else {
    fwrite(STDERR, "DB rewrite failed: " . $mysqli->error . PHP_EOL);
    exit(1);
  }
}

echo "Done." . PHP_EOL;
