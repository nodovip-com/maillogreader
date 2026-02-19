<?php
// debug_check_logs.php - Browser Setup
header('Content-Type: text/html; charset=utf-8');
require_once 'api.php';
$settings = getSettings();
$path = $settings['log_path'];

echo "<h1>Log File Diagnosis</h1>";
echo "<p><strong>Configured Path:</strong> " . htmlspecialchars($path) . "</p>";

if (!file_exists($path)) {
    die("<h2 style='color:red'>FILE NOT FOUND</h2>");
}

$stat = stat($path);
echo "<table border='1' cellpadding='5'>";
echo "<tr><td>Size</td><td>" . number_format($stat['size']) . " bytes (" . number_format($stat['size'] / 1024 / 1024, 2) . " MB)</td></tr>";
echo "<tr><td>Last Modified</td><td>" . date("Y-m-d H:i:s", $stat['mtime']) . " (" . (time() - $stat['mtime']) . "s ago)</td></tr>";
echo "<tr><td>Permissions</td><td>" . substr(sprintf('%o', $stat['mode']), -4) . "</td></tr>";
echo "</table>";

// List neighbor files
$dir = dirname($path);
echo "<h3>Files in Directory ($dir):</h3><ul>";
foreach (glob("$dir/rspamd*") as $f) {
    echo "<li>" . basename($f) . " (" . number_format(filesize($f)) . " bytes) - " . date("Y-m-d H:i:s", filemtime($f)) . "</li>";
}
echo "</ul>";

// Read Head
$handle = fopen($path, 'r');
$head = fread($handle, 1024);
echo "<h3>HEAD (First 1KB)</h3><pre style='background:#eee;padding:10px;'>" . htmlspecialchars($head) . "</pre>";

// Read Tail
fseek($handle, -1024, SEEK_END);
$tail = fread($handle, 1024);
echo "<h3>TAIL (Last 1KB)</h3><pre style='background:#eee;padding:10px;'>" . htmlspecialchars($tail) . "</pre>";
fclose($handle);
