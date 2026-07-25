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
}
