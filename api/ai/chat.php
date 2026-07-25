<?php
/**
 * api/ai/chat.php — Main AI Assistant Chat Entry Point API
 */

header('Content-Type: application/json; charset=utf-8');

require_once __DIR__ . '/../../db.php';
require_once __DIR__ . '/../../classes/ai/AssistantFactory.php';
require_once __DIR__ . '/../../ChatbotService.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['error' => 'Method not allowed. Use POST.']);
    exit();
}

$input = json_decode(file_get_contents('php://input'), true);

if (!is_array($input) || empty($input['message'])) {
    http_response_code(400);
    echo json_encode(['error' => 'Invalid JSON payload. Property "message" is required.']);
    exit();
}

$message = trim($input['message']);
$convId  = isset($input['conversation_id']) ? (int)$input['conversation_id'] : null;

// Determine session user ID & role
$userId = $_SESSION['user_id'] ?? ($input['user_id'] ?? null);
$role   = $_SESSION['user_role'] ?? $_SESSION['role'] ?? ($input['role'] ?? 'Patient');

try {
    $db = get_db_connection();
    $factory = new AssistantFactory($db);
    $response = $factory->handleMessage($message, $userId ? (int)$userId : null, (string)$role, $convId);

    if (empty($response['success'])) {
        $chatbot = new ChatbotService();
        $fallback = $chatbot->respond($message);
        $fallback['degraded']       = true;
        $fallback['assistant_name'] = 'Personal Clinic Assistant (Offline)';
        echo json_encode($fallback, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
        exit();
    }

    $response['degraded'] = $response['degraded'] ?? false;
    echo json_encode($response, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
} catch (Throwable $e) {
    try {
        $chatbot = new ChatbotService();
        $fallback = $chatbot->respond($message);
        $fallback['degraded']       = true;
        $fallback['assistant_name'] = 'Personal Clinic Assistant (Offline)';
        $fallback['error_details']  = $e->getMessage();
        echo json_encode($fallback, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
    } catch (Throwable $t) {
        http_response_code(500);
        echo json_encode([
            'error'   => 'Internal server error processing AI Assistant request.',
            'details' => $e->getMessage(),
        ]);
    }
}
