<?php
if (!defined('TEXTWHISPER_INCLUDE')) {
    define('TEXTWHISPER_INCLUDE', true);
}


function apply_translations($langCode = 'en') {
    if ($langCode === 'en') {
        ob_end_flush();
        return;
    }

    $langPath = __DIR__ . "/../lang/{$langCode}.php";
    if (!file_exists($langPath)) {
        ob_end_flush();
        return;
    }

    $translations = include $langPath;
    $output = ob_get_clean();

    // 🛑 Skip sections wrapped in <!-- skip-translate --> ... <!-- /skip-translate -->
    preg_match_all('/<!-- skip-translate -->(.*?)<!-- \/skip-translate -->/is', $output, $matches);
    $skipped = $matches[0];
    $placeholders = [];

    foreach ($skipped as $i => $block) {
        $placeholder = "__SKIP_TRANSLATE_BLOCK_{$i}__";
        $output = str_replace($block, $placeholder, $output);
        $placeholders[$placeholder] = $block;
    }

    // ✅ Translate the rest
    foreach ($translations as $original => $translated) {
        $output = str_replace($original, $translated, $output);
    }

    // 🔁 Restore skipped blocks
    foreach ($placeholders as $placeholder => $block) {
        $output = str_replace($placeholder, $block, $output);
    }

    echo $output;
}


