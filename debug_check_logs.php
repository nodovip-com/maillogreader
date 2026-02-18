<?php
// Check HEAD and TAIL of the log file to see timestamps
require_once 'api.php';
$settings = getSettings();
$path = $settings['log_path'];

if (!file_exists($path))
    die("File not found: $path\n");

echo "File Size: " . number_format(filesize($path)) . " bytes\n";

// Read Head (First 2KB)
$handle = fopen($path, 'r');
$head = fread($handle, 2048);
echo "\n--- HEAD (Start of file) ---\n";
echo substr($head, 0, 500) . "...\n";

// Read Tail (Last 2KB)
fseek($handle, -2048, SEEK_END);
$tail = fread($handle, 2048);
echo "\n--- TAIL (End of file) ---\n";
echo "..." . substr($tail, -500) . "\n";

fclose($handle);
