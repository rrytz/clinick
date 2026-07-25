<?php
/**
 * ChatbotSession.php — Role-Aware Conversation Session Manager for MediBot
 *
 * Stores conversation history in chat_sessions and chat_messages tables
 * bound to user_id and role so Gemini maintains role-isolated context across turns.
 */
class ChatbotSession
{
    private SQLite3 $db;

    private const MAX_HISTORY = 12;
    private const SESSION_TTL = 3600;

    public function __construct(SQLite3 $db)
    {
        $this->db = $db;
    }

    public function getOrCreate(?int $userId, string $role = 'Patient'): string
    {
        if (!empty($_SESSION['medibot_session_id'])) {
            $sid = $_SESSION['medibot_session_id'];
            $row = $this->db->querySingle(
                "SELECT session_id FROM chat_sessions WHERE session_id = '{$this->esc($sid)}'
                 AND datetime(last_active) > datetime('now', '-" . self::SESSION_TTL . " seconds')",
                true
            );
            if ($row) {
                $this->db->exec(
                    "UPDATE chat_sessions SET last_active = CURRENT_TIMESTAMP
                     WHERE session_id = '{$this->esc($sid)}'"
                );
                return $sid;
            }
        }

        $sid = $this->generateSessionId($userId);
        $stmt = $this->db->prepare(
            "INSERT INTO chat_sessions (session_id, user_id, role) VALUES (:sid, :uid, :role)"
        );
        $stmt->bindValue(':sid',  $sid,     SQLITE3_TEXT);
        $stmt->bindValue(':uid',  $userId,  $userId ? SQLITE3_INTEGER : SQLITE3_NULL);
        $stmt->bindValue(':role', $role,    SQLITE3_TEXT);
        $stmt->execute();

        $_SESSION['medibot_session_id'] = $sid;
        return $sid;
    }

    public function addMessage(
        string $sessionId,
        string $role,
        string $content,
        ?string $intent     = null,
        ?string $toolCalled = null,
        int $tokensUsed     = 0
    ): void {
        $stmt = $this->db->prepare(
            "INSERT INTO chat_messages (session_id, role, content, intent, tool_called, tokens_used)
             VALUES (:sid, :role, :content, :intent, :tool, :tokens)"
        );
        $stmt->bindValue(':sid',     $sessionId,  SQLITE3_TEXT);
        $stmt->bindValue(':role',    $role,        SQLITE3_TEXT);
        $stmt->bindValue(':content', $content,     SQLITE3_TEXT);
        $stmt->bindValue(':intent',  $intent,      $intent  ? SQLITE3_TEXT : SQLITE3_NULL);
        $stmt->bindValue(':tool',    $toolCalled,  $toolCalled ? SQLITE3_TEXT : SQLITE3_NULL);
        $stmt->bindValue(':tokens',  $tokensUsed,  SQLITE3_INTEGER);
        $stmt->execute();
    }

    public function getHistory(string $sessionId): array
    {
        $sid    = $this->esc($sessionId);
        $limit  = self::MAX_HISTORY;
        $result = $this->db->query(
            "SELECT role, content FROM chat_messages
             WHERE session_id = '{$sid}'
             ORDER BY id DESC LIMIT {$limit}"
        );

        $rows = [];
        while ($row = $result->fetchArray(SQLITE3_ASSOC)) {
            $rows[] = $row;
        }

        $rows = array_reverse($rows);

        $contents = [];
        foreach ($rows as $r) {
            $geminiRole = ($r['role'] === 'user') ? 'user' : 'model';
            $contents[] = [
                'role'  => $geminiRole,
                'parts' => [['text' => $r['content']]],
            ];
        }

        return $contents;
    }

    private function generateSessionId(?int $userId): string
    {
        $prefix = $userId ? "usr_{$userId}_" : "gst_";
        return $prefix . bin2hex(random_bytes(8));
    }

    private function esc(string $str): string
    {
        return $this->db->escapeString($str);
    }
}
