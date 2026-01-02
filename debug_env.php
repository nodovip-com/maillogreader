<?php
header('Content-Type: text/plain');

echo "--- Mail Log Reader Diagnostic ---\n\n";

// PHP Info
echo "PHP Version: " . phpversion() . "\n";
echo "Current User: " . get_current_user() . " (UID: " . getmyuid() . ")\n";
echo "Operating System: " . PHP_OS . "\n";

// Check config and settings
require_once 'config.php';
define('SETTINGS_FILE', __DIR__ . '/settings.json');

function getSettings()
{
    $defaults = [
        'log_type' => 'syslog',
        'log_path' => defined('LOG_FILE_PATH') ? LOG_FILE_PATH : 'dummy_mail.log'
    ];
    if (file_exists(SETTINGS_FILE)) {
        $saved = json_decode(file_get_contents(SETTINGS_FILE), true);
        if (is_array($saved)) {
            return array_merge($defaults, $saved);
        }
    }
    return $defaults;
}

$settings = getSettings();
$logPath = $settings['log_path'];
$logType = $settings['log_type'];

echo "\n--- Configuration ---\n";
echo "Log Type: $logType\n";
echo "Log Path: $logPath\n";

echo "\n--- File System Check ---\n";
if (file_exists($logPath)) {
    echo "Log file exists: YES\n";
    $perms = fileperms($logPath);
    echo "Permissions: " . substr(sprintf('%o', $perms), -4) . "\n";

    if (is_readable($logPath)) {
        echo "Readable by PHP: YES\n";
        $handle = fopen($logPath, 'r');
        if ($handle) {
            $firstLine = fgets($handle);
            if ($firstLine !== false) {
                echo "Successfully read first line: YES\n";
                echo "First line starts with: " . substr($firstLine, 0, 50) . "...\n";
            } else {
                echo "Successfully read first line: NO (Empty file?)\n";
            }
            fclose($handle);
        } else {
            echo "Successfully opened file: NO\n";
        }
    } else {
        echo "Readable by PHP: NO\n";
        echo "Suggestion: Check if the web server user (e.g. www-data) has read permissions for this file.\n";
    }
} else {
    echo "Log file exists: NO\n";
    echo "Suggestion: Check the path in Settings or verify Docker volume mapping.\n";
}

// Memory limit check
echo "\n--- PHP Settings ---\n";
echo "Memory Limit: " . ini_get('memory_limit') . "\n";
echo "Open Basedir: " . (ini_get('open_basedir') ?: 'None') . "\n";

echo "\n--- End of Diagnostic ---\n";
