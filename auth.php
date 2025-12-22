<?php
require_once 'config.php';

session_start();

function login($username, $password)
{
    $users = getUsers();
    if (isset($users[$username]) && $users[$username] === $password) {
        $_SESSION['logged_in'] = true;
        $_SESSION['user'] = $username;
        return true;
    }
    return false;
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
    if (!isLoggedIn()) {
        http_response_code(403);
        echo json_encode(['error' => 'Unauthorized']);
        exit;
    }
}

// User Management Functions
function getUsers()
{
    if (!file_exists(USERS_FILE_PATH)) {
        return [];
    }
    $json = file_get_contents(USERS_FILE_PATH);
    return json_decode($json, true) ?? [];
}

function saveUsers($users)
{
    $result = file_put_contents(USERS_FILE_PATH, json_encode($users, JSON_PRETTY_PRINT));
    return $result !== false;
}

function changePassword($username, $oldPassword, $newPassword)
{
    $users = getUsers();

    // Check if user exists
    if (!isset($users[$username])) {
        return ['success' => false, 'error' => 'User not found'];
    }

    // Verify old password
    if ($users[$username] !== $oldPassword) {
        return ['success' => false, 'error' => 'Invalid current password'];
    }

    // Update password
    $users[$username] = $newPassword;
    if (!saveUsers($users)) {
        return ['success' => false, 'error' => 'Failed to save new password. Check file permissions.'];
    }

    return ['success' => true];
}
