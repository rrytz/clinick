<?php
/**
 * scratch/test_staff_remediation_verification.php
 * Empirical Runtime Verification for Phase 4 Staff Remediation
 */

require_once __DIR__ . '/../db.php';
require_once __DIR__ . '/../classes/ai/Tools/ToolRegistry.php';
require_once __DIR__ . '/../classes/ai/SecurityGuard.php';
require_once __DIR__ . '/../classes/ai/AssistantFactory.php';
require_once __DIR__ . '/../ChatbotService_AI.php';

$db = get_db_connection();
$registry = new ToolRegistry($db);
$security = new SecurityGuard($db);
$aiService = new ChatbotService_AI($db);

$staffId = 16;
$role = 'Staff';

$passed = 0;
$failed = 0;

function assertCheck(string $name, bool $condition, string $detail = '') {
    global $passed, $failed;
    if ($condition) {
        echo "✅ [PASS] $name\n";
        if ($detail) echo "   └─ $detail\n";
        $passed++;
    } else {
        echo "❌ [FAIL] $name\n";
        if ($detail) echo "   └─ $detail\n";
        $failed++;
    }
}

echo "======================================================================\n";
echo "  EMPIRICAL VERIFICATION: PHASE 4 STAFF REMEDIATION                   \n";
echo "======================================================================\n\n";

// --- TEST 1: STAFF DECLARATIONS MATCH SECURITYGUARD ---
$declarations = $registry->getDeclarationsForRole('Staff');
$declaredNames = array_column($declarations, 'name');

echo "1. Staff Declarations Schema Check:\n";
echo "   Declared tools: " . implode(', ', $declaredNames) . "\n\n";

assertCheck(
    "Declarations do NOT contain unauthorized Admin tools (getWeeklyStats)",
    !in_array('getWeeklyStats', $declaredNames)
);
assertCheck(
    "Declarations contain new frontdesk tools (getClinicQueueOverview)",
    in_array('getClinicQueueOverview', $declaredNames)
);
assertCheck(
    "Declarations contain new search tool (searchPatientByName)",
    in_array('searchPatientByName', $declaredNames)
);

// --- TEST 2: STAFF TOOL EXECUTION MATRIX ---
echo "\n2. Staff Tool Execution Matrix Check:\n";

$staffToolsToTest = [
    'getAvailableDoctors',
    'getDoctorSchedule',
    'getClinicQueueOverview',
    'searchPatientByName',
    'checkInPatient',
    'getDailyStats',
    'getPendingApprovals',
];

$validAppId = (int)($db->querySingle("SELECT id FROM appointments WHERE status IN ('Scheduled', 'Approved', 'In Progress') LIMIT 1") ?? 1);

foreach ($staffToolsToTest as $tName) {
    $args = match ($tName) {
        'searchPatientByName' => ['query' => 'Test'],
        'getDoctorSchedule'   => ['doctor_id' => 6, 'date' => date('Y-m-d')],
        'checkInPatient'      => ['appointment_id' => $validAppId],
        default               => [],
    };
    $res = $registry->executeToolCall($tName, $args, $staffId, $role);
    $isDispatched = !isset($res['error']) || !str_contains($res['error'], 'Tool handler implementation');
    assertCheck(
        "Tool Execution & Dispatch for '$tName'",
        $isDispatched,
        "Result: " . json_encode($res)
    );
}

// --- TEST 3: END-TO-END STAFF QUICK ACTION TRACE ---
echo "\n3. End-to-End Staff Quick Action Button Trace:\n";

$staffActions = [
    'Patient check-in',
    'Search patient',
    'Queue status',
    'Walk-in guide'
];

foreach ($staffActions as $action) {
    $res = $aiService->respond($action, $staffId, $role);
    $reply = $res['reply'] ?? '';
    
    assertCheck(
        "Quick Action '$action' returns Frontdesk Assistant",
        $res['assistant_name'] === 'Frontdesk Assistant' && $res['role'] === 'Staff'
    );
    assertCheck(
        "Quick Action '$action' reply does NOT contain Patient self-ticket text",
        !str_contains($reply, 'You currently have no active queue ticket')
    );
    assertCheck(
        "Quick Action '$action' reply does NOT contain appointment-booking language",
        !str_contains($reply, 'help you book an appointment')
    );
}

echo "\n----------------------------------------------------------------------\n";
echo "SUMMARY: Passed $passed / " . ($passed + $failed) . " verification tests.\n";
echo "----------------------------------------------------------------------\n";
