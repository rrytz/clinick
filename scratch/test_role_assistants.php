<?php
require_once __DIR__ . '/../db.php';
require_once __DIR__ . '/../classes/ai/AssistantFactory.php';

echo "=== CLINICK ROLE-BASED AI ASSISTANT SYSTEM TEST ===\n";

$db = get_db_connection();
$factory = new AssistantFactory($db);

// 1. Test Patient Assistant
echo "\n--- 1. Patient Assistant Test ---\n";
$patientRes = $factory->handleMessage("Can I check available doctors for tomorrow?", 1, 'Patient');
print_r($patientRes);

// 2. Test Admin Assistant
echo "\n--- 2. Admin Operations Secretary Test ---\n";
$adminRes = $factory->handleMessage("What should I focus on today?", 1, 'Admin');
print_r($adminRes);

// 3. Test Doctor Assistant
echo "\n--- 3. Doctor Clinical Assistant Test ---\n";
$docRes = $factory->handleMessage("Show my assigned patients for today.", 1, 'Doctor');
print_r($docRes);

echo "\n=== ALL TESTS EXECUTED ===\n";
