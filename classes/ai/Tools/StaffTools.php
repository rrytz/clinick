<?php
/**
 * StaffTools.php — Deterministic Tool Implementations for Staff AI Assistant (Frontdesk Operations)
 */

class StaffTools
{
    private SQLite3 $db;

    public function __construct(SQLite3 $db)
    {
        $this->db = $db;
    }

    public static function getDeclarations(): array
    {
        return [
            [
                'name'        => 'getAvailableDoctors',
                'description' => 'Returns list of available doctors with specializations and open dates.',
                'parameters'  => [
                    'type'       => 'OBJECT',
                    'properties' => [
                        'date'           => ['type' => 'STRING', 'description' => 'Optional date in YYYY-MM-DD format.'],
                        'specialization' => ['type' => 'STRING', 'description' => 'Optional doctor specialization filter.'],
                    ],
                ],
            ],
            [
                'name'        => 'getDoctorSchedule',
                'description' => 'Returns available time slots for a specific doctor on a given date.',
                'parameters'  => [
                    'type'       => 'OBJECT',
                    'properties' => [
                        'doctor_id' => ['type' => 'INTEGER', 'description' => 'The ID of the doctor.'],
                        'date'      => ['type' => 'STRING', 'description' => 'Date in YYYY-MM-DD format.'],
                    ],
                    'required'   => ['doctor_id', 'date'],
                ],
            ],
            [
                'name'        => 'getAppointmentStatus',
                'description' => 'Retrieves details and status of a specific appointment or lookup by ID.',
                'parameters'  => [
                    'type'       => 'OBJECT',
                    'properties' => [
                        'appointment_id' => ['type' => 'INTEGER', 'description' => 'Optional specific appointment ID.'],
                    ],
                ],
            ],
            [
                'name'        => 'getClinicQueueOverview',
                'description' => 'Retrieves total live queue numbers, patients waiting, and active doctor consultations across the clinic for today.',
                'parameters'  => [
                    'type'       => 'OBJECT',
                    'properties' => [
                        'date' => ['type' => 'STRING', 'description' => 'Target date (YYYY-MM-DD). Defaults to today.'],
                    ],
                ],
            ],
            [
                'name'        => 'searchPatientByName',
                'description' => 'Searches registered patient index by name or email for frontdesk lookup. Does not return clinical notes.',
                'parameters'  => [
                    'type'       => 'OBJECT',
                    'properties' => [
                        'query' => ['type' => 'STRING', 'description' => 'Patient name or email snippet to search.'],
                    ],
                    'required'   => ['query'],
                ],
            ],
            [
                'name'        => 'checkInPatient',
                'description' => 'Marks an arriving patient\'s scheduled appointment as checked-in / In Progress for today\'s queue.',
                'parameters'  => [
                    'type'       => 'OBJECT',
                    'properties' => [
                        'appointment_id' => ['type' => 'INTEGER', 'description' => 'ID of appointment to check in.'],
                    ],
                    'required'   => ['appointment_id'],
                ],
            ],
            [
                'name'        => 'getDailyStats',
                'description' => 'Returns operational daily statistics including scheduled appointment count and pending approvals.',
                'parameters'  => [
                    'type'       => 'OBJECT',
                    'properties' => [
                        'date' => ['type' => 'STRING', 'description' => 'Target date in YYYY-MM-DD format.'],
                    ],
                ],
            ],
            [
                'name'        => 'getPendingApprovals',
                'description' => 'Returns user registration accounts or appointments currently awaiting approval.',
                'parameters'  => [
                    'type'       => 'OBJECT',
                    'properties' => (object)[],
                ],
            ],
        ];
    }

    public function getClinicQueueOverview(array $args, int $userId): array
    {
        $date = $args['date'] ?? date('Y-m-d');

        $stmtTotal = $this->db->prepare("
            SELECT COUNT(*) as total_scheduled
            FROM appointments
            WHERE appointment_date = :date AND status IN ('Scheduled', 'Approved', 'In Progress')
        ");
        $stmtTotal->bindValue(':date', $date, SQLITE3_TEXT);
        $totalRes = $stmtTotal->execute()->fetchArray(SQLITE3_ASSOC);

        $stmtInProg = $this->db->prepare("
            SELECT COUNT(*) as in_progress
            FROM appointments
            WHERE appointment_date = :date AND status = 'In Progress'
        ");
        $stmtInProg->bindValue(':date', $date, SQLITE3_TEXT);
        $inProgRes = $stmtInProg->execute()->fetchArray(SQLITE3_ASSOC);

        $stmtQueue = $this->db->prepare("
            SELECT a.id as appointment_id, a.queue_number, a.time_slot, a.status,
                   u.name as patient_name, d.name as doctor_name
            FROM appointments a
            JOIN users u ON a.patient_id = u.id
            JOIN users d ON a.doctor_id = d.id
            WHERE a.appointment_date = :date AND a.status IN ('Scheduled', 'Approved', 'In Progress')
            ORDER BY a.queue_number ASC
        ");
        $stmtQueue->bindValue(':date', $date, SQLITE3_TEXT);
        $resQueue = $stmtQueue->execute();

        $queueList = [];
        while ($row = $resQueue->fetchArray(SQLITE3_ASSOC)) {
            $queueList[] = $row;
        }

        return [
            'date'              => $date,
            'total_in_queue'    => $totalRes['total_scheduled'] ?? 0,
            'currently_in_room' => $inProgRes['in_progress'] ?? 0,
            'queue_list'        => $queueList,
        ];
    }

    public function searchPatientByName(array $args, int $userId): array
    {
        $query = trim($args['query'] ?? '');

        // 1. Minimum search query length constraint (anti-enumeration)
        if (strlen($query) < 2) {
            return ['error' => 'Patient search query must be at least 2 characters to prevent broad database enumeration.'];
        }

        // 2. Safeguard against bulk enumeration requests
        $lower = strtolower($query);
        $bulkPhrases = ['all', 'every', 'show all', 'list all', 'all patients', 'every patient', 'show me all patients', 'list every patient', '%', '*'];
        if (in_array($lower, $bulkPhrases, true)) {
            return ['error' => 'Bulk patient enumeration is disabled for security and PHI protection. Please search by specific patient name or email.'];
        }

        // 3. Clean punctuation noise, strip stop words, and tokenize multi-word queries for flexible matching
        $stopWords = ['is', 'there', 'are', 'was', 'were', 'who', 'someone', 'named', 'find', 'search', 'lookup', 'patient', 'patients', 'the', 'a', 'an', 'for', 'of', 'in', 'on', 'at', 'check', 'show', 'list', 'me', 'please', 'any'];

        $cleanedQuery = trim(preg_replace('/[^\p{L}\p{N}\s@.-]/u', '', $query));
        if (empty($cleanedQuery)) {
            $cleanedQuery = $query;
        }

        $words = preg_split('/\s+/', $cleanedQuery, -1, PREG_SPLIT_NO_EMPTY);
        if (empty($words)) {
            return ['error' => 'Patient search query cannot be empty.'];
        }

        $whereClauses = [];
        $params = [];
        $validWordCount = 0;

        foreach ($words as $idx => $word) {
            $cleanWord = trim(preg_replace('/[^\p{L}\p{N}@.-]/u', '', $word), '.-');
            $lowerWord = strtolower($cleanWord);

            if (strlen($cleanWord) < 2 || in_array($lowerWord, $stopWords, true)) {
                continue;
            }

            $safeWord = str_replace(['%', '_'], ['\%', '\_'], $cleanWord);
            $paramName = ":q{$idx}";
            $whereClauses[] = "(name LIKE {$paramName} ESCAPE '\\' OR email LIKE {$paramName} ESCAPE '\\')";
            $params[$paramName] = '%' . $safeWord . '%';
            $validWordCount++;
        }

        if ($validWordCount === 0) {
            return ['error' => 'Patient search query must contain at least 2 characters of valid search text.'];
        }

        $whereSql = implode(' AND ', $whereClauses);

        $stmt = $this->db->prepare("
            SELECT id, name, email, created_at
            FROM users
            WHERE role = 'Patient' AND {$whereSql}
            ORDER BY name ASC
            LIMIT 10
        ");

        foreach ($params as $pName => $pVal) {
            $stmt->bindValue($pName, $pVal, SQLITE3_TEXT);
        }

        $res = $stmt->execute();

        $patients = [];
        while ($row = $res->fetchArray(SQLITE3_ASSOC)) {
            $patients[] = $row;
        }

        return [
            'query'         => $query,
            'match_count'   => count($patients),
            'patients'      => $patients,
        ];
    }

    public function checkInPatient(array $args, int $userId): array
    {
        $appId = (int)($args['appointment_id'] ?? 0);
        $nameQuery = trim($args['patient_name'] ?? $args['query'] ?? '');

        // Ambiguity resolution: if no ID provided, search scheduled appointments for today by name
        if (!$appId && !empty($nameQuery)) {
            $today = date('Y-m-d');
            $stmtFind = $this->db->prepare("
                SELECT a.id, a.time_slot, u.name as patient_name
                FROM appointments a
                JOIN users u ON a.patient_id = u.id
                WHERE a.appointment_date = :date
                  AND (u.name LIKE :q OR u.email LIKE :q)
                  AND a.status IN ('Scheduled', 'Approved')
            ");
            $stmtFind->bindValue(':date', $today, SQLITE3_TEXT);
            $stmtFind->bindValue(':q', '%' . $nameQuery . '%', SQLITE3_TEXT);
            $resFind = $stmtFind->execute();

            $matches = [];
            while ($row = $resFind->fetchArray(SQLITE3_ASSOC)) {
                $matches[] = $row;
            }

            if (count($matches) === 0) {
                return ['error' => "No scheduled appointment found for today matching '{$nameQuery}'."];
            } elseif (count($matches) === 1) {
                $appId = (int)$matches[0]['id'];
            } else {
                return [
                    'ambiguous'    => true,
                    'match_count'  => count($matches),
                    'matches'      => $matches,
                    'message'      => "Multiple appointments found for '{$nameQuery}' today. Please specify the exact Appointment ID.",
                ];
            }
        }

        if (!$appId) {
            return ['error' => 'Appointment ID or Patient Name required for check-in.'];
        }

        $stmtCheck = $this->db->prepare("
            SELECT a.id, a.status, u.name as patient_name
            FROM appointments a
            JOIN users u ON a.patient_id = u.id
            WHERE a.id = :id
        ");
        $stmtCheck->bindValue(':id', $appId, SQLITE3_INTEGER);
        $resCheck = $stmtCheck->execute()->fetchArray(SQLITE3_ASSOC);

        if (!$resCheck) {
            return ['error' => 'Appointment ID #' . $appId . ' not found.'];
        }

        if ($resCheck['status'] === 'Completed' || $resCheck['status'] === 'Cancelled' || $resCheck['status'] === 'In Progress') {
            return ['error' => 'Appointment #' . $appId . ' is already ' . $resCheck['status'] . '. Cannot check in again.'];
        }

        $stmtUp = $this->db->prepare("UPDATE appointments SET status = 'In Progress' WHERE id = :id");
        $stmtUp->bindValue(':id', $appId, SQLITE3_INTEGER);
        $ok = $stmtUp->execute();

        return $ok ? [
            'success'        => true,
            'appointment_id' => $appId,
            'patient_name'   => $resCheck['patient_name'],
            'status'         => 'In Progress',
            'message'        => 'Patient ' . $resCheck['patient_name'] . ' successfully checked in.',
        ] : ['error' => 'Failed to update check-in status.'];
    }
}
