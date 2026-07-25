<?php
/**
 * scratch/test_staff_tools_audit.php
 * Comprehensive Audit of ToolRegistry, SecurityGuard, and Tool Handlers for Staff Role
 */

require_once __DIR__ . '/../db.php';
require_once __DIR__ . '/../classes/ai/Tools/ToolRegistry.php';
require_once __DIR__ . '/../classes/ai/SecurityGuard.php';

$db = get_db_connection();
$registry = new ToolRegistry($db);
$security = new SecurityGuard($db);

$staffId = 16;
$role = 'Staff';

$toolsToTest = [
    'getAvailableDoctors',
    'getDoctorSchedule',
    'getAppointmentStatus',
    'getQueueStatus',
    'getDailyStats',
    'getPendingApprovals',
    'getWeeklyStats',
    'getMonthlyReport',
    'getDoctorWorkload',
    'getNoShowRate',
    'getHighRiskPatients',
    'generateAnalyticsReport'
];

echo "======================================================================\n";
echo "  EMPIRICAL AUDIT: TOOL REGISTRY & DISPATCH MATRIX FOR STAFF ROLE     \n";
echo "======================================================================\n\n";

printf("%-26s | %-12s | %-12s | %-20s\n", "Tool Name", "SecurityGuard", "Declarations", "ToolRegistry Dispatch");
echo str_repeat("-", 80) . "\n";

$declarations = $registry->getDeclarationsForRole($role);
$declaredNames = array_column($declarations, 'name');

foreach ($toolsToTest as $tName) {
    $allowed = $security->isToolAllowed($tName, $role) ? "ALLOWED" : "DENIED";
    $declared = in_array($tName, $declaredNames) ? "DECLARED" : "MISSING";
    
    $res = $registry->executeToolCall($tName, [], $staffId, $role);
    if (isset($res['error'])) {
        $dispatch = "FAILED (" . substr($res['error'], 0, 20) . "...)";
    } else {
        $dispatch = "SUCCESS";
    }

    printf("%-26s | %-12s | %-12s | %-20s\n", $tName, $allowed, $declared, $dispatch);
}

echo "\n----------------------------------------------------------------------\n";
