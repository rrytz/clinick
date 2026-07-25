<?php
/**
 * scratch/test_staff_capability_audit.php
 * Empirical Verification Audit for Frontdesk Assistant Capabilities
 */

require_once __DIR__ . '/../db.php';
require_once __DIR__ . '/../classes/ai/SecurityGuard.php';
require_once __DIR__ . '/../classes/ai/Tools/ToolRegistry.php';
require_once __DIR__ . '/../classes/ai/Tools/StaffTools.php';
require_once __DIR__ . '/../classes/ai/AssistantFactory.php';

$db = get_db_connection();
$security = new SecurityGuard($db);
$tools = new ToolRegistry($db);
$factory = new AssistantFactory($db);

$staffUserId = 3; // Staff role user ID
$role = 'Staff';

echo "======================================================================\n";
echo "  EMPIRICAL CAPABILITY AUDIT: FRONTDESK ASSISTANT\n";
echo "======================================================================\n\n";

$capabilities = [
    'Capability 1 — Live Queue Overview' => [
        'tool_name' => 'getClinicQueueOverview',
        'test_messages' => ['Show clinic queue', 'Queue status', 'How many patients are waiting?'],
        'params' => []
    ],
    'Capability 2 — Walk-in Registration Guide' => [
        'tool_name' => 'getAvailableDoctors',
        'test_messages' => ['Register a walk-in patient', 'How do I create a walk-in appointment?'],
        'params' => []
    ],
    'Capability 3 — Patient Check-in Support' => [
        'tool_name' => 'checkInPatient',
        'test_messages' => ['Check in patient Rivera', 'Patient arrived', 'Mark patient present'],
        'params' => ['appointment_id' => 999] // intentional test ID
    ],
    'Capability 4 — Patient Lookup' => [
        'tool_name' => 'searchPatientByName',
        'test_messages' => ['Find patient Rivera', 'Search patient john@email.com'],
        'params' => ['query' => 'Test']
    ],
    'Capability 5 — Doctor Availability' => [
        'tool_name' => 'getAvailableDoctors',
        'test_messages' => ['Available doctors', 'Who is on duty today?', 'Doctor schedules'],
        'params' => []
    ],
];

foreach ($capabilities as $capName => $capData) {
    echo "----------------------------------------------------------------------\n";
    echo "{$capName}\n";
    echo "----------------------------------------------------------------------\n";

    $toolName = $capData['tool_name'];

    // 1. Declarations check
    $declarations = $tools->getDeclarationsForRole($role);
    $isDeclared = false;
    foreach ($declarations as $decl) {
        if (($decl['name'] ?? '') === $toolName) {
            $isDeclared = true;
            break;
        }
    }
    echo "1. Declared in Role Schema: " . ($isDeclared ? "YES" : "NO") . "\n";

    // 2. SecurityGuard check
    $secAllowed = false;
    try {
        $secAllowed = $security->isToolAllowed($toolName, $role);
    } catch (Exception $e) {
        $secAllowed = false;
    }
    echo "2. SecurityGuard Allowed: " . ($secAllowed ? "YES" : "NO") . "\n";

    // 3. ToolRegistry Execution Trace
    $execResult = null;
    try {
        $execResult = $tools->executeToolCall($toolName, $capData['params'], $staffUserId, $role, 1);
        echo "3. ToolRegistry Execution: SUCCESS\n";
        echo "   └─ Real Output: " . json_encode($execResult, JSON_UNESCAPED_SLASHES) . "\n";
    } catch (Exception $e) {
        echo "3. ToolRegistry Execution: FAILED (" . $e->getMessage() . ")\n";
    }

    // 4. Natural Language AssistantFactory Trace
    echo "4. Natural Language Response Trace:\n";
    foreach ($capData['test_messages'] as $msg) {
        $res = $factory->handleMessage($msg, $staffUserId, $role, 1);
        $replySnippet = str_replace("\n", " ", substr($res['reply'] ?? '', 0, 120));
        $toolsUsed = implode(', ', $res['tool_calls_executed'] ?? []);
        echo "   • Input: \"{$msg}\"\n";
        echo "     Tools Executed: [{$toolsUsed}]\n";
        echo "     Assistant Reply: {$replySnippet}...\n";
    }
    echo "\n";
}
