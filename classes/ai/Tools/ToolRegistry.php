<?php
/**
 * ToolRegistry.php — Master Tool Declarations Registry & Dispatcher
 */

require_once __DIR__ . '/PatientTools.php';
require_once __DIR__ . '/AdminTools.php';
require_once __DIR__ . '/DoctorTools.php';
require_once __DIR__ . '/StaffTools.php';
require_once __DIR__ . '/../SecurityGuard.php';

class ToolRegistry
{
    private SQLite3 $db;
    private SecurityGuard $security;
    private PatientTools $patientTools;
    private AdminTools $adminTools;
    private DoctorTools $doctorTools;
    private StaffTools $staffTools;

    public function __construct(SQLite3 $db)
    {
        $this->db           = $db;
        $this->security     = new SecurityGuard($db);
        $this->patientTools = new PatientTools($db);
        $this->adminTools   = new AdminTools($db);
        $this->doctorTools  = new DoctorTools($db);
        $this->staffTools   = new StaffTools($db);
    }

    /**
     * Retrieves Gemini tool declarations formatted for the specified role.
     */
    public function getDeclarationsForRole(string $role): array
    {
        return match ($role) {
            'Patient' => PatientTools::getDeclarations(),
            'Admin'   => AdminTools::getDeclarations(),
            'Doctor'  => DoctorTools::getDeclarations(),
            'Staff'   => StaffTools::getDeclarations(),
            default   => PatientTools::getDeclarations(),
        };
    }

    /**
     * Executes a tool call request deterministically, enforcing RBAC authorization and audit logging.
     */
    public function executeToolCall(string $toolName, array $args, int $userId, string $role, int $conversationId = 0): array
    {
        $startTime = microtime(true);

        // 1. RBAC Verification
        if (!$this->security->isToolAllowed($toolName, $role)) {
            $this->logToolExecution($conversationId, $userId, $role, $toolName, $args, ['error' => 'Permission denied'], 'DENIED', 0);
            return [
                'error' => "Unauthorized tool call '$toolName' for role '$role'. Access denied.",
            ];
        }

        // 2. Dispatch to deterministic execution handler
        $result = ['error' => 'Unknown tool execution error'];

        try {
            if ($role === 'Patient' && method_exists($this->patientTools, $toolName)) {
                $result = $this->patientTools->$toolName($args, $userId);
            } elseif ($role === 'Admin' && method_exists($this->adminTools, $toolName)) {
                $result = $this->adminTools->$toolName($args, $userId);
            } elseif ($role === 'Doctor' && method_exists($this->doctorTools, $toolName)) {
                $result = $this->doctorTools->$toolName($args, $userId);
            } elseif ($role === 'Staff') {
                if (method_exists($this->staffTools, $toolName)) {
                    $result = $this->staffTools->$toolName($args, $userId);
                } elseif (method_exists($this->adminTools, $toolName)) {
                    $result = $this->adminTools->$toolName($args, $userId);
                } elseif (method_exists($this->patientTools, $toolName)) {
                    $result = $this->patientTools->$toolName($args, $userId);
                } else {
                    $result = ['error' => "Tool handler implementation for '$toolName' not found."];
                }
            } else {
                $result = ['error' => "Tool handler implementation for '$toolName' not found."];
            }
            $status = isset($result['error']) ? 'ERROR' : 'SUCCESS';
        } catch (Throwable $e) {
            $result = ['error' => $e->getMessage()];
            $status = 'ERROR';
        }

        $durationMs = round((microtime(true) - $startTime) * 1000, 2);

        // 3. Audit Log Execution
        $this->logToolExecution($conversationId, $userId, $role, $toolName, $args, $result, $status, $durationMs);

        return $result;
    }

    private function logToolExecution(int $convId, int $userId, string $role, string $toolName, array $params, array $result, string $status, float $durationMs): void
    {
        try {
            if ($convId <= 0) {
                // If conversation_id is not provided or 0, do not log or pass null if nullable
                $convId = null;
            }
            $stmt = @$this->db->prepare("
                INSERT INTO ai_tool_execution_logs 
                (conversation_id, user_id, role, tool_name, input_params, output_result, status, execution_time_ms)
                VALUES (:conv_id, :user_id, :role, :tool_name, :params, :result, :status, :duration)
            ");
            if ($stmt) {
                $stmt->bindValue(':conv_id', $convId, $convId === null ? SQLITE3_NULL : SQLITE3_INTEGER);
                $stmt->bindValue(':user_id', $userId, SQLITE3_INTEGER);
                $stmt->bindValue(':role', $role, SQLITE3_TEXT);
                $stmt->bindValue(':tool_name', $toolName, SQLITE3_TEXT);
                $stmt->bindValue(':params', json_encode($params, JSON_UNESCAPED_UNICODE), SQLITE3_TEXT);
                $stmt->bindValue(':result', json_encode($result, JSON_UNESCAPED_UNICODE), SQLITE3_TEXT);
                $stmt->bindValue(':status', $status, SQLITE3_TEXT);
                $stmt->bindValue(':duration', $durationMs, SQLITE3_FLOAT);
                @$stmt->execute();
            }
        } catch (Throwable $t) {
            // Ignore audit log write errors to preserve primary user workflow
        }
    }
}
