<?php
/**
 * DoctorTools.php — Deterministic Tool Implementations for Doctor AI Assistant (Clinical Workflow)
 */

class DoctorTools
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
                'name'        => 'getAssignedPatients',
                'description' => 'Returns list of assigned patient consultations for the logged-in doctor on a specified date.',
                'parameters'  => [
                    'type'       => 'OBJECT',
                    'properties' => [
                        'date' => ['type' => 'STRING', 'description' => 'Target date (YYYY-MM-DD). Defaults to today.'],
                    ],
                ],
            ],
            [
                'name'        => 'getConsultationHistory',
                'description' => 'Retrieves past diagnoses, treatments, doctor notes, and prescriptions for a specific assigned patient.',
                'parameters'  => [
                    'type'       => 'OBJECT',
                    'properties' => [
                        'patient_id' => ['type' => 'INTEGER', 'description' => 'Patient ID to inspect.'],
                    ],
                    'required'   => ['patient_id'],
                ],
            ],
            [
                'name'        => 'getUpcomingAppointments',
                'description' => 'Fetches remaining scheduled consultations for the doctor.',
                'parameters'  => [
                    'type'       => 'OBJECT',
                    'properties' => (object)[],
                ],
            ],
            [
                'name'        => 'searchAssignedPatientRecords',
                'description' => 'Searches assigned patients by name or email to view their clinical records and appointment history.',
                'parameters'  => [
                    'type'       => 'OBJECT',
                    'properties' => [
                        'query' => ['type' => 'STRING', 'description' => 'Patient name or email search query.'],
                    ],
                    'required'   => ['query'],
                ],
            ],
            [
                'name'        => 'getDoctorAvailability',
                'description' => 'Retrieves active work availability shifts and scheduled days off for the doctor.',
                'parameters'  => [
                    'type'       => 'OBJECT',
                    'properties' => [
                        'month' => ['type' => 'INTEGER', 'description' => 'Month (1-12). Defaults to current month.'],
                        'year'  => ['type' => 'INTEGER', 'description' => 'Year (YYYY). Defaults to current year.'],
                    ],
                ],
            ],
            [
                'name'        => 'getPrescriptionLog',
                'description' => 'Retrieves recent prescription logs issued by the doctor.',
                'parameters'  => [
                    'type'       => 'OBJECT',
                    'properties' => (object)[],
                ],
            ],
            [
                'name'        => 'getNextPatient',
                'description' => 'Returns details of the immediate next patient waiting in the doctor consultation queue today.',
                'parameters'  => [
                    'type'       => 'OBJECT',
                    'properties' => (object)[],
                ],
            ],
        ];
    }

    public function getAssignedPatients(array $args, int $userId): array
    {
        $date = $args['date'] ?? date('Y-m-d');

        $stmt = $this->db->prepare("
            SELECT a.id as appointment_id, a.patient_id, u.name as patient_name, u.email,
                   a.time_slot, a.reason, a.status, a.queue_number
            FROM appointments a
            JOIN users u ON a.patient_id = u.id
            WHERE a.doctor_id = :doctor_id AND a.appointment_date = :date AND a.status != 'Cancelled'
            ORDER BY a.queue_number ASC
        ");
        $stmt->bindValue(':doctor_id', $userId, SQLITE3_INTEGER);
        $stmt->bindValue(':date', $date, SQLITE3_TEXT);
        $res = $stmt->execute();

        $patients = [];
        while ($row = $res->fetchArray(SQLITE3_ASSOC)) {
            $patients[] = $row;
        }

        return [
            'doctor_id'      => $userId,
            'date'           => $date,
            'patient_count'  => count($patients),
            'assigned_list'  => $patients,
        ];
    }

    public function getConsultationHistory(array $args, int $userId): array
    {
        $patientId = (int)($args['patient_id'] ?? 0);

        if (!$patientId) {
            return ['error' => 'Patient ID required.'];
        }

        // RBAC: Verify doctor has at least one appointment with this patient
        $stmtAssign = $this->db->prepare("
            SELECT COUNT(*) as cnt FROM appointments
            WHERE doctor_id = :doc_id AND patient_id = :p_id
        ");
        $stmtAssign->bindValue(':doc_id', $userId, SQLITE3_INTEGER);
        $stmtAssign->bindValue(':p_id', $patientId, SQLITE3_INTEGER);
        $resAssign = $stmtAssign->execute();
        $assigned = $resAssign ? $resAssign->fetchArray(SQLITE3_ASSOC)['cnt'] : 0;

        if ($assigned == 0) {
            return ['error' => 'Access denied. Patient is not assigned to you.'];
        }

        $stmtRecords = $this->db->prepare("
            SELECT record_id, diagnosis, treatment, consultation_date, doctor_notes
            FROM medical_records
            WHERE patient_id = :p_id
            ORDER BY consultation_date DESC
        ");
        $stmtRecords->bindValue(':p_id', $patientId, SQLITE3_INTEGER);
        $resRecords = $stmtRecords->execute();

        $records = [];
        while ($row = $resRecords->fetchArray(SQLITE3_ASSOC)) {
            $records[] = $row;
        }


        $stmtRx = $this->db->prepare("
            SELECT medication, dosage, frequency, created_at
            FROM prescriptions
            WHERE patient_id = :p_id
            ORDER BY created_at DESC
        ");
        $stmtRx->bindValue(':p_id', $patientId, SQLITE3_INTEGER);
        $resRx = $stmtRx->execute();

        $rxList = [];
        while ($row = $resRx->fetchArray(SQLITE3_ASSOC)) {
            $rxList[] = $row;
        }

        return [
            'patient_id'      => $patientId,
            'medical_records' => $records,
            'prescriptions'   => $rxList,
        ];
    }

    public function getUpcomingAppointments(array $args, int $userId): array
    {
        $today = date('Y-m-d');
        $stmt = $this->db->prepare("
            SELECT a.id, a.patient_id, u.name as patient_name, a.appointment_date, a.time_slot, a.reason, a.status
            FROM appointments a
            JOIN users u ON a.patient_id = u.id
            WHERE a.doctor_id = :doctor_id AND a.appointment_date >= :today AND a.status IN ('Scheduled', 'Approved')
            ORDER BY a.appointment_date ASC, a.queue_number ASC
            LIMIT 15
        ");
        $stmt->bindValue(':doctor_id', $userId, SQLITE3_INTEGER);
        $stmt->bindValue(':today', $today, SQLITE3_TEXT);
        $res = $stmt->execute();

        $upcoming = [];
        while ($row = $res->fetchArray(SQLITE3_ASSOC)) {
            $upcoming[] = $row;
        }

        return [
            'doctor_id' => $userId,
            'upcoming'  => $upcoming,
        ];
    }

    public function searchAssignedPatientRecords(array $args, int $userId): array
    {
        $query = trim($args['query'] ?? '');
        $stmt = $this->db->prepare("
            SELECT DISTINCT u.id, u.name, u.email, u.created_at
            FROM users u
            JOIN appointments a ON u.id = a.patient_id
            WHERE a.doctor_id = :did AND u.role = 'Patient'
              AND (u.name LIKE :q OR u.email LIKE :q)
            ORDER BY u.name ASC
            LIMIT 10
        ");
        $stmt->bindValue(':did', $userId, SQLITE3_INTEGER);
        $stmt->bindValue(':q', "%{$query}%", SQLITE3_TEXT);
        $res = $stmt->execute();

        $matches = [];
        while ($row = $res->fetchArray(SQLITE3_ASSOC)) {
            $matches[] = $row;
        }

        return [
            'query'        => $query,
            'doctor_id'    => $userId,
            'match_count'  => count($matches),
            'patients'     => $matches,
        ];
    }

    public function getDoctorAvailability(array $args, int $userId): array
    {
        $month = (int)($args['month'] ?? date('n'));
        $year  = (int)($args['year'] ?? date('Y'));

        $startDate = sprintf('%04d-%02d-01', $year, $month);
        $daysInMonth = (int)date('t', strtotime($startDate));
        $endDate = sprintf('%04d-%02d-%02d', $year, $month, $daysInMonth);

        $stmt = $this->db->prepare("
            SELECT available_date, status, notes
            FROM availability
            WHERE doctor_id = :did AND available_date BETWEEN :start_date AND :end_date
            ORDER BY available_date ASC
        ");
        $stmt->bindValue(':did', $userId, SQLITE3_INTEGER);
        $stmt->bindValue(':start_date', $startDate, SQLITE3_TEXT);
        $stmt->bindValue(':end_date', $endDate, SQLITE3_TEXT);
        $res = $stmt->execute();

        $slots = [];
        $availDays = 0;
        $unavailDays = 0;
        while ($row = $res->fetchArray(SQLITE3_ASSOC)) {
            $slots[] = $row;
            if ($row['status'] === 'Available') { $availDays++; }
            else { $unavailDays++; }
        }

        return [
            'doctor_id'        => $userId,
            'month'            => $month,
            'year'             => $year,
            'available_days'   => $availDays,
            'unavailable_days' => $unavailDays,
            'slots'            => $slots,
        ];
    }

    public function getPrescriptionLog(array $args, int $userId): array
    {
        $stmt = $this->db->prepare("
            SELECT p.id, p.medication, p.dosage, p.frequency, p.created_at, u.name as patient_name
            FROM prescriptions p
            JOIN users u ON p.patient_id = u.id
            WHERE p.doctor_id = :did
            ORDER BY p.created_at DESC
            LIMIT 10
        ");
        $stmt->bindValue(':did', $userId, SQLITE3_INTEGER);
        $res = $stmt->execute();

        $logs = [];
        while ($row = $res->fetchArray(SQLITE3_ASSOC)) {
            $logs[] = $row;
        }

        return [
            'doctor_id' => $userId,
            'log_count' => count($logs),
            'logs'      => $logs,
        ];
    }

    public function getNextPatient(array $args, int $userId): array
    {
        $today = date('Y-m-d');
        $stmt = $this->db->prepare("
            SELECT a.id, a.patient_id, u.name as patient_name, u.email, a.appointment_date, a.time_slot, a.reason, a.status, a.queue_number
            FROM appointments a
            JOIN users u ON a.patient_id = u.id
            WHERE a.doctor_id = :did AND a.appointment_date = :today AND a.status IN ('Scheduled', 'In Progress')
            ORDER BY a.queue_number ASC, a.time_slot ASC
            LIMIT 1
        ");
        $stmt->bindValue(':did', $userId, SQLITE3_INTEGER);
        $stmt->bindValue(':today', $today, SQLITE3_TEXT);
        $res = $stmt->execute();
        $next = $res ? $res->fetchArray(SQLITE3_ASSOC) : null;

        return [
            'doctor_id'    => $userId,
            'has_next'     => !empty($next),
            'next_patient' => $next,
        ];
    }
}
