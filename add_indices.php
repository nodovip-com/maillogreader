<?php
// add_indices.php - Add missing indices for performance
require_once 'api.php';

echo "Checking Database Indices...\n";
$settings = getSettings();
$pdo = getDbConnection($settings);

if (!$pdo) {
    die("Use DB not enabled or connection failed.\n");
}

$start = microtime(true);

try {
    // Check if indices exist
    $indices = $pdo->query("SHOW INDEX FROM mail_logs")->fetchAll();
    $existing = [];
    foreach ($indices as $idx) {
        $existing[] = $idx['Key_name'];
    }

    if (!in_array('idx_timestamp', $existing)) {
        echo "Adding index on 'timestamp'...\n";
        $pdo->exec("CREATE INDEX idx_timestamp ON mail_logs(timestamp)");
        echo "Index 'idx_timestamp' added.\n";
    } else {
        echo "Index 'idx_timestamp' already exists.\n";
    }

    if (!in_array('idx_unix_time', $existing)) {
        echo "Adding index on 'unix_time'...\n";
        $pdo->exec("CREATE INDEX idx_unix_time ON mail_logs(unix_time)");
        echo "Index 'idx_unix_time' added.\n";
    } else {
        echo "Index 'idx_unix_time' already exists.\n";
    }

} catch (PDOException $e) {
    echo "Error: " . $e->getMessage() . "\n";
}

$end = microtime(true);
echo "Done in " . number_format($end - $start, 4) . "s.\n";
