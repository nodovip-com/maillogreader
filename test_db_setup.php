<?php
require_once 'api.php';

echo "--- Database Verification ---\n";

$settings = getSettings();
echo "Current Settings:\n";
print_r($settings);

if ($settings['use_db']) {
    echo "\nAttempting to connect to database...\n";
    $pdo = getDbConnection($settings);
    if ($pdo) {
        echo "SUCCESS: Connected to database and initialized schema.\n";

        $stmt = $pdo->query("SELECT COUNT(*) FROM mail_logs");
        $count = $stmt->fetchColumn();
        echo "Current log count in DB: $count\n";
    } else {
        echo "FAILURE: Could not connect to database. Check credentials in settings.json.\n";
    }
} else {
    echo "\nDatabase usage is currently DISABLED in settings.\n";
}
