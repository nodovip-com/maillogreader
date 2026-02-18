<?php
// Test script for Hybrid Tokenizer Parser
// Matches logic in api.php syncRspamdFile

echo "Testing Hybrid Tokenizer Parser...\n";

// Mock Data: A mix of complete objects, partial updates, and strings with special chars
$dummyInfos = [
    ['unix_time' => 1708280001, 'message-id' => 'msg1'],
    ['unix_time' => 1708280002, 'message-id' => 'msg2_with_brace_{_inside'],
    ['unix_time' => 1708280003, 'message-id' => 'msg3_with_quote_"_inside']
];

$contentToParse = '';
foreach ($dummyInfos as $info) {
    $contentToParse .= json_encode($info) . "\n";
}
// Add some garbage at the end to simulate partial write
$contentToParse .= '{"incomplete": true, "unix_ti';

echo "Input Length: " . strlen($contentToParse) . "\n";

// --- PASTE LOGIC FROM api.php ---

preg_match_all('/["{}\\\\]/', $contentToParse, $matches, PREG_OFFSET_CAPTURE);

$tokens = $matches[0];
$braceDepth = 0;
$startPos = -1;
$inString = false;
$countTokens = count($tokens);
$data = [];

for ($i = 0; $i < $countTokens; $i++) {
    $char = $tokens[$i][0];
    $offset = $tokens[$i][1];

    // Handle String State
    if ($inString) {
        if ($char === '"') {
            $escaped = false;
            $backslashes = 0;
            $j = 1;
            while (($offset - $j) >= 0 && $contentToParse[$offset - $j] === '\\') {
                $backslashes++;
                $j++;
            }
            if ($backslashes % 2 === 1) {
                $escaped = true;
            }

            if (!$escaped) {
                $inString = false;
            }
        }
        continue;
    }

    if ($char === '"') {
        $inString = true;
        continue;
    }

    if ($char === '{') {
        if ($braceDepth === 0) {
            $startPos = $offset;
        }
        $braceDepth++;
    } elseif ($char === '}') {
        if ($braceDepth > 0) {
            $braceDepth--;
            if ($braceDepth === 0) {
                $len = $offset - $startPos + 1;
                $jsonStr = substr($contentToParse, $startPos, $len);

                $obj = json_decode($jsonStr, true);
                if ($obj && isset($obj['unix_time'])) {
                    $data[] = $obj;
                }
                $startPos = -1;
            }
        }
    }
}

// --- END LOGIC ---

echo "Found " . count($data) . " objects.\n";
foreach ($data as $idx => $item) {
    echo "Item $idx: " . $item['message-id'] . "\n";
}

if (count($data) === 3) {
    echo "TEST PASSED.\n";
} else {
    echo "TEST FAILED.\n";
}
