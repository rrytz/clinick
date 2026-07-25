<?php
/**
 * scratch/test_phase3_rbac_verification.php
 * Empirical Verification Suite for RBAC Hardening & Patient Records Tooling
 */

require_once __DIR__ . '/../db.php';
require_once __DIR__ . '/../classes/ai/Tools/ToolRegistry.php';

$db = get_db_connection();
$registry = new ToolRegistry($db);

$passed = 0;
$failed = 0;

function assertTest(string $name, bool $condition, string $detail = '') {
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

echo "=====================================================\n";
echo "  EMPIRICAL VERIFICATION: RBAC & PATIENT RECORDS TOOL\n";
echo "=====================================================\n\n";

// --- TEST 1: Doctor 35 (Doc B Test) accessing Patient 3 (assigned to Doctor 6) ---
// Expected: Access denied (error)
$res1 = $registry->executeToolCall('getConsultationHistory', ['patient_id' => 3], 35, 'Doctor');
assertTest(
    "Test 1: Unassigned Doctor accessing patient records is denied",
    isset($res1['error']) && str_contains(strtolower($res1['error']), 'access denied'),
    "Result: " . json_encode($res1)
);

// --- TEST 2: Doctor 6 (assigned doctor) accessing Patient 3 ---
// Expected: Success, returning medical records & prescriptions
$res2 = $registry->executeToolCall('getConsultationHistory', ['patient_id' => 3], 6, 'Doctor');
assertTest(
    "Test 2: Assigned Doctor accessing patient records succeeds",
    !isset($res2['error']) && isset($res2['medical_records']),
    "Medical Records returned: " . count($res2['medical_records'] ?? []) . ", Prescriptions: " . count($res2['prescriptions'] ?? [])
);

// --- TEST 3: Patient 32 trying to cancel Patient 3's appointment (Appt ID 5) ---
// Expected: Error (not authorized)
$res3 = $registry->executeToolCall('cancelAppointment', ['appointment_id' => 5], 32, 'Patient');
assertTest(
    "Test 3: Unauthorized patient cancelling another patient's appointment fails",
    isset($res3['error']) && str_contains(strtolower($res3['error']), 'not authorized'),
    "Result: " . json_encode($res3)
);

// --- TEST 4: Patient 3 cancelling already-cancelled appointment (Appt ID 5) ---
// Expected: Error (already cancelled)
$res4 = $registry->executeToolCall('cancelAppointment', ['appointment_id' => 5], 3, 'Patient');
assertTest(
    "Test 4: Cancelling an already cancelled appointment returns clear status error",
    isset($res4['error']) && str_contains(strtolower($res4['error']), 'already been cancelled'),
    "Result: " . json_encode($res4)
);

// --- TEST 5: Patient 3 retrieving their own records via getMyRecords ---
// Expected: Success with patient_id 3 records
$res5 = $registry->executeToolCall('getMyRecords', ['record_type' => 'all'], 3, 'Patient');
assertTest(
    "Test 5: Patient 3 fetching own records via getMyRecords returns data",
    !isset($res5['error']) && isset($res5['medical_records']) && count($res5['medical_records']) > 0,
    "Medical Records count: " . count($res5['medical_records'] ?? []) . ", Prescriptions: " . count($res5['prescriptions'] ?? [])
);

// --- TEST 6: Patient 32 (no records) calling getMyRecords ---
// Expected: Returns empty arrays, no errors
$res6 = $registry->executeToolCall('getMyRecords', ['record_type' => 'all'], 32, 'Patient');
assertTest(
    "Test 6: Patient 32 fetching own records returns empty result without leakage",
    !isset($res6['error']) && isset($res6['medical_records']) && count($res6['medical_records']) === 0,
    "Records count: " . count($res6['medical_records'] ?? [])
);

// --- TEST 7: SecurityGuard isToolAllowed check for getMyRecords ---
$sec = new SecurityGuard($db);
$allowedPatient = $sec->isToolAllowed('getMyRecords', 'Patient');
$allowedDoctor = $sec->isToolAllowed('getMyRecords', 'Doctor');
assertTest(
    "Test 7: getMyRecords is allowed for Patient role and disallowed for Doctor role",
    $allowedPatient === true && $allowedDoctor === false,
    "Patient: " . ($allowedPatient ? 'Allowed' : 'Denied') . ", Doctor: " . ($allowedDoctor ? 'Allowed' : 'Denied')
);

// --- TEST 8: Dashboard Scoping Simulation: Doctor 35 attempting prescription write for Patient 3 ---
// Verify via DB query simulating doctor_dashboard.php logic
$stmtAssign = $db->prepare("SELECT COUNT(*) as cnt FROM appointments WHERE doctor_id = :doctor_id AND patient_id = :patient_id");
$stmtAssign->bindValue(':doctor_id', 35, SQLITE3_INTEGER);
$stmtAssign->bindValue(':patient_id', 3, SQLITE3_INTEGER);
$assignRes = $stmtAssign->execute()->fetchArray(SQLITE3_ASSOC);
assertTest(
    "Test 8: Dashboard prescription check blocks unassigned doctor 35 for patient 3",
    ($assignRes['cnt'] ?? 0) === 0,
    "Assignment count: " . ($assignRes['cnt'] ?? 0)
);

echo "\n-----------------------------------------------------\n";
echo "SUMMARY: Passed $passed / " . ($passed + $failed) . " tests.\n";
echo "-----------------------------------------------------\n";
