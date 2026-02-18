<?php
error_reporting(E_ALL);
ini_set('display_errors', 0); // Hide raw errors from output
ini_set('memory_limit', '1024M'); // Further increase memory limit
set_time_limit(0); // Prevent script timeout during large file processing

require_once 'auth.php';

header('Content-Type: application/json');

// Custom error handler to return JSON
set_error_handler(function ($errno, $errstr, $errfile, $errline) {
    if (!(error_reporting() & $errno))
        return false;
    if (!headers_sent())
        header('Content-Type: application/json');
    echo json_encode(['success' => false, 'error' => "PHP Error [$errno]: $errstr in $errfile on line $errline"]);
    exit;
});

// Catch fatal errors
register_shutdown_function(function () {
    $error = error_get_last();
    if ($error !== NULL && ($error['type'] === E_ERROR || $error['type'] === E_PARSE || $error['type'] === E_CORE_ERROR || $error['type'] === E_COMPILE_ERROR)) {
        if (!headers_sent()) {
            header('Content-Type: application/json');
        }
        $msg = "PHP Fatal Error: {$error['message']} in {$error['file']} on line {$error['line']}";
        // Ensure even the error response is valid JSON
        echo json_encode(['success' => false, 'error' => $msg], JSON_PARTIAL_OUTPUT_ON_ERROR | JSON_INVALID_UTF8_SUBSTITUTE);
    }
});

define('SETTINGS_FILE', __DIR__ . '/settings.json');

$action = $_GET['action'] ?? '';
// --- Verified: No file_put_contents on line 37 ---
try {
    switch ($action) {
        case 'login':
            handleLogin();
            break;
        case 'logout':
            logout();
            echo json_encode(['success' => true]);
            break;
        case 'check_auth':
            echo json_encode([
                'logged_in' => isLoggedIn(),
                'user' => $_SESSION['user'] ?? null,
                'setup_required' => !hasUsers()
            ]);
            break;
        case 'setup_admin':
            handleSetupAdmin();
            break;
        case 'get_users':
            requireLogin();
            echo json_encode(array_keys(getUsers()));
            break;
        case 'add_user':
            requireLogin();
            handleAddUser();
            break;
        case 'delete_user':
            requireLogin();
            handleDeleteUser();
            break;
        case 'get_settings':
            requireLogin(); // Only logged in users can see settings
            echo json_encode(getSettings());
            break;
        case 'save_settings':
            requireLogin();
            handleSaveSettings();
            break;
        case 'get_logs':
            requireLogin();
            session_write_close(); // Release lock so other requests (like map/sync) don't block
            handleGetLogs();
            break;
        case 'change_password':
            requireLogin();
            handleChangePassword();
            break;
        case 'sync_logs':
            requireLogin();
            session_write_close(); // Release lock immediately
            handleSyncLogs();
            break;
        case 'test_db':
            requireLogin();
            handleTestDb();
            break;
        case 'get_available_dates':
            session_write_close(); // Public/Auth agnostic or read-only, safe to unlock
            handleGetAvailableDates();
            break;
        case 'get_ip_geo':
            handleGetIpGeo();
            break;
        case 'ping':
            echo json_encode(['success' => true, 'msg' => 'pong']);
            break;
        default:
            echo json_encode(['success' => false, 'error' => 'Invalid action']);
            break;
    }
} catch (Throwable $e) {
    if (!headers_sent())
        header('Content-Type: application/json');
    echo json_encode([
        'success' => false,
        'error' => 'Caught Exception: ' . $e->getMessage() . ' in ' . $e->getFile() . ':' . $e->getLine()
    ], JSON_PARTIAL_OUTPUT_ON_ERROR | JSON_INVALID_UTF8_SUBSTITUTE);
}

/**
 * Get MySQL connection, creating DB and tables if they don't exist.
 */
function getDbConnection($settings = null, &$errorMsg = null)
{
    if ($settings === null) {
        $settings = getSettings();
    }

    $db_host = $settings['db_host'] ?? '';
    $db_name = $settings['db_name'] ?? '';
    $db_user = $settings['db_user'] ?? '';
    $db_pass = $settings['db_pass'] ?? '';

    if (!$db_host || !$db_name || !$db_user) {
        return null;
    }

    try {
        // First try to connect to the server (without DB name) to check/create DB
        $dsn_no_db = "mysql:host=$db_host;charset=utf8mb4";
        $pdo = new PDO($dsn_no_db, $db_user, $db_pass, [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_TIMEOUT => 5
        ]);

        // Create database if not exists
        $pdo->exec("CREATE DATABASE IF NOT EXISTS `$db_name` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci");

        // Connect to the actual database
        $pdo = null;
        $dsn = "mysql:host=$db_host;dbname=$db_name;charset=utf8mb4";
        $pdo = new PDO($dsn, $db_user, $db_pass, [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_TIMEOUT => 5
        ]);

        // Create table if not exists using schema.sql content
        $tableExists = $pdo->query("SHOW TABLES LIKE 'mail_logs'")->rowCount() > 0;
        if (!$tableExists) {
            $schemaFile = __DIR__ . '/schema.sql';
            if (file_exists($schemaFile)) {
                $sql = file_get_contents($schemaFile);
                $pdo->exec($sql);
            }
        } else {
            // Schema Evolution: Check for missing columns
            $colCheck = $pdo->query("SHOW COLUMNS FROM `mail_logs` LIKE 'symbols'");
            if ($colCheck->rowCount() === 0) {
                $pdo->exec("ALTER TABLE `mail_logs` ADD COLUMN `symbols` TEXT AFTER `score` ");
            }
        }

        return $pdo;
    } catch (PDOException $e) {
        $errorMsg = $e->getMessage();
        error_log("Database Connection Error: " . $errorMsg);
        return null;
    }
}


function handleGetIpGeo()
{
    $input = json_decode(file_get_contents('php://input'), true);
    if (!is_array($input)) {
        echo json_encode([]);
        return;
    }

    // Proxy request to ip-api.com (HTTP)
    // Release session lock to allow parallel requests
    session_write_close();

    // We do this server-side to avoid Mixed Content (HTTPS -> HTTP) errors in the browser.
    $url = 'http://ip-api.com/batch';
    $options = [
        'http' => [
            'header' => "Content-type: application/json\r\n",
            'method' => 'POST',
            'content' => json_encode($input)
        ]
    ];
    $context = stream_context_create($options);
    $result = @file_get_contents($url, false, $context);

    if ($result === false) {
        echo json_encode([]);
    } else {
        echo $result;
    }
}

function handleGetAvailableDates()
{
    $settings = getSettings();
    $use_db = $settings['use_db'] ?? false;
    $dates = [];

    if ($use_db) {
        // Optimization: Check for cached dates file generated by cron_sync.php
        $cacheFile = __DIR__ . '/cache_dates.json';
        if (file_exists($cacheFile) && (time() - filemtime($cacheFile) < 3600)) { // 1 hour TTL validity check (just in case)
            echo file_get_contents($cacheFile);
            return;
        }

        $pdo = getDbConnection($settings);
        if ($pdo) {
            try {
                // Get unique dates from the mail_logs table
                $stmt = $pdo->query("SELECT DISTINCT DATE(timestamp) as log_date FROM mail_logs ORDER BY log_date DESC");
                while ($row = $stmt->fetch()) {
                    if ($row['log_date']) {
                        $dates[$row['log_date']] = true;
                    }
                }
                // Return immediately if we got dates from DB
                if (!empty($dates)) {
                    $json = json_encode(['dates' => array_keys($dates)], JSON_PARTIAL_OUTPUT_ON_ERROR | JSON_INVALID_UTF8_SUBSTITUTE);
                    // Save cache if it doesn't exist (e.g. first run before cron)
                    if (!file_exists($cacheFile))
                        file_put_contents($cacheFile, $json);
                    echo $json;
                    return;
                }
            } catch (PDOException $e) {
                error_log("Failed to get dates from DB: " . $e->getMessage());
            }
        }
    }

    // Fallback: File-based scanning if DB is disabled, empty, or fails
    $path = $settings['log_path'];
    if (!file_exists($path)) {
        echo json_encode(['dates' => array_keys($dates)]); // Might have some from DB if it was partially successful
        return;
    }

    $type = $settings['log_type'];
    if ($type === 'rspamd') {
        // Rspamd optimization
        $handle = fopen($path, "r");
        if ($handle) {
            while (($chunk = fread($handle, 1024 * 1024)) !== false) {
                if (preg_match_all('/"unix_time":\s*(\d+)/', $chunk, $matches)) {
                    foreach ($matches[1] as $ts) {
                        $dates[date('Y-m-d', (int) $ts)] = true;
                    }
                }
                if (feof($handle))
                    break;
            }
            fclose($handle);
        }
    } else {
        // Syslog optimization
        $handle = fopen($path, "r");
        if ($handle) {
            while (($line = fgets($handle)) !== false) {
                if (preg_match('/^([A-M][a-z]{2}\s+\d+)/', $line, $m)) {
                    $timestamp = strtotime($m[1]);
                    if ($timestamp) {
                        $dates[date('Y-m-d', $timestamp)] = true;
                    }
                }
            }
            fclose($handle);
        }
    }

    echo json_encode(['dates' => array_keys($dates)], JSON_PARTIAL_OUTPUT_ON_ERROR | JSON_INVALID_UTF8_SUBSTITUTE);
}

function getSettings()
{
    $defaults = [
        'log_type' => 'syslog',
        'log_path' => defined('LOG_FILE_PATH') ? LOG_FILE_PATH : 'dummy_mail.log',
        'db_host' => '',
        'db_name' => '',
        'db_user' => '',
        'db_pass' => '',
        'use_db' => false
    ];

    if (file_exists(SETTINGS_FILE)) {
        $saved = json_decode(file_get_contents(SETTINGS_FILE), true);
        if (is_array($saved)) {
            return array_merge($defaults, $saved);
        }
    }
    return $defaults;
}

function handleSaveSettings()
{
    $input = json_decode(file_get_contents('php://input'), true);
    // Support new fields including DB
    $fields = ['log_type', 'log_path', 'db_host', 'db_name', 'db_user', 'db_pass', 'use_db'];
    $data = getSettings();

    foreach ($fields as $field) {
        if (isset($input[$field])) {
            $data[$field] = $input[$field];
        }
    }

    // Basic validation
    if (!in_array($data['log_type'], ['syslog', 'rspamd'])) {
        echo json_encode(['success' => false, 'error' => 'Invalid log type']);
        return;
    }

    if (!is_writable(SETTINGS_FILE)) {
        echo json_encode(['success' => false, 'error' => 'Settings file is not writable. Please check permissions for: ' . SETTINGS_FILE]);
        return;
    }

    if (file_put_contents(SETTINGS_FILE, json_encode($data, JSON_PRETTY_PRINT))) {
        // If DB enabled, try to initialize it
        if ($data['use_db']) {
            $pdo = getDbConnection($data);
            if (!$pdo) {
                echo json_encode(['success' => true, 'warning' => 'Settings saved but could not connect to MySQL. Please check credentials.']);
                return;
            }
        }
        echo json_encode(['success' => true]);
    } else {
        echo json_encode(['success' => false, 'error' => 'Failed to write settings file. Path might be restricted.']);
    }
}

function handleChangePassword()
{
    $input = json_decode(file_get_contents('php://input'), true);
    $username = $_SESSION['user'];
    $oldPass = $input['old_password'] ?? '';
    $newPass = $input['new_password'] ?? '';

    if (!$oldPass || !$newPass) {
        echo json_encode(['success' => false, 'error' => 'Missing fields']);
        return;
    }

    $result = changePassword($username, $oldPass, $newPass);
    echo json_encode($result);
}

function handleLogin()
{
    $input = json_decode(file_get_contents('php://input'), true);
    $username = trim($input['username'] ?? '');
    $password = $input['password'] ?? '';
    // Code should also be trimmed (6 digits)
    $code = trim($input['code'] ?? '');

    // If setup is required, prevent login
    if (!hasUsers()) {
        echo json_encode(['success' => false, 'error' => 'Setup required', 'setup_required' => true]);
        return;
    }

    if (login($username, $password, $code)) {
        echo json_encode(['success' => true, 'user' => $username]);
    } else {
        echo json_encode(['success' => false, 'error' => 'Invalid credentials or MFA code']);
    }
}

function handleSetupAdmin()
{
    $input = json_decode(file_get_contents('php://input'), true);
    $username = trim($input['username'] ?? '');
    $password = $input['password'] ?? '';

    if (!$username || !$password) {
        echo json_encode(['success' => false, 'error' => 'Missing fields']);
        return;
    }

    echo json_encode(processFirstUser($username, $password));
}

function handleAddUser()
{
    $input = json_decode(file_get_contents('php://input'), true);
    $username = trim($input['username'] ?? '');
    $password = $input['password'] ?? '';

    if (!$username || !$password) {
        echo json_encode(['success' => false, 'error' => 'Missing fields']);
        return;
    }

    echo json_encode(addUser($username, $password));
}

function handleDeleteUser()
{
    $input = json_decode(file_get_contents('php://input'), true);
    $username = $input['username'] ?? ''; // Do not trim, so we can delete bad keys like "user "

    if (!$username) {
        echo json_encode(['success' => false, 'error' => 'Missing fields']);
        return;
    }

    if ($username === $_SESSION['user']) {
        echo json_encode(['success' => false, 'error' => 'Cannot delete yourself']);
        return;
    }

    echo json_encode(deleteUser($username));
}

function handleGetLogs()
{
    $settings = getSettings();
    $path = $settings['log_path'];
    $type = $settings['log_type'];
    $use_db = $settings['use_db'] ?? false;

    if ($use_db) {
        $dbError = null;
        $pdo = getDbConnection($settings, $dbError);
        if ($pdo) {
            $limit = isset($_GET['limit']) ? intval($_GET['limit']) : 100;
            $offset = isset($_GET['offset']) ? intval($_GET['offset']) : 0;
            $search = isset($_GET['search']) ? $_GET['search'] : '';
            $status = isset($_GET['status']) ? $_GET['status'] : '';
            $date = isset($_GET['date']) ? $_GET['date'] : '';

            $where = ["1=1"];
            $params = [];

            if ($search) {
                $where[] = "(message LIKE ? OR sender LIKE ? OR recipient LIKE ? OR host LIKE ? OR queue_id LIKE ?)";
                $searchParam = "%$search%";
                array_push($params, $searchParam, $searchParam, $searchParam, $searchParam, $searchParam);
            }

            if ($status) {
                if ($status === 'sent') {
                    $where[] = "status = 'success'";
                } else {
                    $where[] = "status = ?";
                    $params[] = $status;
                }
            }

            if ($date) {
                $where[] = "DATE(timestamp) = ?";
                $params[] = $date;
            }

            $whereStr = implode(" AND ", $where);
            $sql = "SELECT * FROM mail_logs WHERE $whereStr ORDER BY timestamp DESC LIMIT $limit OFFSET $offset";
            error_log("Fetching logs from DB. Query: $sql");
            $stmt = $pdo->prepare($sql);
            $stmt->execute($params);
            $logs = $stmt->fetchAll();
            error_log("Found " . count($logs) . " logs in DB.");

            // Format for frontend
            foreach ($logs as &$log) {
                if ($type === 'rspamd') {
                    // Try to decode symbols if it's a string from DB (depending on MySQL version/settings)
                    if (is_string($log['symbols'])) {
                        $log['symbols'] = json_decode($log['symbols'], true) ?? [];
                    }
                }
                // Ensure unix_time is int
                $log['unix_time'] = (int) $log['unix_time'];
                $log['timestamp'] = date('d-M-Y H:i:s', $log['unix_time']);
            }

            echo json_encode(['logs' => $logs, 'count' => count($logs), 'type' => 'db_' . $type]);
            return;
        } else {
            error_log("DB Enabled but connection failed: $dbError.");
            echo json_encode(['success' => false, 'error' => "Database connection failed: $dbError. Please check settings."]);
            return;
        }
    }

    // Fallback to file reading if DB not available or disabled
    error_log("Accessing file for logs: $path (exists: " . (file_exists($path) ? 'YES' : 'NO') . ")");

    if (!file_exists($path)) {
        $msg = "Log file not found: $path";
        if (isset($dbWarning))
            $msg = $dbWarning . " | " . $msg;
        echo json_encode(['error' => $msg]);
        exit;
    }

    if (!is_file($path)) {
        $msg = "Log path is not a valid file: $path";
        if (isset($dbWarning))
            $msg = $dbWarning . " | " . $msg;
        echo json_encode(['error' => $msg], JSON_PARTIAL_OUTPUT_ON_ERROR | JSON_INVALID_UTF8_SUBSTITUTE);
        exit;
    }

    if ($type === 'rspamd') {
        processRspamdLogs($path);
    } else {
        processSyslogLogs($path);
    }
}

function handleTestDb()
{
    $error = null;
    $pdo = getDbConnection(null, $error);
    if ($pdo) {
        echo json_encode(['success' => true, 'msg' => 'Connected successfully and database is ready.']);
    } else {
        echo json_encode(['success' => false, 'error' => 'Could not connect to database: ' . ($error ?? 'Unknown error')]);
    }
}

function handleSyncLogs()
{
    $settings = getSettings();
    $path = $settings['log_path'];
    $type = $settings['log_type'];
    $pdo = getDbConnection();

    if (!$pdo) {
        echo json_encode(['success' => false, 'error' => 'Database connection required for sync.']);
        return;
    }

    // Prevent concurrent syncs using a lock file
    $lockFile = __DIR__ . '/sync.lock';
    $fp = fopen($lockFile, 'w+');
    if (!flock($fp, LOCK_EX | LOCK_NB)) {
        // Lock failed, another sync is running
        fclose($fp);
        echo json_encode(['success' => false, 'error' => 'Sync already in progress. Skipping.']);
        return;
    }

    // Default to Lazy/Smart sync to prevent timeouts (504)
    // Only do a full, deep scan if explicitly requested (e.g., via CLI or special admin action)
    $forceFull = isset($_GET['full_resync']) && $_GET['full_resync'] === '1';
    $isLazy = !$forceFull;

    // Attempt to close connection to client immediately if supported
    if (function_exists('fastcgi_finish_request')) {
        session_write_close();
        echo json_encode(['success' => true, 'msg' => 'Sync started in background.']);
        fastcgi_finish_request();
    } else {
        // If we can't background it, we MUST be fast.
        // We rely on the smart/lazy logic below to keep execution < 5s.
        if ($isLazy) {
            // For manual UI clicks without fastcgi, just return success after the quick sync
            // We don't echo here to avoid breaking JSON response if we echo again later
        }
    }

    $filesToProcess = [$path];
    // CRITICAL FIX: Never process .1 (rotated) files in a web request unless explicitly forced.
    // This was causing the 504 Gateway Timeouts by trying to parse tens of thousands of old lines.
    // File .1 processing removed as per user request.

    $totalImported = 0;
    foreach ($filesToProcess as $file) {
        // Increase memory for this operation
        @ini_set('memory_limit', '512M');

        if ($type === 'rspamd') {
            $totalImported += syncRspamdFile($file, $pdo, $isLazy);
        } else {
            $totalImported += syncSyslogFile($file, $pdo, $isLazy);
        }
    }

    // Release lock
    flock($fp, LOCK_UN);
    fclose($fp);

    if (!function_exists('fastcgi_finish_request')) {
        echo json_encode(['success' => true, 'imported' => $totalImported]);
    }
}

function syncRspamdFile($path, $pdo, $lazy = false)
{
    if (!file_exists($path))
        return 0;

    $data = [];

    // Improved Logic: Use Regex to extract individual JSON objects regardless of file structure
    // This handles both array-wrapped files "[{...},{...}]" and newline-delimited "{...}\n{...}"
    // It also robustly handles partial reads from tail.

    $contentToParse = '';

    if ($lazy && filesize($path) > 5 * 1024 * 1024) {
        $handle = fopen($path, 'r');
        fseek($handle, -5 * 1024 * 1024, SEEK_END); // Read last 5MB
        $contentToParse = fread($handle, 5 * 1024 * 1024);
        fclose($handle);
    } else {
        $contentToParse = file_get_contents($path);
    }

    if (!$contentToParse)
        return 0;

    // Optimized Regex Extraction (Fastest) with increased limits
    // We abandoned the manual character loop because it was too slow (~43s in PHP).
    // PCRE is C-optimized and can parse 5MB in milliseconds if we raise the limits.

    @ini_set('pcre.backtrack_limit', '10000000');
    @ini_set('pcre.recursion_limit', '10000000');

    // Recursive pattern matches balanced braces { ... { ... } }
    $pattern = '/\{(?:[^{}]|(?R))*\}/';

    if (preg_match_all($pattern, $contentToParse, $matches)) {
        foreach ($matches[0] as $jsonStr) {
            $obj = json_decode($jsonStr, true);
            if ($obj && isset($obj['unix_time'])) {
                $data[] = $obj;
            }
        }
    }

    // Process found data
    if (empty($data))
        return 0;

    if (!is_array($data))
        return 0;

    $stmt = $pdo->prepare("INSERT IGNORE INTO mail_logs (
        log_hash, timestamp, unix_time, host, component, message, status, action, 
        score, symbols, queue_id, sender, recipient, size, user, scan_time
    ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)");

    $count = 0;
    // If lazy, we only process the last entries to be super fast
    if ($lazy) {
        $data = array_slice($data, -500);
    }

    $chunkSize = 1000;
    $chunks = array_chunk($data, $chunkSize);

    foreach ($chunks as $chunk) {
        $pdo->beginTransaction();
        try {
            foreach ($chunk as $entry) {
                if (!isset($entry['unix_time']))
                    continue;
                $parsed = parseRspamdEntry($entry);
                $logHash = $entry['message-id'] ?? md5(json_encode($entry));

                $stmt->execute([
                    $logHash,
                    date('Y-m-d H:i:s', $parsed['unix_time']),
                    $parsed['unix_time'],
                    $parsed['host'],
                    $parsed['component'],
                    $parsed['message'],
                    $parsed['status'],
                    $parsed['action'],
                    $parsed['score'],
                    json_encode($parsed['symbols']),
                    $parsed['queue_id'],
                    $parsed['sender'],
                    $parsed['recipient'],
                    $parsed['size'],
                    $parsed['user'],
                    $parsed['scan_time']
                ]);
                if ($stmt->rowCount() > 0)
                    $count++;
            }
            $pdo->commit();
        } catch (Exception $e) {
            $pdo->rollBack();
            error_log("Sync failed in chunk: " . $e->getMessage());
        }
    }
    return $count;
}

function syncSyslogFile($path, $pdo, $lazy = false)
{
    if (!file_exists($path))
        return 0;
    $handle = fopen($path, 'r');
    if (!$handle)
        return 0;

    // Handle lazy sync by reading only the end of the file
    if ($lazy && filesize($path) > 1024 * 1024) {
        fseek($handle, -1024 * 1024, SEEK_END);
        fgets($handle); // Discard partial line
    }

    $stmt = $pdo->prepare("INSERT IGNORE INTO mail_logs (
        log_hash, timestamp, unix_time, host, component, message, status, 
        queue_id, sender, recipient
    ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)");

    $count = 0;
    // Process in smaller chunks to be friendly to memory/time
    while (!feof($handle)) {
        $pdo->beginTransaction();
        try {
            $chunkCount = 0;
            while ($chunkCount < 1000 && ($line = fgets($handle)) !== false) {
                $line = trim($line);
                if (empty($line))
                    continue;

                $parsed = parseLogLine($line);
                $logHash = hash('sha256', $line);

                $stmt->execute([
                    $logHash,
                    date('Y-m-d H:i:s', $parsed['unix_time']),
                    $parsed['unix_time'],
                    $parsed['host'],
                    $parsed['component'],
                    $parsed['message'],
                    $parsed['status'],
                    $parsed['queue_id'],
                    $parsed['sender'],
                    $parsed['recipient']
                ]);
                if ($stmt->rowCount() > 0)
                    $count++;
                $chunkCount++;
            }
            $pdo->commit();
        } catch (Exception $e) {
            $pdo->rollBack();
            error_log("Sync failed in syslog chunk: " . $e->getMessage());
            break;
        }
        if (feof($handle) || ($lazy && $count > 2000))
            break;
    }
    fclose($handle);
    return $count;
}

function processRspamdLogs($path)
{
    @ini_set('memory_limit', '1024M');
    $files = [$path];
    if (file_exists($path . '.1')) {
        $files[] = $path . '.1';
    }

    $allData = [];
    $errors = [];
    foreach ($files as $file) {
        if (!file_exists($file))
            continue;
        if (!is_readable($file)) {
            $errors[] = "Permission denied: $file";
            continue;
        }
        $json = file_get_contents($file);
        if ($json === false) {
            $errors[] = "Failed to read: $file";
            continue;
        }
        $decoded = json_decode($json, true);
        if (json_last_error() !== JSON_ERROR_NONE) {
            $errors[] = "JSON Parse Error ($file): " . json_last_error_msg();
            continue;
        }
        if (is_array($decoded)) {
            $allData = array_merge($allData, $decoded);
        }
    }

    if (empty($allData)) {
        $msg = !empty($errors) ? implode(" | ", $errors) : "No log entries found in files.";
        echo json_encode(['logs' => [], 'count' => 0, 'type' => 'rspamd', 'warning' => $msg]);
        return;
    }

    // Sort all records by unix_time DESC (newest first)
    usort($allData, function ($a, $b) {
        return ($b['unix_time'] ?? 0) <=> ($a['unix_time'] ?? 0);
    });

    $parsedLogs = [];
    $limit = isset($_GET['limit']) ? intval($_GET['limit']) : 100;
    $offset = isset($_GET['offset']) ? intval($_GET['offset']) : 0;
    $search = isset($_GET['search']) ? strtolower($_GET['search']) : '';
    $filterStatus = isset($_GET['status']) ? $_GET['status'] : '';
    $filterDate = isset($_GET['date']) ? $_GET['date'] : '';

    $count = 0;
    $totalProcessed = 0;

    foreach ($allData as $entry) {
        // Date Filter
        if ($filterDate && isset($entry['unix_time'])) {
            $entryDate = date('Y-m-d', $entry['unix_time']);
            if ($entryDate !== $filterDate)
                continue;
        }

        $parsed = parseRspamdEntry($entry);

        // Search
        if ($search) {
            $searchable = strtolower(json_encode($parsed));
            if (strpos($searchable, $search) === false)
                continue;
        }

        // Status Filter
        if ($filterStatus) {
            $entryStatus = $parsed['status'];
            if ($filterStatus === 'sent' && $entryStatus === 'success') {
                // Keep
            } elseif ($filterStatus === 'error' && $entryStatus === 'error') {
                // Keep
            } elseif ($filterStatus === 'deferred' && $entryStatus === 'deferred') {
                // Keep
            } elseif ($filterStatus === 'info' || $filterStatus === '' || $filterStatus === 'unknown') {
                // Keep
            } else {
                if ($filterStatus !== $entryStatus)
                    continue;
            }
        }

        if ($totalProcessed >= $offset && $count < $limit) {
            $parsedLogs[] = $parsed;
            $count++;
        }
        $totalProcessed++;

        if ($count >= $limit)
            break;
    }

    echo json_encode(['logs' => $parsedLogs, 'count' => count($parsedLogs), 'type' => 'rspamd'], JSON_PARTIAL_OUTPUT_ON_ERROR | JSON_INVALID_UTF8_SUBSTITUTE);
}

function parseRspamdEntry($entry)
{
    // Map Rspamd JSON to our internal structure
    $timestamp = date('d-M-Y H:i:s', $entry['unix_time']);
    $action = $entry['action'];

    // Map action to status classes
    $status = 'info';
    if ($action === 'reject')
        $status = 'error';
    elseif ($action === 'no action')
        $status = 'success';
    elseif ($action === 'soft reject' || $action === 'greylist')
        $status = 'deferred';

    $sender = $entry['sender_mime'] ?? $entry['sender_smtp'] ?? 'unknown';
    $recipients = $entry['rcpt_mime'] ?? $entry['rcpt_smtp'] ?? [];
    $recipient = is_array($recipients) ? implode(', ', $recipients) : $recipients;

    return [
        'timestamp' => $timestamp,
        'unix_time' => $entry['unix_time'], // Pass raw timestamp for frontend TZ
        'host' => $entry['ip'] ?? 'unknown',
        'component' => 'rspamd',
        'message' => $entry['subject'] ?? '(No Subject)',
        'status' => $status, // For color coding
        'action' => $action, // Real action name
        'score' => $entry['score'] ?? 0,
        'symbols' => $entry['symbols'] ?? [],
        'queue_id' => $entry['message-id'] ?? '',
        'sender' => $sender,
        'recipient' => $recipient,
        'size' => $entry['size'] ?? 0,
        'user' => $entry['user'] ?? '',
        'scan_time' => $entry['time_real'] ?? 0
    ];
}

function processSyslogLogs($path)
{
    $files = [$path];
    if (file_exists($path . '.1')) {
        $files[] = $path . '.1';
    }

    $parsedLogs = [];
    $limit = isset($_GET['limit']) ? intval($_GET['limit']) : 100;
    $offset = isset($_GET['offset']) ? intval($_GET['offset']) : 0;
    $search = isset($_GET['search']) ? strtolower($_GET['search']) : '';
    $filterStatus = isset($_GET['status']) ? $_GET['status'] : '';
    $filterDate = isset($_GET['date']) ? $_GET['date'] : '';

    $count = 0;
    $totalProcessed = 0;

    $allFilesFailed = true;
    $errors = [];

    foreach ($files as $file) {
        if (!file_exists($file))
            continue;
        if (!is_readable($file)) {
            $errors[] = "Permission denied: $file";
            continue;
        }
        $handle = fopen($file, 'r');
        if (!$handle) {
            $errors[] = "Could not open handle: $file";
            continue;
        }

        $allFilesFailed = false;
        fseek($handle, 0, SEEK_END);
        $pos = ftell($handle);
        $buffer = "";
        $chunkSize = 8192;

        while ($pos > 0 && $count < $limit) {
            $readSize = min($pos, $chunkSize);
            $pos -= $readSize;
            fseek($handle, $pos);
            $chunk = fread($handle, $readSize);
            $buffer = $chunk . $buffer;

            $lines = explode("\n", $buffer);
            $buffer = array_shift($lines);

            for ($i = count($lines) - 1; $i >= 0; $i--) {
                $line = trim($lines[$i]);
                if (empty($line))
                    continue;

                $parsed = parseLogLine($line);
                if (!$parsed)
                    continue;

                if ($filterDate) {
                    $ts = strtotime($parsed['timestamp']);
                    if (!$ts || date('Y-m-d', $ts) !== $filterDate)
                        continue;
                }

                if ($search) {
                    if (strpos(strtolower($line), $search) === false)
                        continue;
                }

                if ($filterStatus) {
                    if ($parsed['status'] !== $filterStatus)
                        continue;
                } else {
                    if (!$search && ($parsed['status'] === 'info' || $parsed['status'] === 'unknown'))
                        continue;
                }

                if ($totalProcessed >= $offset) {
                    $parsedLogs[] = $parsed;
                    $count++;
                }
                $totalProcessed++;

                if ($count >= $limit)
                    break 2;
            }
        }
        fclose($handle);
    }

    $response = ['logs' => $parsedLogs, 'count' => count($parsedLogs), 'type' => 'syslog'];
    if ($allFilesFailed && !empty($errors)) {
        $response['warning'] = implode(" | ", $errors);
    }
    echo json_encode($response, JSON_PARTIAL_OUTPUT_ON_ERROR | JSON_INVALID_UTF8_SUBSTITUTE);
}

function parseLogLine($line)
{
    // Existing regex logic
    if (preg_match('/^([A-M][a-z]{2}\s+\d+\s\d{2}:\d{2}:\d{2})\s+(\S+)\s+([^:]+):\s+(.*)$/', $line, $matches)) {
        $log = [
            'raw' => $line,
            'timestamp' => $matches[1],
            'unix_time' => strtotime($matches[1]), // Calculated for frontend TZ conversion
            'host' => $matches[2],
            'component' => $matches[3],
            'message' => $matches[4],
            'status' => 'info',
            'queue_id' => '',
            'sender' => '',
            'recipient' => ''
        ];

        $message = $log['message'];

        if (preg_match('/([A-F0-9]{10,12}):/', $message, $qMatches)) {
            $log['queue_id'] = $qMatches[1];
        }

        if (preg_match('/status=([a-zA-Z]+)/', $message, $sMatches)) {
            $log['status'] = $sMatches[1];
        } elseif (preg_match('/warning:/i', $message)) {
            $log['status'] = 'warning';
        } elseif (preg_match('/error:/i', $message) || preg_match('/failed/i', $message)) {
            $log['status'] = 'error';
        }

        if (preg_match('/from=<([^>]+)>/', $message, $fMatches)) {
            $log['sender'] = $fMatches[1];
        }

        if (preg_match('/to=<([^>]+)>/', $message, $tMatches)) {
            $log['recipient'] = $tMatches[1];
        }

        if (preg_match('/Login attempt for:\s+[\'"]?([^\s\'"\/]+)/', $message, $lMatches)) {
            $log['sender'] = $lMatches[1];
            if (preg_match('/success/', $message))
                $log['status'] = 'success';
            elseif (preg_match('/failed/', $message))
                $log['status'] = 'failed';
        }

        return $log;
    }

    return [
        'raw' => $line,
        'timestamp' => '',
        'host' => '',
        'component' => 'unknown',
        'message' => $line,
        'status' => 'unknown',
        'queue_id' => '',
        'sender' => '',
        'recipient' => ''
    ];
}
