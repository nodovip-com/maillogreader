<?php
require_once 'auth.php';
$users = getUsers();
echo "Users File: " . USERS_FILE_PATH . "\n";
if (empty($users)) {
    echo "No users found.\n";
} else {
    foreach ($users as $u => $d) {
        $secret = $d['secret'] ?? 'MISSING';
        $hash = $d['hash'] ?? 'MISSING';
        echo "User: [$u]\n";
        echo "  - Hash: " . (strlen($hash) > 0 ? "Present" : "Empty") . "\n";
        echo "  - Secret: " . $secret . " (Len: " . strlen($secret) . ")\n";
    }
}
