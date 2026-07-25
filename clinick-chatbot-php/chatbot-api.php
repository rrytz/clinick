<?php
/**
 * Standalone chatbot endpoint — no Laravel routing required.
 *
 * SETUP:
 * Drop this file, plus NaiveBayesClassifier.php, ChatbotService.php, and
 * chatbot-data.php, into the SAME folder in your project (e.g. alongside
 * db.php, admin_dashboard.php, etc). Then the widget can call it directly
 * at whatever path this file ends up at, e.g. "/chatbot-api.php".
 *
 * If CLINICK turns out to use Laravel routing after all, use
 * ChatbotController.php instead and delete this file.
 */

header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *'); // tighten this to your actual domain in production

require_once __DIR__ . '/ChatbotService.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['error' => 'Only POST requests are allowed.']);
    exit;
}

$input = json_decode(file_get_contents('php://input'), true);
$message = $input['message'] ?? null;

if (!$message || !is_string($message) || trim($message) === '') {
    http_response_code(400);
    echo json_encode(['error' => "A non-empty 'message' string is required."]);
    exit;
}

if (strlen($message) > 500) {
    http_response_code(400);
    echo json_encode(['error' => 'Message too long (max 500 characters).']);
    exit;
}

try {
    $chatbot = new ChatbotService();
    $result = $chatbot->respond(trim($message));
    echo json_encode($result);
} catch (\Throwable $e) {
    http_response_code(500);
    echo json_encode(['error' => 'Something went wrong processing your message.']);
    // Uncomment while debugging locally, remove before deploying:
    // echo json_encode(['error' => $e->getMessage()]);
}
