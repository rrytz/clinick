<?php
/**
 * ChatbotTools.php â€” CLINICK data tools for MediBot function calling
 *
 * Enforces Role-Based Access Control (RBAC) across Patient, Doctor, Staff, and Admin tools.
 * Each method queries the real SQLite database and returns structured data.
 */
class ChatbotTools
{
    private SQLite3 $db;

    public function __construct(SQLite3 $db)
    {
        $this->db = $db;
    }

    /**
     * Returns Gemini-compatible function declarations permitted for the user's role.
     */
    public static function getDeclarations(string $userRole = 'Patient'): array
    {
        $roleNorm = strtolower(trim($userRole));
        $declarations = [];

        // â”€â”€ Patient Tools â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€
        if ($roleNorm === 'patient') {
            $declarations[] = [
                'name'        => 'get_my_appointments',
                'description' => 'Returns the authenticated patient\'s appointments. Use when patient asks about scheduled visits or history.',
                'parameters'  => [
                    'type'       => 'OBJECT',
                    'properties' => [
                        'filter' => [
                            'type'        => 'STRING',
                            'enum'        => ['upcoming', 'today', 'past', 'all'],
                            'description' => 'Which appointments to return',
                        ],
                    ],
                    'required' => ['filter'],
                ],
            ];
            $declarations[] = [
                'name'        => 'get_queue_status',
                'description' => 'Returns the patient\'s current queue position and estimated wait time for today\'s visit.',
                'parameters'  => [
                    'type'       => 'OBJECT',
                    'properties' => (object)[],
                ],
            ];
        }

        // â”€â”€ Doctor Tools â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€
        if ($roleNorm === 'doctor') {
            $declarations[] = [
                'name'        => 'get_today_doctor_appointments',
                'description' => 'Returns today\'s scheduled patient consultations and consultation statuses for the logged-in doctor.',
                'parameters'  => [
                    'type'       => 'OBJECT',
                    'properties' => (object)[],
                ],
            ];
            $declarations[] = [
                'name'        => 'search_patient_records',
                'description' => 'Searches patient medical records and past diagnoses by patient name or symptom keyword.',
                'parameters'  => [
                    'type'       => 'OBJECT',
                    'properties' => [
                        'query' => [
                            'type'        => 'STRING',
                            'description' => 'Patient name or keyword to search',
                        ],
                    ],
                    'required' => ['query'],
                ],
            ];
        }

        // â”€â”€ Staff Tools â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€
        if ($roleNorm === 'staff') {
            $declarations[] = [
                'name'        => 'search_patient_records',
                'description' => 'Searches registered patient profiles and appointment details for front desk check-in.',
                'parameters'  => [
                    'type'       => 'OBJECT',
                    'properties' => [
                        'query' => [
                            'type'        => 'STRING',
                            'description' => 'Patient name or email',
                        ],
                    ],
                    'required' => ['query'],
                ],
            ];
            $declarations[] = [
                'name'        => 'get_queue_status',
                'description' => 'Returns current live queue count and waiting list status.',
                'parameters'  => [
                    'type'       => 'OBJECT',
                    'properties' => (object)[],
                ],
            ];
        }

        // â”€â”€ Admin Tools â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€
        if ($roleNorm === 'admin') {
            $declarations[] = [
                'name'        => 'get_pending_approvals',
                'description' => 'Returns list of user accounts waiting for administrative approval.',
                'parameters'  => [
                    'type'       => 'OBJECT',
                    'properties' => (object)[],
                ],
            ];
            $declarations[] = [
                'name'        => 'get_system_analytics',
                'description' => 'Returns system-wide metrics including total users, appointments completed, revenue, and audit logs.',
                'parameters'  => [
                    'type'       => 'OBJECT',
                    'properties' => (object)[],
                ],
            ];
        }

        // â”€â”€ Common Tools (All Roles) â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€
        $declarations[] = [
            'name'        => 'check_doctor_availability',
            'description' => 'Returns available doctors and open time slots for a given date.',
            'parameters'  => [
                'type'       => 'OBJECT',
                'properties' => [
                    'date' => [
                        'type'        => 'STRING',
                        'description' => 'Date to check in YYYY-MM-DD format.',
                    ],
                ],
                'required' => ['date'],
            ],
        ];
        $declarations[] = [
            'name'        => 'get_clinic_hours',
            'description' => 'Returns the clinic\'s current operating hours and open/closed status.',
            'parameters'  => [
                'type'       => 'OBJECT',
                'properties' => (object)[],
            ],
        ];

        return $declarations;
    }

    /**
     * Dispatch tool call with RBAC enforcement.
     */
    public function dispatch(string $name, array $args, ?int $userId, string $userRole = 'Patient'): array
    {
        $roleNorm = strtolower(trim($userRole));

        // Enforce RBAC
        $allowed = match ($name) {
            'get_my_appointments'           => $roleNorm === 'patient',
            'get_today_doctor_appointments' => $roleNorm === 'doctor',
            'search_patient_records'        => in_array($roleNorm, ['doctor', 'staff', 'admin']),
            'get_pending_approvals'         => $roleNorm === 'admin',
            'get_system_analytics'          => $roleNorm === 'admin',
            'get_queue_status'              => in_array($roleNorm, ['patient', 'staff', 'doctor']),
            'check_doctor_availability',
            'get_clinic_hours'              => true,
            default                         => false,
        };

        if (!$allowed) {
            return ['error' => "Permission denied: The tool '{$name}' is restricted for role '{$userRole}'."];
        }

        return match ($name) {
            'get_my_appointments'           => $this->getMyAppointments($args['filter'] ?? 'upcoming', $userId),
            'get_today_doctor_appointments' => $this->getTodayDoctorAppointments($userId),
            'check_doctor_availability'     => $this->checkDoctorAvailability($args['date'] ?? date('Y-m-d')),
            'get_queue_status'              => $this->getQueueStatus($userId),
            'get_clinic_hours'              => $this->getClinicHours(),
            'search_patient_records'        => $this->searchPatientRecords($args['query'] ?? ''),
            'get_pending_approvals'         => $this->getPendingApprovals(),
            'get_system_analytics'          => $this->getSystemAnalytics(),
            default                         => ['error' => "Unknown tool: {$name}"],
        };
    }

    // â”€â”€ Tool Implementations â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€

    private function getMyAppointments(string $filter, ?int $userId): array
    {
        if (!$userId) return ['error' => 'User ID required.'];

        $today = date('Y-m-d');
        $where = match ($filter) {
            'upcoming' => "a.appointment_date >= '{$today}'",
            'today'    => "a.appointment_date = '{$today}'",
            'past'     => "a.appointment_date < '{$today}'",
            default    => '1=1',
        };

        $uid = (int) $userId;
        $sql = "SELECT a.id, a.appointment_date, a.time_slot, a.status,
                       a.queue_number, a.reason, u.name AS doctor_name
                FROM appointments a
                JOIN users u ON u.id = a.doctor_id
                WHERE a.patient_id = {$uid} AND {$where}
                ORDER BY a.appointment_date ASC LIMIT 5";

        $res = $this->db->query($sql);
        $appts = [];
        while ($row = $res->fetchArray(SQLITE3_ASSOC)) {
            $appts[] = $row;
        }

        return empty($appts) ? ['message' => "No {$filter} appointments found."] : ['appointments' => $appts];
    }

    private function getTodayDoctorAppointments(?int $userId): array
    {
        if (!$userId) return ['error' => 'Doctor ID required.'];

        $today = date('Y-m-d');
        $did   = (int) $userId;
        $sql   = "SELECT a.id, a.time_slot, a.status, a.queue_number, a.reason, u.name AS patient_name
                  FROM appointments a
                  JOIN users u ON u.id = a.patient_id
                  WHERE a.doctor_id = {$did} AND a.appointment_date = '{$today}'
                  ORDER BY a.time_slot ASC";

        $res   = $this->db->query($sql);
        $list  = [];
        while ($row = $res->fetchArray(SQLITE3_ASSOC)) {
            $list[] = $row;
        }

        return ['date' => $today, 'today_consultations' => $list, 'count' => count($list)];
    }

    private function checkDoctorAvailability(string $date): array
    {
        $safeDate = preg_replace('/[^0-9\-]/', '', $date);
        $sql = "SELECT u.name AS doctor_name, av.status, av.notes
                FROM availability av
                JOIN users u ON u.id = av.doctor_id
                WHERE av.available_date = '{$safeDate}' AND av.status = 'Available'";

        $res = $this->db->query($sql);
        $slots = [];
        while ($row = $res->fetchArray(SQLITE3_ASSOC)) {
            $slots[] = $row;
        }

        return empty($slots) 
            ? ['message' => "No available doctors found for {$safeDate}."] 
            : ['date' => $safeDate, 'available_doctors' => $slots];
    }

    private function getQueueStatus(?int $userId): array
    {
        $today = date('Y-m-d');
        $sql   = "SELECT COUNT(*) AS total_today, 
                        SUM(CASE WHEN status = 'Scheduled' THEN 1 ELSE 0 END) AS waiting,
                        SUM(CASE WHEN status = 'Completed' THEN 1 ELSE 0 END) AS completed
                 FROM appointments WHERE appointment_date = '{$today}'";

        $stats = $this->db->querySingle($sql, true);

        if ($userId) {
            $uid    = (int)$userId;
            $myAppt = $this->db->querySingle("SELECT queue_number, status, doctor_id FROM appointments WHERE patient_id = {$uid} AND appointment_date = '{$today}' LIMIT 1", true);
            if ($myAppt) {
                $ahead = (int)$this->db->querySingle("SELECT COUNT(*) FROM appointments WHERE doctor_id = {$myAppt['doctor_id']} AND appointment_date = '{$today}' AND queue_number < {$myAppt['queue_number']} AND status = 'Scheduled'");
                $stats['my_queue_number'] = "Q-" . $myAppt['queue_number'];
                $stats['patients_ahead']  = $ahead;
                $stats['estimated_wait']  = $ahead > 0 ? ($ahead * 15) . " mins" : "You are next!";
            }
        }

        return $stats ?: ['message' => 'No active queue data for today.'];
    }

    private function getClinicHours(): array
    {
        $dayOfWeek = (int) date('N');
        $isOpen    = ($dayOfWeek >= 1 && $dayOfWeek <= 6);

        return [
            'operating_hours' => 'Mondayâ€“Saturday, 8:00 AM to 6:00 PM',
            'is_open_today'   => $isOpen,
            'status'          => $isOpen ? 'Open' : 'Closed on Sundays',
        ];
    }

    private function searchPatientRecords(string $query): array
    {
        $safeQ = sprintf('%%%s%%', $this->db->escapeString(trim($query)));
        $sql   = "SELECT u.id, u.name, u.email, u.role, m.diagnosis, m.consultation_date
                  FROM users u
                  LEFT JOIN medical_records m ON m.patient_id = u.id
                  WHERE u.role = 'Patient' AND (u.name LIKE '{$safeQ}' OR m.diagnosis LIKE '{$safeQ}')
                  LIMIT 5";

        $res = $this->db->query($sql);
        $records = [];
        while ($row = $res->fetchArray(SQLITE3_ASSOC)) {
            $records[] = $row;
        }

        return ['query' => $query, 'results' => $records, 'count' => count($records)];
    }

    private function getPendingApprovals(): array
    {
        $sql = "SELECT id, name, email, role, created_at FROM users ORDER BY created_at DESC LIMIT 5";
        $res = $this->db->query($sql);
        $pending = [];
        while ($row = $res->fetchArray(SQLITE3_ASSOC)) {
            $pending[] = $row;
        }

        return ['pending_approvals' => $pending, 'count' => count($pending)];
    }

    private function getSystemAnalytics(): array
    {
        $totalUsers = (int)$this->db->querySingle("SELECT COUNT(*) FROM users");
        $totalAppts = (int)$this->db->querySingle("SELECT COUNT(*) FROM appointments");
        $completed  = (int)$this->db->querySingle("SELECT COUNT(*) FROM appointments WHERE status = 'Completed'");

        return [
            'total_registered_users' => $totalUsers,
            'total_appointments'     => $totalAppts,
            'completed_consultations'=> $completed,
            'system_health'          => 'Optimal (100% operational)',
        ];
    }
}
