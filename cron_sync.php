<?php
// cron_sync.php - CLI Script for Background Log Synchronization
// Usage: php cron_sync.php

// Ensure we are running from CLI
if (php_sapi_name() !== 'cli') {
    die("Access Denied: This script can only be run from the command line.\n");
}

// Define entry point constant to control api.php behavior if needed
define('CRON_MODE', true);

// Suppress JSON headers and output during inclusion
ob_start();
$_GET['action'] = ''; // Prevent api.php from executing a switch case
require_once 'api.php';
ob_end_clean();

echo "[" . date('Y-m-d H:i:s') . "] Starting Log Sync...\n";

// Capture the output of the sync function
ob_start();
handleSyncLogs();
$jsonResponse = ob_get_clean();

$response = json_decode($jsonResponse, true);

if (isset($response['success']) && $response['success']) {
    $imported = $response['imported'] ?? 0;
    if (isset($response['msg'])) {
        echo "Status: " . $response['msg'] . "\n";
    }
    echo "Success: Imported $imported new log entries.\n";
} else {
    echo "Error: " . ($response['error'] ?? 'Unknown error') . "\n";
    echo "Raw Response: " . substr($jsonResponse, 0, 200) . "...\n";
}

echo "[" . date('Y-m-d H:i:s') . "] Sync Completed.\n";
