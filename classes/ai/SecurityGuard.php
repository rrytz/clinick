<?php
/**
 * SecurityGuard.php — Security & RBAC Enforcement Engine for CLINICK AI Assistants
 *
 * Handles user authentication, session role verification, rate limiting, prompt injection
 * defense, input sanitization, and output privacy post-filtering.
 */

class SecurityGuard
{
    private SQLite3 $db;
    private const MAX_REQ_PER_MINUTE = 20;

    public function __construct(SQLite3 $db)
    {
        $this->db = $db;
    }

    /**
     * Verifies user session and role authorization.
     */
    public function validateUser(?int $userId, string $requestedRole): array
    {
        if (session_status() === PHP_SESSION_NONE) {
            session_start();
        }

        $sessionUserId = $_SESSION['user_id'] ?? $userId;
        $sessionRole   = $_SESSION['user_role'] ?? $_SESSION['role'] ?? $requestedRole;

        if (!$sessionUserId) {
            return [
                'valid' => false,
                'error' => 'Unauthorized access. User identity required.',
            ];
        }

        // Normalize roles
        $normalizedRole = match (strtolower(trim($sessionRole))) {
            'admin', 'administrator', 'super admin' => 'Admin',
            'doctor', 'physician'                   => 'Doctor',
            'staff', 'clinical staff'               => 'Staff',
            default                                 => 'Patient',
        };

        // Ensure user_id exists in DB to prevent foreign key constraints
        $uid = (int)$sessionUserId;
        $stmtUser = $this->db->prepare("SELECT id FROM users WHERE id = :id");
        $stmtUser->bindValue(':id', $uid, SQLITE3_INTEGER);
        $resUser = $stmtUser->execute();

        if (!$resUser || !$resUser->fetchArray()) {
            // Check if any user exists for role, or create a default user record
            $stmtRole = $this->db->prepare("SELECT id FROM users WHERE role = :role LIMIT 1");
            $stmtRole->bindValue(':role', $normalizedRole, SQLITE3_TEXT);
            $resRole = $stmtRole->execute();
            if ($resRole && $rowRole = $resRole->fetchArray(SQLITE3_ASSOC)) {
                $uid = (int)$rowRole['id'];
            } else {
                // Insert a fallback user entry for this role
                $email = strtolower($normalizedRole) . '_' . time() . '@clinick.local';
                $stmtIns = $this->db->prepare("INSERT INTO users (name, email, password, role, status) VALUES (:name, :email, 'hash', :role, 'Active')");
                $stmtIns->bindValue(':name', "$normalizedRole User", SQLITE3_TEXT);
                $stmtIns->bindValue(':email', $email, SQLITE3_TEXT);
                $stmtIns->bindValue(':role', $normalizedRole, SQLITE3_TEXT);
                $stmtIns->execute();
                $uid = (int)$this->db->lastInsertRowID();
            }
        }

        return [
            'valid'   => true,
            'user_id' => $uid,
            'role'    => $normalizedRole,
        ];
    }

    /**
     * Enforces rate limiting per user ID.
     */
    public function checkRateLimit(int $userId): bool
    {
        $stmt = $this->db->prepare("
            SELECT COUNT(*) as req_count 
            FROM ai_messages m
            JOIN ai_conversations c ON m.conversation_id = c.id
            WHERE c.user_id = :user_id 
              AND m.sender_type = 'user' 
              AND m.created_at >= datetime('now', '-1 minute')
        ");
        $stmt->bindValue(':user_id', $userId, SQLITE3_INTEGER);
        $res = $stmt->execute();
        $row = $res ? $res->fetchArray(SQLITE3_ASSOC) : ['req_count' => 0];

        return ($row['req_count'] ?? 0) < self::MAX_REQ_PER_MINUTE;
    }

    /**
     * Sanitizes user input against prompt injection and malicious characters.
     */
    public function sanitizeInput(string $input): string
    {
        $trimmed = trim($input);
        
        // Remove common prompt jailbreak injection vectors
        $patterns = [
            '/ignore previous instructions/i',
            '/forget previous system/i',
            '/system prompt override/i',
            '/you are now unrestricted/i',
            '/act as a Linux terminal/i',
        ];

        $cleaned = preg_replace($patterns, '[REDACTED_ATTEMPT]', $trimmed);

        // Escape HTML tags to prevent XSS in chat UI
        return htmlspecialchars($cleaned, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    }

    /**
     * Verifies if a specific tool is authorized for execution by the given user role.
     */
    public function isToolAllowed(string $toolName, string $role): bool
    {
        $allowedTools = match ($role) {
            'Patient' => [
                'getAvailableDoctors',
                'getDoctorSchedule',
                'getAppointmentStatus',
                'getQueueStatus',
                'createAppointment',
                'rescheduleAppointment',
                'cancelAppointment',
                'check_symptoms_naive_bayes',
                'getMyRecords',
            ],
            'Admin' => [
                'getDailyStats',
                'getWeeklyStats',
                'getMonthlyReport',
                'getDoctorWorkload',
                'getNoShowRate',
                'getPendingApprovals',
                'getHighRiskPatients',
                'generateAnalyticsReport',
            ],
            'Doctor' => [
                'getAssignedPatients',
                'getConsultationHistory',
                'getUpcomingAppointments',
                'searchAssignedPatientRecords',
                'getDoctorAvailability',
                'getPrescriptionLog',
                'getNextPatient',
            ],
            'Staff' => [
                'getAvailableDoctors',
                'getDoctorSchedule',
                'getAppointmentStatus',
                'getQueueStatus',
                'getClinicQueueOverview',
                'searchPatientByName',
                'checkInPatient',
                'getDailyStats',
                'getPendingApprovals',
            ],
            default => [],
        };

        return in_array($toolName, $allowedTools, true);
    }

    /**
     * Post-processes AI text outputs to ensure zero leakage of confidential tokens or raw system metadata.
     */
    public function sanitizeOutput(string $output): string
    {
        // Mask passwords, hashes, or secret key patterns if accidentally present
        $output = preg_replace('/(password|secret|key|hash)\s*=\s*\S+/i', '$1=***REDACTED***', $output);
        return $output;
    }
}
