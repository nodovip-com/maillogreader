<?php
require_once 'auth.php';

header('Content-Type: application/json');

define('SETTINGS_FILE', __DIR__ . '/settings.json');

$action = $_GET['action'] ?? '';

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
    case 'get_available_dates':
        handleGetAvailableDates();
        break;
    default:
        echo json_encode(['success' => false, 'error' => 'Invalid action']);
        break;
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
        $json = file_get_contents($path);
        $data = json_decode($json, true);
        if (is_array($data)) {
            foreach ($data as $entry) {
                if (isset($entry['unix_time'])) {
                    $d = date('Y-m-d', $entry['unix_time']);
                    $dates[$d] = true;
                }
            }
        }
    } else {
        // Syslog
        $handle = fopen($path, "r");
        if ($handle) {
            // Read lines. If file is huge this is slow, but we need to scan all for calendar.
            // Optimization: Read chunks or just assume regex works.
            while (($line = fgets($handle)) !== false) {
                if (preg_match('/^([A-M][a-z]{2}\s+\d+)/', $line, $m)) {
                    // "Dec 29" -> Current Year assumed
                    // Use checkdate to be safe? Or just strtotime.
                    // Syslog doesn't have year. We assume logs are recent (this year).
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

    echo json_encode(['dates' => array_keys($dates)]);
}

function getSettings()
{
    $defaults = [
        'log_type' => 'syslog',
        'log_path' => defined('LOG_FILE_PATH') ? LOG_FILE_PATH : 'dummy_mail.log'
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
    if (!isset($input['log_type']) || !isset($input['log_path'])) {
        echo json_encode(['success' => false, 'error' => 'Missing fields']);
        return;
    }

    // Basic validation
    if (!in_array($input['log_type'], ['syslog', 'rspamd'])) {
        echo json_encode(['success' => false, 'error' => 'Invalid log type']);
        return;
    }

    if (file_put_contents(SETTINGS_FILE, json_encode($input, JSON_PRETTY_PRINT))) {
        echo json_encode(['success' => true]);
    } else {
        echo json_encode(['success' => false, 'error' => 'Failed to write settings file']);
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

    if (!file_exists($path)) {
        echo json_encode(['error' => 'Log file not found: ' . $path]);
        exit;
    }

    if ($type === 'rspamd') {
        processRspamdLogs($path);
    } else {
        processSyslogLogs($path);
    }
}

function processRspamdLogs($path)
{
    // Rspamd logs are usually a large JSON array.
    // Reading entire file into memory might be heavy if huge, but for now we assume it fits like the text log.
    $json = file_get_contents($path);
    $data = json_decode($json, true);

    if (!is_array($data)) {
        echo json_encode(['error' => 'Invalid JSON in log file']);
        return;
    }

    // Reverse to show newest first? 
    // Usually standard Rspamd history is newest first? Or oldest? 
    // JSON arrays have order. Assuming we want newest (top) first.
    // Let's assume the JSON is chronologically appended (oldest first). So reverse it.
    $data = array_reverse($data);

    $parsedLogs = [];
    $limit = isset($_GET['limit']) ? intval($_GET['limit']) : 100;
    $offset = isset($_GET['offset']) ? intval($_GET['offset']) : 0;
    $search = isset($_GET['search']) ? strtolower($_GET['search']) : '';
    // Status filter in Rspamd = Action? (reject, no action, etc.)
    $filterStatus = isset($_GET['status']) ? $_GET['status'] : '';
    $filterDate = isset($_GET['date']) ? $_GET['date'] : '';

    $count = 0;
    $totalProcessed = 0;

    foreach ($data as $entry) {
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

    echo json_encode(['logs' => $parsedLogs, 'count' => count($parsedLogs), 'type' => 'rspamd']);
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
    if (!file_exists($path)) {
        // Fallback for empty/missing
        echo json_encode(['logs' => [], 'count' => 0]);
        return;
    }

    $lines = file($path, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
    $lines = array_reverse($lines);

    $parsedLogs = [];
    $limit = isset($_GET['limit']) ? intval($_GET['limit']) : 100;
    $offset = isset($_GET['offset']) ? intval($_GET['offset']) : 0;
    $search = isset($_GET['search']) ? strtolower($_GET['search']) : '';
    $filterStatus = isset($_GET['status']) ? $_GET['status'] : '';
    $filterDate = isset($_GET['date']) ? $_GET['date'] : '';

    $count = 0;
    $totalProcessed = 0;

    foreach ($lines as $line) {
        $parsed = parseLogLine($line);
        if (!$parsed)
            continue;

        if ($filterDate) {
            // parsed['timestamp'] is "Dec 29 12:00:00"
            // assumes current year.
            $ts = strtotime($parsed['timestamp']);
            if ($ts) {
                $entryDate = date('Y-m-d', $ts);
                if ($entryDate !== $filterDate)
                    continue;
            }
        }

        if ($search) {
            $jsonParsed = json_encode($parsed);
            if (strpos(strtolower($jsonParsed), $search) === false)
                continue;
        }

        if ($filterStatus) {
            if ($filterStatus === 'info' && $parsed['status'] !== 'info') {
                // loose logic
            }
            if ($parsed['status'] !== $filterStatus)
                continue;
        } else {
            if (!$search && ($parsed['status'] === 'info' || $parsed['status'] === 'unknown'))
                continue;
        }

        if ($totalProcessed >= $offset && $count < $limit) {
            $parsedLogs[] = $parsed;
            $count++;
        }
        $totalProcessed++;
        if ($count >= $limit)
            break;
    }

    echo json_encode(['logs' => $parsedLogs, 'count' => count($parsedLogs), 'type' => 'syslog']);
}

function parseLogLine($line)
{
    // Existing regex logic
    if (preg_match('/^([A-M][a-z]{2}\s+\d+\s\d{2}:\d{2}:\d{2})\s+(\S+)\s+([^:]+):\s+(.*)$/', $line, $matches)) {
        $log = [
            'raw' => $line,
            'timestamp' => $matches[1],
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
