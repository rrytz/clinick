<?php
/**
 * scratch/test_staff_quick_actions_trace.php
 * End-to-end tracing of all 4 Staff Quick Action buttons
 */

require_once __DIR__ . '/../db.php';
require_once __DIR__ . '/../classes/ai/AssistantFactory.php';
require_once __DIR__ . '/../ChatbotService_AI.php';

$db = get_db_connection();
$ai = new ChatbotService_AI($db);

$staffId = 16;
$role = 'Staff';

$staffActions = [
    'Patient check-in',
    'Search patient',
    'Queue status',
    'Walk-in guide'
];

echo "======================================================================\n";
echo "  EMPIRICAL TRACE: ALL 4 STAFF QUICK ACTION BUTTONS                  \n";
echo "======================================================================\n\n";

foreach ($staffActions as $action) {
    echo "----------------------------------------------------------------------\n";
    echo "ACTION CLICKED: \"$action\"\n";
    
    $res = $ai->respond($action, $staffId, $role);
    
    echo "Returned Assistant Name : " . ($res['assistant_name'] ?? 'NONE') . "\n";
    echo "Returned Role           : " . ($res['role'] ?? 'NONE') . "\n";
    echo "Executed Tools          : " . json_encode($res['tool_calls'] ?? []) . "\n";
    echo "Response Text           :\n" . ($res['reply'] ?? '') . "\n";
}
echo "----------------------------------------------------------------------\n";
