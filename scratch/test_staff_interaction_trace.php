<?php
/**
 * scratch/test_staff_interaction_trace.php
 * Empirical Execution Tracing for Staff Chatbot Requests
 */

require_once __DIR__ . '/../db.php';
require_once __DIR__ . '/../classes/ai/AssistantFactory.php';
require_once __DIR__ . '/../ChatbotService.php';
require_once __DIR__ . '/../ChatbotService_AI.php';

$db = get_db_connection();

// 1. Get a Staff user ID (or create one)
$staffUser = $db->querySingle("SELECT id, name, role FROM users WHERE role IN ('Staff', 'Clinical Staff') LIMIT 1", true);
$staffId = $staffUser['id'] ?? 16;
$staffRole = $staffUser['role'] ?? 'Staff';

echo "=== TRACE EXECUTION FOR STAFF USER ===\n";
echo "Staff User ID: $staffId, Role: $staffRole\n\n";

$testMessages = [
    "Patient check-in",
    "Search patient",
    "hello",
    "Check my appointments",
    "Register walk-in patient"
];

foreach ($testMessages as $msg) {
    echo "-----------------------------------------------------\n";
    echo "TEST INPUT MESSAGE: \"$msg\"\n";

    // Trace A: ChatbotService_AI (Primary Path called by chatbot-api.php)
    try {
        $aiService = new ChatbotService_AI($db);
        $aiResult = $aiService->respond($msg, $staffId, $staffRole);
        echo "A. ChatbotService_AI Response:\n";
        echo json_encode($aiResult, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE) . "\n";
    } catch (Throwable $e) {
        echo "A. ChatbotService_AI EXCEPTION: " . $e->getMessage() . "\n";
    }

    // Trace B: Offline / Fallback ChatbotService
    try {
        $legacyService = new ChatbotService();
        $legacyResult = $legacyService->respond($msg);
        echo "B. Fallback ChatbotService Response:\n";
        echo json_encode($legacyResult, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE) . "\n";
    } catch (Throwable $e) {
        echo "B. Fallback ChatbotService EXCEPTION: " . $e->getMessage() . "\n";
    }
}
