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
            handleGetLogs();
            break;
        case 'change_password':
            requireLogin();
            handleChangePassword();
            break;
        case 'sync_logs':
            requireLogin();
            handleSyncLogs();
            break;
        case 'test_db':
            requireLogin();
            handleTestDb();
            break;
        case 'get_available_dates':
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
function getDbConnection($settings = null)
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
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC
        ]);

        // Create database if not exists
        $pdo->exec("CREATE DATABASE IF NOT EXISTS `$db_name` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci");

        // Connect to the actual database
        $pdo = null;
        $dsn = "mysql:host=$db_host;dbname=$db_name;charset=utf8mb4";
        $pdo = new PDO($dsn, $db_user, $db_pass, [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC
        ]);

        // Create table if not exists using schema.sql content
        $tableExists = $pdo->query("SHOW TABLES LIKE 'mail_logs'")->rowCount() > 0;
        if (!$tableExists) {
            $schemaFile = __DIR__ . '/schema.sql';
            if (file_exists($schemaFile)) {
                $sql = file_get_contents($schemaFile);
                $pdo->exec($sql);
            }
        }

        return $pdo;
    } catch (PDOException $e) {
        error_log("Database Connection Error: " . $e->getMessage());
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
    $path = $settings['log_path'];

    if (!file_exists($path)) {
        echo json_encode(['dates' => []]);
        return;
    }

    $dates = [];
    $type = $settings['log_type'];

    if ($type === 'rspamd') {
        // Rspamd - Optimized: Do not decode the whole JSON just for dates.
        // Scan for "unix_time": 123456789 using regex.
        $handle = fopen($path, "r");
        if ($handle) {
            $fsize = filesize($path);
            $chunk_size = 1024 * 1024; // 1MB chunks

            while (($chunk = fread($handle, $chunk_size)) !== false) {
                if (preg_match_all('/"unix_time":\s*(\d+)/', $chunk, $matches)) {
                    foreach ($matches[1] as $ts) {
                        $d = date('Y-m-d', (int) $ts);
                        $dates[$d] = true;
                    }
                }
                if (feof($handle))
                    break;
            }
            fclose($handle);
        }
    } else {
        // Syslog - Optimized: Scan for dates in the whole file
        $handle = fopen($path, "r");
        if ($handle) {
            while (($line = fgets($handle)) !== false) {
                if (preg_match('/^([A-M][a-z]{2}\s+\d+)/', $line, $m)) {
                    $timestamp = strtotime($m[1]);
                    if ($timestamp) {
                        $d = date('Y-m-d', $timestamp);
                        $dates[$d] = true;
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
        $pdo = getDbConnection();
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
            $stmt = $pdo->prepare($sql);
            $stmt->execute($params);
            $logs = $stmt->fetchAll();

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
        }
    }

    // Fallback to file reading if DB not available or disabled
    if (!file_exists($path)) {
        echo json_encode(['error' => 'Log file not found: ' . $path]);
        exit;
    }

    if (!is_file($path)) {
        echo json_encode(['error' => 'Log path is not a valid file: ' . $path], JSON_PARTIAL_OUTPUT_ON_ERROR | JSON_INVALID_UTF8_SUBSTITUTE);
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
    $pdo = getDbConnection();
    if ($pdo) {
        echo json_encode(['success' => true, 'msg' => 'Connected successfully and database is ready.']);
    } else {
        echo json_encode(['success' => false, 'error' => 'Could not connect to database. Check credentials.']);
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

    $filesToProcess = [$path];
    if (file_exists($path . '.1')) {
        $filesToProcess[] = $path . '.1';
    }

    $totalImported = 0;

    foreach ($filesToProcess as $file) {
        if ($type === 'rspamd') {
            $totalImported += syncRspamdFile($file, $pdo);
        } else {
            $totalImported += syncSyslogFile($file, $pdo);
        }
    }

    echo json_encode(['success' => true, 'imported' => $totalImported]);
}

function syncRspamdFile($path, $pdo)
{
    $json = file_get_contents($path);
    if (!$json)
        return 0;
    $data = json_decode($json, true);
    if (!is_array($data))
        return 0;

    $stmt = $pdo->prepare("INSERT IGNORE INTO mail_logs (
        log_hash, timestamp, unix_time, host, component, message, status, action, 
        score, symbols, queue_id, sender, recipient, size, user, scan_time
    ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)");

    $count = 0;
    foreach ($data as $entry) {
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
    return $count;
}

function syncSyslogFile($path, $pdo)
{
    $handle = fopen($path, 'r');
    if (!$handle)
        return 0;

    $stmt = $pdo->prepare("INSERT IGNORE INTO mail_logs (
        log_hash, timestamp, unix_time, host, component, message, status, 
        queue_id, sender, recipient
    ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)");

    $count = 0;
    while (($line = fgets($handle)) !== false) {
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
    }
    fclose($handle);
    return $count;
}

function processRspamdLogs($path)
{
    // Rspamd logs can be large. 26MB JSON can consume 200MB+ RAM.
    $fsize = filesize($path);

    // Attempt to set memory limit even higher if possible
    @ini_set('memory_limit', '1024M');

    if ($fsize > 30 * 1024 * 1024) {
        // If file is very large, reading the whole thing is dangerous.
        // For now, we try since we have 1GB limit attempt.
    }

    $json = file_get_contents($path);
    if ($json === false) {
        echo json_encode(['error' => 'Could not read log file: ' . $path]);
        return;
    }

    $data = json_decode($json, true);
    if (json_last_error() !== JSON_ERROR_NONE) {
        echo json_encode(['error' => 'JSON Parse Error: ' . json_last_error_msg() . '. File size: ' . $fsize . ' bytes.']);
        return;
    }

    if (!is_array($data)) {
        echo json_encode(['error' => 'Invalid JSON in log file (not an array)']);
        return;
    }

    // Process in reverse without array_reverse() to save a bit of peak memory
    $totalLogs = count($data);
    $parsedLogs = [];
    $limit = isset($_GET['limit']) ? intval($_GET['limit']) : 100;
    $offset = isset($_GET['offset']) ? intval($_GET['offset']) : 0;
    $search = isset($_GET['search']) ? strtolower($_GET['search']) : '';
    $filterStatus = isset($_GET['status']) ? $_GET['status'] : '';
    $filterDate = isset($_GET['date']) ? $_GET['date'] : '';

    $count = 0;
    $totalProcessed = 0;

    // Start from the end
    for ($i = $totalLogs - 1; $i >= 0; $i--) {
        $entry = $data[$i];
        // Date Filter (Optimized check before parsing completely if possible, but parsing needed for time)
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
            // Parsed['status'] is already normalized in parseRspamdEntry:
            // 'success' (no action), 'error' (reject), 'deferred' (soft reject), 'info' (others)

            $entryStatus = $parsed['status'];

            // Direct comparison for common filters
            if ($filterStatus === 'sent' && $entryStatus === 'success') {
                // Keep
            } elseif ($filterStatus === 'error' && $entryStatus === 'error') {
                // Keep
            } elseif ($filterStatus === 'deferred' && $entryStatus === 'deferred') {
                // Keep
            } elseif ($filterStatus === 'info' || $filterStatus === '' || $filterStatus === 'unknown') {
                // info shows everything or specifics? 
                // IF user purposely chose "warning" or "bounced" (which dont map well to Rspamd), we might filter them out or show none.
                // Let's allow loose matching if it's not one of the strict ones above.
                if ($filterStatus === 'info') {
                    // Show all is usually empty string filter, but if specific 'info' selected, technically all are info?
                    // Or just keep logic simple:
                }
            } else {
                // If filter is specific (e.g. 'bounced') but entry is 'success', skip.
                // We don't have 'bounced' in Rspamd usually.
                // If filter doesn't match entry's status, skip.
                if ($filterStatus !== $entryStatus) {
                    continue;
                }
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
    // Optimized syslog reading: Read backwards from end of file
    $handle = fopen($path, 'r');
    if (!$handle) {
        echo json_encode(['logs' => [], 'count' => 0]);
        return;
    }

    $parsedLogs = [];
    $limit = isset($_GET['limit']) ? intval($_GET['limit']) : 100;
    $offset = isset($_GET['offset']) ? intval($_GET['offset']) : 0;
    $search = isset($_GET['search']) ? strtolower($_GET['search']) : '';
    $filterStatus = isset($_GET['status']) ? $_GET['status'] : '';
    $filterDate = isset($_GET['date']) ? $_GET['date'] : '';

    $count = 0;
    $totalProcessed = 0;

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
        // The first element might be a partial line, keep it for the next iteration
        $buffer = array_shift($lines);

        // Process lines in reverse order
        for ($i = count($lines) - 1; $i >= 0; $i--) {
            $line = trim($lines[$i]);
            if (empty($line))
                continue;

            $parsed = parseLogLine($line);
            if (!$parsed)
                continue;

            // Date Filter
            if ($filterDate) {
                $ts = strtotime($parsed['timestamp']);
                if ($ts) {
                    $entryDate = date('Y-m-d', $ts);
                    if ($entryDate !== $filterDate)
                        continue;
                } else {
                    continue;
                }
            }

            // Search Filter
            if ($search) {
                $searchable = strtolower($line); // Search raw line for performance
                if (strpos($searchable, $search) === false)
                    continue;
            }

            // Status Filter
            if ($filterStatus) {
                if ($parsed['status'] !== $filterStatus)
                    continue;
            } else {
                // If no search, hide 'info' and 'unknown' logs
                if (!$search && ($parsed['status'] === 'info' || $parsed['status'] === 'unknown')) {
                    continue;
                }
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

    echo json_encode(['logs' => $parsedLogs, 'count' => count($parsedLogs), 'type' => 'syslog'], JSON_PARTIAL_OUTPUT_ON_ERROR | JSON_INVALID_UTF8_SUBSTITUTE);
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
