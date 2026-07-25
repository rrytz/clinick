<?php
/**
 * scratch/test_roles_verification.php
 * Comprehensive Verification Suite for All 4 AI Assistant Roles
 */

require_once __DIR__ . '/../db.php';
require_once __DIR__ . '/../classes/ai/AssistantFactory.php';
require_once __DIR__ . '/../ChatbotService_AI.php';

$db = get_db_connection();
$aiService = new ChatbotService_AI($db);

$passed = 0;
$failed = 0;

function assertRole(string $name, bool $condition, string $detail = '') {
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
echo "  EMPIRICAL VERIFICATION: 4-ROLE AI ASSISTANT SYSTEM  \n";
echo "=====================================================\n\n";

// --- TEST 1: STAFF ROLE ---
$staffUser = $db->querySingle("SELECT id FROM users WHERE role IN ('Staff', 'Clinical Staff') LIMIT 1") ?? 16;
$resStaff = $aiService->respond("Patient check-in", (int)$staffUser, "Staff");

assertRole(
    "Staff Test 1: Role is Staff",
    $resStaff['role'] === 'Staff',
    "Role returned: " . ($resStaff['role'] ?? 'none')
);
assertRole(
    "Staff Test 2: Assistant Name is Frontdesk Assistant",
    $resStaff['assistant_name'] === 'Frontdesk Assistant',
    "Assistant Name returned: " . ($resStaff['assistant_name'] ?? 'none')
);
assertRole(
    "Staff Test 3: Reply does NOT contain Personal Clinic Assistant",
    !str_contains($resStaff['reply'], 'Personal Clinic Assistant'),
    "Reply snippet: " . substr($resStaff['reply'], 0, 80) . "..."
);

// --- TEST 2: PATIENT ROLE ---
$patientUser = $db->querySingle("SELECT id FROM users WHERE role = 'Patient' LIMIT 1") ?? 3;
$resPatient = $aiService->respond("How do I book an appointment?", (int)$patientUser, "Patient");

assertRole(
    "Patient Test 1: Role is Patient",
    $resPatient['role'] === 'Patient',
    "Role returned: " . ($resPatient['role'] ?? 'none')
);
assertRole(
    "Patient Test 2: Assistant Name is Personal Clinic Assistant",
    $resPatient['assistant_name'] === 'Personal Clinic Assistant',
    "Assistant Name returned: " . ($resPatient['assistant_name'] ?? 'none')
);

// --- TEST 3: DOCTOR ROLE ---
$doctorUser = $db->querySingle("SELECT id FROM users WHERE role = 'Doctor' LIMIT 1") ?? 6;
$resDoctor = $aiService->respond("Search patient", (int)$doctorUser, "Doctor");

assertRole(
    "Doctor Test 1: Role is Doctor",
    $resDoctor['role'] === 'Doctor',
    "Role returned: " . ($resDoctor['role'] ?? 'none')
);
assertRole(
    "Doctor Test 2: Assistant Name is Clinical Workflow Assistant",
    $resDoctor['assistant_name'] === 'Clinical Workflow Assistant',
    "Assistant Name returned: " . ($resDoctor['assistant_name'] ?? 'none')
);

// --- TEST 4: ADMIN ROLE ---
$adminUser = $db->querySingle("SELECT id FROM users WHERE role = 'Admin' LIMIT 1") ?? 10;
$resAdmin = $aiService->respond("Show analytics", (int)$adminUser, "Admin");

assertRole(
    "Admin Test 1: Role is Admin",
    $resAdmin['role'] === 'Admin',
    "Role returned: " . ($resAdmin['role'] ?? 'none')
);
assertRole(
    "Admin Test 2: Assistant Name is AI Operations Secretary",
    $resAdmin['assistant_name'] === 'AI Operations Secretary',
    "Assistant Name returned: " . ($resAdmin['assistant_name'] ?? 'none')
);

echo "\n-----------------------------------------------------\n";
echo "SUMMARY: Passed $passed / " . ($passed + $failed) . " tests.\n";
echo "-----------------------------------------------------\n";
