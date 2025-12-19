<?php
require_once 'auth.php';

header('Content-Type: application/json');

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
    case 'get_logs':
        requireLogin();
        handleGetLogs();
        break;
    default:
        echo json_encode(['error' => 'Invalid action']);
        break;
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
    if (!file_exists(LOG_FILE_PATH)) {
        echo json_encode(['error' => 'Log file not found: ' . LOG_FILE_PATH]);
        exit;
    }

    $lines = file(LOG_FILE_PATH, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
    // Reverse lines to show newest first
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

        // Filtering
        if ($search) {
            $jsonParsed = json_encode($parsed);
            if (strpos(strtolower($jsonParsed), $search) === false) {
                continue;
            }
        }

        if ($filterStatus) {
            // Strict filter if selected
            if ($filterStatus === 'info') {
                // Special case: if user explicitly wants info, maybe show info only?
                // The dropdown says "Info (Show All)"? No, "Info" is usually just info status.
                // If user wants EVERYTHING, we need a specific flag. 
                // However, the prompt is: if I search by ID, show me everything.
                if ($parsed['status'] !== 'info') {
                    // actually if it's strictly 'info', we lose 'sent'.
                    // Let's rely on the search check below.
                }
            }

            if ($parsed['status'] !== $filterStatus) {
                continue;
            }
        } else {
            // Default behavior: Filter out noise (info, unknown)... 
            // BUT if we are searching (e.g. by ID), we likely want to see those hidden logs too if they match the ID.
            if (!$search) {
                if ($parsed['status'] === 'info' || $parsed['status'] === 'unknown') {
                    continue;
                }
            }
        }

        // Pagination window
        if ($totalProcessed >= $offset && $count < $limit) {
            $parsedLogs[] = $parsed;
            $count++;
        }
        $totalProcessed++;

        if ($count >= $limit)
            break; // Optimization: stop after limit reached (if not simple paging)
        // Note: For true pagination with accurate total counts we'd need to parse everything, but for logs usually infinite scroll/load more is okay.
    }

    echo json_encode(['logs' => $parsedLogs, 'count' => count($parsedLogs)]);
}

function parseLogLine($line)
{
    // Regex based on the provided sample:
    // Dec 15 13:15:05 srv mailu-smtp[4062297]: Dec 15 05:15:05 srv postfix/qmgr[376]: EA17210E775E: from=<...>, size=..., nrcpt=1 (queue active)

    // We try to capture the component and message.
    // The format seems to vary slightly, but generally starts with Syslog Header.

    // Pattern explanation:
    // ^([A-M][a-z]{2}\s+\d+\s\d{2}:\d{2}:\d{2})\s+(\S+)\s+([^:]+):\s+(.*)$
    // 1: Timestamp (Dec 15 13:15:05)
    // 2: Host (srv)
    // 3: Service/Component (mailu-smtp[4062297])
    // 4: Message (Dec 15 05:15:05 srv postfix/qmgr[376]: EA17210E775E: ...)

    if (preg_match('/^([A-M][a-z]{2}\s+\d+\s\d{2}:\d{2}:\d{2})\s+(\S+)\s+([^:]+):\s+(.*)$/', $line, $matches)) {
        $log = [
            'raw' => $line,
            'timestamp' => $matches[1],
            'host' => $matches[2],
            'component' => $matches[3],
            'message' => $matches[4],
            'status' => 'info', // Default
            'queue_id' => '',
            'sender' => '',
            'recipient' => ''
        ];

        // Further parsing of the message to extract details
        $message = $log['message'];

        // Extract Queue ID if present (hexdigit string followed by colon)
        if (preg_match('/([A-F0-9]{10,12}):/', $message, $qMatches)) {
            $log['queue_id'] = $qMatches[1];
        }

        // Extract Status
        if (preg_match('/status=([a-zA-Z]+)/', $message, $sMatches)) {
            $log['status'] = $sMatches[1]; // deferred, sent, bounced, etc.
        } elseif (preg_match('/warning:/i', $message)) {
            $log['status'] = 'warning';
        } elseif (preg_match('/error:/i', $message) || preg_match('/failed/i', $message)) {
            $log['status'] = 'error';
        }

        // Extract Sender (from=<...>)
        if (preg_match('/from=<([^>]+)>/', $message, $fMatches)) {
            $log['sender'] = $fMatches[1];
        }

        // Extract Recipient (to=<...>)
        if (preg_match('/to=<([^>]+)>/', $message, $tMatches)) {
            $log['recipient'] = $tMatches[1];
        }

        // Login attempts
        if (preg_match('/Login attempt for:\s+[\'"]?([^\s\'"\/]+)/', $message, $lMatches)) {
            $log['sender'] = $lMatches[1]; // Treat user as sender for login logs
            if (preg_match('/success/', $message)) {
                $log['status'] = 'success';
            } elseif (preg_match('/failed/', $message)) {
                $log['status'] = 'failed';
            }
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
