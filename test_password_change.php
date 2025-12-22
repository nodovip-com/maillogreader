<?php
require_once 'auth.php';

echo "Testing Password Change...\n";
echo "Users file: " . USERS_FILE_PATH . "\n";
echo "Writable: " . (is_writable(USERS_FILE_PATH) ? 'Yes' : 'No') . "\n";

// Test 1: Wrong password
$res = changePassword('admin', 'wrongpass', 'newpass');
echo "Test 1 (Wrong Pass): " . json_encode($res) . "\n";

// Test 2: Correct password (changing secret123 -> newsecret123)
// Note: Resetting afterwards to avoid breaking login if it works
$res = changePassword('admin', 'secret123', 'newsecret123');
echo "Test 2 (Correct Pass): " . json_encode($res) . "\n";

if ($res['success']) {
    echo "Password changed successfully. Reverting...\n";
    changePassword('admin', 'newsecret123', 'secret123');
}
?>