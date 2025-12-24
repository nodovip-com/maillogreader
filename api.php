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
        echo json_encode(['logged_in' => isLoggedIn(), 'user' => $_SESSION['user'] ?? null]);
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
    default:
        echo json_encode(['error' => 'Invalid action']);
        break;
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
    $username = $input['username'] ?? '';
    $password = $input['password'] ?? '';

    if (login($username, $password)) {
        echo json_encode(['success' => true, 'user' => $username]);
    } else {
        echo json_encode(['success' => false, 'error' => 'Invalid credentials']);
    }
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

    $count = 0;
    $totalProcessed = 0;

    foreach ($data as $entry) {
        $parsed = parseRspamdEntry($entry);

        // Search
        if ($search) {
            $searchable = strtolower(json_encode($parsed));
            if (strpos($searchable, $search) === false)
                continue;
        }

        // Status Filter
        if ($filterStatus) {
            // Mapping UI status to Rspamd actions
            // UI: sent, error, deferred, info
            // Rspamd: 'no action' (~sent/info), 'reject' (error), 'soft reject' (deferred), 'add header' (info/rewrite)

            // Normalize for filter checking
            $entryAction = strtolower($parsed['status']); // using mapped status
            // Simple mapping check
            if ($filterStatus === 'error' && $entryAction !== 'reject')
                continue;
            if ($filterStatus === 'sent' && $entryAction !== 'no action')
                continue;
            // For now, loose filtering or just exact match if user types explicit action
            if ($filterStatus !== 'info' && strpos($entryAction, $filterStatus) === false && $filterStatus !== 'unknown') {
                // Maybe skip strict filtering for Rspamd initial implementation to avoid confusion
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

    $count = 0;
    $totalProcessed = 0;

    foreach ($lines as $line) {
        $parsed = parseLogLine($line);
        if (!$parsed)
            continue;

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
