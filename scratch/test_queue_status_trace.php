<?php
/**
 * scratch/test_queue_status_trace.php
 * Tracing "Queue status" execution for a Staff user
 */

require_once __DIR__ . '/../db.php';
require_once __DIR__ . '/../classes/ai/Tools/ToolRegistry.php';
require_once __DIR__ . '/../classes/ai/AssistantFactory.php';

$db = get_db_connection();

$staffId = 16; // Staff user
$role = 'Staff';

echo "=== TRACING QUEUE STATUS FOR STAFF USER (ID: $staffId) ===\n\n";

// 1. Direct Tool Execution Test
$tools = new ToolRegistry($db);
$toolResult = $tools->executeToolCall('getQueueStatus', [], $staffId, $role);

echo "1. Direct Tool Execution Result (getQueueStatus):\n";
echo json_encode($toolResult, JSON_PRETTY_PRINT) . "\n\n";

// 2. Full Assistant Execution Test
$factory = new AssistantFactory($db);
$response = $factory->handleMessage("Queue status", $staffId, $role);

echo "2. AssistantFactory Full Response for 'Queue status':\n";
echo json_encode($response, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE) . "\n\n";
