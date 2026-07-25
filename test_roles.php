<?php
/**
 * Role-Based MediBot AI Architecture Test Suite
 *
 * Verifies role isolation, RBAC tool permissions, persona system prompts,
 * and knowledge base separation across Patient, Doctor, Staff, and Admin roles.
 */

require_once __DIR__ . '/db.php';
require_once __DIR__ . '/ChatbotKnowledge.php';
require_once __DIR__ . '/ChatbotTools.php';
require_once __DIR__ . '/ChatbotService_AI.php';

$db = get_db_connection();
$tools = new ChatbotTools($db);
$knowledge = new ChatbotKnowledge($db);
$ai = new ChatbotService_AI($db);

echo "========================================================================================\n";
echo "                   ROLE-BASED MEDIBOT ARCHITECTURE TEST SUITE                           \n";
echo "========================================================================================\n";

$passCount = 0;
$totalCount = 0;

function runTest(string $testName, bool $condition): void {
    global $passCount, $totalCount;
    $totalCount++;
    $status = $condition ? "âœ… PASS" : "âŒ FAIL";
    if ($condition) $passCount++;
    printf("  %-75s | %-6s\n", $testName, $status);
}

// â”€â”€ TEST 1: Knowledge Base Role Isolation â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€
echo "\n--- 1. KNOWLEDGE BASE ROLE ISOLATION ---\n";

$patientChunks = $knowledge->search("how to book appointment", 3, "Patient");
runTest("Patient KB includes Patient Booking guide", !empty($patientChunks) && str_contains($patientChunks[0]['title'], 'Book'));

$doctorChunks = $knowledge->search("prescribe medication", 3, "Doctor");
runTest("Doctor KB includes Doctor Prescription workflow", !empty($doctorChunks) && str_contains($doctorChunks[0]['title'], 'Prescription'));

$adminChunks = $knowledge->search("pending approvals", 3, "Admin");
runTest("Admin KB includes Admin User Approvals guide", !empty($adminChunks) && str_contains($adminChunks[0]['title'], 'Approvals'));

$patientAdminChunks = $knowledge->search("pending approvals", 3, "Patient");
runTest("Patient KB excludes Admin Approvals guide", empty($patientAdminChunks) || $patientAdminChunks[0]['role_scope'] !== 'admin');

// â”€â”€ TEST 2: Tool Declaration Matrix by Role â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€
echo "\n--- 2. TOOL DECLARATIONS RBAC MATRIX ---\n";

$patientTools = array_column(ChatbotTools::getDeclarations('Patient'), 'name');
runTest("Patient tools contain get_my_appointments", in_array('get_my_appointments', $patientTools));
runTest("Patient tools EXCLUDE get_pending_approvals", !in_array('get_pending_approvals', $patientTools));

$doctorTools = array_column(ChatbotTools::getDeclarations('Doctor'), 'name');
runTest("Doctor tools contain get_today_doctor_appointments", in_array('get_today_doctor_appointments', $doctorTools));
runTest("Doctor tools EXCLUDE get_pending_approvals", !in_array('get_pending_approvals', $doctorTools));

$adminTools = array_column(ChatbotTools::getDeclarations('Admin'), 'name');
runTest("Admin tools contain get_pending_approvals", in_array('get_pending_approvals', $adminTools));
runTest("Admin tools contain get_system_analytics", in_array('get_system_analytics', $adminTools));

// â”€â”€ TEST 3: RBAC Dispatch Enforcement â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€
echo "\n--- 3. RBAC DISPATCH ENFORCEMENT ---\n";

$res1 = $tools->dispatch('get_pending_approvals', [], 1, 'Patient');
runTest("Patient attempting get_pending_approvals is DENIED", isset($res1['error']) && str_contains($res1['error'], 'Permission denied'));

$res2 = $tools->dispatch('get_today_doctor_appointments', [], 1, 'Patient');
runTest("Patient attempting get_today_doctor_appointments is DENIED", isset($res2['error']) && str_contains($res2['error'], 'Permission denied'));

$res3 = $tools->dispatch('get_pending_approvals', [], 1, 'Admin');
runTest("Admin attempting get_pending_approvals is ALLOWED", !isset($res3['error']));

$res4 = $tools->dispatch('get_system_analytics', [], 1, 'Admin');
runTest("Admin attempting get_system_analytics is ALLOWED", !isset($res4['error']) && isset($res4['system_health']));

// â”€â”€ TEST 4: Role-Aware AI Responses â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€
echo "\n--- 4. ROLE-AWARE AI ASSISTANT RESPONSES ---\n";

if ($ai->isConfigured()) {
    $respDoctor = $ai->respond("How do I prescribe medication?", 1, "Doctor");
    if (isset($respDoctor["error"])) { echo "  [Doctor Error: " . $respDoctor["error"] . "]\n"; }
    runTest("Doctor asking 'How do I prescribe medication?' gets clinical response", !empty($respDoctor['reply']) && !isset($respDoctor['error']));

    $respPatient = $ai->respond("How do I book an appointment?", 1, "Patient");
    runTest("Patient asking 'How do I book an appointment?' gets patient guide", !empty($respPatient['reply']) && !isset($respPatient['error']));
} else {
    echo "  (Skipping AI API call test â€” API key not set)\n";
}

echo "========================================================================================\n";
printf("  â˜… VERIFICATION SUMMARY: %d / %d TESTS PASSED (%s%%)\n", $passCount, $totalCount, round(($passCount/$totalCount)*100, 2));
echo "========================================================================================\n";
