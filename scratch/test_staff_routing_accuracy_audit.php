<?php
/**
 * scratch/test_staff_routing_accuracy_audit.php
 * Comprehensive AI Routing Accuracy & Anti-Enumeration Stress Test
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
$staffTools = new StaffTools($db);

$staffUserId = 3;
$role = 'Staff';

echo "======================================================================\n";
echo "  STRESS TEST: FRONTDESK AI ROUTING & ANTI-ENUMERATION AUDIT\n";
echo "======================================================================\n\n";

$passCount = 0;
$totalTests = 0;

function assertTest(bool $condition, string $description, string $details = '') {
    global $passCount, $totalTests;
    $totalTests++;
    if ($condition) {
        $passCount++;
        echo "✅ [PASS] {$description}\n";
        if (!empty($details)) echo "   └─ {$details}\n";
    } else {
        echo "❌ [FAIL] {$description}\n";
        if (!empty($details)) echo "   └─ {$details}\n";
    }
}

// --------------------------------------------------------------------
// TEST SECTION 1: Anti-Enumeration & Minimum Search Query Constraints
// --------------------------------------------------------------------
echo "1. Anti-Enumeration & PHI Protection Constraints:\n";

$shortRes = $staffTools->searchPatientByName(['query' => 'a'], $staffUserId);
assertTest(
    isset($shortRes['error']) && str_contains($shortRes['error'], 'at least 2 characters'),
    "Single-character search query rejected ('a')",
    $shortRes['error'] ?? ''
);

$bulkRes1 = $staffTools->searchPatientByName(['query' => 'show me all patients'], $staffUserId);
assertTest(
    isset($bulkRes1['error']) && str_contains($bulkRes1['error'], 'Bulk patient enumeration is disabled'),
    "Bulk search phrase rejected ('show me all patients')",
    $bulkRes1['error'] ?? ''
);

$bulkRes2 = $staffTools->searchPatientByName(['query' => 'show all'], $staffUserId);
assertTest(
    isset($bulkRes2['error']) && str_contains($bulkRes2['error'], 'Bulk patient enumeration is disabled'),
    "Wildcard enumeration query rejected ('show all')",
    $bulkRes2['error'] ?? ''
);

$validRes = $staffTools->searchPatientByName(['query' => 'Test'], $staffUserId);
assertTest(
    isset($validRes['match_count']) && $validRes['match_count'] > 0,
    "Valid 4-character search accepted ('Test')",
    "Match count: " . ($validRes['match_count'] ?? 0)
);

echo "\n";

// --------------------------------------------------------------------
// TEST SECTION 2: Check-In Ambiguity & Multi-Match Safety
// --------------------------------------------------------------------
echo "2. Check-In Ambiguity & Multi-Match Resolution:\n";

// Create test appointments for today with same patient name snippet
$today = date('Y-m-d');
$db->exec("INSERT INTO appointments (patient_id, doctor_id, appointment_date, time_slot, status) VALUES (32, 6, '{$today}', '09:00 AM', 'Scheduled')");
$appId1 = $db->lastInsertRowID();

$db->exec("INSERT INTO appointments (patient_id, doctor_id, appointment_date, time_slot, status) VALUES (32, 6, '{$today}', '02:00 PM', 'Scheduled')");
$appId2 = $db->lastInsertRowID();

$ambigCheck = $staffTools->checkInPatient(['patient_name' => 'TestFirstName'], $staffUserId);
assertTest(
    isset($ambigCheck['ambiguous']) && $ambigCheck['ambiguous'] === true && count($ambigCheck['matches']) >= 2,
    "Multiple scheduled appointments trigger ambiguity alert rather than mutating wrong record",
    "Matches found: " . count($ambigCheck['matches'] ?? [])
);

$singleCheck = $staffTools->checkInPatient(['appointment_id' => $appId1], $staffUserId);
assertTest(
    isset($singleCheck['success']) && $singleCheck['success'] === true && $singleCheck['status'] === 'In Progress',
    "Explicit Appointment ID check-in succeeds",
    "Appointment #{$appId1} status: " . ($singleCheck['status'] ?? '')
);

// Clean up test appointments
$db->exec("DELETE FROM appointments WHERE id IN ({$appId1}, {$appId2})");

echo "\n";

// --------------------------------------------------------------------
// TEST SECTION 3: Queue Overview Scaling Test (50 Mock Appointments)
// --------------------------------------------------------------------
echo "3. Queue Overview Scaling & Performance (Mock Dataset):\n";

$createdIds = [];
for ($i = 0; $i < 30; $i++) {
    $db->exec("INSERT INTO appointments (patient_id, doctor_id, appointment_date, time_slot, status) VALUES (32, 6, '{$today}', '10:00 AM', 'Scheduled')");
    $createdIds[] = $db->lastInsertRowID();
}
for ($i = 0; $i < 20; $i++) {
    $db->exec("INSERT INTO appointments (patient_id, doctor_id, appointment_date, time_slot, status) VALUES (33, 16, '{$today}', '11:00 AM', 'In Progress')");
    $createdIds[] = $db->lastInsertRowID();
}

$queueOverview = $staffTools->getClinicQueueOverview(['date' => $today], $staffUserId);
assertTest(
    isset($queueOverview['total_in_queue']) && $queueOverview['total_in_queue'] >= 50,
    "Queue overview correctly aggregates 50+ concurrent appointments",
    "Total in queue: " . ($queueOverview['total_in_queue'] ?? 0) . ", In room: " . ($queueOverview['currently_in_room'] ?? 0)
);

// Clean up mock queue records
if (!empty($createdIds)) {
    $db->exec("DELETE FROM appointments WHERE id IN (" . implode(',', $createdIds) . ")");
}

echo "\n";

// --------------------------------------------------------------------
// TEST SECTION 4: Comprehensive Audit Log Recording Test
// --------------------------------------------------------------------
echo "4. Comprehensive Audit Log Recording (Success, Failure, Denied):\n";

log_audit_action($staffUserId, 'Staff User', 'Successful Action Test', 'Details success');
log_audit_action($staffUserId, 'Staff User', 'Failed Action Test', 'Details failure');
log_audit_action($staffUserId, 'Staff User', 'Denied Action Test', 'Details denied');

$stmtLogs = $db->prepare("SELECT COUNT(*) as cnt FROM audit_logs WHERE user_id = :uid AND action LIKE '%Action Test'");
$stmtLogs->bindValue(':uid', $staffUserId, SQLITE3_INTEGER);
$logCnt = $stmtLogs->execute()->fetchArray(SQLITE3_ASSOC)['cnt'] ?? 0;

assertTest(
    $logCnt === 3,
    "Audit log system records success, failure, and denied action attempts",
    "Audit log entries recorded: {$logCnt} / 3"
);

echo "\n----------------------------------------------------------------------\n";
echo "SUMMARY: Passed {$passCount} / {$totalTests} verification tests.\n";
echo "----------------------------------------------------------------------\n";
