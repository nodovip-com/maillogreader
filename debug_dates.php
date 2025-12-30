<?php
date_default_timezone_set('UTC'); // Ensure UTC or system time
require_once 'config.php';

// Mock settings
$path = 'dummy_mail.log';
if (!file_exists($path)) {
    die("Log file not found: $path\n");
}

echo "Scanning $path...\n";
$handle = fopen($path, "r");
$dates = [];
if ($handle) {
    while (($line = fgets($handle)) !== false) {
        if (preg_match('/^([A-M][a-z]{2}\s+\d+)/', $line, $m)) {
            $timestamp = strtotime($m[1]);
            if ($timestamp) {
                $d = date('Y-m-d', $timestamp);
                $dates[$d] = true;
                // Debug first match
                // echo "Matched: " . $m[1] . " -> " . $d . "\n";
            }
        }
    }
    fclose($handle);
}

echo "Found Dates:\n";
print_r(array_keys($dates));
