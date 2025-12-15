<?php
require_once 'config.php';

session_start();

function login($username, $password)
{
    global $users;
    if (isset($users[$username]) && $users[$username] === $password) {
        $_SESSION['user'] = $username;
        $_SESSION['logged_in'] = true;
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
        header('HTTP/1.0 403 Forbidden');
        echo json_encode(['error' => 'Authentication required']);
        exit;
    }
}
