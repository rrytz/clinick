<?php
/**
 * chatbot-stream.php — Server-Sent Events streaming endpoint for MediBot AI
 *
 * Usage: GET /CLINICK/chatbot-stream.php?message=...&session_id=...
 *
 * Returns SSE stream:
 *   data: {"text": "chunk of text"}
 *   data: {"text": "another chunk"}
 *   data: [DONE]
 *
 * Falls back to non-streaming JSON if AI is not configured.
 */

// ── CORS & SSE headers ────────────────────────────────────────────────
header('Content-Type: text/event-stream');
header('Cache-Control: no-cache, no-store');
header('X-Accel-Buffering: no'); // Nginx fix
header('Connection: keep-alive');
header('Access-Control-Allow-Origin: *');

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

// ── Rate limiting ─────────────────────────────────────────────────────
$rateKey  = 'medibot_rate_' . ($_SESSION['user_id'] ?? 'guest');
$rateNow  = time();
$rateHits = $_SESSION[$rateKey] ?? [];
// Keep only hits in the last 60 seconds
$rateHits = array_filter($rateHits, fn($t) => $t > $rateNow - 60);
if (count($rateHits) >= 20) {
    echo "data: " . json_encode(['text' => "You're sending messages too quickly. Please wait a moment."]) . "\n\n";
    echo "data: [DONE]\n\n";
    exit;
}
$rateHits[]           = $rateNow;
$_SESSION[$rateKey]   = $rateHits;

// ── Input validation ──────────────────────────────────────────────────
$message = trim($_GET['message'] ?? '');
if (empty($message) || strlen($message) > 500) {
    echo "data: " . json_encode(['error' => 'Invalid message']) . "\n\n";
    echo "data: [DONE]\n\n";
    exit;
}

$userId = isset($_SESSION['user_id']) ? (int) $_SESSION['user_id'] : null;

// ── Load AI layer ─────────────────────────────────────────────────────
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/ChatbotService_AI.php';

$ai = new ChatbotService_AI($db);

if (!$ai->isConfigured()) {
    // AI not configured — stream a helpful message
    echo "data: " . json_encode(['text' => "MediBot AI is not yet configured. Please add your GOOGLE_API_KEY to the .env file."]) . "\n\n";
    echo "data: [DONE]\n\n";
    exit;
}

// ── Stream response ───────────────────────────────────────────────────
ob_implicit_flush(true);
if (ob_get_level()) ob_end_clean();

$ai->stream($message, $userId);
