<?php
/**
 * PatientTools.php — Deterministic Tool Implementations for Patient AI Assistant
 */

class PatientTools
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
                        'specialization' => ['type' => 'STRING', 'description' => 'Optional doctor specialization filter (e.g., Pediatrics, General Medicine).'],
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
                'description' => 'Retrieves details and status of an appointment or all appointments for the patient.',
                'parameters'  => [
                    'type'       => 'OBJECT',
                    'properties' => [
                        'appointment_id' => ['type' => 'INTEGER', 'description' => 'Optional specific appointment ID.'],
                    ],
                ],
            ],
            [
                'name'        => 'getQueueStatus',
                'description' => 'Returns current live queue position, queue number, and estimated wait time for today\'s visit.',
                'parameters'  => [
                    'type'       => 'OBJECT',
                    'properties' => (object)[],
                ],
            ],
            [
                'name'        => 'createAppointment',
                'description' => 'Books a new appointment for the patient with a specific doctor, date, and time slot.',
                'parameters'  => [
                    'type'       => 'OBJECT',
                    'properties' => [
                        'doctor_id' => ['type' => 'INTEGER', 'description' => 'Doctor ID to book with.'],
                        'date'      => ['type' => 'STRING', 'description' => 'Appointment date (YYYY-MM-DD).'],
                        'time_slot' => ['type' => 'STRING', 'description' => 'Time slot (e.g., 09:00 AM, 02:30 PM).'],
                        'reason'    => ['type' => 'STRING', 'description' => 'Brief reason for consultation.'],
                    ],
                    'required'   => ['doctor_id', 'date', 'time_slot'],
                ],
            ],
            [
                'name'        => 'rescheduleAppointment',
                'description' => 'Reschedules an existing appointment to a new date and time slot.',
                'parameters'  => [
                    'type'       => 'OBJECT',
                    'properties' => [
                        'appointment_id' => ['type' => 'INTEGER', 'description' => 'ID of appointment to reschedule.'],
                        'new_date'       => ['type' => 'STRING', 'description' => 'New target date (YYYY-MM-DD).'],
                        'new_time_slot'  => ['type' => 'STRING', 'description' => 'New target time slot.'],
                    ],
                    'required'   => ['appointment_id', 'new_date', 'new_time_slot'],
                ],
            ],
            [
                'name'        => 'cancelAppointment',
                'description' => 'Cancels an existing appointment for the logged-in patient.',
                'parameters'  => [
                    'type'       => 'OBJECT',
                    'properties' => [
                        'appointment_id' => ['type' => 'INTEGER', 'description' => 'ID of appointment to cancel.'],
                        'reason'         => ['type' => 'STRING', 'description' => 'Optional cancellation reason.'],
                    ],
                    'required'   => ['appointment_id'],
                ],
            ],
            [
                'name'        => 'check_symptoms_naive_bayes',
                'description' => 'Assesses patient-described symptoms using a deterministic Naive Bayes classifier. MUST be invoked whenever the patient describes symptoms or asks what condition they might have. DO NOT attempt to diagnose using pre-trained knowledge.',
                'parameters'  => [
                    'type'       => 'OBJECT',
                    'properties' => [
                        'symptom_text' => ['type' => 'STRING', 'description' => 'Patient-described symptoms or physical discomfort.'],
                    ],
                    'required'   => ['symptom_text'],
                ],
            ],
            [
                'name'        => 'getMyRecords',
                'description' => 'Retrieves the logged-in patient\'s own medical records including consultation history, diagnoses, treatments, doctor notes, and prescriptions. Does not accept a patient_id parameter — always returns only the authenticated user\'s own records.',
                'parameters'  => [
                    'type'       => 'OBJECT',
                    'properties' => [
                        'record_type' => ['type' => 'STRING', 'description' => 'Optional filter: "consultations", "prescriptions", or "all" (default).'],
                    ],
                ],
            ],
        ];
    }

    public function getAvailableDoctors(array $args, int $userId): array
    {
        $date = $args['date'] ?? date('Y-m-d');
        $spec = $args['specialization'] ?? '';

        $query = "
            SELECT u.id as doctor_id, u.name as doctor_name, 
                   COALESCE(d.specialization, 'General Medicine') as specialization,
                   COALESCE(d.availability_status, 'Available') as status
            FROM users u
            LEFT JOIN doctors d ON u.id = d.doctor_id
            WHERE u.role IN ('Doctor', 'Staff') AND u.status = 'Active'
        ";

        if (!empty($spec)) {
            $query .= " AND (d.specialization LIKE :spec OR u.name LIKE :spec)";
        }

        $stmt = $this->db->prepare($query);
        if (!empty($spec)) {
            $stmt->bindValue(':spec', "%$spec%", SQLITE3_TEXT);
        }

        $res = $stmt->execute();
        $doctors = [];
        while ($row = $res->fetchArray(SQLITE3_ASSOC)) {
            $doctors[] = $row;
        }

        return [
            'query_date' => $date,
            'count'      => count($doctors),
            'doctors'    => $doctors,
        ];
    }

    public function getDoctorSchedule(array $args, int $userId): array
    {
        $doctorId = (int)($args['doctor_id'] ?? 0);
        $date     = $args['date'] ?? date('Y-m-d');

        // Fetch doctor info
        $stmtDoc = $this->db->prepare("SELECT name FROM users WHERE id = :id AND role = 'Doctor'");
        $stmtDoc->bindValue(':id', $doctorId, SQLITE3_INTEGER);
        $resDoc = $stmtDoc->execute();
        $doc = $resDoc ? $resDoc->fetchArray(SQLITE3_ASSOC) : null;

        if (!$doc) {
            return ['error' => 'Doctor not found.'];
        }

        // Standard time slots
        $allSlots = ['09:00 AM', '10:00 AM', '11:00 AM', '01:00 PM', '02:00 PM', '03:00 PM', '04:00 PM'];

        // Booked slots query
        $stmtBooked = $this->db->prepare("
            SELECT time_slot FROM appointments 
            WHERE doctor_id = :doc_id AND appointment_date = :date AND status != 'Cancelled'
        ");
        $stmtBooked->bindValue(':doc_id', $doctorId, SQLITE3_INTEGER);
        $stmtBooked->bindValue(':date', $date, SQLITE3_TEXT);
        $resBooked = $stmtBooked->execute();

        $booked = [];
        while ($row = $resBooked->fetchArray(SQLITE3_ASSOC)) {
            $booked[] = $row['time_slot'];
        }

        $availableSlots = array_values(array_diff($allSlots, $booked));

        return [
            'doctor_id'       => $doctorId,
            'doctor_name'     => $doc['name'],
            'date'            => $date,
            'available_slots' => $availableSlots,
            'booked_slots'    => $booked,
        ];
    }

    public function getAppointmentStatus(array $args, int $userId): array
    {
        $appId = isset($args['appointment_id']) ? (int)$args['appointment_id'] : null;

        if ($appId) {
            $stmt = $this->db->prepare("
                SELECT a.id, a.appointment_date, a.time_slot, a.reason, a.status, a.queue_number,
                       u.name as doctor_name
                FROM appointments a
                JOIN users u ON a.doctor_id = u.id
                WHERE a.id = :app_id AND a.patient_id = :patient_id
            ");
            $stmt->bindValue(':app_id', $appId, SQLITE3_INTEGER);
            $stmt->bindValue(':patient_id', $userId, SQLITE3_INTEGER);
            $res = $stmt->execute();
            $item = $res ? $res->fetchArray(SQLITE3_ASSOC) : null;

            return $item ? ['appointment' => $item] : ['error' => 'Appointment not found or unauthorized.'];
        }

        $stmt = $this->db->prepare("
            SELECT a.id, a.appointment_date, a.time_slot, a.reason, a.status, a.queue_number,
                   u.name as doctor_name
            FROM appointments a
            JOIN users u ON a.doctor_id = u.id
            WHERE a.patient_id = :patient_id
            ORDER BY a.appointment_date DESC, a.time_slot ASC
            LIMIT 10
        ");
        $stmt->bindValue(':patient_id', $userId, SQLITE3_INTEGER);
        $res = $stmt->execute();

        $list = [];
        while ($row = $res->fetchArray(SQLITE3_ASSOC)) {
            $list[] = $row;
        }

        return ['appointments' => $list];
    }

    public function getQueueStatus(array $args, int $userId): array
    {
        $today = date('Y-m-d');
        $stmt = $this->db->prepare("
            SELECT a.id, a.queue_number, a.status, a.time_slot, u.name as doctor_name
            FROM appointments a
            JOIN users u ON a.doctor_id = u.id
            WHERE a.patient_id = :patient_id AND a.appointment_date = :today AND a.status IN ('Scheduled', 'Approved', 'In Progress')
            ORDER BY a.queue_number ASC LIMIT 1
        ");
        $stmt->bindValue(':patient_id', $userId, SQLITE3_INTEGER);
        $stmt->bindValue(':today', $today, SQLITE3_TEXT);
        $res = $stmt->execute();
        $myQueue = $res ? $res->fetchArray(SQLITE3_ASSOC) : null;

        if (!$myQueue) {
            return [
                'has_queue_today' => false,
                'message'         => 'No active appointment or queue ticket found for today.',
            ];
        }

        $qNum = $myQueue['queue_number'] ?? 1;

        // Calculate patients ahead
        $stmtAhead = $this->db->prepare("
            SELECT COUNT(*) as count_ahead 
            FROM appointments 
            WHERE appointment_date = :today AND status IN ('Scheduled', 'Approved') AND queue_number < :q_num
        ");
        $stmtAhead->bindValue(':today', $today, SQLITE3_TEXT);
        $stmtAhead->bindValue(':q_num', $qNum, SQLITE3_INTEGER);
        $resAhead = $stmtAhead->execute();
        $rowAhead = $resAhead ? $resAhead->fetchArray(SQLITE3_ASSOC) : ['count_ahead' => 0];

        $ahead = $rowAhead['count_ahead'] ?? 0;
        $estWaitMinutes = $ahead * 15;

        return [
            'has_queue_today'  => true,
            'queue_number'     => $qNum,
            'doctor_name'      => $myQueue['doctor_name'],
            'time_slot'        => $myQueue['time_slot'],
            'patients_ahead'   => $ahead,
            'est_wait_minutes' => $estWaitMinutes,
            'status'           => $myQueue['status'],
        ];
    }

    public function createAppointment(array $args, int $userId): array
    {
        $doctorId = (int)($args['doctor_id'] ?? 0);
        $date     = trim($args['date'] ?? '');
        $slot     = trim($args['time_slot'] ?? '');
        $reason   = trim($args['reason'] ?? 'General Consultation');

        if (!$doctorId || !$date || !$slot) {
            return ['error' => 'Missing doctor_id, date, or time_slot for appointment creation.'];
        }

        // Calculate queue number for the date
        $stmtQ = $this->db->prepare("SELECT MAX(queue_number) as max_q FROM appointments WHERE appointment_date = :date");
        $stmtQ->bindValue(':date', $date, SQLITE3_TEXT);
        $resQ = $stmtQ->execute();
        $rowQ = $resQ ? $resQ->fetchArray(SQLITE3_ASSOC) : ['max_q' => 0];
        $nextQueue = ($rowQ['max_q'] ?? 0) + 1;

        $stmt = $this->db->prepare("
            INSERT INTO appointments (patient_id, doctor_id, appointment_date, time_slot, reason, status, queue_number)
            VALUES (:patient_id, :doctor_id, :date, :slot, :reason, 'Scheduled', :q_num)
        ");
        $stmt->bindValue(':patient_id', $userId, SQLITE3_INTEGER);
        $stmt->bindValue(':doctor_id', $doctorId, SQLITE3_INTEGER);
        $stmt->bindValue(':date', $date, SQLITE3_TEXT);
        $stmt->bindValue(':slot', $slot, SQLITE3_TEXT);
        $stmt->bindValue(':reason', $reason, SQLITE3_TEXT);
        $stmt->bindValue(':q_num', $nextQueue, SQLITE3_INTEGER);

        $ok = $stmt->execute();
        if (!$ok) {
            return ['error' => 'Failed to record appointment in database.'];
        }

        $newId = $this->db->lastInsertRowID();

        return [
            'success'        => true,
            'appointment_id' => $newId,
            'date'           => $date,
            'time_slot'      => $slot,
            'queue_number'   => $nextQueue,
            'status'         => 'Scheduled',
            'message'        => 'Appointment successfully booked!',
        ];
    }

    public function rescheduleAppointment(array $args, int $userId): array
    {
        $appId   = (int)($args['appointment_id'] ?? 0);
        $newDate = trim($args['new_date'] ?? '');
        $newSlot = trim($args['new_time_slot'] ?? '');

        if (!$appId || !$newDate || !$newSlot) {
            return ['error' => 'Appointment ID, new date, and new time slot are required.'];
        }

        $stmtCheck = $this->db->prepare("SELECT id FROM appointments WHERE id = :id AND patient_id = :p_id");
        $stmtCheck->bindValue(':id', $appId, SQLITE3_INTEGER);
        $stmtCheck->bindValue(':p_id', $userId, SQLITE3_INTEGER);
        $resCheck = $stmtCheck->execute();
        if (!$resCheck || !$resCheck->fetchArray()) {
            return ['error' => 'Appointment not found or unauthorized to modify.'];
        }

        $stmt = $this->db->prepare("
            UPDATE appointments 
            SET appointment_date = :new_date, time_slot = :new_slot, status = 'Rescheduled'
            WHERE id = :id AND patient_id = :p_id
        ");
        $stmt->bindValue(':new_date', $newDate, SQLITE3_TEXT);
        $stmt->bindValue(':new_slot', $newSlot, SQLITE3_TEXT);
        $stmt->bindValue(':id', $appId, SQLITE3_INTEGER);
        $stmt->bindValue(':p_id', $userId, SQLITE3_INTEGER);

        $ok = $stmt->execute();

        return $ok ? [
            'success'        => true,
            'appointment_id' => $appId,
            'new_date'       => $newDate,
            'new_time_slot'  => $newSlot,
            'message'        => 'Appointment successfully rescheduled!',
        ] : ['error' => 'Failed to reschedule appointment.'];
    }

    public function cancelAppointment(array $args, int $userId): array
    {
        $appId  = (int)($args['appointment_id'] ?? 0);
        $reason = trim($args['reason'] ?? 'Patient requested cancellation');

        if (!$appId) {
            return ['error' => 'Appointment ID required.'];
        }

        // RBAC: Explicit ownership pre-check
        $stmtOwner = $this->db->prepare("SELECT id, status FROM appointments WHERE id = :id AND patient_id = :p_id");
        $stmtOwner->bindValue(':id', $appId, SQLITE3_INTEGER);
        $stmtOwner->bindValue(':p_id', $userId, SQLITE3_INTEGER);
        $resOwner = $stmtOwner->execute();
        $appt = $resOwner ? $resOwner->fetchArray(SQLITE3_ASSOC) : null;

        if (!$appt) {
            return ['error' => 'Appointment not found or you are not authorized to cancel it.'];
        }
        if ($appt['status'] === 'Cancelled') {
            return ['error' => 'This appointment has already been cancelled.'];
        }
        if ($appt['status'] === 'Completed') {
            return ['error' => 'Cannot cancel a completed appointment.'];
        }

        $stmt = $this->db->prepare("
            UPDATE appointments 
            SET status = 'Cancelled'
            WHERE id = :id AND patient_id = :p_id
        ");
        $stmt->bindValue(':id', $appId, SQLITE3_INTEGER);
        $stmt->bindValue(':p_id', $userId, SQLITE3_INTEGER);
        $ok = $stmt->execute();

        return $ok ? [
            'success'        => true,
            'appointment_id' => $appId,
            'status'         => 'Cancelled',
            'message'        => 'Appointment has been cancelled.',
        ] : ['error' => 'Unable to cancel appointment.'];
    }

    public function check_symptoms_naive_bayes(array $args, int $userId): array
    {
        $symptomText = trim($args['symptom_text'] ?? '');
        if (empty($symptomText)) {
            return ['error' => 'Symptom description is required.'];
        }

        require_once dirname(__DIR__, 3) . '/clinick-chatbot-php/DiagnosisClassifier.php';
        $diagClassifier = new DiagnosisClassifier();
        $diagResult     = $diagClassifier->classify($symptomText);

        $isEmergency    = !empty($diagResult['isEmergency']);
        $urgencyLevel   = $isEmergency ? 'EMERGENCY ESCALATION' : 'Normal Consultation';
        $category       = $isEmergency ? 'Potential Emergency Medical Condition' : ($diagResult['category'] ?? 'General Assessment');
        $confidence     = $isEmergency ? 1.0 : ($diagResult['confidence'] ?? 0.50);
        $confidenceTier = $isEmergency ? 'High Confidence' : ($diagResult['confidenceTier'] ?? 'Moderate Confidence');

        // Mandatory Universal Audit Logging (nothing bypasses logging)
        try {
            $stmt = $this->db->prepare("
                INSERT INTO symptoms (patient_id, symptoms_entered, predicted_condition, probability_score, urgency_level, is_emergency)
                VALUES (:pid, :sym, :cond, :score, :urgency, :emerg)
            ");
            $stmt->bindValue(':pid', $userId, SQLITE3_INTEGER);
            $stmt->bindValue(':sym', $symptomText, SQLITE3_TEXT);
            $stmt->bindValue(':cond', $category, SQLITE3_TEXT);
            $stmt->bindValue(':score', $confidence, SQLITE3_FLOAT);
            $stmt->bindValue(':urgency', $urgencyLevel, SQLITE3_TEXT);
            $stmt->bindValue(':emerg', $isEmergency ? 1 : 0, SQLITE3_INTEGER);
            $stmt->execute();
        } catch (Throwable $e) {
            // Non-blocking log write
        }

        if ($isEmergency) {
            return [
                'is_emergency'       => true,
                'urgency_level'      => 'EMERGENCY ESCALATION',
                'possible_condition' => 'Potential Emergency Medical Condition',
                'confidence_score'   => 1.0,
                'confidence_tier'    => 'High Confidence',
                'recommendation'     => 'Please proceed immediately to the nearest Emergency Room or call emergency services (911 / hotline). Do not delay medical care.',
                'disclaimer'         => $diagResult['disclaimer'],
            ];
        }

        return [
            'is_emergency'       => false,
            'urgency_level'      => 'Normal Consultation',
            'possible_condition' => $category,
            'confidence_score'   => $confidence,
            'confidence_tier'    => $confidenceTier,
            'recommendation'     => 'Rest, stay hydrated, and schedule a consultation with one of our clinic doctors for an accurate medical evaluation.',
            'disclaimer'         => $diagResult['disclaimer'],
        ];
    }

    public function getMyRecords(array $args, int $userId): array
    {
        $type = strtolower(trim($args['record_type'] ?? 'all'));
        $result = ['patient_id' => $userId];

        if ($type === 'all' || $type === 'consultations') {
            $stmt = $this->db->prepare("
                SELECT mr.record_id, mr.diagnosis, mr.treatment, mr.consultation_date, mr.doctor_notes,
                       u.name as doctor_name
                FROM medical_records mr
                LEFT JOIN users u ON mr.doctor_id = u.id
                WHERE mr.patient_id = :pid
                ORDER BY mr.consultation_date DESC
            ");
            $stmt->bindValue(':pid', $userId, SQLITE3_INTEGER);
            $res = $stmt->execute();
            $records = [];
            while ($row = $res->fetchArray(SQLITE3_ASSOC)) {
                $records[] = $row;
            }
            $result['medical_records'] = $records;
        }

        if ($type === 'all' || $type === 'prescriptions') {
            $stmtRx = $this->db->prepare("
                SELECT p.medication, p.dosage, p.frequency, p.doctor_name, p.created_at
                FROM prescriptions p
                WHERE p.patient_id = :pid
                ORDER BY p.created_at DESC
            ");
            $stmtRx->bindValue(':pid', $userId, SQLITE3_INTEGER);
            $resRx = $stmtRx->execute();
            $rxList = [];
            while ($row = $resRx->fetchArray(SQLITE3_ASSOC)) {
                $rxList[] = $row;
            }
            $result['prescriptions'] = $rxList;
        }

        return $result;
    }
}
