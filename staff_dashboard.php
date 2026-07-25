<?php
require_once __DIR__ . '/db.php';

// Auth Guard: Only Staff and Clinical Staff allowed
check_auth(['Staff', 'Clinical Staff']);

$db = get_db_connection();
$staff_id = $_SESSION['user_id'];
$staff_name = $_SESSION['user_name'];
$staff_role = $_SESSION['user_role'];
$success_msg = "";
$error_msg = "";

// A. Handle GET export trigger BEFORE sending any HTML output
if (isset($_GET['action']) && $_GET['action'] === 'export_csv') {
    $report_type = $_GET['report_type'] ?? '';
    $doctor_filter = isset($_GET['doctor_id']) ? filter_var($_GET['doctor_id'], FILTER_VALIDATE_INT) : null;
    $start_date = $_GET['start_date'] ?? '';
    $end_date = $_GET['end_date'] ?? '';

    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename=CLINICK_' . $report_type . '_Report_' . date('Ymd_His') . '.csv');
    
    $output = fopen('php://output', 'w');

    if ($report_type === 'appointments') {
        fputcsv($output, ['Appointment ID', 'Patient Name', 'Doctor Name', 'Date', 'Time Slot', 'Reason', 'Status', 'Queue Number']);
        
        $sql = "SELECT a.id, u.name as patient_name, d.name as doctor_name, a.appointment_date, a.time_slot, a.reason, a.status, a.queue_number 
                FROM appointments a 
                JOIN users u ON a.patient_id = u.id 
                JOIN users d ON a.doctor_id = d.id 
                WHERE 1=1";
        if ($doctor_filter) $sql .= " AND a.doctor_id = $doctor_filter";
        if ($start_date) $sql .= " AND a.appointment_date >= '$start_date'";
        if ($end_date) $sql .= " AND a.appointment_date <= '$end_date'";
        $sql .= " ORDER BY a.appointment_date ASC, a.time_slot ASC";
        
        $res = $db->query($sql);
        while ($row = $res->fetchArray(SQLITE3_ASSOC)) {
            fputcsv($output, $row);
        }
    } elseif ($report_type === 'consultations') {
        fputcsv($output, ['Record ID', 'Patient Name', 'Doctor Name', 'Diagnosis', 'Treatment', 'Consultation Date']);
        $sql = "SELECT r.record_id, u.name as patient_name, d.name as doctor_name, r.diagnosis, r.treatment, r.consultation_date 
                FROM medical_records r 
                JOIN users u ON r.patient_id = u.id 
                JOIN users d ON r.doctor_id = d.id 
                WHERE 1=1";
        if ($doctor_filter) $sql .= " AND r.doctor_id = $doctor_filter";
        if ($start_date) $sql .= " AND r.consultation_date >= '$start_date'";
        if ($end_date) $sql .= " AND r.consultation_date <= '$end_date'";
        $sql .= " ORDER BY r.consultation_date DESC";
        
        $res = $db->query($sql);
        while ($row = $res->fetchArray(SQLITE3_ASSOC)) {
            fputcsv($output, $row);
        }
    } elseif ($report_type === 'records_summary') {
        fputcsv($output, ['Patient ID', 'Patient Name', 'Total Appointments', 'Total Diagnoses']);
        $sql = "SELECT u.id, u.name, 
                (SELECT COUNT(*) FROM appointments WHERE patient_id = u.id) as appt_count,
                (SELECT COUNT(*) FROM medical_records WHERE patient_id = u.id) as rec_count
                FROM users u 
                WHERE u.role = 'Patient' ORDER BY u.name ASC";
        $res = $db->query($sql);
        while ($row = $res->fetchArray(SQLITE3_ASSOC)) {
            fputcsv($output, $row);
        }
    } elseif ($report_type === 'queue_waiting') {
        fputcsv($output, ['Appointment Date', 'Patient Name', 'Doctor Name', 'Queue Number', 'Status']);
        $sql = "SELECT a.appointment_date, u.name as patient_name, d.name as doctor_name, a.queue_number, a.status 
                FROM appointments a 
                JOIN users u ON a.patient_id = u.id 
                JOIN users d ON a.doctor_id = d.id 
                WHERE a.status IN ('Scheduled', 'Checked-in')";
        if ($start_date) $sql .= " AND a.appointment_date >= '$start_date'";
        if ($end_date) $sql .= " AND a.appointment_date <= '$end_date'";
        $sql .= " ORDER BY a.appointment_date ASC, a.queue_number ASC";
        
        $res = $db->query($sql);
        while ($row = $res->fetchArray(SQLITE3_ASSOC)) {
            fputcsv($output, $row);
        }
    } elseif ($report_type === 'visit_history') {
        fputcsv($output, ['Patient Name', 'Visit Date', 'Doctor Name', 'Reason', 'Status']);
        $sql = "SELECT u.name as patient_name, a.appointment_date, d.name as doctor_name, a.reason, a.status 
                FROM appointments a 
                JOIN users u ON a.patient_id = u.id 
                JOIN users d ON a.doctor_id = d.id 
                WHERE a.status = 'Completed'";
        if ($start_date) $sql .= " AND a.appointment_date >= '$start_date'";
        if ($end_date) $sql .= " AND a.appointment_date <= '$end_date'";
        $sql .= " ORDER BY a.appointment_date DESC";
        
        $res = $db->query($sql);
        while ($row = $res->fetchArray(SQLITE3_ASSOC)) {
            fputcsv($output, $row);
        }
    } elseif ($report_type === 'doctor_workload') {
        fputcsv($output, ['Doctor Name', 'Scheduled Visits', 'Completed Visits', 'Cancelled/No-Show Visits']);
        $sql = "SELECT d.name as doctor_name,
                SUM(CASE WHEN a.status = 'Scheduled' THEN 1 ELSE 0 END) as scheduled_count,
                SUM(CASE WHEN a.status = 'Completed' THEN 1 ELSE 0 END) as completed_count,
                SUM(CASE WHEN a.status IN ('Cancelled', 'No-Show') THEN 1 ELSE 0 END) as inactive_count
                FROM users d
                LEFT JOIN appointments a ON d.id = a.doctor_id
                WHERE d.role IN ('Doctor', 'Clinical Staff')";
        if ($start_date) $sql .= " AND a.appointment_date >= '$start_date'";
        if ($end_date) $sql .= " AND a.appointment_date <= '$end_date'";
        $sql .= " GROUP BY d.id ORDER BY d.name ASC";
        
        $res = $db->query($sql);
        while ($row = $res->fetchArray(SQLITE3_ASSOC)) {
            fputcsv($output, $row);
        }
    } elseif ($report_type === 'noshows') {
        fputcsv($output, ['Appointment Date', 'Patient Name', 'Doctor Name', 'Time Slot', 'Status']);
        $sql = "SELECT a.appointment_date, u.name as patient_name, d.name as doctor_name, a.time_slot, a.status 
                FROM appointments a 
                JOIN users u ON a.patient_id = u.id 
                JOIN users d ON a.doctor_id = d.id 
                WHERE a.status IN ('Cancelled', 'No-Show')";
        if ($start_date) $sql .= " AND a.appointment_date >= '$start_date'";
        if ($end_date) $sql .= " AND a.appointment_date <= '$end_date'";
        $sql .= " ORDER BY a.appointment_date DESC";
        
        $res = $db->query($sql);
        while ($row = $res->fetchArray(SQLITE3_ASSOC)) {
            fputcsv($output, $row);
        }
    } elseif ($report_type === 'billing') {
        fputcsv($output, ['Consultation Date', 'Patient Name', 'Doctor Name', 'Consultation Fee (Simulated)', 'Payment Status']);
        $sql = "SELECT a.appointment_date, u.name as patient_name, d.name as doctor_name, 'â‚±500.00' as fee, 'Paid' as payment_status 
                FROM appointments a 
                JOIN users u ON a.patient_id = u.id 
                JOIN users d ON a.doctor_id = d.id 
                WHERE a.status = 'Completed'";
        if ($start_date) $sql .= " AND a.appointment_date >= '$start_date'";
        if ($end_date) $sql .= " AND a.appointment_date <= '$end_date'";
        $sql .= " ORDER BY a.appointment_date DESC";
        
        $res = $db->query($sql);
        while ($row = $res->fetchArray(SQLITE3_ASSOC)) {
            fputcsv($output, $row);
        }
    } elseif ($report_type === 'high_risk') {
        fputcsv($output, ['Date Flagged', 'Patient Name', 'Symptoms Entered', 'Predicted Condition', 'Risk Probability']);
        $sql = "SELECT s.created_at, u.name as patient_name, s.symptoms_entered, s.predicted_condition, (s.probability_score * 100) || '%' as prob_score 
                FROM symptoms s 
                JOIN users u ON s.patient_id = u.id 
                WHERE s.probability_score >= 0.80";
        if ($start_date) $sql .= " AND s.created_at >= '$start_date'";
        if ($end_date) $sql .= " AND s.created_at <= '$end_date'";
        $sql .= " ORDER BY s.created_at DESC";
        
        $res = $db->query($sql);
        while ($row = $res->fetchArray(SQLITE3_ASSOC)) {
            fputcsv($output, $row);
        }
    } elseif ($report_type === 'disease_trends') {
        fputcsv($output, ['Condition Name', 'Total Flagged Cases', 'Average Probability Score']);
        $sql = "SELECT predicted_condition, COUNT(*) as cases_count, ROUND(AVG(probability_score) * 100, 1) || '%' as avg_score 
                FROM symptoms 
                GROUP BY predicted_condition ORDER BY cases_count DESC";
        $res = $db->query($sql);
        while ($row = $res->fetchArray(SQLITE3_ASSOC)) {
            fputcsv($output, $row);
        }
    }

    fclose($output);
    exit();
}

// B. Handle all updates, form saves, status modifications
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
    $action = $_POST['action'];

    // 1. Walk-in Registration
    if ($action === 'register_walkin') {
        $surname = trim($_POST['surname'] ?? '');
        $first_name = trim($_POST['first_name'] ?? '');
        $middle_name = trim($_POST['middle_name'] ?? '');
        $name = trim(trim($first_name . ' ' . $middle_name) . ' ' . $surname);

        $email = filter_var(trim($_POST['email'] ?? ''), FILTER_VALIDATE_EMAIL);
        $gender = trim($_POST['gender'] ?? 'Male');
        $birth_date = trim($_POST['birth_date'] ?? '');
        $contact = trim($_POST['contact_details'] ?? '');
        $med_history = trim($_POST['medical_history'] ?? 'None');
        $pref_lang = trim($_POST['preferred_language'] ?? 'English');
        $password = password_hash('password123', PASSWORD_DEFAULT); // Default temp password

        if (empty($surname) || empty($first_name) || !$email || empty($birth_date)) {
            $error_msg = "Please enter surname, first name, valid email, and birth date.";
        } else {
            // Check email
            $stmt_check = $db->prepare("SELECT id FROM users WHERE email = :email");
            $stmt_check->bindValue(':email', $email, SQLITE3_TEXT);
            if ($stmt_check->execute()->fetchArray(SQLITE3_ASSOC)) {
                $error_msg = "This email address is already registered.";
            } else {
                $db->exec("BEGIN TRANSACTION;");
                try {
                    $stmt_u = $db->prepare("INSERT INTO users (name, email, password, role, status) VALUES (:name, :email, :pass, 'Patient', 'Active')");
                    $stmt_u->bindValue(':name', $name, SQLITE3_TEXT);
                    $stmt_u->bindValue(':email', $email, SQLITE3_TEXT);
                    $stmt_u->bindValue(':pass', $password, SQLITE3_TEXT);
                    $stmt_u->execute();
                    
                    $new_pid = $db->lastInsertRowID();
                    
                    $stmt_p = $db->prepare("INSERT INTO patients (patient_id, name, gender, birth_date, contact_details, medical_history, preferred_language) 
                                           VALUES (:pid, :name, :gender, :bdate, :contact, :history, :lang)");
                    $stmt_p->bindValue(':pid', $new_pid, SQLITE3_INTEGER);
                    $stmt_p->bindValue(':name', $name, SQLITE3_TEXT);
                    $stmt_p->bindValue(':gender', $gender, SQLITE3_TEXT);
                    $stmt_p->bindValue(':bdate', $birth_date, SQLITE3_TEXT);
                    $stmt_p->bindValue(':contact', $contact, SQLITE3_TEXT);
                    $stmt_p->bindValue(':history', $med_history, SQLITE3_TEXT);
                    $stmt_p->bindValue(':lang', $pref_lang, SQLITE3_TEXT);
                    $stmt_p->execute();
                    
                    $db->exec("COMMIT;");
                    $success_msg = "Walk-in patient registered successfully! Temporary password: password123";
                } catch (Exception $e) {
                    $db->exec("ROLLBACK;");
                    $error_msg = "Failed to register patient: " . $e->getMessage();
                }
            }
        }
    }

    // 2. Edit Patient profile
    elseif ($action === 'edit_patient') {
        $pid = filter_var($_POST['patient_id'] ?? null, FILTER_VALIDATE_INT);
        $name = trim($_POST['name'] ?? '');
        $gender = trim($_POST['gender'] ?? 'Male');
        $birth_date = trim($_POST['birth_date'] ?? '');
        $contact = trim($_POST['contact_details'] ?? '');
        $med_history = trim($_POST['medical_history'] ?? '');
        $pref_lang = trim($_POST['preferred_language'] ?? 'English');

        if (!$pid || empty($name)) {
            $error_msg = "Invalid parameters for patient update.";
        } else {
            $db->exec("BEGIN TRANSACTION;");
            try {
                $stmt_u = $db->prepare("UPDATE users SET name = :name WHERE id = :pid");
                $stmt_u->bindValue(':name', $name, SQLITE3_TEXT);
                $stmt_u->bindValue(':pid', $pid, SQLITE3_INTEGER);
                $stmt_u->execute();

                $stmt_p = $db->prepare("UPDATE patients SET name = :name, gender = :gender, birth_date = :bdate, contact_details = :contact, medical_history = :history, preferred_language = :lang WHERE patient_id = :pid");
                $stmt_p->bindValue(':name', $name, SQLITE3_TEXT);
                $stmt_p->bindValue(':gender', $gender, SQLITE3_TEXT);
                $stmt_p->bindValue(':bdate', $birth_date, SQLITE3_TEXT);
                $stmt_p->bindValue(':contact', $contact, SQLITE3_TEXT);
                $stmt_p->bindValue(':history', $med_history, SQLITE3_TEXT);
                $stmt_p->bindValue(':lang', $pref_lang, SQLITE3_TEXT);
                $stmt_p->bindValue(':pid', $pid, SQLITE3_INTEGER);
                $stmt_p->execute();

                $db->exec("COMMIT;");
                $success_msg = "Patient record updated successfully.";
            } catch (Exception $e) {
                $db->exec("ROLLBACK;");
                $error_msg = "Failed to update record: " . $e->getMessage();
            }
        }
    }

    // 3. Deactivate/Archive patient
    elseif ($action === 'archive_patient') {
        $pid = filter_var($_POST['patient_id'] ?? null, FILTER_VALIDATE_INT);
        if ($pid) {
            $stmt = $db->prepare("UPDATE users SET status = 'Archived' WHERE id = :pid");
            $stmt->bindValue(':pid', $pid, SQLITE3_INTEGER);
            if ($stmt->execute()) {
                $success_msg = "Patient record archived successfully.";
            } else {
                $error_msg = "Failed to archive patient record.";
            }
        }
    }

    // 4. Activate/Unarchive patient
    elseif ($action === 'unarchive_patient') {
        $pid = filter_var($_POST['patient_id'] ?? null, FILTER_VALIDATE_INT);
        if ($pid) {
            $stmt = $db->prepare("UPDATE users SET status = 'Active' WHERE id = :pid");
            $stmt->bindValue(':pid', $pid, SQLITE3_INTEGER);
            if ($stmt->execute()) {
                $success_msg = "Patient record reactivated successfully.";
            } else {
                $error_msg = "Failed to reactivate patient record.";
            }
        }
    }

    // 5. Book appointment on behalf of patient
    elseif ($action === 'book_appointment') {
        $pid = filter_var($_POST['patient_id'] ?? null, FILTER_VALIDATE_INT);
        $did = filter_var($_POST['doctor_id'] ?? null, FILTER_VALIDATE_INT);
        $date = trim($_POST['appointment_date'] ?? '');
        $slot = trim($_POST['time_slot'] ?? '');
        $reason = trim($_POST['reason'] ?? '');

        if (!$pid || !$did || empty($date) || empty($slot)) {
            $error_msg = "All fields are required to schedule an appointment.";
        } else {
            // Check availability
            $stmt_avail = $db->prepare("SELECT status FROM availability WHERE doctor_id = :did AND available_date = :date");
            $stmt_avail->bindValue(':did', $did, SQLITE3_INTEGER);
            $stmt_avail->bindValue(':date', $date, SQLITE3_TEXT);
            $av = $stmt_avail->execute()->fetchArray(SQLITE3_ASSOC);

            if ($av && $av['status'] === 'Unavailable') {
                $error_msg = "This doctor is marked as unavailable/off on the selected date.";
            } else {
                // Get next queue number
                $stmt_q = $db->prepare("SELECT COUNT(*) as count FROM appointments WHERE doctor_id = :did AND appointment_date = :date");
                $stmt_q->bindValue(':did', $did, SQLITE3_INTEGER);
                $stmt_q->bindValue(':date', $date, SQLITE3_TEXT);
                $count = $stmt_q->execute()->fetchArray(SQLITE3_ASSOC)['count'] ?? 0;
                $queue_number = $count + 1;

                $stmt_ins = $db->prepare("INSERT INTO appointments (patient_id, doctor_id, appointment_date, time_slot, reason, status, queue_number) 
                                         VALUES (:pid, :did, :date, :slot, :reason, 'Scheduled', :qnum)");
                $stmt_ins->bindValue(':pid', $pid, SQLITE3_INTEGER);
                $stmt_ins->bindValue(':did', $did, SQLITE3_INTEGER);
                $stmt_ins->bindValue(':date', $date, SQLITE3_TEXT);
                $stmt_ins->bindValue(':slot', $slot, SQLITE3_TEXT);
                $stmt_ins->bindValue(':reason', $reason, SQLITE3_TEXT);
                $stmt_ins->bindValue(':qnum', $queue_number, SQLITE3_INTEGER);
                
                if ($stmt_ins->execute()) {
                    $success_msg = "Appointment scheduled successfully. Queue Number: Q-" . $queue_number;
                } else {
                    $error_msg = "Failed to schedule appointment.";
                }
            }
        }
    }

    // 6. Reschedule appointment
    elseif ($action === 'reschedule_appointment') {
        $aid = filter_var($_POST['appointment_id'] ?? null, FILTER_VALIDATE_INT);
        $date = trim($_POST['reschedule_date'] ?? '');
        $slot = trim($_POST['reschedule_slot'] ?? '');

        if (!$aid || empty($date) || empty($slot)) {
            $error_msg = "Please select date and slot.";
        } else {
            // Find doctor
            $stmt_d = $db->prepare("SELECT doctor_id FROM appointments WHERE id = :aid");
            $stmt_d->bindValue(':aid', $aid, SQLITE3_INTEGER);
            $did = $stmt_d->execute()->fetchArray(SQLITE3_ASSOC)['doctor_id'] ?? null;

            if ($did) {
                // Calculate next queue number
                $stmt_q = $db->prepare("SELECT COUNT(*) as count FROM appointments WHERE doctor_id = :did AND appointment_date = :date");
                $stmt_q->bindValue(':did', $did, SQLITE3_INTEGER);
                $stmt_q->bindValue(':date', $date, SQLITE3_TEXT);
                $count = $stmt_q->execute()->fetchArray(SQLITE3_ASSOC)['count'] ?? 0;
                $queue_number = $count + 1;

                $stmt_up = $db->prepare("UPDATE appointments SET appointment_date = :date, time_slot = :slot, queue_number = :qnum, status = 'Scheduled' WHERE id = :aid");
                $stmt_up->bindValue(':date', $date, SQLITE3_TEXT);
                $stmt_up->bindValue(':slot', $slot, SQLITE3_TEXT);
                $stmt_up->bindValue(':qnum', $queue_number, SQLITE3_INTEGER);
                $stmt_up->bindValue(':aid', $aid, SQLITE3_INTEGER);

                if ($stmt_up->execute()) {
                    $success_msg = "Appointment rescheduled successfully! New Queue Number: Q-" . $queue_number;
                } else {
                    $error_msg = "Failed to reschedule appointment.";
                }
            }
        }
    }

    // 7. QR Check-In manual verification code
    elseif ($action === 'checkin_patient') {
        $code = trim($_POST['checkin_code'] ?? '');
        $appt_id = filter_var($code, FILTER_VALIDATE_INT);

        if (!$appt_id) {
            $error_msg = "Invalid appointment validation pass code format.";
        } else {
            $stmt_find = $db->prepare("SELECT a.*, p.name as patient_name, d.name as doctor_name 
                                       FROM appointments a 
                                       JOIN users p ON a.patient_id = p.id 
                                       JOIN users d ON a.doctor_id = d.id 
                                       WHERE a.id = :id");
            $stmt_find->bindValue(':id', $appt_id, SQLITE3_INTEGER);
            $appt = $stmt_find->execute()->fetchArray(SQLITE3_ASSOC);

            if (!$appt) {
                $error_msg = "No scheduled appointment matched this check-in pass.";
            } else {
                $stmt_up = $db->prepare("UPDATE appointments SET status = 'Checked-in' WHERE id = :id");
                $stmt_up->bindValue(':id', $appt_id, SQLITE3_INTEGER);
                if ($stmt_up->execute()) {
                    $success_msg = "Check-in successful! " . htmlspecialchars($appt['patient_name']) . " is checked-in for doctor " . htmlspecialchars($appt['doctor_name']) . " (Q-" . $appt['queue_number'] . ").";
                } else {
                    $error_msg = "Failed to update check-in status.";
                }
            }
        }
    }

    // 8. Resend manually triggered notifications
    elseif ($action === 'resend_reminder') {
        $aid = filter_var($_POST['appointment_id'] ?? null, FILTER_VALIDATE_INT);
        if ($aid) {
            // Check reminder offset
            $offset = trim($_POST['reminder_offset'] ?? '2 hours before');
            $type = trim($_POST['reminder_type'] ?? 'SMS');

            $stmt_ins = $db->prepare("INSERT INTO reminders (appointment_id, reminder_type, reminder_offset, status) VALUES (:aid, :type, :offset, 'Sent')");
            $stmt_ins->bindValue(':aid', $aid, SQLITE3_INTEGER);
            $stmt_ins->bindValue(':type', $type, SQLITE3_TEXT);
            $stmt_ins->bindValue(':offset', $offset, SQLITE3_TEXT);
            
            if ($stmt_ins->execute()) {
                $success_msg = "Manual " . $type . " appointment reminder notification sent successfully to patient.";
            } else {
                $error_msg = "Failed to trigger notification reminder dispatch.";
            }
        }
    }

    // 9. Approve / Reject / Complete / Cancel / No-show
    elseif (in_array($action, ['approve_appt', 'reject_appt', 'complete_appt', 'cancel_appt', 'noshow_appt'])) {
        $aid = filter_var($_POST['appointment_id'] ?? null, FILTER_VALIDATE_INT);
        if ($aid) {
            $status_map = [
                'approve_appt' => 'Scheduled',
                'reject_appt' => 'Rejected',
                'complete_appt' => 'Completed',
                'cancel_appt' => 'Cancelled',
                'noshow_appt' => 'No-Show'
            ];
            $new_status = $status_map[$action];

            $stmt = $db->prepare("UPDATE appointments SET status = :status WHERE id = :aid");
            $stmt->bindValue(':status', $new_status, SQLITE3_TEXT);
            $stmt->bindValue(':aid', $aid, SQLITE3_INTEGER);
            if ($stmt->execute()) {
                $success_msg = "Appointment status updated to '" . $new_status . "' successfully.";
            } else {
                $error_msg = "Failed to update appointment status.";
            }
        }
    }
}

// C. Query variables for dashboards
$tab = $_GET['tab'] ?? 'overview';

// Core Statistics calculations
$today_str = date('Y-m-d');

// Today's total appointments
$stmt_today = $db->prepare("SELECT COUNT(*) FROM appointments WHERE appointment_date = :today");
$stmt_today->bindValue(':today', $today_str, SQLITE3_TEXT);
$today_total_appointments = $stmt_today->execute()->fetchArray()[0] ?? 0;

// Current queue count (checked-in or scheduled today)
$stmt_queue = $db->prepare("SELECT COUNT(*) FROM appointments WHERE appointment_date = :today AND status IN ('Scheduled', 'Checked-in')");
$stmt_queue->bindValue(':today', $today_str, SQLITE3_TEXT);
$today_queue_count = $stmt_queue->execute()->fetchArray()[0] ?? 0;

// Pending approvals
$pending_approvals = $db->querySingle("SELECT COUNT(*) FROM appointments WHERE status = 'Pending'") ?? 0;

// No-shows today
$stmt_noshow = $db->prepare("SELECT COUNT(*) FROM appointments WHERE appointment_date = :today AND status = 'No-Show'");
$stmt_noshow->bindValue(':today', $today_str, SQLITE3_TEXT);
$today_no_shows = $stmt_noshow->execute()->fetchArray()[0] ?? 0;

// Trend calculations (today vs yesterday)
$yesterday_str = date('Y-m-d', strtotime('-1 day'));
$stmt_yest_total = $db->prepare("SELECT COUNT(*) FROM appointments WHERE appointment_date = :yesterday");
$stmt_yest_total->bindValue(':yesterday', $yesterday_str, SQLITE3_TEXT);
$yesterday_total = $stmt_yest_total->execute()->fetchArray()[0] ?? 0;

$stmt_yest_queue = $db->prepare("SELECT COUNT(*) FROM appointments WHERE appointment_date = :yesterday AND status IN ('Scheduled', 'Checked-in')");
$stmt_yest_queue->bindValue(':yesterday', $yesterday_str, SQLITE3_TEXT);
$yesterday_queue = $stmt_yest_queue->execute()->fetchArray()[0] ?? 0;

$stmt_yest_noshow = $db->prepare("SELECT COUNT(*) FROM appointments WHERE appointment_date = :yesterday AND status = 'No-Show'");
$stmt_yest_noshow->bindValue(':yesterday', $yesterday_str, SQLITE3_TEXT);
$yesterday_no_shows = $stmt_yest_noshow->execute()->fetchArray()[0] ?? 0;

if (!function_exists('get_trend_html')) {
    function get_trend_html($current, $previous, $invert = false) {
        if ($previous == 0 && $current == 0) return '<span class="stats-trend stable"><i class="fa-solid fa-minus"></i> No change</span>';
        if ($previous == 0) {
            $class = $invert ? 'down' : 'up';
            return '<span class="stats-trend ' . $class . '"><i class="fa-solid fa-arrow-trend-up"></i> New</span>';
        }
        $pct = round((($current - $previous) / $previous) * 100);
        if ($pct > 0) {
            $class = $invert ? 'down' : 'up';
            $icon = 'fa-arrow-trend-up';
            return '<span class="stats-trend ' . $class . '"><i class="fa-solid ' . $icon . '"></i> +' . $pct . '%</span>';
        } elseif ($pct < 0) {
            $class = $invert ? 'up' : 'down';
            $icon = 'fa-arrow-trend-down';
            return '<span class="stats-trend ' . $class . '"><i class="fa-solid ' . $icon . '"></i> ' . $pct . '%</span>';
        }
        return '<span class="stats-trend stable"><i class="fa-solid fa-minus"></i> No change</span>';
    }
}

// Active doctors & patients arrays
$docs_res = $db->query("SELECT id, name FROM users WHERE role IN ('Doctor', 'Clinical Staff') AND status = 'Active' ORDER BY name ASC");
$doctors = [];
while ($d = $docs_res->fetchArray(SQLITE3_ASSOC)) {
    $doctors[] = $d;
}

$pats_res = $db->query("SELECT id, name, email FROM users WHERE role = 'Patient' ORDER BY name ASC");
$patients_list = [];
while ($p = $pats_res->fetchArray(SQLITE3_ASSOC)) {
    $patients_list[] = $p;
}

?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>CLINICK - Clinical Staff Workspace</title>
    <!-- Google Fonts: Inter -->
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link rel="stylesheet" href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&display=swap">
    <!-- FontAwesome Icon CDN -->
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <!-- Custom Style Sheet -->
    <link rel="stylesheet" href="style.css?v=<?php echo filemtime('style.css'); ?>">
    <link rel="stylesheet" href="dashboard.css?v=<?php echo filemtime('dashboard.css'); ?>">
    <script src="js/theme-controller.js?v=<?php echo filemtime('js/theme-controller.js'); ?>"></script>
    <style>
        .profile-section-card {
            border: 1px solid var(--border-color);
            border-radius: var(--radius-md);
            padding: 1.25rem;
            background: var(--card-bg);
            margin-bottom: 1.5rem;
        }
        .profile-section-title {
            font-size: 1.05rem;
            font-weight: 700;
            color: var(--primary);
            border-bottom: 2px solid var(--border-color);
            padding-bottom: 0.5rem;
            margin-bottom: 1rem;
        }
        .history-list-item {
            padding: 0.85rem;
            border-bottom: 1px solid var(--border-color);
        }
        .history-list-item:last-child {
            border-bottom: none;
        }
    </style>
</head>
<body>

    <div class="dashboard-container">
        
        <!-- Top Navigation -->
        <header class="top-nav">
            <a href="?tab=overview" class="nav-brand">
                <span class="nav-brand-mark">CL</span>
                <span>CLINICK</span>
            </a>
            
            <div class="nav-tabs-wrapper">
                <ul class="nav-tabs">
                    <li>
                        <a href="?tab=overview" class="nav-link <?php echo $tab === 'overview' ? 'active' : ''; ?>">
                            <i class="fa-solid fa-chart-line"></i>
                            <span>Dashboard</span>
                        </a>
                    </li>
                    <li>
                        <a href="?tab=patients" class="nav-link <?php echo $tab === 'patients' ? 'active' : ''; ?>">
                            <i class="fa-solid fa-id-card"></i>
                            <span>Patient Records</span>
                        </a>
                    </li>
                    <li>
                        <a href="?tab=appointments" class="nav-link <?php echo $tab === 'appointments' ? 'active' : ''; ?>">
                            <i class="fa-solid fa-calendar-check"></i>
                            <span>Appointments</span>
                        </a>
                    </li>
                    <li>
                        <a href="?tab=checkin" class="nav-link <?php echo $tab === 'checkin' ? 'active' : ''; ?>">
                            <i class="fa-solid fa-qrcode"></i>
                            <span>Check-In</span>
                        </a>
                    </li>
                    <li>
                        <a href="?tab=reports" class="nav-link <?php echo $tab === 'reports' ? 'active' : ''; ?>">
                            <i class="fa-solid fa-chart-pie"></i>
                            <span>Reports</span>
                        </a>
                    </li>
                    <li>
                        <a href="?tab=notifications" class="nav-link <?php echo $tab === 'notifications' ? 'active' : ''; ?>">
                            <i class="fa-solid fa-bell"></i>
                            <span>Notifications</span>
                        </a>
                    </li>
                </ul>
            </div>
            
            <div class="nav-actions">
                <div class="nav-user">
                    <div class="nav-user-avatar">
                        <i class="fa-solid fa-user-gear"></i>
                    </div>
                    <div class="nav-user-details">
                        <span class="nav-user-name"><?php echo htmlspecialchars($staff_name); ?></span>
                        <span class="nav-user-role"><?php echo htmlspecialchars($staff_role); ?></span>
                    </div>
                </div>
                
                <button class="theme-toggle" id="theme-toggle" title="Toggle dark mode" style="margin:0;">
                    <span class="theme-toggle-thumb"><i class="fa-solid fa-sun"></i></span>
                </button>
                
                <a href="index.php?logout=true" class="btn btn-logout btn-secondary btn-sm" title="Sign Out" style="margin-left: 0.5rem; border-radius: 50%; width: 36px; height: 36px; display: flex; align-items: center; justify-content: center; padding: 0;">
                    <i class="fa-solid fa-right-from-bracket"></i>
                </a>
            </div>
        </header>

        <!-- Main Workspace -->
        <main class="main-content">
            
            <div class="page-header">
                <h1>Clinical Staff Workspace</h1>
                <p>Logistics, Scheduling, Patient Profiles, and Operational Analytics.</p>
            </div>

            <?php if (!empty($success_msg)): ?>
                <div class="alert alert-success">
                    <i class="fa-solid fa-circle-check"></i>
                    <span><?php echo htmlspecialchars($success_msg); ?></span>
                </div>
            <?php endif; ?>

            <?php if (!empty($error_msg)): ?>
                <div class="alert alert-danger">
                    <i class="fa-solid fa-triangle-exclamation"></i>
                    <span><?php echo htmlspecialchars($error_msg); ?></span>
                </div>
            <?php endif; ?>

            <!-- TAB 1: OVERVIEW -->
            <?php if ($tab === 'overview'): ?>
                <div class="stats-grid">
                    <!-- Today's Appointments KPI -->
                    <div class="stats-card">
                        <div class="stats-info">
                            <span class="stats-label">Today's Appointments</span>
                            <span class="stats-number"><?php echo $today_total_appointments; ?></span>
                            <?php echo get_trend_html($today_total_appointments, $yesterday_total); ?>
                        </div>
                        <div class="stats-icon-container stats-icon-primary">
                            <i class="fa-solid fa-calendar-day"></i>
                        </div>
                    </div>
                    <!-- Current Queue Count KPI -->
                    <div class="stats-card">
                        <div class="stats-info">
                            <span class="stats-label">Current Queue Count</span>
                            <span class="stats-number"><?php echo $today_queue_count; ?></span>
                            <?php echo get_trend_html($today_queue_count, $yesterday_queue); ?>
                        </div>
                        <div class="stats-icon-container stats-icon-success">
                            <i class="fa-solid fa-user-group"></i>
                        </div>
                    </div>
                    <!-- Pending Approvals KPI -->
                    <div class="stats-card">
                        <div class="stats-info">
                            <span class="stats-label">Pending Approvals</span>
                            <span class="stats-number"><?php echo $pending_approvals; ?></span>
                            <span class="stats-trend <?php echo $pending_approvals > 0 ? 'down' : 'stable'; ?>">
                                <i class="fa-solid <?php echo $pending_approvals > 0 ? 'fa-clock' : 'fa-check'; ?>"></i>
                                <?php echo $pending_approvals > 0 ? 'Action needed' : 'All clear'; ?>
                            </span>
                        </div>
                        <div class="stats-icon-container stats-icon-warning">
                            <i class="fa-solid fa-circle-exclamation"></i>
                        </div>
                    </div>
                    <!-- No-Shows KPI -->
                    <div class="stats-card">
                        <div class="stats-info">
                            <span class="stats-label">No-Shows Today</span>
                            <span class="stats-number"><?php echo $today_no_shows; ?></span>
                            <?php echo get_trend_html($today_no_shows, $yesterday_no_shows, true); ?>
                        </div>
                        <div class="stats-icon-container stats-icon-danger">
                            <i class="fa-solid fa-user-xmark"></i>
                        </div>
                    </div>
                </div>
                <!-- 3-Column Main Content Layout -->
                <div class="dashboard-main-grid">
                    <!-- Column Left: Analytics & Recent Patients -->
                    <div class="column-left">
                        <div class="card">
                            <div class="card-header">
                                <h2><i class="fa-solid fa-chart-line"></i> Analytics Overview</h2>
                            </div>
                            <div class="card-body">
                                <p class="text-xs text-muted" style="margin-bottom: var(--space-4);">Consultations and bookings ratios today.</p>
                                <div style="display:flex; flex-direction:column; gap: var(--space-3);">
                                    <div style="display:flex; justify-content:space-between; font-size:0.8rem;">
                                        <span>Capacity Utilization</span>
                                        <strong><?php echo $today_total_appointments > 0 ? round(($today_queue_count / $today_total_appointments) * 100) : 0; ?>%</strong>
                                    </div>
                                    <div style="display:flex; justify-content:space-between; font-size:0.8rem;">
                                        <span>Attendance Rate</span>
                                        <strong>92%</strong>
                                    </div>
                                </div>
                            </div>
                        </div>
                        
                        <div class="card">
                            <div class="card-header">
                                <h2><i class="fa-solid fa-users"></i> Recent Patients</h2>
                            </div>
                            <div class="card-body">
                                <div class="table-responsive">
                                    <table class="data-table">
                                        <tbody>
                                            <?php
                                            $recent_pts = $db->query("SELECT u.id, u.name FROM users u WHERE u.role = 'Patient' ORDER BY u.id DESC LIMIT 3");
                                            while ($pt = $recent_pts->fetchArray(SQLITE3_ASSOC)):
                                            ?>
                                                <tr>
                                                    <td>
                                                        <strong style="font-size:0.8rem;"><?php echo htmlspecialchars($pt['name']); ?></strong><br>
                                                        <span class="text-xxs text-muted">ID: #<?php echo $pt['id']; ?></span>
                                                    </td>
                                                </tr>
                                            <?php endwhile; ?>
                                        </tbody>
                                    </table>
                                </div>
                            </div>
                        </div>
                    </div>

                    <!-- Column Center: Quick Actions & Queue Status -->
                    <div class="column-center">
                        <div class="card">
                            <div class="card-header">
                                <h2><i class="fa-solid fa-bolt"></i> Quick Actions</h2>
                            </div>
                            <div class="card-body">
                                <div class="quick-actions-grid">
                                    <a href="?tab=patients&action=show_register_form" class="quick-action-card">
                                        <div class="quick-action-icon"><i class="fa-solid fa-user-plus"></i></div>
                                        <div class="quick-action-info">
                                            <span class="quick-action-title">Register</span>
                                            <span class="quick-action-desc">Add walk-in patient</span>
                                        </div>
                                    </a>
                                    <a href="?tab=patients" class="quick-action-card">
                                        <div class="quick-action-icon"><i class="fa-solid fa-magnifying-glass"></i></div>
                                        <div class="quick-action-info">
                                            <span class="quick-action-title">Search</span>
                                            <span class="quick-action-desc">Lookup histories</span>
                                        </div>
                                    </a>
                                    <a href="?tab=appointments" class="quick-action-card">
                                        <div class="quick-action-icon"><i class="fa-solid fa-list-ol"></i></div>
                                        <div class="quick-action-info">
                                            <span class="quick-action-title">Queue Order</span>
                                            <span class="quick-action-desc">View active queue</span>
                                        </div>
                                    </a>
                                    <a href="?tab=checkin" class="quick-action-card">
                                        <div class="quick-action-icon"><i class="fa-solid fa-qrcode"></i></div>
                                        <div class="quick-action-info">
                                            <span class="quick-action-title">QR Scan</span>
                                            <span class="quick-action-desc">Check-in terminal</span>
                                        </div>
                                    </a>
                                </div>
                            </div>
                        </div>

                        <div class="card">
                            <div class="card-header">
                                <h2><i class="fa-solid fa-clock-rotate-left"></i> Queue Status Monitor</h2>
                            </div>
                            <div class="card-body">
                                <div class="table-responsive">
                                    <table class="data-table">
                                        <thead>
                                            <tr>
                                                <th>Queue ID</th>
                                                <th>Patient</th>
                                                <th>Status</th>
                                            </tr>
                                        </thead>
                                        <tbody>
                                            <?php
                                            $queue_monitoring = $db->query("SELECT a.id, u.name, a.status FROM appointments a JOIN users u ON a.patient_id = u.id WHERE a.appointment_date = '" . date('Y-m-d') . "' LIMIT 3");
                                            $has_queue = false;
                                            while ($q = $queue_monitoring->fetchArray(SQLITE3_ASSOC)):
                                                $has_queue = true;
                                            ?>
                                                <tr>
                                                    <td>#<?php echo $q['id']; ?></td>
                                                    <td><strong><?php echo htmlspecialchars($q['name']); ?></strong></td>
                                                    <td><span class="badge badge-<?php echo strtolower($q['status']); ?>"><?php echo $q['status']; ?></span></td>
                                                </tr>
                                            <?php endwhile; 
                                            if (!$has_queue): ?>
                                                <tr><td colspan="3" class="table-empty">No active patients in queue.</td></tr>
                                            <?php endif; ?>
                                        </tbody>
                                    </table>
                                </div>
                            </div>
                        </div>
                    </div>

                    <!-- Column Right: Timeline & Critical Alerts -->
                    <div class="column-right">
                        <div class="card">
                            <div class="card-header">
                                <h2><i class="fa-solid fa-timeline"></i> Appointment Timeline</h2>
                            </div>
                            <div class="card-body">
                                <div class="timeline-list">
                                    <?php
                                    $timeline_appts = $db->query("SELECT a.time_slot, u.name, a.reason FROM appointments a JOIN users u ON a.patient_id = u.id WHERE a.appointment_date = '" . date('Y-m-d') . "' ORDER BY a.time_slot ASC LIMIT 3");
                                    $has_timeline = false;
                                    while ($ta = $timeline_appts->fetchArray(SQLITE3_ASSOC)):
                                        $has_timeline = true;
                                    ?>
                                        <div class="timeline-item">
                                            <div class="timeline-time"><?php echo htmlspecialchars($ta['time_slot']); ?></div>
                                            <div class="timeline-title"><?php echo htmlspecialchars($ta['name']); ?></div>
                                            <div class="timeline-desc"><?php echo htmlspecialchars($ta['reason']); ?></div>
                                        </div>
                                    <?php endwhile;
                                    if (!$has_timeline): ?>
                                        <p class="text-xs text-muted">No appointments scheduled for today.</p>
                                    <?php endif; ?>
                                </div>
                            </div>
                        </div>

                        <div class="card">
                            <div class="card-header">
                                <h2><i class="fa-solid fa-triangle-exclamation"></i> Critical Alerts</h2>
                            </div>
                            <div class="card-body scrollable-y no-padding">
                                <?php
                                $alerts_res = $db->query("SELECT u.name, sc.predicted_condition, sc.probability_score, sc.symptoms_entered 
                                                         FROM symptoms sc 
                                                         JOIN users u ON sc.patient_id = u.id 
                                                         WHERE sc.probability_score >= 0.8 
                                                         ORDER BY sc.symptom_id DESC LIMIT 3");
                                $has_alerts = false;
                                while ($alert = $alerts_res->fetchArray(SQLITE3_ASSOC)):
                                    $has_alerts = true;
                                ?>
                                    <div class="alert alert-danger" style="margin: 0.5rem; border-left: 4px solid var(--danger);">
                                        <div style="font-size:0.8rem;">
                                            <strong class="text-danger">HIGH-RISK: <?php echo htmlspecialchars($alert['predicted_condition']); ?> (<?php echo round($alert['probability_score'] * 100); ?>%)</strong><br>
                                            <span>Patient: <?php echo htmlspecialchars($alert['name']); ?></span><br>
                                            <span class="text-xxs text-muted">Symptoms: <?php echo htmlspecialchars($alert['symptoms_entered']); ?></span>
                                        </div>
                                    </div>
                                <?php endwhile;
                                if (!$has_alerts): ?>
                                    <p class="text-xs text-muted" style="padding: 1rem; text-align: center;">All clinical risk checks clear.</p>
                                <?php endif; ?>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- Bottom Row: Activity Feed & Reports Preview -->
                <div class="dashboard-bottom-grid">
                    <div class="card">
                        <div class="card-header">
                            <h2><i class="fa-solid fa-clock-rotate-left"></i> System Activity Feed</h2>
                        </div>
                        <div class="card-body">
                            <div class="activity-feed-list">
                                <?php
                                $recent_audits = $db->query("SELECT timestamp, username, action FROM audit_logs ORDER BY id DESC LIMIT 4");
                                while ($audit = $recent_audits->fetchArray(SQLITE3_ASSOC)):
                                ?>
                                    <div class="activity-item">
                                        <span class="activity-text">
                                            <strong><?php echo htmlspecialchars($audit['username'] ?: 'System Guest'); ?></strong> 
                                            <?php echo htmlspecialchars($audit['action']); ?>
                                        </span>
                                        <span class="activity-time"><?php echo $audit['timestamp']; ?></span>
                                    </div>
                                <?php endwhile; ?>
                            </div>
                        </div>
                    </div>
                    
                    <div class="card">
                        <div class="card-header">
                            <h2><i class="fa-solid fa-chart-column"></i> Daily Operational Summary</h2>
                        </div>
                        <div class="card-body">
                            <p class="text-xs text-muted" style="margin-bottom: var(--space-4);">Live metrics snapshot.</p>
                            <div style="display:flex; flex-direction:column; gap: var(--space-3); font-size:0.85rem;">
                                <div style="display:flex; justify-content:space-between;">
                                    <span>Active Patients Directory</span>
                                    <strong><?php echo $db->querySingle("SELECT COUNT(*) FROM users WHERE role = 'Patient'"); ?></strong>
                                </div>
                                <div style="display:flex; justify-content:space-between;">
                                    <span>Active Staff Directory</span>
                                    <strong><?php echo $db->querySingle("SELECT COUNT(*) FROM users WHERE role = 'Staff'"); ?></strong>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>

            <!-- TAB 2: PATIENT RECORDS MANAGEMENT -->
            <?php elseif ($tab === 'patients'): 
                $profile_search_id = isset($_GET['profile_id']) ? filter_var($_GET['profile_id'], FILTER_VALIDATE_INT) : null;
                $search_query = trim($_GET['search'] ?? '');
                
                // Form triggers
                $show_register_form = (isset($_GET['action']) && $_GET['action'] === 'show_register_form');
                $show_edit_id = isset($_GET['edit_id']) ? filter_var($_GET['edit_id'], FILTER_VALIDATE_INT) : null;
            ?>

                <?php if ($profile_search_id): 
                    // 1. RENDER DETAILED PROFILE
                    $stmt_u = $db->prepare("SELECT u.*, p.gender, p.birth_date, p.contact_details, p.medical_history, p.preferred_language 
                                            FROM users u 
                                            LEFT JOIN patients p ON u.id = p.patient_id 
                                            WHERE u.id = :pid AND u.role = 'Patient'");
                    $stmt_u->bindValue(':pid', $profile_search_id, SQLITE3_INTEGER);
                    $pat = $stmt_u->execute()->fetchArray(SQLITE3_ASSOC);
                ?>
                    <div class="chart-title">
                        <a href="?tab=patients" class="btn-sm btn-secondary"><i class="fa-solid fa-arrow-left"></i> Back to Patient Index</a>
                    </div>
                    
                    <?php if (!$pat): ?>
                        <div class="alert alert-danger">Patient record not found.</div>
                    <?php else: ?>
                        <div class="card">
                            <div class="card-header" style="display:flex; justify-content:space-between; align-items:center;">
                                <h2><i class="fa-solid fa-address-card"></i> Profile: <?php echo htmlspecialchars($pat['name']); ?></h2>
                                <span class="badge badge-<?php echo strtolower($pat['status'] ?? 'Active'); ?>"><?php echo htmlspecialchars($pat['status'] ?? 'Active'); ?></span>
                            </div>
                            <div class="card-body">
                                <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 2rem;">
                                    <!-- Left demographics -->
                                    <div class="profile-section-card">
                                        <div class="profile-section-title">Demographics & Details</div>
                                        <table style="width: 100%; border: none;">
                                            <tr style="border:none;"><td style="font-weight:700; width: 140px; padding: 0.35rem 0;">Gender:</td><td><?php echo htmlspecialchars($pat['gender'] ?? 'N/A'); ?></td></tr>
                                            <tr style="border:none;"><td style="font-weight:700; padding: 0.35rem 0;">Birth Date:</td><td><?php echo htmlspecialchars($pat['birth_date'] ?? 'N/A'); ?></td></tr>
                                            <tr style="border:none;"><td style="font-weight:700; padding: 0.35rem 0;">Contact Details:</td><td><?php echo htmlspecialchars($pat['contact_details'] ?? 'N/A'); ?></td></tr>
                                            <tr style="border:none;"><td style="font-weight:700; padding: 0.35rem 0;">Preferred Lang:</td><td><?php echo htmlspecialchars($pat['preferred_language'] ?? 'N/A'); ?></td></tr>
                                            <tr style="border:none;"><td style="font-weight:700; padding: 0.35rem 0;">Medical History:</td><td><?php echo nl2br(htmlspecialchars($pat['medical_history'] ?? 'None')); ?></td></tr>
                                        </table>
                                        <div style="margin-top: 1.5rem; display: flex; gap: 0.5rem;">
                                            <a href="?tab=patients&edit_id=<?php echo $pat['id']; ?>" class="btn-sm btn-outline"><i class="fa-solid fa-pen"></i> Edit Demographics</a>
                                            <?php if (($pat['status'] ?? 'Active') === 'Active'): ?>
                                                <form action="?tab=patients" method="POST" onsubmit="return confirm('Are you sure you want to deactivate/archive this patient record?');" style="display:inline;">
                                                    <input type="hidden" name="action" value="archive_patient">
                                                    <input type="hidden" name="patient_id" value="<?php echo $pat['id']; ?>">
                                                    <button type="submit" class="btn-sm btn-danger"><i class="fa-solid fa-box-archive"></i> Archive Profile</button>
                                                </form>
                                            <?php else: ?>
                                                <form action="?tab=patients" method="POST" onsubmit="return confirm('Are you sure you want to reactivate this patient record?');" style="display:inline;">
                                                    <input type="hidden" name="action" value="unarchive_patient">
                                                    <input type="hidden" name="patient_id" value="<?php echo $pat['id']; ?>">
                                                    <button type="submit" class="btn-sm btn-success"><i class="fa-solid fa-key"></i> Reactivate Profile</button>
                                                </form>
                                            <?php endif; ?>
                                        </div>
                                    </div>

                                    <!-- Right stats -->
                                    <div style="display: flex; flex-direction: column; gap: 1rem;">
                                        <!-- Symptom Checker History -->
                                        <div class="profile-section-card" style="flex-grow: 1;">
                                            <div class="profile-section-title">Symptoms History (Naive Bayes)</div>
                                            <div style="max-height: 180px; overflow-y: auto;">
                                                <?php
                                                $stmt_sym = $db->prepare("SELECT * FROM symptoms WHERE patient_id = :pid ORDER BY created_at DESC");
                                                $stmt_sym->bindValue(':pid', $profile_search_id, SQLITE3_INTEGER);
                                                $s_res = $stmt_sym->execute();
                                                $has_sym = false;
                                                while ($s = $s_res->fetchArray(SQLITE3_ASSOC)):
                                                    $has_sym = true;
                                                ?>
                                                    <div class="history-list-item">
                                                        <div style="font-weight:700; font-size:0.85rem; color:var(--text-color);"><?php echo htmlspecialchars($s['predicted_condition']); ?> (<?php echo $s['probability_score'] * 100; ?>%)</div>
                                                        <div style="font-size:0.75rem; color:var(--text-muted); margin-top:0.15rem;">Symptoms: <?php echo htmlspecialchars($s['symptoms_entered']); ?></div>
                                                        <span style="font-size: 0.7rem; color:var(--text-muted);"><?php echo date('M d, Y', strtotime($s['created_at'])); ?></span>
                                                    </div>
                                                <?php endwhile; ?>
                                                <?php if (!$has_sym): ?>
                                                    <p style="text-align: center; color: var(--text-muted); font-size: 0.85rem; padding: 1rem 0;">No symptoms checked.</p>
                                                <?php endif; ?>
                                            </div>
                                        </div>
                                    </div>
                                </div>

                                <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 2rem; margin-top: 1rem;">
                                    <!-- Bottom Left: Appointments -->
                                    <div class="profile-section-card">
                                        <div class="profile-section-title">Linked Appointments</div>
                                        <div style="max-height: 250px; overflow-y: auto;">
                                            <?php
                                            $stmt_ap = $db->prepare("SELECT a.*, d.name as doctor_name FROM appointments a JOIN users d ON a.doctor_id = d.id WHERE a.patient_id = :pid ORDER BY a.appointment_date DESC");
                                            $stmt_ap->bindValue(':pid', $profile_search_id, SQLITE3_INTEGER);
                                            $ap_res = $stmt_ap->execute();
                                            $has_ap = false;
                                            while ($a = $ap_res->fetchArray(SQLITE3_ASSOC)):
                                                $has_ap = true;
                                            ?>
                                                <div class="history-list-item">
                                                    <div style="font-weight:700; font-size:0.85rem;">Dr. <?php echo htmlspecialchars($a['doctor_name']); ?> &bull; <?php echo htmlspecialchars($a['appointment_date']); ?></div>
                                                    <div style="font-size:0.75rem; color:var(--text-color); margin-top:0.15rem;">Slot: <?php echo htmlspecialchars($a['time_slot']); ?> | Reason: <?php echo htmlspecialchars($a['reason'] ?: 'None'); ?></div>
                                                    <span class="badge badge-<?php echo strtolower($a['status']); ?>" style="font-size:0.7rem; padding:0.1rem 0.35rem; margin-top:0.25rem; display:inline-block;"><?php echo htmlspecialchars($a['status']); ?></span>
                                                </div>
                                            <?php endwhile; ?>
                                            <?php if (!$has_ap): ?>
                                                <p style="text-align: center; color: var(--text-muted); font-size: 0.85rem; padding: 1rem 0;">No appointments recorded.</p>
                                            <?php endif; ?>
                                        </div>
                                    </div>

                                    <!-- Bottom Right: Chatbot Logs -->
                                    <div class="profile-section-card">
                                        <div class="profile-section-title">Chatbot Conversations</div>
                                        <div style="max-height: 250px; overflow-y: auto;">
                                            <?php
                                            $stmt_ch = $db->prepare("SELECT * FROM chatbot_logs WHERE patient_id = :pid ORDER BY timestamp DESC");
                                            $stmt_ch->bindValue(':pid', $profile_search_id, SQLITE3_INTEGER);
                                            $ch_res = $stmt_ch->execute();
                                            $has_ch = false;
                                            while ($c = $ch_res->fetchArray(SQLITE3_ASSOC)):
                                                $has_ch = true;
                                            ?>
                                                <div class="history-list-item" style="font-size: 0.8rem;">
                                                    <div style="color:var(--text-muted); margin-bottom: 0.15rem;">Language: <?php echo htmlspecialchars($c['language_used']); ?> &bull; <?php echo date('M d, h:i A', strtotime($c['timestamp'])); ?></div>
                                                    <div><strong>User:</strong> <?php echo htmlspecialchars($c['message']); ?></div>
                                                    <div style="color: var(--primary); margin-top: 0.15rem;"><strong>Bot:</strong> <?php echo htmlspecialchars($c['response']); ?></div>
                                                </div>
                                            <?php endwhile; ?>
                                            <?php if (!$has_ch): ?>
                                                <p style="text-align: center; color: var(--text-muted); font-size: 0.85rem; padding: 1rem 0;">No chatbot logs.</p>
                                            <?php endif; ?>
                                        </div>
                                    </div>
                                </div>

                                <!-- Medical Records Summary (Read-Only) -->
                                <div class="profile-section-card" style="margin-top: 1.5rem;">
                                    <div class="profile-section-title">Electronic Medical Records (Staff View-only)</div>
                                    <div style="max-height: 300px; overflow-y: auto;">
                                        <?php
                                        $stmt_rec = $db->prepare("SELECT r.*, d.name as doctor_name FROM medical_records r JOIN users d ON r.doctor_id = d.id WHERE r.patient_id = :pid ORDER BY r.consultation_date DESC");
                                        $stmt_rec->bindValue(':pid', $profile_search_id, SQLITE3_INTEGER);
                                        $rec_res = $stmt_rec->execute();
                                        $has_rec = false;
                                        while ($r = $rec_res->fetchArray(SQLITE3_ASSOC)):
                                            $has_rec = true;
                                        ?>
                                            <div class="history-list-item" style="border-bottom: 1px dashed var(--border-color);">
                                                <div style="display:flex; justify-content:space-between; align-items:center;">
                                                    <span style="font-weight:700;">Dr. <?php echo htmlspecialchars($r['doctor_name']); ?></span>
                                                    <span style="font-size:0.75rem; color:var(--text-muted);"><?php echo htmlspecialchars($r['consultation_date']); ?></span>
                                                </div>
                                                <div style="font-size:0.85rem; margin-top:0.25rem;"><strong>Diagnosis:</strong> <?php echo htmlspecialchars($r['diagnosis']); ?></div>
                                                <div style="font-size:0.85rem; margin-top:0.15rem;"><strong>Treatment Plan:</strong> <?php echo htmlspecialchars($r['treatment']); ?></div>
                                                <?php if (!empty($r['doctor_notes'])): ?>
                                                    <div style="font-size:0.8rem; background:rgba(20, 184, 166, 0.02); border-left: 2px solid var(--primary-light); padding:0.25rem 0.5rem; margin-top:0.35rem; color: var(--text-muted);">
                                                        <strong>Doctor Notes:</strong> <?php echo htmlspecialchars($r['doctor_notes']); ?>
                                                    </div>
                                                <?php endif; ?>
                                            </div>
                                        <?php endwhile; ?>
                                        <?php if (!$has_rec): ?>
                                            <p style="text-align: center; color: var(--text-muted); font-size: 0.85rem; padding: 2rem 0;">No official medical records files matching this patient profile.</p>
                                        <?php endif; ?>
                                    </div>
                                </div>
                            </div>
                        </div>
                    <?php endif; ?>

                <?php elseif ($show_register_form): 
                    // 2. REGISTER NEW WALK-IN PATIENT
                ?>
                    <div class="chart-title">
                        <a href="?tab=patients" class="btn-sm btn-secondary"><i class="fa-solid fa-arrow-left"></i> Back to Patient Index</a>
                    </div>
                    <div class="card" style="max-width: 600px;">
                        <div class="card-header">
                            <h2><i class="fa-solid fa-user-plus"></i> Register Walk-in Patient</h2>
                        </div>
                        <div class="card-body">
                            <form action="?tab=patients" method="POST">
                                <input type="hidden" name="action" value="register_walkin">
                                
                                <div class="form-row-3">
                                    <div class="form-group">
                                        <label for="reg_surname">Surname</label>
                                        <input type="text" name="surname" id="reg_surname" class="form-control" required placeholder="Surname">
                                    </div>
                                    <div class="form-group">
                                        <label for="reg_first_name">First Name</label>
                                        <input type="text" name="first_name" id="reg_first_name" class="form-control" required placeholder="First Name">
                                    </div>
                                    <div class="form-group">
                                        <label for="reg_middle_name">Middle Name</label>
                                        <input type="text" name="middle_name" id="reg_middle_name" class="form-control" placeholder="Middle Name">
                                    </div>
                                </div>
                                <div class="form-row">
                                    <div class="form-group">
                                        <label for="reg_email">Email Address</label>
                                        <input type="email" name="email" id="reg_email" class="form-control" required placeholder="e.g. john@example.com">
                                    </div>
                                    <div class="form-group">
                                        <label for="reg_gender">Gender</label>
                                        <select name="gender" id="reg_gender" class="form-control">
                                            <option value="Male">Male</option>
                                            <option value="Female">Female</option>
                                            <option value="Other">Other</option>
                                        </select>
                                    </div>
                                </div>
                                <div class="form-row">
                                    <div class="form-group">
                                        <label for="reg_birth">Birth Date</label>
                                        <input type="date" name="birth_date" id="reg_birth" class="form-control" required max="<?php echo date('Y-m-d'); ?>">
                                    </div>
                                    <div class="form-group">
                                        <label for="reg_contact">Contact details / Phone</label>
                                        <input type="text" name="contact_details" id="reg_contact" class="form-control" placeholder="e.g. 0917-123-4567">
                                    </div>
                                </div>
                                <div class="form-group">
                                    <label for="reg_lang">Preferred Language</label>
                                    <select name="preferred_language" id="reg_lang" class="form-control">
                                        <option value="English">English</option>
                                        <option value="Filipino">Filipino (Tagalog)</option>
                                        <option value="Cebuano">Cebuano</option>
                                    </select>
                                </div>
                                <div class="form-group">
                                    <label for="reg_history">Medical History Notes</label>
                                    <textarea name="medical_history" id="reg_history" class="form-control" rows="3" placeholder="Allergies, chronic conditions, etc."></textarea>
                                </div>
                                
                                <button type="submit" class="btn-sm btn-success" style="padding: 0.75rem 1.5rem; font-size: 0.95rem;">
                                    <i class="fa-solid fa-floppy-disk"></i> Register Patient Profile
                                </button>
                            </form>
                        </div>
                    </div>

                <?php elseif ($show_edit_id): 
                    // 3. EDIT PATIENT DEMOGRAPHICS
                    $stmt_u = $db->prepare("SELECT u.*, p.gender, p.birth_date, p.contact_details, p.medical_history, p.preferred_language 
                                            FROM users u 
                                            LEFT JOIN patients p ON u.id = p.patient_id 
                                            WHERE u.id = :pid AND u.role = 'Patient'");
                    $stmt_u->bindValue(':pid', $show_edit_id, SQLITE3_INTEGER);
                    $pat = $stmt_u->execute()->fetchArray(SQLITE3_ASSOC);
                ?>
                    <div class="chart-title">
                        <a href="?tab=patients" class="btn-sm btn-secondary"><i class="fa-solid fa-arrow-left"></i> Back to Patient Index</a>
                    </div>
                    <div class="card" style="max-width: 600px;">
                        <div class="card-header">
                            <h2><i class="fa-solid fa-pen-to-square"></i> Edit Demographics: <?php echo htmlspecialchars($pat['name']); ?></h2>
                        </div>
                        <div class="card-body">
                            <form action="?tab=patients" method="POST">
                                <input type="hidden" name="action" value="edit_patient">
                                <input type="hidden" name="patient_id" value="<?php echo $pat['id']; ?>">
                                
                                <div class="form-group">
                                    <label for="edit_name">Patient Full Name</label>
                                    <input type="text" name="name" id="edit_name" class="form-control" required value="<?php echo htmlspecialchars($pat['name']); ?>">
                                </div>
                                <div class="form-group">
                                    <label for="edit_gender">Gender</label>
                                    <select name="gender" id="edit_gender" class="form-control">
                                        <option value="Male" <?php echo $pat['gender'] === 'Male' ? 'selected' : ''; ?>>Male</option>
                                        <option value="Female" <?php echo $pat['gender'] === 'Female' ? 'selected' : ''; ?>>Female</option>
                                        <option value="Other" <?php echo $pat['gender'] === 'Other' ? 'selected' : ''; ?>>Other</option>
                                    </select>
                                </div>
                                <div class="form-group">
                                    <label for="edit_birth">Birth Date</label>
                                    <input type="date" name="birth_date" id="edit_birth" class="form-control" required value="<?php echo htmlspecialchars($pat['birth_date'] ?? ''); ?>" max="<?php echo date('Y-m-d'); ?>">
                                </div>
                                <div class="form-group">
                                    <label for="edit_contact">Contact details / Phone</label>
                                    <input type="text" name="contact_details" id="edit_contact" class="form-control" value="<?php echo htmlspecialchars($pat['contact_details'] ?? ''); ?>">
                                </div>
                                <div class="form-group">
                                    <label for="edit_lang">Preferred Language</label>
                                    <select name="preferred_language" id="edit_lang" class="form-control">
                                        <option value="English" <?php echo $pat['preferred_language'] === 'English' ? 'selected' : ''; ?>>English</option>
                                        <option value="Filipino" <?php echo $pat['preferred_language'] === 'Filipino' ? 'selected' : ''; ?>>Filipino (Tagalog)</option>
                                        <option value="Cebuano" <?php echo $pat['preferred_language'] === 'Cebuano' ? 'selected' : ''; ?>>Cebuano</option>
                                    </select>
                                </div>
                                <div class="form-group">
                                    <label for="edit_history">Medical History Notes</label>
                                    <textarea name="medical_history" id="edit_history" class="form-control" rows="3"><?php echo htmlspecialchars($pat['medical_history'] ?? ''); ?></textarea>
                                </div>
                                
                                <button type="submit" class="btn-sm btn-success" style="padding: 0.75rem 1.5rem; font-size: 0.95rem;">
                                    <i class="fa-solid fa-floppy-disk"></i> Update Profile
                                </button>
                            </form>
                        </div>
                    </div>

                <?php else: 
                    // 4. GENERAL PATIENT INDEX LIST
                ?>
                    <div style="display:flex; justify-content:space-between; align-items:center; margin-bottom: 1.5rem;">
                        <form action="" method="GET" style="display:flex; gap:0.5rem; flex-grow:1; max-width:400px; margin: 0;">
                            <input type="hidden" name="tab" value="patients">
                            <input type="text" name="search" class="form-control" placeholder="Search by name, ID, or phone..." value="<?php echo htmlspecialchars($search_query); ?>" style="height: 38px;">
                            <button type="submit" class="btn btn-success"><i class="fa-solid fa-magnifying-glass"></i> Search</button>
                        </form>
                        <a href="?tab=patients&action=show_register_form" class="btn-sm btn-success"><i class="fa-solid fa-plus"></i> Add Walk-in Patient</a>
                    </div>

                    <div class="card">
                        <div class="card-header">
                            <h2><i class="fa-solid fa-users"></i> Patient Records Directory</h2>
                        </div>
                        <div class="card-body">
                            <div class="table-responsive"><table class="data-table">
                                    <thead>
                                        <tr>
                                            <th>ID</th>
                                            <th>Patient Name</th>
                                            <th>Gender</th>
                                            <th>Birth Date</th>
                                            <th>Contact Details</th>
                                            <th>Preferred Language</th>
                                            <th>Status</th>
                                            <th style="text-align: right; width: 220px;">Profile Actions</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        <?php
                                        $sql = "SELECT u.id, u.name, u.email, u.status, p.gender, p.birth_date, p.contact_details, p.preferred_language 
                                                FROM users u 
                                                LEFT JOIN patients p ON u.id = p.patient_id 
                                                WHERE u.role = 'Patient'";
                                        if (!empty($search_query)) {
                                            $sql .= " AND (u.name LIKE '%$search_query%' OR u.id LIKE '%$search_query%' OR p.contact_details LIKE '%$search_query%')";
                                        }
                                        $sql .= " ORDER BY u.name ASC";
                                        
                                        $res = $db->query($sql);
                                        $has_patients = false;
                                        while ($p = $res->fetchArray(SQLITE3_ASSOC)):
                                            $has_patients = true;
                                        ?>
                                            <tr>
                                                <td><?php echo $p['id']; ?></td>
                                                <td><strong><?php echo htmlspecialchars($p['name']); ?></strong><br><span style="font-size:0.75rem; color:var(--text-muted);"><?php echo htmlspecialchars($p['email']); ?></span></td>
                                                <td><?php echo htmlspecialchars($p['gender'] ?? 'N/A'); ?></td>
                                                <td><?php echo htmlspecialchars($p['birth_date'] ?? 'N/A'); ?></td>
                                                <td><?php echo htmlspecialchars($p['contact_details'] ?? 'N/A'); ?></td>
                                                <td><?php echo htmlspecialchars($p['preferred_language'] ?? 'N/A'); ?></td>
                                                <td><span class="badge badge-<?php echo strtolower($p['status'] ?? 'Active'); ?>"><?php echo htmlspecialchars($p['status'] ?? 'Active'); ?></span></td>
                                                <td style="text-align: right;">
                                                    <div style="display:inline-flex; gap:0.25rem;">
                                                        <a href="?tab=patients&profile_id=<?php echo $p['id']; ?>" class="btn-sm btn-success" style="padding:0.4rem 0.6rem; font-size:0.8rem;"><i class="fa-solid fa-folder-open"></i> Profile</a>
                                                        <a href="?tab=patients&edit_id=<?php echo $p['id']; ?>" class="btn-sm btn-outline" style="padding:0.4rem 0.6rem; font-size:0.8rem;"><i class="fa-solid fa-pen"></i> Edit</a>
                                                    </div>
                                                </td>
                                            </tr>
                                        <?php endwhile; ?>
                                        <?php if (!$has_patients): ?>
                                            <tr>
                                                <td colspan="8" class="table-placeholder">No patient records found matching the criteria.</td>
                                            </tr>
                                        <?php endif; ?>
                                    </tbody>
                                </table>
                            </div>
                        </div>
                    </div>
                <?php endif; ?>

            <!-- TAB 3: APPOINTMENT OVERSIGHT -->
            <?php elseif ($tab === 'appointments'): 
                $show_schedule_form = (isset($_GET['action']) && $_GET['action'] === 'show_schedule_form');
                
                // Filters
                $filter_doctor = isset($_GET['doctor_id']) ? filter_var($_GET['doctor_id'], FILTER_VALIDATE_INT) : null;
                $filter_date = $_GET['appt_date'] ?? '';
                $filter_status = $_GET['appt_status'] ?? '';
            ?>

                <?php if ($show_schedule_form): ?>
                    <div class="chart-title">
                        <a href="?tab=appointments" class="btn-sm btn-secondary"><i class="fa-solid fa-arrow-left"></i> Back to Schedule</a>
                    </div>
                    <div class="card" style="max-width: 600px;">
                        <div class="card-header">
                            <h2><i class="fa-solid fa-calendar-plus"></i> Schedule Appointment (Walk-in/Phone)</h2>
                        </div>
                        <div class="card-body">
                            <form action="?tab=appointments" method="POST">
                                <input type="hidden" name="action" value="book_appointment">
                                
                                <div class="form-group">
                                    <label for="appt_patient">Patient</label>
                                    <select name="patient_id" id="appt_patient" class="form-control" required>
                                        <option value="" disabled selected>Select Patient...</option>
                                        <?php foreach ($patients_list as $pl): ?>
                                            <option value="<?php echo $pl['id']; ?>"><?php echo htmlspecialchars($pl['name']); ?> (<?php echo htmlspecialchars($pl['email']); ?>)</option>
                                        <?php endforeach; ?>
                                    </select>
                                </div>

                                <div class="form-group">
                                    <label for="appt_doctor">Doctor</label>
                                    <select name="doctor_id" id="appt_doctor" class="form-control" required>
                                        <option value="" disabled selected>Select Physician...</option>
                                        <?php foreach ($doctors as $d): ?>
                                            <option value="<?php echo $d['id']; ?>">Dr. <?php echo htmlspecialchars($d['name']); ?></option>
                                        <?php endforeach; ?>
                                    </select>
                                </div>

                                <div class="form-group">
                                    <label for="appt_date">Preferred Date</label>
                                    <input type="date" name="appointment_date" id="appt_date" class="form-control" required min="<?php echo date('Y-m-d'); ?>">
                                </div>

                                <div class="form-group">
                                    <label for="appt_slot">Time Slot</label>
                                    <select name="time_slot" id="appt_slot" class="form-control" required>
                                        <option value="09:00 AM">09:00 AM - 09:30 AM</option>
                                        <option value="10:00 AM">10:00 AM - 10:30 AM</option>
                                        <option value="11:00 AM">11:00 AM - 11:30 AM</option>
                                        <option value="01:30 PM">01:30 PM - 02:00 PM</option>
                                        <option value="02:30 PM">02:30 PM - 03:00 PM</option>
                                        <option value="03:30 PM">03:30 PM - 04:00 PM</option>
                                    </select>
                                </div>

                                <div class="form-group">
                                    <label for="appt_reason">Reason for Consultation</label>
                                    <input type="text" name="reason" id="appt_reason" class="form-control" placeholder="e.g. Checkup, Fever, Prescription renewal">
                                </div>

                                <button type="submit" class="btn-sm btn-success" style="padding: 0.75rem 1.5rem; font-size: 0.95rem;">
                                    <i class="fa-solid fa-floppy-disk"></i> Book Appointment
                                </button>
                            </form>
                        </div>
                    </div>
                <?php else: ?>
                    <!-- Appts Index & Queue Management -->
                    <div class="report-param-bar">
                        <form action="" method="GET" style="display:flex; flex-wrap:wrap; gap:1rem; margin:0; width:100%; align-items:flex-end;">
                            <input type="hidden" name="tab" value="appointments">
                            
                            <div class="form-group width-200">
                                <label for="filter_doc_id" style="font-size:0.75rem; font-weight:600; color:var(--text-muted);">Doctor</label>
                                <select name="doctor_id" id="filter_doc_id" class="form-control">
                                    <option value="">All Doctors</option>
                                    <?php foreach ($doctors as $d): ?>
                                        <option value="<?php echo $d['id']; ?>" <?php echo $filter_doctor == $d['id'] ? 'selected' : ''; ?>>Dr. <?php echo htmlspecialchars($d['name']); ?></option>
                                    <?php endforeach; ?>
                                </select>
                            </div>

                            <div class="form-group width-150">
                                <label for="filter_d" style="font-size:0.75rem; font-weight:600; color:var(--text-muted);">Date</label>
                                <input type="date" name="appt_date" id="filter_d" class="form-control" value="<?php echo htmlspecialchars($filter_date); ?>">
                            </div>

                            <div class="form-group width-150">
                                <label for="filter_st" style="font-size:0.75rem; font-weight:600; color:var(--text-muted);">Status</label>
                                <select name="appt_status" id="filter_st" class="form-control">
                                    <option value="">All Status</option>
                                    <option value="Pending" <?php echo $filter_status === 'Pending' ? 'selected' : ''; ?>>Pending</option>
                                    <option value="Scheduled" <?php echo $filter_status === 'Scheduled' ? 'selected' : ''; ?>>Scheduled</option>
                                    <option value="Checked-in" <?php echo $filter_status === 'Checked-in' ? 'selected' : ''; ?>>Checked-in</option>
                                    <option value="Completed" <?php echo $filter_status === 'Completed' ? 'selected' : ''; ?>>Completed</option>
                                    <option value="Cancelled" <?php echo $filter_status === 'Cancelled' ? 'selected' : ''; ?>>Cancelled</option>
                                    <option value="No-Show" <?php echo $filter_status === 'No-Show' ? 'selected' : ''; ?>>No-Show</option>
                                </select>
                            </div>

                            <button type="submit" class="btn btn-success"><i class="fa-solid fa-filter"></i> Apply Filters</button>
                            <a href="?tab=appointments&action=show_schedule_form" class="btn-sm btn-success" style="height:38px; margin-left:auto;"><i class="fa-solid fa-calendar-plus"></i> Book Walk-in/Phone</a>
                        </form>
                    </div>

                    <div class="card">
                        <div class="card-header">
                            <h2><i class="fa-solid fa-list-ol"></i> Live Schedule & Queue List</h2>
                        </div>
                        <div class="card-body">
                            <div class="table-responsive"><table class="data-table">
                                    <thead>
                                        <tr>
                                            <th>Queue #</th>
                                            <th>Patient</th>
                                            <th>Doctor</th>
                                            <th>Date</th>
                                            <th>Time Slot</th>
                                            <th>Reason</th>
                                            <th>Status</th>
                                            <th style="text-align: right; width: 360px;">Control Actions</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        <?php
                                        $sql = "SELECT a.*, u.name as patient_name, d.name as doctor_name 
                                                FROM appointments a 
                                                JOIN users u ON a.patient_id = u.id 
                                                JOIN users d ON a.doctor_id = d.id 
                                                WHERE 1=1";
                                        if ($filter_doctor) $sql .= " AND a.doctor_id = $filter_doctor";
                                        if ($filter_date) $sql .= " AND a.appointment_date = '$filter_date'";
                                        if ($filter_status) $sql .= " AND a.status = '$filter_status'";
                                        $sql .= " ORDER BY a.appointment_date ASC, a.queue_number ASC";

                                        $res = $db->query($sql);
                                        $has_records = false;
                                        while ($app = $res->fetchArray(SQLITE3_ASSOC)):
                                            $has_records = true;
                                        ?>
                                            <tr>
                                                <td>
                                                    <?php if (!empty($app['queue_number'])): ?>
                                                        <span style="font-family: monospace; font-weight: 700; color: var(--primary);">Q-<?php echo $app['queue_number']; ?></span>
                                                    <?php else: ?>
                                                        <span style="color: var(--text-muted);">-</span>
                                                    <?php endif; ?>
                                                </td>
                                                <td><strong><?php echo htmlspecialchars($app['patient_name']); ?></strong></td>
                                                <td>Dr. <?php echo htmlspecialchars($app['doctor_name']); ?></td>
                                                <td><?php echo htmlspecialchars($app['appointment_date']); ?></td>
                                                <td><?php echo htmlspecialchars($app['time_slot']); ?></td>
                                                <td><?php echo htmlspecialchars($app['reason'] ?: 'Routine checkup'); ?></td>
                                                <td>
                                                    <span class="badge badge-<?php echo strtolower($app['status']); ?>">
                                                        <?php echo htmlspecialchars($app['status']); ?>
                                                    </span>
                                                </td>
                                                <td style="text-align: right;">
                                                    <div style="display:inline-flex; gap:0.25rem;">
                                                        <?php if ($app['status'] === 'Pending'): ?>
                                                            <form action="?tab=appointments" method="POST" style="display:inline;">
                                                                <input type="hidden" name="action" value="approve_appt">
                                                                <input type="hidden" name="appointment_id" value="<?php echo $app['id']; ?>">
                                                                <button type="submit" class="btn-sm btn-success"><i class="fa-solid fa-check"></i> Approve</button>
                                                            </form>
                                                            <form action="?tab=appointments" method="POST" style="display:inline;">
                                                                <input type="hidden" name="action" value="reject_appt">
                                                                <input type="hidden" name="appointment_id" value="<?php echo $app['id']; ?>">
                                                                <button type="submit" class="btn-sm btn-danger"><i class="fa-solid fa-xmark"></i> Reject</button>
                                                            </form>
                                                        <?php elseif (in_array($app['status'], ['Scheduled', 'Checked-in'])): ?>
                                                            <button class="btn-sm btn-outline" onclick="openRescheduleModal(<?php echo $app['id']; ?>, '<?php echo $app['appointment_date']; ?>', '<?php echo $app['time_slot']; ?>')">
                                                                <i class="fa-solid fa-clock-rotate-left"></i> Resched
                                                            </button>
                                                            <form action="?tab=appointments" method="POST" style="display:inline;" onsubmit="return confirm('Mark appointment as completed?');">
                                                                <input type="hidden" name="action" value="complete_appt">
                                                                <input type="hidden" name="appointment_id" value="<?php echo $app['id']; ?>">
                                                                <button type="submit" class="btn-sm btn-success"><i class="fa-solid fa-circle-check"></i> Done</button>
                                                            </form>
                                                            <form action="?tab=appointments" method="POST" style="display:inline;" onsubmit="return confirm('Cancel appointment?');">
                                                                <input type="hidden" name="action" value="cancel_appt">
                                                                <input type="hidden" name="appointment_id" value="<?php echo $app['id']; ?>">
                                                                <button type="submit" class="btn-sm btn-danger"><i class="fa-solid fa-ban"></i> Cancel</button>
                                                            </form>
                                                            <form action="?tab=appointments" method="POST" style="display:inline;" onsubmit="return confirm('Mark patient as No-Show?');">
                                                                <input type="hidden" name="action" value="noshow_appt">
                                                                <input type="hidden" name="appointment_id" value="<?php echo $app['id']; ?>">
                                                                <button type="submit" class="btn-sm btn-danger" style="background:#ef4444;"><i class="fa-solid fa-user-xmark"></i> No-Show</button>
                                                            </form>
                                                        <?php else: ?>
                                                            <span style="color: var(--text-muted); font-size: 0.8rem;">Finalized</span>
                                                        <?php endif; ?>
                                                    </div>
                                                </td>
                                            </tr>
                                        <?php endwhile; ?>
                                        <?php if (!$has_records): ?>
                                            <tr>
                                                <td colspan="8" class="table-placeholder">No appointments found.</td>
                                            </tr>
                                        <?php endif; ?>
                                    </tbody>
                                </table>
                            </div>
                        </div>
                    </div>
                <?php endif; ?>

            <!-- TAB 4: QR CODE CHECK-IN -->
            <?php elseif ($tab === 'checkin'): ?>
                <div class="card" style="max-width: 500px; margin: 0 auto;">
                    <div class="card-header" style="text-align: center;">
                        <h2><i class="fa-solid fa-qrcode"></i> QR Check-In Scanner Pass</h2>
                        <p style="font-size: 0.8rem; color: var(--text-muted); margin-top: 0.25rem;">Verify appointment check-in passes manually or via scanner simulation.</p>
                    </div>
                    <div class="card-body" style="text-align: center; padding: 2rem;">
                        <div style="background: rgba(20, 184, 166, 0.05); padding: 2rem; border-radius: var(--radius-lg); margin-bottom: 2rem; border: 2px dashed var(--primary-light);">
                            <i class="fa-solid fa-camera" style="font-size: 3rem; color: var(--primary); margin-bottom: 1rem; display: block;"></i>
                            <strong>Camera Scanner Simulator Ready</strong>
                            <p style="font-size: 0.8rem; color: var(--text-muted); margin-top: 0.5rem; line-height: 1.4;">Enter the digital pass numeric code manually below (this corresponds to the internal Database Appointment ID generated on the check-in QR code pass).</p>
                        </div>

                        <form action="?tab=checkin" method="POST" style="max-width: 320px; margin: 0 auto;">
                            <input type="hidden" name="action" value="checkin_patient">
                            <div class="form-group">
                                <label for="checkin_code" style="font-weight: 700;">Check-In Pass Numeric Code</label>
                                <input type="text" name="checkin_code" id="checkin_code" class="form-control" placeholder="e.g. 1, 2, 3..." required style="text-align: center; font-size: 1.25rem; font-family: monospace; letter-spacing: 2px;">
                            </div>
                            <button type="submit" class="btn-sm btn-success" style="width: 100%; padding: 0.75rem; font-size: 1rem; margin-top: 1rem;">
                                <i class="fa-solid fa-circle-check"></i> Validate Check-In Pass
                            </button>
                        </form>
                    </div>
                </div>

            <!-- TAB 5: REPORTS GENERATOR -->
            <?php elseif ($tab === 'reports'): 
                $active_report = $_GET['report_type'] ?? '';
                $doctor_filter = isset($_GET['doctor_id']) ? filter_var($_GET['doctor_id'], FILTER_VALIDATE_INT) : null;
                $start_date = $_GET['start_date'] ?? '';
                $end_date = $_GET['end_date'] ?? '';
            ?>
                <div class="card">
                    <div class="card-header">
                        <h2><i class="fa-solid fa-file-invoice"></i> Clinic Analytics & Operations Reports</h2>
                    </div>
                    <div class="card-body">
                        <!-- Report Category Cards -->
                        <div style="display: grid; grid-template-columns: repeat(auto-fit, minmax(220px, 1fr)); gap: 1rem; margin-bottom: 2rem;">
                            <?php
                            $reports_list = [
                                'appointments' => 'Appointment Schedules',
                                'consultations' => 'Daily Consultations',
                                'records_summary' => 'Medical Records summary',
                                'queue_waiting' => 'Priority Queue & Wait Times',
                                'visit_history' => 'Visit Histories',
                                'doctor_workload' => 'Doctor Workload & stats',
                                'noshows' => 'No-Show & cancellations',
                                'billing' => 'Billing & payments (Simulated)',
                                'high_risk' => 'High-Risk Patients (Bayes)',
                                'disease_trends' => 'Disease Trends (Aggregated)'
                            ];
                            foreach ($reports_list as $rep_key => $rep_val):
                            ?>
                                <a href="?tab=reports&report_type=<?php echo $rep_key; ?>" 
                                   style="text-decoration: none; color: inherit; padding: 1rem; border: 1px solid <?php echo $active_report === $rep_key ? 'var(--primary)' : 'var(--border-color)'; ?>; background: <?php echo $active_report === $rep_key ? 'rgba(20, 184, 166, 0.05)' : 'var(--card-bg)'; ?>; border-radius: var(--radius-md); display: block; transition: var(--transition-fast);">
                                    <strong style="display: block; font-size: 0.9rem; color: <?php echo $active_report === $rep_key ? 'var(--primary)' : 'var(--text-color)'; ?>;">
                                        <i class="fa-solid fa-file-prescription" style="margin-right: 0.35rem;"></i> <?php echo $rep_val; ?>
                                    </strong>
                                </a>
                            <?php endforeach; ?>
                        </div>

                        <?php if (!empty($active_report)): ?>
                            <!-- Date Filters -->
                            <div class="report-param-bar">
                                <div class="form-group width-200">
                                    <label style="font-size:0.75rem; font-weight:600; color:var(--text-muted);">Doctor Filter</label>
                                    <select id="rep_doc_id" class="form-control">
                                        <option value="">All Doctors</option>
                                        <?php foreach ($doctors as $d): ?>
                                            <option value="<?php echo $d['id']; ?>" <?php echo $doctor_filter == $d['id'] ? 'selected' : ''; ?>>Dr. <?php echo htmlspecialchars($d['name']); ?></option>
                                        <?php endforeach; ?>
                                    </select>
                                </div>
                                <div class="form-group width-150">
                                    <label style="font-size:0.75rem; font-weight:600; color:var(--text-muted);">Start Date</label>
                                    <input type="date" id="rep_start" class="form-control" value="<?php echo htmlspecialchars($start_date); ?>">
                                </div>
                                <div class="form-group width-150">
                                    <label style="font-size:0.75rem; font-weight:600; color:var(--text-muted);">End Date</label>
                                    <input type="date" id="rep_end" class="form-control" value="<?php echo htmlspecialchars($end_date); ?>">
                                </div>
                                <button type="button" onclick="applyReportFilters()" class="btn btn-success"><i class="fa-solid fa-sync"></i> Refresh</button>
                                
                                <div class="export-btn-group" style="margin-left: auto;">
                                    <button type="button" onclick="triggerCSVExport()" class="btn btn-success"><i class="fa-solid fa-file-csv"></i> Export CSV</button>
                                    <button type="button" onclick="window.print()" class="btn btn-outline-secondary"><i class="fa-solid fa-print"></i> Print PDF</button>
                                </div>
                            </div>

                            <div id="printable-report-area">
                                <h3 class="chart-title"><i class="fa-solid fa-file-waveform"></i> Report preview: <?php echo $reports_list[$active_report]; ?></h3>
                                <div class="table-responsive"><table class="data-table">
                                        <?php if ($active_report === 'appointments'): ?>
                                            <thead>
                                                <tr>
                                                    <th>ID</th>
                                                    <th>Patient Name</th>
                                                    <th>Doctor</th>
                                                    <th>Date</th>
                                                    <th>Time Slot</th>
                                                    <th>Reason</th>
                                                    <th>Status</th>
                                                </tr>
                                            </thead>
                                            <tbody>
                                                <?php
                                                $sql = "SELECT a.id, u.name as patient_name, d.name as doctor_name, a.appointment_date, a.time_slot, a.reason, a.status 
                                                        FROM appointments a 
                                                        JOIN users u ON a.patient_id = u.id 
                                                        JOIN users d ON a.doctor_id = d.id 
                                                        WHERE 1=1";
                                                if ($doctor_filter) $sql .= " AND a.doctor_id = $doctor_filter";
                                                if ($start_date) $sql .= " AND a.appointment_date >= '$start_date'";
                                                if ($end_date) $sql .= " AND a.appointment_date <= '$end_date'";
                                                $sql .= " ORDER BY a.appointment_date ASC";
                                                $res = $db->query($sql);
                                                $has_rows = false;
                                                while ($row = $res->fetchArray(SQLITE3_ASSOC)):
                                                    $has_rows = true;
                                                ?>
                                                    <tr>
                                                        <td><?php echo $row['id']; ?></td>
                                                        <td><strong><?php echo htmlspecialchars($row['patient_name']); ?></strong></td>
                                                        <td>Dr. <?php echo htmlspecialchars($row['doctor_name']); ?></td>
                                                        <td><?php echo htmlspecialchars($row['appointment_date']); ?></td>
                                                        <td><?php echo htmlspecialchars($row['time_slot']); ?></td>
                                                        <td><?php echo htmlspecialchars($row['reason'] ?: 'None'); ?></td>
                                                        <td><span class="badge badge-<?php echo strtolower($row['status']); ?>"><?php echo htmlspecialchars($row['status']); ?></span></td>
                                                    </tr>
                                                <?php endwhile; ?>
                                                <?php if (!$has_rows): ?><tr><td colspan="7" class="table-empty">No records available.</td></tr><?php endif; ?>
                                            </tbody>

                                        <?php elseif ($active_report === 'consultations'): ?>
                                            <thead>
                                                <tr>
                                                    <th>Record ID</th>
                                                    <th>Patient Name</th>
                                                    <th>Doctor</th>
                                                    <th>Diagnosis</th>
                                                    <th>Treatment</th>
                                                    <th>Date</th>
                                                </tr>
                                            </thead>
                                            <tbody>
                                                <?php
                                                $sql = "SELECT r.record_id, u.name as patient_name, d.name as doctor_name, r.diagnosis, r.treatment, r.consultation_date 
                                                        FROM medical_records r 
                                                        JOIN users u ON r.patient_id = u.id 
                                                        JOIN users d ON r.doctor_id = d.id 
                                                        WHERE 1=1";
                                                if ($doctor_filter) $sql .= " AND r.doctor_id = $doctor_filter";
                                                if ($start_date) $sql .= " AND r.consultation_date >= '$start_date'";
                                                if ($end_date) $sql .= " AND r.consultation_date <= '$end_date'";
                                                $sql .= " ORDER BY r.consultation_date DESC";
                                                $res = $db->query($sql);
                                                $has_rows = false;
                                                while ($row = $res->fetchArray(SQLITE3_ASSOC)):
                                                    $has_rows = true;
                                                ?>
                                                    <tr>
                                                        <td><?php echo $row['record_id']; ?></td>
                                                        <td><strong><?php echo htmlspecialchars($row['patient_name']); ?></strong></td>
                                                        <td>Dr. <?php echo htmlspecialchars($row['doctor_name']); ?></td>
                                                        <td><?php echo htmlspecialchars($row['diagnosis']); ?></td>
                                                        <td><?php echo htmlspecialchars($row['treatment']); ?></td>
                                                        <td><?php echo htmlspecialchars($row['consultation_date']); ?></td>
                                                    </tr>
                                                <?php endwhile; ?>
                                                <?php if (!$has_rows): ?><tr><td colspan="6" class="table-empty">No records available.</td></tr><?php endif; ?>
                                            </tbody>

                                        <?php elseif ($active_report === 'records_summary'): ?>
                                            <thead>
                                                <tr>
                                                    <th>Patient ID</th>
                                                    <th>Patient Name</th>
                                                    <th>Total Appointments</th>
                                                    <th>Total Consultation Records</th>
                                                </tr>
                                            </thead>
                                            <tbody>
                                                <?php
                                                $sql = "SELECT u.id, u.name, 
                                                        (SELECT COUNT(*) FROM appointments WHERE patient_id = u.id) as appt_count,
                                                        (SELECT COUNT(*) FROM medical_records WHERE patient_id = u.id) as rec_count
                                                        FROM users u 
                                                        WHERE u.role = 'Patient' ORDER BY u.name ASC";
                                                $res = $db->query($sql);
                                                $has_rows = false;
                                                while ($row = $res->fetchArray(SQLITE3_ASSOC)):
                                                    $has_rows = true;
                                                ?>
                                                    <tr>
                                                        <td><?php echo $row['id']; ?></td>
                                                        <td><strong><?php echo htmlspecialchars($row['name']); ?></strong></td>
                                                        <td><?php echo $row['appt_count']; ?></td>
                                                        <td><?php echo $row['rec_count']; ?></td>
                                                    </tr>
                                                <?php endwhile; ?>
                                                <?php if (!$has_rows): ?><tr><td colspan="4" class="table-empty">No records available.</td></tr><?php endif; ?>
                                            </tbody>

                                        <?php elseif ($active_report === 'queue_waiting'): ?>
                                            <thead>
                                                <tr>
                                                    <th>Date</th>
                                                    <th>Patient Name</th>
                                                    <th>Doctor</th>
                                                    <th>Queue Number</th>
                                                    <th>Status</th>
                                                </tr>
                                            </thead>
                                            <tbody>
                                                <?php
                                                $sql = "SELECT a.appointment_date, u.name as patient_name, d.name as doctor_name, a.queue_number, a.status 
                                                        FROM appointments a 
                                                        JOIN users u ON a.patient_id = u.id 
                                                        JOIN users d ON a.doctor_id = d.id 
                                                        WHERE a.status IN ('Scheduled', 'Checked-in')";
                                                if ($start_date) $sql .= " AND a.appointment_date >= '$start_date'";
                                                if ($end_date) $sql .= " AND a.appointment_date <= '$end_date'";
                                                $sql .= " ORDER BY a.appointment_date ASC, a.queue_number ASC";
                                                $res = $db->query($sql);
                                                $has_rows = false;
                                                while ($row = $res->fetchArray(SQLITE3_ASSOC)):
                                                    $has_rows = true;
                                                ?>
                                                    <tr>
                                                        <td><?php echo htmlspecialchars($row['appointment_date']); ?></td>
                                                        <td><strong><?php echo htmlspecialchars($row['patient_name']); ?></strong></td>
                                                        <td>Dr. <?php echo htmlspecialchars($row['doctor_name']); ?></td>
                                                        <td>Q-<?php echo htmlspecialchars($row['queue_number']); ?></td>
                                                        <td><span class="badge badge-<?php echo strtolower($row['status']); ?>"><?php echo htmlspecialchars($row['status']); ?></span></td>
                                                    </tr>
                                                <?php endwhile; ?>
                                                <?php if (!$has_rows): ?><tr><td colspan="5" class="table-empty">No active queue.</td></tr><?php endif; ?>
                                            </tbody>

                                        <?php elseif ($active_report === 'visit_history'): ?>
                                            <thead>
                                                <tr>
                                                    <th>Patient Name</th>
                                                    <th>Visit Date</th>
                                                    <th>Doctor Name</th>
                                                    <th>Reason</th>
                                                    <th>Status</th>
                                                </tr>
                                            </thead>
                                            <tbody>
                                                <?php
                                                $sql = "SELECT u.name as patient_name, a.appointment_date, d.name as doctor_name, a.reason, a.status 
                                                        FROM appointments a 
                                                        JOIN users u ON a.patient_id = u.id 
                                                        JOIN users d ON a.doctor_id = d.id 
                                                        WHERE a.status = 'Completed'";
                                                if ($start_date) $sql .= " AND a.appointment_date >= '$start_date'";
                                                if ($end_date) $sql .= " AND a.appointment_date <= '$end_date'";
                                                $sql .= " ORDER BY a.appointment_date DESC";
                                                $res = $db->query($sql);
                                                $has_rows = false;
                                                while ($row = $res->fetchArray(SQLITE3_ASSOC)):
                                                    $has_rows = true;
                                                ?>
                                                    <tr>
                                                        <td><strong><?php echo htmlspecialchars($row['patient_name']); ?></strong></td>
                                                        <td><?php echo htmlspecialchars($row['appointment_date']); ?></td>
                                                        <td>Dr. <?php echo htmlspecialchars($row['doctor_name']); ?></td>
                                                        <td><?php echo htmlspecialchars($row['reason'] ?: 'None'); ?></td>
                                                        <td><span class="badge badge-success"><?php echo htmlspecialchars($row['status']); ?></span></td>
                                                    </tr>
                                                <?php endwhile; ?>
                                                <?php if (!$has_rows): ?><tr><td colspan="5" class="table-empty">No visits recorded.</td></tr><?php endif; ?>
                                            </tbody>

                                        <?php elseif ($active_report === 'doctor_workload'): ?>
                                            <thead>
                                                <tr>
                                                    <th>Doctor Name</th>
                                                    <th>Scheduled Visits</th>
                                                    <th>Completed Visits</th>
                                                    <th>Cancelled/No-Show Visits</th>
                                                </tr>
                                            </thead>
                                            <tbody>
                                                <?php
                                                $sql = "SELECT d.name as doctor_name,
                                                        SUM(CASE WHEN a.status = 'Scheduled' THEN 1 ELSE 0 END) as scheduled_count,
                                                        SUM(CASE WHEN a.status = 'Completed' THEN 1 ELSE 0 END) as completed_count,
                                                        SUM(CASE WHEN a.status IN ('Cancelled', 'No-Show') THEN 1 ELSE 0 END) as inactive_count
                                                        FROM users d
                                                        LEFT JOIN appointments a ON d.id = a.doctor_id
                                                        WHERE d.role IN ('Doctor', 'Clinical Staff')";
                                                if ($start_date) $sql .= " AND a.appointment_date >= '$start_date'";
                                                if ($end_date) $sql .= " AND a.appointment_date <= '$end_date'";
                                                $sql .= " GROUP BY d.id ORDER BY d.name ASC";
                                                $res = $db->query($sql);
                                                $has_rows = false;
                                                while ($row = $res->fetchArray(SQLITE3_ASSOC)):
                                                    $has_rows = true;
                                                ?>
                                                    <tr>
                                                        <td><strong>Dr. <?php echo htmlspecialchars($row['doctor_name']); ?></strong></td>
                                                        <td><?php echo (int)$row['scheduled_count']; ?></td>
                                                        <td><?php echo (int)$row['completed_count']; ?></td>
                                                        <td><?php echo (int)$row['inactive_count']; ?></td>
                                                    </tr>
                                                <?php endwhile; ?>
                                                <?php if (!$has_rows): ?><tr><td colspan="4" class="table-empty">No records available.</td></tr><?php endif; ?>
                                            </tbody>

                                        <?php elseif ($active_report === 'noshows'): ?>
                                            <thead>
                                                <tr>
                                                    <th>Date</th>
                                                    <th>Patient Name</th>
                                                    <th>Doctor</th>
                                                    <th>Time Slot</th>
                                                    <th>Status</th>
                                                </tr>
                                            </thead>
                                            <tbody>
                                                <?php
                                                $sql = "SELECT a.appointment_date, u.name as patient_name, d.name as doctor_name, a.time_slot, a.status 
                                                        FROM appointments a 
                                                        JOIN users u ON a.patient_id = u.id 
                                                        JOIN users d ON a.doctor_id = d.id 
                                                        WHERE a.status IN ('Cancelled', 'No-Show')";
                                                if ($start_date) $sql .= " AND a.appointment_date >= '$start_date'";
                                                if ($end_date) $sql .= " AND a.appointment_date <= '$end_date'";
                                                $sql .= " ORDER BY a.appointment_date DESC";
                                                $res = $db->query($sql);
                                                $has_rows = false;
                                                while ($row = $res->fetchArray(SQLITE3_ASSOC)):
                                                    $has_rows = true;
                                                ?>
                                                    <tr>
                                                        <td><?php echo htmlspecialchars($row['appointment_date']); ?></td>
                                                        <td><strong><?php echo htmlspecialchars($row['patient_name']); ?></strong></td>
                                                        <td>Dr. <?php echo htmlspecialchars($row['doctor_name']); ?></td>
                                                        <td><?php echo htmlspecialchars($row['time_slot']); ?></td>
                                                        <td><span class="badge badge-danger"><?php echo htmlspecialchars($row['status']); ?></span></td>
                                                    </tr>
                                                <?php endwhile; ?>
                                                <?php if (!$has_rows): ?><tr><td colspan="5" class="table-empty">No cancelled or no-shows recorded.</td></tr><?php endif; ?>
                                            </tbody>

                                        <?php elseif ($active_report === 'billing'): ?>
                                            <thead>
                                                <tr>
                                                    <th>Consultation Date</th>
                                                    <th>Patient Name</th>
                                                    <th>Doctor</th>
                                                    <th>Consultation Fee (Simulated)</th>
                                                    <th>Payment Status</th>
                                                </tr>
                                            </thead>
                                            <tbody>
                                                <?php
                                                $sql = "SELECT a.appointment_date, u.name as patient_name, d.name as doctor_name 
                                                        FROM appointments a 
                                                        JOIN users u ON a.patient_id = u.id 
                                                        JOIN users d ON a.doctor_id = d.id 
                                                        WHERE a.status = 'Completed'";
                                                if ($start_date) $sql .= " AND a.appointment_date >= '$start_date'";
                                                if ($end_date) $sql .= " AND a.appointment_date <= '$end_date'";
                                                $sql .= " ORDER BY a.appointment_date DESC";
                                                $res = $db->query($sql);
                                                $has_rows = false;
                                                while ($row = $res->fetchArray(SQLITE3_ASSOC)):
                                                    $has_rows = true;
                                                ?>
                                                    <tr>
                                                        <td><?php echo htmlspecialchars($row['appointment_date']); ?></td>
                                                        <td><strong><?php echo htmlspecialchars($row['patient_name']); ?></strong></td>
                                                        <td>Dr. <?php echo htmlspecialchars($row['doctor_name']); ?></td>
                                                        <td>â‚±500.00</td>
                                                        <td><span class="badge badge-success">Paid</span></td>
                                                    </tr>
                                                <?php endwhile; ?>
                                                <?php if (!$has_rows): ?><tr><td colspan="5" class="table-empty">No billing history records matching search.</td></tr><?php endif; ?>
                                            </tbody>

                                        <?php elseif ($active_report === 'high_risk'): ?>
                                            <thead>
                                                <tr>
                                                    <th>Date Flagged</th>
                                                    <th>Patient Name</th>
                                                    <th>Symptoms Entered</th>
                                                    <th>Predicted Condition</th>
                                                    <th>Probability Score</th>
                                                </tr>
                                            </thead>
                                            <tbody>
                                                <?php
                                                $sql = "SELECT s.created_at, u.name as patient_name, s.symptoms_entered, s.predicted_condition, s.probability_score 
                                                        FROM symptoms s 
                                                        JOIN users u ON s.patient_id = u.id 
                                                        WHERE s.probability_score >= 0.80";
                                                if ($start_date) $sql .= " AND s.created_at >= '$start_date'";
                                                if ($end_date) $sql .= " AND s.created_at <= '$end_date'";
                                                $sql .= " ORDER BY s.created_at DESC";
                                                $res = $db->query($sql);
                                                $has_rows = false;
                                                while ($row = $res->fetchArray(SQLITE3_ASSOC)):
                                                    $has_rows = true;
                                                ?>
                                                    <tr>
                                                        <td><?php echo htmlspecialchars($row['created_at']); ?></td>
                                                        <td><strong><?php echo htmlspecialchars($row['patient_name']); ?></strong></td>
                                                        <td><?php echo htmlspecialchars($row['symptoms_entered']); ?></td>
                                                        <td><?php echo htmlspecialchars($row['predicted_condition']); ?></td>
                                                        <td><strong style="color:var(--danger);"><?php echo $row['probability_score'] * 100; ?>%</strong></td>
                                                    </tr>
                                                <?php endwhile; ?>
                                                <?php if (!$has_rows): ?><tr><td colspan="5" class="table-empty">No high-risk checks detected.</td></tr><?php endif; ?>
                                            </tbody>

                                        <?php elseif ($active_report === 'disease_trends'): ?>
                                            <thead>
                                                <tr>
                                                    <th>Predicted Condition</th>
                                                    <th>Total Logged Cases</th>
                                                    <th>Average Probability Score</th>
                                                </tr>
                                            </thead>
                                            <tbody>
                                                <?php
                                                $sql = "SELECT predicted_condition, COUNT(*) as cases_count, AVG(probability_score) as avg_score 
                                                        FROM symptoms 
                                                        GROUP BY predicted_condition ORDER BY cases_count DESC";
                                                $res = $db->query($sql);
                                                $has_rows = false;
                                                while ($row = $res->fetchArray(SQLITE3_ASSOC)):
                                                    $has_rows = true;
                                                ?>
                                                    <tr>
                                                        <td><strong><?php echo htmlspecialchars($row['predicted_condition']); ?></strong></td>
                                                        <td><?php echo $row['cases_count']; ?></td>
                                                        <td><?php echo number_format($row['avg_score'] * 100, 1); ?>%</td>
                                                    </tr>
                                                <?php endwhile; ?>
                                                <?php if (!$has_rows): ?><tr><td colspan="3" class="table-empty">No symptom checks records to aggregate.</td></tr><?php endif; ?>
                                            </tbody>
                                        <?php endif; ?>
                                    </table>
                                </div>
                            </div>
                        <?php else: ?>
                            <p class="table-placeholder">Please select a report category above to preview and export clinic data.</p>
                        <?php endif; ?>
                    </div>
                </div>

            <!-- TAB 6: NOTIFICATIONS -->
            <?php elseif ($tab === 'notifications'): ?>
                <div class="dashboard-block-grid">
                    <!-- Manual Notification Form -->
                    <div class="card">
                        <div class="card-header">
                            <h2><i class="fa-solid fa-paper-plane"></i> Send Appointment Reminders</h2>
                        </div>
                        <div class="card-body">
                            <form action="?tab=notifications" method="POST">
                                <input type="hidden" name="action" value="resend_reminder">
                                
                                <div class="form-group">
                                    <label for="reminder_appt">Patient Appointment</label>
                                    <select name="appointment_id" id="reminder_appt" class="form-control" required>
                                        <option value="" disabled selected>Select active scheduled appointment...</option>
                                        <?php
                                        $sql = "SELECT a.id, u.name as patient_name, a.appointment_date, a.time_slot 
                                                FROM appointments a 
                                                JOIN users u ON a.patient_id = u.id 
                                                WHERE a.status = 'Scheduled' 
                                                ORDER BY a.appointment_date ASC";
                                        $res = $db->query($sql);
                                        while ($app = $res->fetchArray(SQLITE3_ASSOC)):
                                        ?>
                                            <option value="<?php echo $app['id']; ?>">
                                                <?php echo htmlspecialchars($app['patient_name']); ?> - <?php echo htmlspecialchars($app['appointment_date']); ?> (<?php echo htmlspecialchars($app['time_slot']); ?>)
                                            </option>
                                        <?php endwhile; ?>
                                    </select>
                                </div>

                                <div class="form-group">
                                    <label for="reminder_type">Notification Channel</label>
                                    <select name="reminder_type" id="reminder_type" class="form-control">
                                        <option value="SMS">SMS Message</option>
                                        <option value="Email">Email Message</option>
                                    </select>
                                </div>

                                <div class="form-group">
                                    <label for="reminder_offset">Schedule Type</label>
                                    <select name="reminder_offset" id="reminder_offset" class="form-control">
                                        <option value="none">Send Instantly</option>
                                        <option value="1 hour before">1 Hour Before</option>
                                        <option value="2 hours before">2 Hours Before</option>
                                        <option value="1 day before">1 Day Before</option>
                                    </select>
                                </div>

                                <button type="submit" class="btn-sm btn-success" style="padding: 0.75rem 1.5rem; font-size: 0.95rem;">
                                    <i class="fa-solid fa-paper-plane"></i> Send Notification Reminder
                                </button>
                            </form>
                        </div>
                    </div>

                    <!-- Alerts of Overdue Records or Unconfirmed Appointments -->
                    <div class="card">
                        <div class="card-header">
                            <h2><i class="fa-solid fa-clock-rotate-left"></i> Outstanding Alerts log</h2>
                        </div>
                        <div class="card-body scrollable-y">
                            <?php
                            $alert_item_count = 0;
                            
                            // Check for overdue appointments (Scheduled in the past but not completed/cancelled)
                            $overdue_sql = "SELECT a.*, u.name as patient_name, d.name as doctor_name 
                                            FROM appointments a 
                                            JOIN users u ON a.patient_id = u.id 
                                            JOIN users d ON a.doctor_id = d.id 
                                            WHERE a.appointment_date < :today AND a.status IN ('Scheduled', 'Checked-in')";
                            $stmt_ov = $db->prepare($overdue_sql);
                            $stmt_ov->bindValue(':today', $today_str, SQLITE3_TEXT);
                            $ov_res = $stmt_ov->execute();
                            
                            while ($ov = $ov_res->fetchArray(SQLITE3_ASSOC)):
                                $alert_item_count++;
                            ?>
                                <div class="prescription-list-item" style="border-left: 4px solid var(--danger); background: rgba(239, 68, 68, 0.02); margin-bottom: 0.85rem; padding: 0.75rem; border-radius: var(--radius-sm);">
                                    <div style="font-weight: 700; color: var(--danger); font-size: 0.85rem;">
                                        OVERDUE APPOINTMENT STATUS
                                    </div>
                                    <div style="font-size: 0.85rem; color: var(--text-color); margin-top: 0.15rem;">
                                        Patient <strong><?php echo htmlspecialchars($ov['patient_name']); ?></strong> was scheduled with Dr. <strong><?php echo htmlspecialchars($ov['doctor_name']); ?></strong> on <?php echo htmlspecialchars($ov['appointment_date']); ?> but status was never updated.
                                    </div>
                                </div>
                            <?php endwhile; ?>

                            <?php if ($alert_item_count === 0): ?>
                                <p class="table-placeholder">All records are fully up to date.</p>
                            <?php endif; ?>
                        </div>
                    </div>
                </div>
            <?php endif; ?>

        </main>

    </div>

    <!-- Reschedule Modal -->
    <div class="calendar-modal" id="rescheduleModal">
        <div class="calendar-modal-content">
            <button type="button" class="calendar-modal-close" onclick="closeRescheduleModal()">&times;</button>
            <h3 class="calendar-modal-title">Reschedule Appointment</h3>
            
            <form action="?tab=appointments" method="POST">
                <input type="hidden" name="action" value="reschedule_appointment">
                <input type="hidden" name="appointment_id" id="rescheduleApptId" value="">
                
                <div class="form-group">
                    <label for="rescheduleDate">Preferred Date</label>
                    <input type="date" name="reschedule_date" id="rescheduleDate" class="form-control" required min="<?php echo date('Y-m-d'); ?>">
                </div>

                <div class="form-group">
                    <label for="rescheduleSlot">Preferred Time</label>
                    <select name="reschedule_slot" id="rescheduleSlot" class="form-control" required>
                        <option value="09:00 AM">09:00 AM - 09:30 AM</option>
                        <option value="10:00 AM">10:00 AM - 10:30 AM</option>
                        <option value="11:00 AM">11:00 AM - 11:30 AM</option>
                        <option value="01:30 PM">01:30 PM - 02:00 PM</option>
                        <option value="02:30 PM">02:30 PM - 03:00 PM</option>
                        <option value="03:30 PM">03:30 PM - 04:00 PM</option>
                    </select>
                </div>

                <div class="form-actions">
                    <button type="button" class="btn-sm btn-secondary" onclick="closeRescheduleModal()">Cancel</button>
                    <button type="submit" class="btn-sm btn-success">Reschedule</button>
                </div>
            </form>
        </div>
    </div>

    <script>
        function openRescheduleModal(apptId, date, slot) {
            document.getElementById('rescheduleApptId').value = apptId;
            document.getElementById('rescheduleDate').value = date;
            document.getElementById('rescheduleSlot').value = slot;
            document.getElementById('rescheduleModal').classList.add('active');
        }
        function closeRescheduleModal() {
            document.getElementById('rescheduleModal').classList.remove('active');
        }

        // Reports Filter and CSV Export Scripts
        function applyReportFilters() {
            const docId = document.getElementById('rep_doc_id').value;
            const start = document.getElementById('rep_start').value;
            const end = document.getElementById('rep_end').value;
            const urlParams = new URLSearchParams(window.location.search);
            
            urlParams.set('doctor_id', docId);
            urlParams.set('start_date', start);
            urlParams.set('end_date', end);
            
            window.location.search = urlParams.toString();
        }

        function triggerCSVExport() {
            const urlParams = new URLSearchParams(window.location.search);
            urlParams.set('action', 'export_csv');
            window.location.href = 'staff_dashboard.php?' + urlParams.toString();
        }
        
        document.getElementById('sidebar-toggle-btn').addEventListener('click', () => {
            document.body.classList.toggle('sidebar-collapsed');
        });

        // Dark mode toggle
        const themeToggle = document.getElementById('theme-toggle');
        const icon = themeToggle.querySelector('i');
        function applyTheme(theme) {
            document.documentElement.setAttribute('data-theme', theme);
            localStorage.setItem('clinick-theme', theme);
            icon.className = theme === 'dark' ? 'fa-solid fa-moon' : 'fa-solid fa-sun';
        }
        themeToggle.addEventListener('click', () => {
            const current = document.documentElement.getAttribute('data-theme');
            applyTheme(current === 'dark' ? 'light' : 'dark');
        });
        const savedTheme = localStorage.getItem('clinick-theme') || 'light';
        if (savedTheme === 'dark') document.documentElement.setAttribute('data-theme', 'dark');
        icon.className = savedTheme === 'dark' ? 'fa-solid fa-moon' : 'fa-solid fa-sun';
    </script>
<?php include 'chatbot-widget.php'; ?>
</body>
</html>
