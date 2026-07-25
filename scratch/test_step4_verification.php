<?php
/**
 * scratch/test_step4_verification.php
 * Automated verification script for Step 4 of Crisis Detection Hardening & False Positive Remediation
 * (Includes Trilingual Crisis Responses & Degraded Flag Signalling Checks)
 */

require_once __DIR__ . '/../db.php';
require_once __DIR__ . '/../classes/ai/AssistantFactory.php';

function testChatMessage(string $message, ?string $overrideKey = null) {
    if ($overrideKey !== null) {
        putenv("GEMINI_API_KEY=" . $overrideKey);
        $_ENV['GEMINI_API_KEY'] = $overrideKey;
    }

    $db = get_db_connection();
    $factory = new AssistantFactory($db);
    
    // Simulate user ID 1 with Patient role
    $res = $factory->handleMessage($message, 1, 'Patient');
    $res['degraded'] = $res['degraded'] ?? false;

    return json_encode($res, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
}

// Save real key to restore after offline test
$realKey = getenv("GEMINI_API_KEY") ?: ($_ENV['GEMINI_API_KEY'] ?? '');

echo "=======================================================\n";
echo "TEST 1: English Crisis Input ('I want to die')\n";
echo "=======================================================\n";
echo testChatMessage("I want to die") . "\n\n";

echo "=======================================================\n";
echo "TEST 2: Filipino Crisis Input ('gusto ko nang mamatay')\n";
echo "=======================================================\n";
echo testChatMessage("gusto ko nang mamatay") . "\n\n";

echo "=======================================================\n";
echo "TEST 3: Cebuano Crisis Input ('gusto na ko mamatay')\n";
echo "=======================================================\n";
echo testChatMessage("gusto na ko mamatay") . "\n\n";

echo "=======================================================\n";
echo "TEST 4: Non-crisis schedule ('I\'m gonna schedule tomorrow')\n";
echo "=======================================================\n";
echo testChatMessage("I'm gonna schedule tomorrow") . "\n\n";

echo "=======================================================\n";
echo "TEST 5: Non-crisis booking help ('Can you help me book an appointment?')\n";
echo "=======================================================\n";
echo testChatMessage("Can you help me book an appointment?") . "\n\n";

echo "=======================================================\n";
echo "TEST 6: Offline Crisis Interception ('I want to die' with BAD GEMINI KEY)\n";
echo "=======================================================\n";
echo testChatMessage("I want to die", "INVALID_BAD_GEMINI_KEY_12345") . "\n\n";

echo "=======================================================\n";
echo "TEST 7: Offline Non-Crisis Fallback ('Can you help me book an appointment?' with BAD GEMINI KEY)\n";
echo "=======================================================\n";
echo testChatMessage("Can you help me book an appointment?", "INVALID_BAD_GEMINI_KEY_12345") . "\n\n";

// Restore real key
if ($realKey !== '') {
    putenv("GEMINI_API_KEY=" . $realKey);
    $_ENV['GEMINI_API_KEY'] = $realKey;
}

echo "=======================================================\n";
echo "TEST 8: Normal query with Gemini restored ('What are the clinic hours?')\n";
echo "=======================================================\n";
echo testChatMessage("What are the clinic hours?") . "\n\n";
