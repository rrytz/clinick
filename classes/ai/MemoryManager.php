<?php
/**
 * MemoryManager.php — 3-Tier Multi-Turn Conversation Memory Engine
 *
 * Tier 1: Sliding Window Short-Term History (ai_messages)
 * Tier 2: Entity & Intent Context Key-Value Store (ai_context_store)
 * Tier 3: Compressed Executive Summaries (ai_memory_summaries)
 */

class MemoryManager
{
    private SQLite3 $db;
    private const WINDOW_LIMIT = 12; // Messages count in active sliding window

    public function __construct(SQLite3 $db)
    {
        $this->db = $db;
    }

    /**
     * Gets existing active conversation ID or initializes a new conversation session.
     */
    public function getOrCreateConversation(int $userId, string $role, ?int $conversationId = null): int
    {
        if ($conversationId) {
            $stmt = $this->db->prepare("SELECT id FROM ai_conversations WHERE id = :id AND user_id = :u_id AND status = 'Active'");
            $stmt->bindValue(':id', $conversationId, SQLITE3_INTEGER);
            $stmt->bindValue(':u_id', $userId, SQLITE3_INTEGER);
            $res = $stmt->execute();
            if ($res && $row = $res->fetchArray(SQLITE3_ASSOC)) {
                return (int)$row['id'];
            }
        }

        // Fetch or create latest active conversation thread for user & role
        $stmtActive = $this->db->prepare("
            SELECT id FROM ai_conversations 
            WHERE user_id = :u_id AND role = :role AND status = 'Active' 
            ORDER BY updated_at DESC LIMIT 1
        ");
        $stmtActive->bindValue(':u_id', $userId, SQLITE3_INTEGER);
        $stmtActive->bindValue(':role', $role, SQLITE3_TEXT);
        $resActive = $stmtActive->execute();

        if ($resActive && $rowActive = $resActive->fetchArray(SQLITE3_ASSOC)) {
            return (int)$rowActive['id'];
        }

        // Create new conversation session
        $title = $role . ' Assistant Session (' . date('M d, Y H:i') . ')';
        $stmtIns = $this->db->prepare("
            INSERT INTO ai_conversations (user_id, role, title, status) 
            VALUES (:u_id, :role, :title, 'Active')
        ");
        $stmtIns->bindValue(':u_id', $userId, SQLITE3_INTEGER);
        $stmtIns->bindValue(':role', $role, SQLITE3_TEXT);
        $stmtIns->bindValue(':title', $title, SQLITE3_TEXT);
        $stmtIns->execute();

        return (int)$this->db->lastInsertRowID();
    }

    /**
     * Appends a new message to the conversation trajectory.
     */
    public function saveMessage(int $conversationId, string $senderType, ?string $content, ?array $toolCalls = null, ?string $toolCallId = null): int
    {
        $stmt = $this->db->prepare("
            INSERT INTO ai_messages (conversation_id, sender_type, content, tool_calls, tool_call_id)
            VALUES (:conv_id, :sender, :content, :tool_calls, :tool_call_id)
        ");
        $stmt->bindValue(':conv_id', $conversationId, SQLITE3_INTEGER);
        $stmt->bindValue(':sender', $senderType, SQLITE3_TEXT);
        $stmt->bindValue(':content', $content, SQLITE3_TEXT);
        $stmt->bindValue(':tool_calls', $toolCalls ? json_encode($toolCalls, JSON_UNESCAPED_UNICODE) : null, SQLITE3_TEXT);
        $stmt->bindValue(':tool_call_id', $toolCallId, SQLITE3_TEXT);
        $stmt->execute();

        // Touch updated_at in conversation
        $this->db->exec("UPDATE ai_conversations SET updated_at = CURRENT_TIMESTAMP WHERE id = $conversationId");

        return (int)$this->db->lastInsertRowID();
    }

    /**
     * Retrieves sliding window of recent messages formatted for Gemini contents payload.
     */
    public function getRecentHistory(int $conversationId): array
    {
        $stmt = $this->db->prepare("
            SELECT sender_type, content, tool_calls, tool_call_id 
            FROM (
                SELECT id, sender_type, content, tool_calls, tool_call_id 
                FROM ai_messages 
                WHERE conversation_id = :conv_id 
                ORDER BY id DESC LIMIT :lim
            ) ORDER BY id ASC
        ");
        $stmt->bindValue(':conv_id', $conversationId, SQLITE3_INTEGER);
        $stmt->bindValue(':lim', self::WINDOW_LIMIT, SQLITE3_INTEGER);
        $res = $stmt->execute();

        $contents = [];
        while ($row = $res->fetchArray(SQLITE3_ASSOC)) {
            $role = match ($row['sender_type']) {
                'user'      => 'user',
                'assistant' => 'model',
                'tool'      => 'user',
                default     => 'user',
            };

            $parts = [];
            if (!empty($row['content'])) {
                $parts[] = ['text' => $row['content']];
            }

            if (!empty($row['tool_calls'])) {
                $tCalls = json_decode($row['tool_calls'], true);
                if (is_array($tCalls)) {
                    foreach ($tCalls as $tc) {
                        $parts[] = ['functionCall' => $tc];
                    }
                }
            }

            if (!empty($parts)) {
                $contents[] = [
                    'role'  => $role,
                    'parts' => $parts,
                ];
            }
        }

        return $contents;
    }

    /**
     * Stores an active entity or intent state key-value pair in ai_context_store.
     */
    public function setContext(int $conversationId, int $userId, string $key, mixed $value): void
    {
        $jsonVal = json_encode($value, JSON_UNESCAPED_UNICODE);
        $stmt = $this->db->prepare("
            INSERT INTO ai_context_store (conversation_id, user_id, context_key, context_value, updated_at)
            VALUES (:c_id, :u_id, :key, :val, CURRENT_TIMESTAMP)
            ON CONFLICT(conversation_id, context_key) DO UPDATE SET context_value = :val, updated_at = CURRENT_TIMESTAMP
        ");
        $stmt->bindValue(':c_id', $conversationId, SQLITE3_INTEGER);
        $stmt->bindValue(':u_id', $userId, SQLITE3_INTEGER);
        $stmt->bindValue(':key', $key, SQLITE3_TEXT);
        $stmt->bindValue(':val', $jsonVal, SQLITE3_TEXT);
        $stmt->execute();
    }

    /**
     * Fetches stored context values for resolving follow-ups.
     */
    public function getContext(int $conversationId): array
    {
        $stmt = $this->db->prepare("
            SELECT context_key, context_value 
            FROM ai_context_store 
            WHERE conversation_id = :c_id
        ");
        $stmt->bindValue(':c_id', $conversationId, SQLITE3_INTEGER);
        $res = $stmt->execute();

        $context = [];
        while ($row = $res->fetchArray(SQLITE3_ASSOC)) {
            $context[$row['context_key']] = json_decode($row['context_value'], true);
        }

        return $context;
    }

    /**
     * Retrieves or creates a summary of older turns in the conversation.
     */
    public function getSummary(int $conversationId): string
    {
        $stmt = $this->db->prepare("SELECT summary_text FROM ai_memory_summaries WHERE conversation_id = :c_id");
        $stmt->bindValue(':c_id', $conversationId, SQLITE3_INTEGER);
        $res = $stmt->execute();
        if ($res && $row = $res->fetchArray(SQLITE3_ASSOC)) {
            return $row['summary_text'];
        }
        return '';
    }
}
