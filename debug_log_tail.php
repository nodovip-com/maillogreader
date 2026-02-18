<?php
// Debug script to check log file tail format and regex behavior
// Usage: php debug_log_tail.php

require_once 'api.php'; // To get settings and verify path

function debugLogTail()
{
    $settings = getSettings();
    $path = $settings['log_path'];

    echo "Checking log file: $path\n";
    if (!file_exists($path)) {
        echo "File not found!\n";
        return;
    }

    $size = filesize($path);
    echo "File size: " . number_format($size) . " bytes\n";

    // Read last 2KB to see structure
    $handle = fopen($path, 'r');
    $seek = max(0, $size - 2048);
    fseek($handle, $seek);
    $tail = fread($handle, 2048);
    fclose($handle);

    echo "\n--- Last 2KB Preview ---\n";
    echo substr($tail, -500); // Show last 500 chars
    echo "\n------------------------\n";

    // Test Regex behavior on a 5MB chunk simulation (using repeat)
    echo "\nTesting Regex Limit on dummy data...\n";
    $dummy = str_repeat('{"test":1},', 10000);
    $pattern = '/\{(?:[^{}]|(?R))*\}/';

    $start = microtime(true);
    preg_match_all($pattern, $dummy, $matches);
    $end = microtime(true);

    echo "Regex matched " . count($matches[0]) . " items in " . ($end - $start) . "s\n";

    // Check actual log tail regex match
    echo "\nTesting Regex on actual last 2KB...\n";
    preg_match_all($pattern, $tail, $realMatches);
    echo "Matched " . count($realMatches[0]) . " items in tail.\n";
    if (count($realMatches[0]) > 0) {
        echo "First match sample: " . substr($realMatches[0][0], 0, 50) . "...\n";
    }
}

debugLogTail();
