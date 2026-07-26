<?php
require_once __DIR__ . '/../db.php';
require_once __DIR__ . '/../classes/ai/AssistantFactory.php';

$db = get_db_connection();
$factory = new AssistantFactory($db);

// Find a doctor user ID
$docRow = $db->querySingle("SELECT id, name FROM users WHERE role = 'Doctor' LIMIT 1", true);
$docId = $docRow['id'] ?? 1;

$testQueries = [
    "Today's appointments",
    "My appointments",
    "Show schedule",
    "Who is my next patient?",
    "Assigned patients",
    "Patients today",
    "Complete consultation",
    "Consultation workflow",
    "Show patient records",
    "Prescription guide",
    "Work availability"
];

echo "======================================================================\n";
echo "  DOCTOR ASSISTANT INTENT ROUTING MATRIX AUDIT\n";
echo "  Doctor User ID: {$docId} (" . ($docRow['name'] ?? 'Doctor') . ")\n";
echo "======================================================================\n\n";

printf("%-26s | %-24s | %-20s | %-22s\n", "Query", "Tool Executed", "Generic Greeting?", "Result");
echo str_repeat("-", 100) . "\n";

foreach ($testQueries as $q) {
    $res = $factory->handleMessage($q, $docId, 'Doctor', null);
    $reply = $res['reply'] ?? '';
    $tools = implode(', ', $res['tool_calls_executed'] ?? []);
    if (empty($tools)) $tools = 'None';
    
    $isGreeting = str_contains($reply, "Hello Doctor! I am your Clinical Workflow Assistant");
    $status = $isGreeting ? "FAIL (Generic Greeting)" : "PASS (Tool Executed)";
    
    printf("%-26s | %-24s | %-20s | %-22s\n", $q, $tools, $isGreeting ? "YES" : "NO", $status);
}
