<?php
/**
 * api/ai/conversations.php — AI Conversation Session & History API
 */

header('Content-Type: application/json; charset=utf-8');

require_once __DIR__ . '/../../db.php';
require_once __DIR__ . '/../../classes/ai/SecurityGuard.php';

$db = get_db_connection();
$security = new SecurityGuard($db);

$userId = $_SESSION['user_id'] ?? ($_GET['user_id'] ?? null);
$role   = $_SESSION['user_role'] ?? $_SESSION['role'] ?? ($_GET['role'] ?? 'Patient');

$auth = $security->validateUser($userId ? (int)$userId : null, (string)$role);
if (!$auth['valid']) {
    http_response_code(401);
    echo json_encode(['error' => $auth['error']]);
    exit();
}

$validUserId = $auth['user_id'];
$validRole   = $auth['role'];

$method = $_SERVER['REQUEST_METHOD'];

if ($method === 'GET') {
    $convId = isset($_GET['id']) ? (int)$_GET['id'] : null;

    if ($convId) {
        // Fetch message history for conversation
        $stmt = $db->prepare("
            SELECT id, sender_type, content, created_at 
            FROM ai_messages 
            WHERE conversation_id = :conv_id 
            ORDER BY id ASC
        ");
        $stmt->bindValue(':conv_id', $convId, SQLITE3_INTEGER);
        $res = $stmt->execute();

        $messages = [];
        while ($row = $res->fetchArray(SQLITE3_ASSOC)) {
            $messages[] = $row;
        }

        echo json_encode(['conversation_id' => $convId, 'messages' => $messages], JSON_PRETTY_PRINT);
        exit();
    }

    // List all conversations for user
    $stmt = $db->prepare("
        SELECT id, title, role, status, created_at, updated_at 
        FROM ai_conversations 
        WHERE user_id = :u_id AND role = :role AND status = 'Active' 
        ORDER BY updated_at DESC
    ");
    $stmt->bindValue(':u_id', $validUserId, SQLITE3_INTEGER);
    $stmt->bindValue(':role', $validRole, SQLITE3_TEXT);
    $res = $stmt->execute();

    $conversations = [];
    while ($row = $res->fetchArray(SQLITE3_ASSOC)) {
        $conversations[] = $row;
    }

    echo json_encode(['conversations' => $conversations], JSON_PRETTY_PRINT);
    exit();
}

if ($method === 'POST') {
    // Create new conversation
    $title = $validRole . ' Assistant Thread (' . date('M d H:i') . ')';
    $stmt = $db->prepare("
        INSERT INTO ai_conversations (user_id, role, title, status)
        VALUES (:u_id, :role, :title, 'Active')
    ");
    $stmt->bindValue(':u_id', $validUserId, SQLITE3_INTEGER);
    $stmt->bindValue(':role', $validRole, SQLITE3_TEXT);
    $stmt->bindValue(':title', $title, SQLITE3_TEXT);
    $stmt->execute();

    $newId = $db->lastInsertRowID();
    echo json_encode(['success' => true, 'conversation_id' => $newId, 'title' => $title]);
    exit();
}

if ($method === 'DELETE') {
    $convId = isset($_GET['id']) ? (int)$_GET['id'] : null;
    if (!$convId) {
        http_response_code(400);
        echo json_encode(['error' => 'Conversation ID required for deletion.']);
        exit();
    }

    $stmt = $db->prepare("UPDATE ai_conversations SET status = 'Archived' WHERE id = :id AND user_id = :u_id");
    $stmt->bindValue(':id', $convId, SQLITE3_INTEGER);
    $stmt->bindValue(':u_id', $validUserId, SQLITE3_INTEGER);
    $stmt->execute();

    echo json_encode(['success' => true, 'message' => 'Conversation archived.']);
    exit();
}
