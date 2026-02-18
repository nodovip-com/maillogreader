<?php
require_once 'config.php';

session_start();

// --- MFA Helper Class (TOTP) ---
class MfaHelper
{
    public static function generateSecret($length = 16)
    {
        $chars = 'ABCDEFGHIJKLMNOPQRSTUVWXYZ234567'; // Base32
        $secret = '';
        for ($i = 0; $i < $length; $i++) {
            $secret .= $chars[random_int(0, strlen($chars) - 1)];
        }
        return $secret;
    }

    public static function verifyCode($secret, $code)
    {
        if (strlen($code) != 6)
            return false;
        // Verify current window and previous/next window (30s skew)
        for ($i = -1; $i <= 1; $i++) {
            if (self::getCode($secret, $i) === $code)
                return true;
        }
        return false;
    }

    private static function getCode($secret, $timeSliceOffset = 0)
    {
        $timestamp = floor(time() / 30) + $timeSliceOffset;
        $secretKey = self::base32Decode($secret);

        $binaryTimestamp = pack('N*', 0) . pack('N*', $timestamp);
        $hash = hash_hmac('sha1', $binaryTimestamp, $secretKey, true);

        $offset = ord($hash[19]) & 0xf;
        $otp = (
            ((ord($hash[$offset + 0]) & 0x7f) << 24) |
            ((ord($hash[$offset + 1]) & 0xff) << 16) |
            ((ord($hash[$offset + 2]) & 0xff) << 8) |
            (ord($hash[$offset + 3]) & 0xff)
        ) % 1000000;

        return str_pad((string) $otp, 6, '0', STR_PAD_LEFT);
    }

    private static function base32Decode($base32)
    {
        $base32 = strtoupper($base32);
        $chars = 'ABCDEFGHIJKLMNOPQRSTUVWXYZ234567';
        $binary = '';
        for ($i = 0; $i < strlen($base32); $i++) {
            $val = strpos($chars, $base32[$i]);
            if ($val === false)
                continue;
            $binary .= sprintf('%05b', $val);
        }
        $binaryLength = strlen($binary);
        $result = '';
        for ($i = 0; $i < $binaryLength; $i += 8) {
            if ($i + 8 > $binaryLength)
                break;
            $result .= chr(bindec($val = substr($binary, $i, 8)));
        }
        return $result;
    }
}

// --- Auth Functions ---

function login($username, $password, $code)
{
    $users = getUsers();
    if (!isset($users[$username]))
        return false;

    $userData = $users[$username];

    // Check Password Hash
    if (!password_verify($password, $userData['hash'])) {
        return false;
    }

    // Check MFA
    if (!MfaHelper::verifyCode($userData['secret'], $code)) {
        return false;
    }

    $_SESSION['logged_in'] = true;
    $_SESSION['user'] = $username;
    return true;
}

function logout()
{
    session_destroy();
}

function isLoggedIn()
{
    return isset($_SESSION['logged_in']) && $_SESSION['logged_in'] === true;
}

function requireLogin()
{
    // Allow CLI scripts (cron jobs) to bypass login
    if (php_sapi_name() === 'cli') {
        return;
    }

    if (!isLoggedIn()) {
        http_response_code(403);
        echo json_encode(['error' => 'Unauthorized']);
        exit;
    }
}

// --- User Management ---

function getUsers()
{
    if (!file_exists(USERS_FILE_PATH))
        return [];
    $json = file_get_contents(USERS_FILE_PATH);
    return json_decode($json, true) ?? [];
}

function saveUsers($users)
{
    $result = file_put_contents(USERS_FILE_PATH, json_encode($users, JSON_PRETTY_PRINT));
    return $result !== false;
}

function hasUsers()
{
    $users = getUsers();
    return !empty($users);
}

function addUser($username, $password)
{
    $users = getUsers();
    if (isset($users[$username])) {
        return ['success' => false, 'error' => 'User already exists'];
    }

    $secret = MfaHelper::generateSecret();
    $hash = password_hash($password, PASSWORD_DEFAULT);

    $users[$username] = [
        'hash' => $hash,
        'secret' => $secret
    ];

    if (saveUsers($users)) {
        return ['success' => true, 'secret' => $secret];
    }
    return ['success' => false, 'error' => 'Failed to save user'];
}

function changePassword($username, $oldPassword, $newPassword)
{
    $users = getUsers();
    if (!isset($users[$username])) {
        return ['success' => false, 'error' => 'User not found'];
    }

    // Verify old
    if (!password_verify($oldPassword, $users[$username]['hash'])) {
        return ['success' => false, 'error' => 'Invalid current password'];
    }

    // Update
    $users[$username]['hash'] = password_hash($newPassword, PASSWORD_DEFAULT);
    saveUsers($users);

    return ['success' => true];
}

function deleteUser($username)
{
    $users = getUsers();
    if (!isset($users[$username])) {
        return ['success' => false, 'error' => 'User not found'];
    }

    // Prevent deleting last user
    if (count($users) <= 1) {
        return ['success' => false, 'error' => 'Cannot delete the last user'];
    }

    unset($users[$username]);
    if (saveUsers($users)) {
        return ['success' => true];
    }
    return ['success' => false, 'error' => 'Failed to save changes'];
}

function processFirstUser($username, $password)
{
    if (hasUsers()) {
        return ['success' => false, 'error' => 'Setup already completed'];
    }

    // Reuse addUser logic locally but force save as first
    $secret = MfaHelper::generateSecret();
    $hash = password_hash($password, PASSWORD_DEFAULT);

    $users = [
        $username => [
            'hash' => $hash,
            'secret' => $secret
        ]
    ];

    if (saveUsers($users)) {
        return ['success' => true, 'secret' => $secret];
    }
    return ['success' => false, 'error' => 'Failed to create admin user. Check permissions.'];
}
