<?php
/**
 * chatbot-api.php — Role-Aware MediBot Endpoint
 *
 * 4-Layer routing with Role Awareness (Patient, Doctor, Staff, Admin):
 *   Layer 1: Emergency keyword check    -> instant emergency response
 *   Layer 2: Keyword pre-matcher        -> instant match (5ms)
 *   Layer 3: Naive Bayes classifier     -> local intent fallback
 *   Layer 4: Gemini AI Layer            -> Role-scoped persona, RAG context, and RBAC tools
 */

header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

// Rate limiting (60 requests/minute per user)
$rateKey  = 'medibot_rate_' . ($_SESSION['user_id'] ?? 'guest');
$rateNow  = time();
$rateHits = $_SESSION[$rateKey] ?? [];
$rateHits = array_filter($rateHits, fn($t) => $t > $rateNow - 60);
if (count($rateHits) >= 60) {
    http_response_code(429);
    echo json_encode([
        'error' => 'Too many requests.',
        'reply' => "You're sending messages too quickly. Please wait a moment."
    ]);
    exit;
}
$rateHits[] = $rateNow;
$_SESSION[$rateKey] = $rateHits;

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['error' => 'Only POST requests are allowed.']);
    exit;
}

$input   = json_decode(file_get_contents('php://input'), true);
$message = trim($input['message'] ?? '');

if (!$message || strlen($message) > 500) {
    http_response_code(400);
    echo json_encode(['error' => "A non-empty 'message' string is required (max 500 chars)."]);
    exit;
}

$userId   = isset($_SESSION['user_id']) ? (int) $_SESSION['user_id'] : (isset($input['userId']) ? (int)$input['userId'] : null);
$userRole = $_SESSION['user_role'] ?? $_SESSION['role'] ?? $input['role'] ?? 'Patient';

// Normalize role string
$userRole = match (strtolower(trim($userRole))) {
    'doctor', 'clinical staff' => 'Doctor',
    'staff', 'receptionist'     => 'Staff',
    'admin', 'administrator'   => 'Admin',
    default                    => 'Patient',
};

require_once __DIR__ . '/db.php';
require_once __DIR__ . '/NaiveBayesClassifier.php';
require_once __DIR__ . '/ChatbotService.php';
require_once __DIR__ . '/ChatbotService_AI.php';
require_once __DIR__ . '/ChatbotKnowledge.php';

try {
    $db = get_db_connection();

    // Route directly to Role-Based AI Assistant System
    $ai = new ChatbotService_AI($db);
    $aiResult = $ai->respond($message, $userId, $userRole);

    echo json_encode(array_merge($aiResult, ['source' => 'ai_assistant']));
    exit;

} catch (Throwable $e) {
    http_response_code(500);
    echo json_encode([
        'error'  => 'Something went wrong.',
        'reply'  => "I am your CLINICK assistant. How can I help you with your account, appointments, or clinic workflows?",
        'intent' => 'error',
        'role'   => $userRole,
    ]);
}
