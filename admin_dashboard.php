<?php
require_once __DIR__ . '/db.php';

// Auth Guard: Only Admin allowed
check_auth(['Admin']);

$db = get_db_connection();
$admin_id = $_SESSION['user_id'];
$admin_name = $_SESSION['user_name'];
$admin_role = $_SESSION['user_role'];
$success_msg = "";
$error_msg = "";

// Helper to check if a user is the primary seed admin to prevent lockout
if (!function_exists('is_primary_admin')) {
    function is_primary_admin($email) {
        return strtolower(trim($email)) === 'admin@clinick.com';
    }
}


// A. Handle GET export trigger BEFORE sending any HTML output
if (isset($_GET['action']) && $_GET['action'] === 'export_csv') {
    $report_type = $_GET['report_type'] ?? '';
    $doctor_filter = isset($_GET['doctor_id']) && $_GET['doctor_id'] !== '' ? filter_var($_GET['doctor_id'], FILTER_VALIDATE_INT) : null;
    $patient_filter = isset($_GET['patient_id']) && $_GET['patient_id'] !== '' ? filter_var($_GET['patient_id'], FILTER_VALIDATE_INT) : null;
    $start_date = $_GET['start_date'] ?? '';
    $end_date = $_GET['end_date'] ?? '';

    // Log the audit view
    log_audit_action($admin_id, $admin_name, 'Exported CSV Report', 'Report: ' . $report_type);

    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename=CLINICK_Admin_' . $report_type . '_Report_' . date('Ymd_His') . '.csv');
    
    $output = fopen('php://output', 'w');

    if ($report_type === 'appointments') {
        fputcsv($output, ['Appointment ID', 'Patient Name', 'Doctor Name', 'Date', 'Time Slot', 'Reason', 'Status', 'Queue Number']);
        $sql = "SELECT a.id, u.name as patient_name, d.name as doctor_name, a.appointment_date, a.time_slot, a.reason, a.status, a.queue_number 
                FROM appointments a 
                JOIN users u ON a.patient_id = u.id 
                JOIN users d ON a.doctor_id = d.id 
                WHERE 1=1";
        if ($doctor_filter) $sql .= " AND a.doctor_id = $doctor_filter";
        if ($patient_filter) $sql .= " AND a.patient_id = $patient_filter";
        if ($start_date) $sql .= " AND a.appointment_date >= '$start_date'";
        if ($end_date) $sql .= " AND a.appointment_date <= '$end_date'";
        $sql .= " ORDER BY a.appointment_date ASC, a.time_slot ASC";
        
        $res = $db->query($sql);
        while ($row = $res->fetchArray(SQLITE3_ASSOC)) {
            fputcsv($output, $row);
        }
    } elseif ($report_type === 'consultations') {
        fputcsv($output, ['Record ID', 'Patient Name', 'Doctor Name', 'Diagnosis', 'Treatment', 'Consultation Date', 'Doctor Notes']);
        $sql = "SELECT r.record_id, u.name as patient_name, d.name as doctor_name, r.diagnosis, r.treatment, r.consultation_date, r.doctor_notes 
                FROM medical_records r 
                JOIN users u ON r.patient_id = u.id 
                JOIN users d ON r.doctor_id = d.id 
                WHERE 1=1";
        if ($doctor_filter) $sql .= " AND r.doctor_id = $doctor_filter";
        if ($patient_filter) $sql .= " AND r.patient_id = $patient_filter";
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
                WHERE u.role = 'Patient'";
        if ($patient_filter) $sql .= " AND u.id = $patient_filter";
        $sql .= " ORDER BY u.name ASC";
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
        if ($doctor_filter) $sql .= " AND a.doctor_id = $doctor_filter";
        if ($patient_filter) $sql .= " AND a.patient_id = $patient_filter";
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
        if ($doctor_filter) $sql .= " AND a.doctor_id = $doctor_filter";
        if ($patient_filter) $sql .= " AND a.patient_id = $patient_filter";
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
        if ($doctor_filter) $sql .= " AND d.id = $doctor_filter";
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
        if ($doctor_filter) $sql .= " AND a.doctor_id = $doctor_filter";
        if ($patient_filter) $sql .= " AND a.patient_id = $patient_filter";
        if ($start_date) $sql .= " AND a.appointment_date >= '$start_date'";
        if ($end_date) $sql .= " AND a.appointment_date <= '$end_date'";
        $sql .= " ORDER BY a.appointment_date DESC";
        
        $res = $db->query($sql);
        while ($row = $res->fetchArray(SQLITE3_ASSOC)) {
            fputcsv($output, $row);
        }
    } elseif ($report_type === 'billing') {
        fputcsv($output, ['Consultation Date', 'Patient Name', 'Doctor Name', 'Consultation Fee', 'Payment Status']);
        $sql = "SELECT a.appointment_date, u.name as patient_name, d.name as doctor_name, 'Ã¢â€šÂ±500.00' as fee, 'Paid' as payment_status 
                FROM appointments a 
                JOIN users u ON a.patient_id = u.id 
                JOIN users d ON a.doctor_id = d.id 
                WHERE a.status = 'Completed'";
        if ($doctor_filter) $sql .= " AND a.doctor_id = $doctor_filter";
        if ($patient_filter) $sql .= " AND a.patient_id = $patient_filter";
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
        if ($patient_filter) $sql .= " AND s.patient_id = $patient_filter";
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

// B. Handle Administrative POST triggers (User, Doctor, Record management)
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
    $action = $_POST['action'];

    // 1. User Management CRUD
    if ($action === 'add_user') {
        $surname = trim($_POST['surname'] ?? '');
        $first_name = trim($_POST['first_name'] ?? '');
        $middle_name = trim($_POST['middle_name'] ?? '');
        $name = trim(trim($first_name . ' ' . $middle_name) . ' ' . $surname);

        $email = filter_var(trim($_POST['email'] ?? ''), FILTER_VALIDATE_EMAIL);
        $password = $_POST['password'] ?? '';
        $role = trim($_POST['role'] ?? '');
        $status = trim($_POST['status'] ?? 'Active');

        if (empty($surname) || empty($first_name) || !$email || empty($password) || empty($role)) {
            $error_msg = "Please enter valid user account details.";
        } else {
            $stmt_check = $db->prepare("SELECT id FROM users WHERE email = :email");
            $stmt_check->bindValue(':email', $email, SQLITE3_TEXT);
            if ($stmt_check->execute()->fetchArray(SQLITE3_ASSOC)) {
                $error_msg = "This email is already registered.";
            } else {
                $hashed_pw = password_hash($password, PASSWORD_DEFAULT);
                $db->exec("BEGIN TRANSACTION;");
                try {
                    $stmt_ins = $db->prepare("INSERT INTO users (name, email, password, role, status) VALUES (:name, :email, :pw, :role, :status)");
                    $stmt_ins->bindValue(':name', $name, SQLITE3_TEXT);
                    $stmt_ins->bindValue(':email', $email, SQLITE3_TEXT);
                    $stmt_ins->bindValue(':pw', $hashed_pw, SQLITE3_TEXT);
                    $stmt_ins->bindValue(':role', $role, SQLITE3_TEXT);
                    $stmt_ins->bindValue(':status', $status, SQLITE3_TEXT);
                    $stmt_ins->execute();

                    $new_uid = $db->lastInsertRowID();

                    if ($role === 'Patient') {
                        $stmt_p = $db->prepare("INSERT INTO patients (patient_id, name, gender, birth_date, contact_details, medical_history, preferred_language) VALUES (:id, :name, 'Male', '1995-08-15', '0917-123-4567', 'None', 'English')");
                        $stmt_p->bindValue(':id', $new_uid, SQLITE3_INTEGER);
                        $stmt_p->bindValue(':name', $name, SQLITE3_TEXT);
                        $stmt_p->execute();
                    } elseif ($role === 'Doctor' || $role === 'Clinical Staff') {
                        $stmt_d = $db->prepare("INSERT INTO doctors (doctor_id, name, specialization, contact_number, availability_status) VALUES (:id, :name, 'General Medicine', '0918-987-6543', 'Available')");
                        $stmt_d->bindValue(':id', $new_uid, SQLITE3_INTEGER);
                        $stmt_d->bindValue(':name', $name, SQLITE3_TEXT);
                        $stmt_d->execute();
                    }

                    $db->exec("COMMIT;");
                    log_audit_action($admin_id, $admin_name, 'Created User Account', 'User: ' . $email . ', Role: ' . $role);
                    $success_msg = "Account for " . htmlspecialchars($name) . " created successfully.";
                } catch (Exception $e) {
                    $db->exec("ROLLBACK;");
                    $error_msg = "Failed to create user account: " . $e->getMessage();
                }
            }
        }
    }

    elseif ($action === 'edit_user') {
        $target_id = filter_var($_POST['user_id'] ?? null, FILTER_VALIDATE_INT);
        $name = trim($_POST['name'] ?? '');
        $email = filter_var(trim($_POST['email'] ?? ''), FILTER_VALIDATE_EMAIL);
        $role = trim($_POST['role'] ?? '');
        $status = trim($_POST['status'] ?? 'Active');

        // Check if user exists
        $stmt_check = $db->prepare("SELECT email, role FROM users WHERE id = :id");
        $stmt_check->bindValue(':id', $target_id, SQLITE3_INTEGER);
        $target_user = $stmt_check->execute()->fetchArray(SQLITE3_ASSOC);

        if (!$target_user) {
            $error_msg = "User record not found.";
        } elseif (is_primary_admin($target_user['email']) && ($role !== 'Admin' || $status !== 'Active' || $email !== 'admin@clinick.com')) {
            // Safeguard blocked demotions/deactivations for core admin account
            $error_msg = "Safety lock: The primary super-administrator account cannot be demoted, deactivated, or renamed.";
        } elseif (empty($name) || !$email || empty($role)) {
            $error_msg = "Fields cannot be empty.";
        } else {
            $db->exec("BEGIN TRANSACTION;");
            try {
                $stmt_up = $db->prepare("UPDATE users SET name = :name, email = :email, role = :role, status = :status WHERE id = :id");
                $stmt_up->bindValue(':name', $name, SQLITE3_TEXT);
                $stmt_up->bindValue(':email', $email, SQLITE3_TEXT);
                $stmt_up->bindValue(':role', $role, SQLITE3_TEXT);
                $stmt_up->bindValue(':status', $status, SQLITE3_TEXT);
                $stmt_up->bindValue(':id', $target_id, SQLITE3_INTEGER);
                $stmt_up->execute();

                // Sync corresponding patient/doctor name changes
                if ($role === 'Patient') {
                    $stmt_p = $db->prepare("INSERT OR IGNORE INTO patients (patient_id, name) VALUES (:id, :name)");
                    $stmt_p->bindValue(':id', $target_id, SQLITE3_INTEGER);
                    $stmt_p->bindValue(':name', $name, SQLITE3_TEXT);
                    $stmt_p->execute();
                    
                    $stmt_pu = $db->prepare("UPDATE patients SET name = :name WHERE patient_id = :id");
                    $stmt_pu->bindValue(':name', $name, SQLITE3_TEXT);
                    $stmt_pu->bindValue(':id', $target_id, SQLITE3_INTEGER);
                    $stmt_pu->execute();
                } elseif ($role === 'Doctor' || $role === 'Clinical Staff') {
                    $stmt_d = $db->prepare("INSERT OR IGNORE INTO doctors (doctor_id, name) VALUES (:id, :name)");
                    $stmt_d->bindValue(':id', $target_id, SQLITE3_INTEGER);
                    $stmt_d->bindValue(':name', $name, SQLITE3_TEXT);
                    $stmt_d->execute();

                    $stmt_du = $db->prepare("UPDATE doctors SET name = :name WHERE doctor_id = :id");
                    $stmt_du->bindValue(':name', $name, SQLITE3_TEXT);
                    $stmt_du->bindValue(':id', $target_id, SQLITE3_INTEGER);
                    $stmt_du->execute();
                }

                $db->exec("COMMIT;");
                log_audit_action($admin_id, $admin_name, 'Modified User Details', 'Target User ID: ' . $target_id . ' (' . $email . ')');
                $success_msg = "User details updated successfully.";
            } catch (Exception $e) {
                $db->exec("ROLLBACK;");
                $error_msg = "Failed to update user account: " . $e->getMessage();
            }
        }
    }

    elseif ($action === 'deactivate_user') {
        $target_id = filter_var($_POST['user_id'] ?? null, FILTER_VALIDATE_INT);
        $stmt_check = $db->prepare("SELECT email FROM users WHERE id = :id");
        $stmt_check->bindValue(':id', $target_id, SQLITE3_INTEGER);
        $email = $stmt_check->execute()->fetchArray(SQLITE3_ASSOC)['email'] ?? '';

        if (is_primary_admin($email)) {
            $error_msg = "Safety lock: The primary super-administrator account cannot be deactivated.";
        } else {
            $stmt = $db->prepare("UPDATE users SET status = 'Inactive' WHERE id = :id");
            $stmt->bindValue(':id', $target_id, SQLITE3_INTEGER);
            if ($stmt->execute()) {
                log_audit_action($admin_id, $admin_name, 'Deactivated User Account', 'User ID: ' . $target_id);
                $success_msg = "User account deactivated successfully.";
            } else {
                $error_msg = "Failed to deactivate account.";
            }
        }
    }

    elseif ($action === 'reactivate_user') {
        $target_id = filter_var($_POST['user_id'] ?? null, FILTER_VALIDATE_INT);
        $stmt = $db->prepare("UPDATE users SET status = 'Active' WHERE id = :id");
        $stmt->bindValue(':id', $target_id, SQLITE3_INTEGER);
        if ($stmt->execute()) {
            log_audit_action($admin_id, $admin_name, 'Reactivated User Account', 'User ID: ' . $target_id);
            $success_msg = "User account reactivated successfully.";
        } else {
            $error_msg = "Failed to reactivate account.";
        }
    }

    elseif ($action === 'delete_user') {
        $target_id = filter_var($_POST['user_id'] ?? null, FILTER_VALIDATE_INT);
        $stmt_check = $db->prepare("SELECT email FROM users WHERE id = :id");
        $stmt_check->bindValue(':id', $target_id, SQLITE3_INTEGER);
        $email = $stmt_check->execute()->fetchArray(SQLITE3_ASSOC)['email'] ?? '';

        if (is_primary_admin($email)) {
            $error_msg = "Safety lock: The primary super-administrator account cannot be deleted.";
        } else {
            $stmt = $db->prepare("DELETE FROM users WHERE id = :id");
            $stmt->bindValue(':id', $target_id, SQLITE3_INTEGER);
            if ($stmt->execute()) {
                log_audit_action($admin_id, $admin_name, 'Deleted User Account', 'User ID: ' . $target_id . ' (Email: ' . $email . ')');
                $success_msg = "User account deleted successfully.";
            } else {
                $error_msg = "Failed to delete account.";
            }
        }
    }

    elseif ($action === 'reset_password') {
        $target_id = filter_var($_POST['user_id'] ?? null, FILTER_VALIDATE_INT);
        $new_pw = $_POST['new_password'] ?? '';
        
        $stmt_check = $db->prepare("SELECT email FROM users WHERE id = :id");
        $stmt_check->bindValue(':id', $target_id, SQLITE3_INTEGER);
        $email = $stmt_check->execute()->fetchArray(SQLITE3_ASSOC)['email'] ?? '';

        if (is_primary_admin($email) && $admin_id !== $target_id) {
            $error_msg = "Safety lock: Password reset on super-administrator is restricted.";
        } elseif (strlen($new_pw) < 6) {
            $error_msg = "Password must be at least 6 characters long.";
        } else {
            $hashed = password_hash($new_pw, PASSWORD_DEFAULT);
            $stmt = $db->prepare("UPDATE users SET password = :pw WHERE id = :id");
            $stmt->bindValue(':pw', $hashed, SQLITE3_TEXT);
            $stmt->bindValue(':id', $target_id, SQLITE3_INTEGER);
            if ($stmt->execute()) {
                log_audit_action($admin_id, $admin_name, 'Reset User Password', 'Target User ID: ' . $target_id);
                $success_msg = "Password reset successfully.";
            } else {
                $error_msg = "Failed to reset password.";
            }
        }
    }

    // 2. Doctor Details Edit
    elseif ($action === 'edit_doctor_details') {
        $did = filter_var($_POST['doctor_id'] ?? null, FILTER_VALIDATE_INT);
        $specialization = trim($_POST['specialization'] ?? 'General Medicine');
        $contact = trim($_POST['contact_number'] ?? '');
        $avail = trim($_POST['availability_status'] ?? 'Available');

        if ($did) {
            $stmt = $db->prepare("UPDATE doctors SET specialization = :spec, contact_number = :contact, availability_status = :avail WHERE doctor_id = :did");
            $stmt->bindValue(':spec', $specialization, SQLITE3_TEXT);
            $stmt->bindValue(':contact', $contact, SQLITE3_TEXT);
            $stmt->bindValue(':avail', $avail, SQLITE3_TEXT);
            $stmt->bindValue(':did', $did, SQLITE3_INTEGER);
            if ($stmt->execute()) {
                log_audit_action($admin_id, $admin_name, 'Modified Doctor Profile', 'Doctor ID: ' . $did);
                $success_msg = "Doctor details updated successfully.";
            } else {
                $error_msg = "Failed to update doctor profile.";
            }
        }
    }

    // 3. Patient Soft Archiving
    elseif ($action === 'archive_patient') {
        $pid = filter_var($_POST['patient_id'] ?? null, FILTER_VALIDATE_INT);
        if ($pid) {
            $stmt = $db->prepare("UPDATE users SET status = 'Archived' WHERE id = :id");
            $stmt->bindValue(':id', $pid, SQLITE3_INTEGER);
            if ($stmt->execute()) {
                log_audit_action($admin_id, $admin_name, 'Soft-Archived Patient Records', 'Patient ID: ' . $pid);
                $success_msg = "Patient record soft-archived successfully.";
            } else {
                $error_msg = "Failed to soft-archive patient record.";
            }
        }
    }

    elseif ($action === 'restore_patient') {
        $pid = filter_var($_POST['patient_id'] ?? null, FILTER_VALIDATE_INT);
        if ($pid) {
            $stmt = $db->prepare("UPDATE users SET status = 'Active' WHERE id = :id");
            $stmt->bindValue(':id', $pid, SQLITE3_INTEGER);
            if ($stmt->execute()) {
                log_audit_action($admin_id, $admin_name, 'Restored Soft-Archived Patient Records', 'Patient ID: ' . $pid);
                $success_msg = "Patient record restored successfully.";
            } else {
                $error_msg = "Failed to restore patient record.";
            }
        }
    }

    // 4. System Settings Reference Data Config
    elseif ($action === 'update_system_config') {
        $languages = trim($_POST['languages'] ?? '');
        $statuses = trim($_POST['statuses'] ?? '');
        $symptoms = trim($_POST['symptoms'] ?? '');
        $iso_eval = trim($_POST['iso_eval'] ?? '');

        // Verify JSON syntax for ISO settings
        json_decode($iso_eval);
        if (json_last_error() !== JSON_ERROR_NONE) {
            $error_msg = "ISO evaluation settings must be valid JSON format.";
        } else {
            $db->exec("BEGIN TRANSACTION;");
            try {
                $stmt1 = $db->prepare("UPDATE system_settings SET value = :val WHERE key = 'supported_languages'");
                $stmt1->bindValue(':val', $languages, SQLITE3_TEXT);
                $stmt1->execute();

                $stmt2 = $db->prepare("UPDATE system_settings SET value = :val WHERE key = 'appointment_statuses'");
                $stmt2->bindValue(':val', $statuses, SQLITE3_TEXT);
                $stmt2->execute();

                $stmt3 = $db->prepare("UPDATE system_settings SET value = :val WHERE key = 'symptom_categories'");
                $stmt3->bindValue(':val', $symptoms, SQLITE3_TEXT);
                $stmt3->execute();

                $stmt4 = $db->prepare("UPDATE system_settings SET value = :val WHERE key = 'iso_evaluation_settings'");
                $stmt4->bindValue(':val', $iso_eval, SQLITE3_TEXT);
                $stmt4->execute();

                $db->exec("COMMIT;");
                log_audit_action($admin_id, $admin_name, 'Updated Global System Configurations');
                $success_msg = "System configuration settings updated successfully.";
            } catch (Exception $e) {
                $db->exec("ROLLBACK;");
                $error_msg = "Failed to save configuration: " . $e->getMessage();
            }
        }
    }

    // 5. Flag / Unflag Chatbot Logs
    elseif ($action === 'toggle_flag_chatbot') {
        $log_id = filter_var($_POST['log_id'] ?? null, FILTER_VALIDATE_INT);
        $new_val = filter_var($_POST['is_flagged'] ?? 0, FILTER_VALIDATE_INT);

        if ($log_id !== null) {
            $stmt = $db->prepare("UPDATE chatbot_logs SET is_flagged = :flagged WHERE log_id = :lid");
            $stmt->bindValue(':flagged', $new_val, SQLITE3_INTEGER);
            $stmt->bindValue(':lid', $log_id, SQLITE3_INTEGER);
            if ($stmt->execute()) {
                log_audit_action($admin_id, $admin_name, 'Toggled Flag on Chatbot Log ID: ' . $log_id, 'Flag value: ' . $new_val);
                $success_msg = "Chatbot message log audit flag updated.";
            }
        }
    }
}

// C. Fetch general queries for view display
$tab = $_GET['tab'] ?? 'overview';

// Overview statistics calculations
$total_patients = $db->querySingle("SELECT COUNT(*) FROM users WHERE role = 'Patient'") ?? 0;
$total_doctors = $db->querySingle("SELECT COUNT(*) FROM users WHERE role = 'Doctor'") ?? 0;
$active_users = $db->querySingle("SELECT COUNT(*) FROM users WHERE status = 'Active'") ?? 0;

$today_str = date('Y-m-d');
$stmt_today = $db->prepare("SELECT COUNT(*) FROM appointments WHERE appointment_date = :today");
$stmt_today->bindValue(':today', $today_str, SQLITE3_TEXT);
$today_appointments = $stmt_today->execute()->fetchArray()[0] ?? 0;

$high_risk_patients = $db->querySingle("SELECT COUNT(DISTINCT patient_id) FROM symptoms WHERE probability_score >= 0.80") ?? 0;

// Trend calculations (compare today vs yesterday)
$yesterday_str = date('Y-m-d', strtotime('-1 day'));
$stmt_yest = $db->prepare("SELECT COUNT(*) FROM appointments WHERE appointment_date = :yesterday");
$stmt_yest->bindValue(':yesterday', $yesterday_str, SQLITE3_TEXT);
$yesterday_appointments = $stmt_yest->execute()->fetchArray()[0] ?? 0;

// Patient growth (last 7 days vs prior 7 days)
$recent_patients = $db->querySingle("SELECT COUNT(*) FROM users WHERE role = 'Patient' AND created_at >= date('now', '-7 days')") ?? 0;
$prior_patients = $db->querySingle("SELECT COUNT(*) FROM users WHERE role = 'Patient' AND created_at >= date('now', '-14 days') AND created_at < date('now', '-7 days')") ?? 0;

// Helper function for trend display
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

// System health simulated stats
$server_status = "Healthy";
$database_status = "Connected (SQLite)";
$recent_errors_count = $db->querySingle("SELECT COUNT(*) FROM audit_logs WHERE action LIKE '%Failed%' OR action LIKE '%Suspicious%'") ?? 0;
$avg_chatbot_response_time = "142 ms"; // Simulated metric

// Doctor list for filters
$doctors_res = $db->query("SELECT id, name FROM users WHERE role IN ('Doctor', 'Clinical Staff') AND status = 'Active' ORDER BY name ASC");
$doctors_filter_list = [];
while ($d = $doctors_res->fetchArray(SQLITE3_ASSOC)) {
    $doctors_filter_list[] = $d;
}

// Patient list for filters
$pats_res = $db->query("SELECT id, name FROM users WHERE role = 'Patient' ORDER BY name ASC");
$patients_filter_list = [];
while ($p = $pats_res->fetchArray(SQLITE3_ASSOC)) {
    $patients_filter_list[] = $p;
}

?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>CLINICK - System Administration Dashboard</title>
    <!-- Google Fonts: Inter -->
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link rel="stylesheet" href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&display=swap">
    <!-- FontAwesome Icon CDN -->
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <!-- Chart.js CDN for visual summaries -->
    <script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
    <!-- Custom Style Sheet -->
    <link rel="stylesheet" href="dashboard.css?v=<?php echo filemtime('dashboard.css'); ?>">
    <script src="js/theme-controller.js?v=<?php echo filemtime('js/theme-controller.js'); ?>"></script>
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
                            <i class="fa-solid fa-chart-pie"></i>
                            <span>Overview</span>
                        </a>
                    </li>
                    <li>
                        <a href="?tab=users" class="nav-link <?php echo $tab === 'users' ? 'active' : ''; ?>">
                            <i class="fa-solid fa-users-gear"></i>
                            <span>Users Directory</span>
                        </a>
                    </li>
                    <li>
                        <a href="?tab=doctors" class="nav-link <?php echo $tab === 'doctors' ? 'active' : ''; ?>">
                            <i class="fa-solid fa-user-doctor"></i>
                            <span>Doctors</span>
                        </a>
                    </li>
                    <li>
                        <a href="?tab=patients" class="nav-link <?php echo $tab === 'patients' ? 'active' : ''; ?>">
                            <i class="fa-solid fa-id-card"></i>
                            <span>Patients</span>
                        </a>
                    </li>
                    <li>
                        <a href="?tab=reports" class="nav-link <?php echo $tab === 'reports' ? 'active' : ''; ?>">
                            <i class="fa-solid fa-file-invoice-dollar"></i>
                            <span>Reports</span>
                        </a>
                    </li>
                    <li>
                        <a href="?tab=monitoring" class="nav-link <?php echo $tab === 'monitoring' ? 'active' : ''; ?>">
                            <i class="fa-solid fa-shield-halved"></i>
                            <span>Audit & Logs</span>
                        </a>
                    </li>
                    <li>
                        <a href="?tab=settings" class="nav-link <?php echo $tab === 'settings' ? 'active' : ''; ?>">
                            <i class="fa-solid fa-sliders"></i>
                            <span>Settings</span>
                        </a>
                    </li>
                </ul>
            </div>
            
            <div class="nav-actions">
                <div class="nav-user">
                    <div class="nav-user-avatar">
                        <i class="fa-solid fa-user-shield"></i>
                    </div>
                    <div class="nav-user-details">
                        <span class="nav-user-name"><?php echo htmlspecialchars($admin_name); ?></span>
                        <span class="nav-user-role">Administrator</span>
                    </div>
                </div>
                
                <button class="theme-toggle" id="theme-toggle" title="Toggle dark mode" style="margin:0;">
                    <span class="theme-toggle-thumb"><i class="fa-solid fa-sun"></i></span>
                </button>

                <a href="index.php?logout=true" class="btn btn-logout btn-secondary btn-sm" title="Sign Out">
                    <i class="fa-solid fa-right-from-bracket"></i>
                </a>
            </div>
        </header>

        <!-- Main Workspace -->
        <main class="main-content">
            
            <div class="page-header">
                <h1>Administrative Console</h1>
                <p>System Security, Audit Trails, Global Configuration, and Analytics Insights.</p>
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
                    <div class="stats-card">
                        <div class="stats-info">
                            <span class="stats-label">Total Registered Patients</span>
                            <span class="stats-number"><?php echo $total_patients; ?></span>
                            <?php echo get_trend_html($recent_patients, $prior_patients); ?>
                        </div>
                        <div class="stats-icon-container stats-icon-primary">
                            <i class="fa-solid fa-hospital-user"></i>
                        </div>
                    </div>
                    <div class="stats-card">
                        <div class="stats-info">
                            <span class="stats-label">Medical Practitioners</span>
                            <span class="stats-number"><?php echo $total_doctors; ?></span>
                            <span class="stats-trend stable"><i class="fa-solid fa-minus"></i> Stable</span>
                        </div>
                        <div class="stats-icon-container stats-icon-success">
                            <i class="fa-solid fa-stethoscope"></i>
                        </div>
                    </div>
                    <div class="stats-card">
                        <div class="stats-info">
                            <span class="stats-label">Today's Appointments</span>
                            <span class="stats-number"><?php echo $today_appointments; ?></span>
                            <?php echo get_trend_html($today_appointments, $yesterday_appointments); ?>
                        </div>
                        <div class="stats-icon-container stats-icon-warning">
                            <i class="fa-solid fa-calendar-day"></i>
                        </div>
                    </div>
                    <div class="stats-card">
                        <div class="stats-info">
                            <span class="stats-label">Flagged High-Risk Patients</span>
                            <span class="stats-number"><?php echo $high_risk_patients; ?></span>
                            <span class="stats-trend <?php echo $high_risk_patients > 0 ? 'down' : 'stable'; ?>">
                                <i class="fa-solid <?php echo $high_risk_patients > 0 ? 'fa-triangle-exclamation' : 'fa-shield-halved'; ?>"></i>
                                <?php echo $high_risk_patients > 0 ? 'Needs review' : 'Clear'; ?>
                            </span>
                        </div>
                        <div class="stats-icon-container stats-icon-danger">
                            <i class="fa-solid fa-heart-pulse"></i>
                        </div>
                    </div>
                </div>

                <!-- 3-Column Main Content Layout -->
                <div class="dashboard-main-grid">
                    <!-- Column Left: System Health & Status -->
                    <div class="column-left">
                        <div class="card">
                            <div class="card-header">
                                <h2><i class="fa-solid fa-server"></i> System Health</h2>
                            </div>
                            <div class="card-body">
                                <div style="display:flex; flex-direction:column; gap: var(--space-4);">
                                    <div style="display:flex; justify-content:space-between; align-items:center; font-size:0.85rem;">
                                        <span class="text-muted">Server Status</span>
                                        <strong><i class="fa-solid fa-circle-check text-success"></i> <?php echo $server_status; ?></strong>
                                    </div>
                                    <div style="display:flex; justify-content:space-between; align-items:center; font-size:0.85rem;">
                                        <span class="text-muted">Database</span>
                                        <strong><i class="fa-solid fa-database text-primary"></i> <?php echo $database_status; ?></strong>
                                    </div>
                                    <div style="display:flex; justify-content:space-between; align-items:center; font-size:0.85rem;">
                                        <span class="text-muted">Security Audits</span>
                                        <strong><span class="badge badge-<?php echo $recent_errors_count > 0 ? 'cancelled' : 'completed'; ?>"><?php echo $recent_errors_count; ?> Flagged</span></strong>
                                    </div>
                                    <div style="display:flex; justify-content:space-between; align-items:center; font-size:0.85rem;">
                                        <span class="text-muted">Avg. Response</span>
                                        <strong><i class="fa-solid fa-reply text-secondary"></i> <?php echo $avg_chatbot_response_time; ?></strong>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>

                    <!-- Column Center: Dynamic Charts -->
                    <div class="column-center">
                        <div class="card">
                            <div class="card-header">
                                <h2><i class="fa-solid fa-chart-line"></i> Consultations Frequency</h2>
                            </div>
                            <div class="card-body">
                                <canvas id="appointmentsChart" class="chart-canvas"></canvas>
                            </div>
                        </div>
                        <div class="card">
                            <div class="card-header">
                                <h2><i class="fa-solid fa-chart-pie"></i> Symptom Interpretations</h2>
                            </div>
                            <div class="card-body">
                                <canvas id="conditionsChart" class="chart-canvas"></canvas>
                            </div>
                        </div>
                    </div>

                    <!-- Column Right: Timeline & Critical Alerts -->
                    <div class="column-right">
                        <div class="card">
                            <div class="card-header">
                                <h2><i class="fa-solid fa-timeline"></i> System Timeline</h2>
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
                                <h2><i class="fa-solid fa-triangle-exclamation"></i> Security Alerts</h2>
                            </div>
                            <div class="card-body scrollable-y no-padding">
                                <?php
                                $alerts_res = $db->query("SELECT u.name, sc.predicted_condition, sc.probability_score 
                                                         FROM symptoms sc 
                                                         JOIN users u ON sc.patient_id = u.id 
                                                         WHERE sc.probability_score >= 0.8 
                                                         ORDER BY sc.symptom_id DESC LIMIT 2");
                                $has_alerts = false;
                                while ($alert = $alerts_res->fetchArray(SQLITE3_ASSOC)):
                                    $has_alerts = true;
                                ?>
                                    <div class="alert alert-danger" style="margin: 0.5rem; border-left: 4px solid var(--danger);">
                                        <div style="font-size:0.8rem;">
                                            <strong class="text-danger">HIGH-RISK: <?php echo htmlspecialchars($alert['predicted_condition']); ?> (<?php echo round($alert['probability_score'] * 100); ?>%)</strong><br>
                                            <span>Patient: <?php echo htmlspecialchars($alert['name']); ?></span>
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

                <?php
                // Fetch chart data
                $appts_data = [];
                $appts_labels = [];
                $res_appts = $db->query("SELECT appointment_date, COUNT(*) as count FROM appointments GROUP BY appointment_date ORDER BY appointment_date DESC LIMIT 7");
                while ($r = $res_appts->fetchArray(SQLITE3_ASSOC)) {
                    $appts_labels[] = $r['appointment_date'];
                    $appts_data[] = $r['count'];
                }
                $appts_labels = array_reverse($appts_labels);
                $appts_data = array_reverse($appts_data);

                $cond_data = [];
                $cond_labels = [];
                $res_cond = $db->query("SELECT predicted_condition, COUNT(*) as count FROM symptoms GROUP BY predicted_condition ORDER BY count DESC LIMIT 5");
                while ($r = $res_cond->fetchArray(SQLITE3_ASSOC)) {
                    $cond_labels[] = $r['predicted_condition'];
                    $cond_data[] = $r['count'];
                }
                ?>
                <script>
                    const ctxAppts = document.getElementById('appointmentsChart').getContext('2d');
                    new Chart(ctxAppts, {
                        type: 'line',
                        data: {
                            labels: <?php echo json_encode($appts_labels); ?>,
                            datasets: [{
                                label: 'Visits',
                                data: <?php echo json_encode($appts_data); ?>,
                                borderColor: '#14b8a6',
                                backgroundColor: 'rgba(20, 184, 166, 0.1)',
                                borderWidth: 2,
                                fill: true,
                                tension: 0.3
                            }]
                        },
                        options: {
                            responsive: true,
                            maintainAspectRatio: false,
                            plugins: { legend: { display: false } }
                        }
                    });

                    const ctxConds = document.getElementById('conditionsChart').getContext('2d');
                    new Chart(ctxConds, {
                        type: 'doughnut',
                        data: {
                            labels: <?php echo json_encode($cond_labels); ?>,
                            datasets: [{
                                data: <?php echo json_encode($cond_data); ?>,
                                backgroundColor: ['#0d9488', '#0f766e', '#14b8a6', '#2dd4bf', '#5eead4']
                            }]
                        },
                        options: {
                            responsive: true,
                            maintainAspectRatio: false,
                            plugins: { legend: { position: 'right' } }
                        }
                    });
                </script>

            <!-- TAB 2: USER MANAGEMENT -->
            <?php elseif ($tab === 'users'): 
                $search = trim($_GET['search'] ?? '');
                $role_filter = trim($_GET['role'] ?? '');
                $status_filter = trim($_GET['status'] ?? '');
                
                $sql = "SELECT * FROM users WHERE 1=1";
                if ($search !== '') {
                    $sql .= " AND (name LIKE '%$search%' OR email LIKE '%$search%')";
                }
                if ($role_filter !== '') {
                    $sql .= " AND role = '$role_filter'";
                }
                if ($status_filter !== '') {
                    $sql .= " AND status = '$status_filter'";
                }
                $sql .= " ORDER BY id DESC";
                $users_res = $db->query($sql);

                $show_add = isset($_GET['action']) && $_GET['action'] === 'show_add';
                $show_edit_id = isset($_GET['edit_id']) ? filter_var($_GET['edit_id'], FILTER_VALIDATE_INT) : null;
                $show_reset_id = isset($_GET['reset_id']) ? filter_var($_GET['reset_id'], FILTER_VALIDATE_INT) : null;
            ?>
                
                <!-- Add User Form -->
                <?php if ($show_add): ?>
                    <div class="card" style="margin-bottom: 1.5rem;">
                        <div class="card-header">
                            <h2><i class="fa-solid fa-user-plus"></i> Create User Account</h2>
                        </div>
                        <div class="card-body">
                            <form action="admin_dashboard.php?tab=users" method="POST">
                                <input type="hidden" name="action" value="add_user">
                                <div class="form-row-3">
                                    <div class="form-group">
                                        <label for="add_surname">Surname</label>
                                        <input type="text" id="add_surname" name="surname" class="form-control" placeholder="Surname" required>
                                    </div>
                                    <div class="form-group">
                                        <label for="add_first_name">First Name</label>
                                        <input type="text" id="add_first_name" name="first_name" class="form-control" placeholder="First Name" required>
                                    </div>
                                    <div class="form-group">
                                        <label for="add_middle_name">Middle Name</label>
                                        <input type="text" id="add_middle_name" name="middle_name" class="form-control" placeholder="Middle Name">
                                    </div>
                                </div>
                                <div class="form-row">
                                    <div class="form-group">
                                        <label for="add_email">Email Address</label>
                                        <input type="email" id="add_email" name="email" class="form-control" placeholder="e.g. john@example.com" required>
                                    </div>
                                    <div class="form-group">
                                        <label for="add_pw">Temporary Password</label>
                                        <input type="password" id="add_pw" name="password" class="form-control" placeholder="Min. 6 characters" required minlength="6">
                                    </div>
                                </div>
                                <div class="form-row">
                                    <div class="form-group">
                                        <label for="add_role">System Access Role</label>
                                        <select id="add_role" name="role" class="form-control" required>
                                            <option value="Patient">Patient</option>
                                            <option value="Doctor">Doctor</option>
                                            <option value="Clinical Staff">Clinical Staff</option>
                                            <option value="Admin">Administrator</option>
                                        </select>
                                    </div>
                                    <div class="form-group">
                                        <label for="add_status">Account Status</label>
                                        <select id="add_status" name="status" class="form-control" required>
                                            <option value="Active">Active</option>
                                            <option value="Inactive">Inactive</option>
                                        </select>
                                    </div>
                                </div>
                                <div style="margin-top: 1.5rem;">
                                    <button type="submit" class="btn"><i class="fa-solid fa-check"></i> Save Account</button>
                                    <a href="?tab=users" class="btn btn-secondary">Cancel</a>
                                </div>
                            </form>
                        </div>
                    </div>
                <?php endif; ?>

                <!-- Edit User Form -->
                <?php if ($show_edit_id): 
                    $stmt = $db->prepare("SELECT * FROM users WHERE id = :id");
                    $stmt->bindValue(':id', $show_edit_id, SQLITE3_INTEGER);
                    $u = $stmt->execute()->fetchArray(SQLITE3_ASSOC);
                ?>
                    <?php if ($u): ?>
                        <div class="card" style="margin-bottom: 1.5rem;">
                            <div class="card-header">
                                <h2><i class="fa-solid fa-user-pen"></i> Modify Account: <?php echo htmlspecialchars($u['name']); ?></h2>
                            </div>
                            <div class="card-body">
                                <form action="admin_dashboard.php?tab=users" method="POST" onsubmit="return confirm('Are you sure you want to update this user account details?');">
                                    <input type="hidden" name="action" value="edit_user">
                                    <input type="hidden" name="user_id" value="<?php echo $u['id']; ?>">
                                    <div class="form-row">
                                        <div class="form-group">
                                            <label for="edit_name">Full Name</label>
                                            <input type="text" id="edit_name" name="name" class="form-control" value="<?php echo htmlspecialchars($u['name']); ?>" required>
                                        </div>
                                        <div class="form-group">
                                            <label for="edit_email">Email Address</label>
                                            <input type="email" id="edit_email" name="email" class="form-control" value="<?php echo htmlspecialchars($u['email']); ?>" required <?php echo is_primary_admin($u['email']) ? 'readonly' : ''; ?>>
                                        </div>
                                    </div>
                                    <div class="form-row">
                                        <div class="form-group">
                                            <label for="edit_role">System Access Role</label>
                                            <select id="edit_role" name="role" class="form-control" required <?php echo is_primary_admin($u['email']) ? 'disabled' : ''; ?>>
                                                <option value="Patient" <?php echo $u['role'] === 'Patient' ? 'selected' : ''; ?>>Patient</option>
                                                <option value="Doctor" <?php echo $u['role'] === 'Doctor' ? 'selected' : ''; ?>>Doctor</option>
                                                <option value="Clinical Staff" <?php echo $u['role'] === 'Clinical Staff' ? 'selected' : ''; ?>>Clinical Staff</option>
                                                <option value="Staff" <?php echo $u['role'] === 'Staff' ? 'selected' : ''; ?>>Administrative Staff</option>
                                                <option value="Admin" <?php echo $u['role'] === 'Admin' ? 'selected' : ''; ?>>Administrator</option>
                                            </select>
                                            <?php if (is_primary_admin($u['email'])): ?>
                                                <input type="hidden" name="role" value="Admin">
                                            <?php endif; ?>
                                        </div>
                                        <div class="form-group">
                                            <label for="edit_status">Account Status</label>
                                            <select id="edit_status" name="status" class="form-control" required <?php echo is_primary_admin($u['email']) ? 'disabled' : ''; ?>>
                                                <option value="Active" <?php echo $u['status'] === 'Active' ? 'selected' : ''; ?>>Active</option>
                                                <option value="Inactive" <?php echo $u['status'] === 'Inactive' ? 'selected' : ''; ?>>Inactive</option>
                                                <option value="Archived" <?php echo $u['status'] === 'Archived' ? 'selected' : ''; ?>>Archived</option>
                                            </select>
                                            <?php if (is_primary_admin($u['email'])): ?>
                                                <input type="hidden" name="status" value="Active">
                                            <?php endif; ?>
                                        </div>
                                    </div>
                                    <button type="submit" class="btn"><i class="fa-solid fa-check"></i> Save Changes</button>
                                    <a href="?tab=users" class="btn btn-secondary">Cancel</a>
                                </form>
                            </div>
                        </div>
                    <?php endif; ?>
                <?php endif; ?>

                <!-- Reset Password form modal style -->
                <?php if ($show_reset_id): ?>
                    <div class="card" style="margin-bottom: 1.5rem; border: 1px solid var(--danger);">
                        <div class="card-header" style="background: rgba(239, 68, 68, 0.05);">
                            <h2 style="color:var(--danger);"><i class="fa-solid fa-key"></i> Force Password Reset</h2>
                        </div>
                        <div class="card-body">
                            <form action="admin_dashboard.php?tab=users" method="POST">
                                <input type="hidden" name="action" value="reset_password">
                                <input type="hidden" name="user_id" value="<?php echo $show_reset_id; ?>">
                                <div class="form-group" style="margin-bottom:1.5rem;">
                                    <label for="new_pw">New Secure Password</label>
                                    <input type="password" id="new_pw" name="new_password" class="form-control" placeholder="Min. 6 characters" required minlength="6">
                                </div>
                                <button type="submit" class="btn" style="background-color:var(--danger);"><i class="fa-solid fa-rotate"></i> Reset Password Now</button>
                                <a href="?tab=users" class="btn btn-secondary">Cancel</a>
                            </form>
                        </div>
                    </div>
                <?php endif; ?>

                <!-- User Directory Table View -->
                <div class="card">
                    <div class="card-header" style="display:flex; justify-content:space-between; align-items:center; flex-wrap:wrap; gap:1rem;">
                        <h2><i class="fa-solid fa-users"></i> Users Directory</h2>
                        <a href="?tab=users&action=show_add" class="btn"><i class="fa-solid fa-plus"></i> Add Account</a>
                    </div>
                    <div class="card-body">
                        <!-- Filters -->
                        <form action="admin_dashboard.php" method="GET" style="display:flex; gap:1rem; margin-bottom:1.5rem; flex-wrap:wrap; align-items:flex-end;">
                            <input type="hidden" name="tab" value="users">
                            <div class="form-group" style="flex:1; min-width:200px;">
                                <input type="text" name="search" class="form-control" placeholder="Search by name or email..." value="<?php echo htmlspecialchars($search); ?>">
                            </div>
                            <div class="form-group" style="width:180px;">
                                <select name="role" class="form-control">
                                    <option value="">All Roles</option>
                                    <option value="Patient" <?php echo $role_filter === 'Patient' ? 'selected' : ''; ?>>Patient</option>
                                    <option value="Doctor" <?php echo $role_filter === 'Doctor' ? 'selected' : ''; ?>>Doctor</option>
                                    <option value="Clinical Staff" <?php echo $role_filter === 'Clinical Staff' ? 'selected' : ''; ?>>Clinical Staff</option>
                                    <option value="Staff" <?php echo $role_filter === 'Staff' ? 'selected' : ''; ?>>Staff</option>
                                    <option value="Admin" <?php echo $role_filter === 'Admin' ? 'selected' : ''; ?>>Admin</option>
                                </select>
                            </div>
                            <div class="form-group" style="width:150px;">
                                <select name="status" class="form-control">
                                    <option value="">All Statuses</option>
                                    <option value="Active" <?php echo $status_filter === 'Active' ? 'selected' : ''; ?>>Active</option>
                                    <option value="Inactive" <?php echo $status_filter === 'Inactive' ? 'selected' : ''; ?>>Inactive</option>
                                    <option value="Archived" <?php echo $status_filter === 'Archived' ? 'selected' : ''; ?>>Archived</option>
                                </select>
                            </div>
                            <button type="submit" class="btn"><i class="fa-solid fa-filter"></i> Filter</button>
                            <a href="?tab=users" class="btn btn-secondary">Clear</a>
                        </form>

                        <div class="table-responsive">
                            <table class="data-table">
                                <thead>
                                    <tr>
                                        <th>User ID</th>
                                        <th>Name</th>
                                        <th>Email (Username)</th>
                                        <th>Access Role</th>
                                        <th>Account Status</th>
                                        <th style="text-align:right;">Actions</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php 
                                    $has_users = false;
                                    while ($u = $users_res->fetchArray(SQLITE3_ASSOC)):
                                        $has_users = true;
                                    ?>
                                        <tr>
                                            <td><strong>#<?php echo $u['id']; ?></strong></td>
                                            <td><?php echo htmlspecialchars($u['name']); ?></td>
                                            <td><?php echo htmlspecialchars($u['email']); ?></td>
                                            <td><span class="badge badge-primary"><?php echo htmlspecialchars($u['role']); ?></span></td>
                                            <td><span class="badge badge-<?php echo strtolower($u['status'] ?? 'Active'); ?>"><?php echo htmlspecialchars($u['status'] ?? 'Active'); ?></span></td>
                                            <td style="text-align:right;">
                                                <div style="display:inline-flex; gap:0.25rem;">
                                                    <a href="?tab=users&edit_id=<?php echo $u['id']; ?>" class="btn-sm btn-success"><i class="fa-solid fa-edit"></i> Edit</a>
                                                    <a href="?tab=users&reset_id=<?php echo $u['id']; ?>" class="btn-sm btn-outline"><i class="fa-solid fa-key"></i> PW</a>
                                                    
                                                    <?php if (!is_primary_admin($u['email'])): ?>
                                                        <?php if (($u['status'] ?? 'Active') === 'Active'): ?>
                                                            <form action="admin_dashboard.php?tab=users" method="POST" style="display:inline-block; margin:0;" onsubmit="return confirm('Deactivate this user account?');">
                                                                <input type="hidden" name="action" value="deactivate_user">
                                                                <input type="hidden" name="user_id" value="<?php echo $u['id']; ?>">
                                                                <button type="submit" class="btn-sm btn-danger"><i class="fa-solid fa-ban"></i> Deactivate</button>
                                                            </form>
                                                        <?php else: ?>
                                                            <form action="admin_dashboard.php?tab=users" method="POST" style="display:inline-block; margin:0;" onsubmit="return confirm('Reactivate this user account?');">
                                                                <input type="hidden" name="action" value="reactivate_user">
                                                                <input type="hidden" name="user_id" value="<?php echo $u['id']; ?>">
                                                                <button type="submit" class="btn-sm btn-success" style="background-color:var(--secondary);"><i class="fa-solid fa-circle-check"></i> Reactivate</button>
                                                            </form>
                                                        <?php endif; ?>

                                                        <form action="admin_dashboard.php?tab=users" method="POST" style="display:inline-block; margin:0;" onsubmit="return confirm('Are you sure you want to permanently delete this user account? All associated records will be permanently removed.');">
                                                            <input type="hidden" name="action" value="delete_user">
                                                            <input type="hidden" name="user_id" value="<?php echo $u['id']; ?>">
                                                            <button type="submit" class="btn-sm btn-danger" style="background-color:#b91c1c; border-color:#b91c1c; color:#fff;"><i class="fa-solid fa-trash"></i> Delete</button>
                                                        </form>
                                                    <?php endif; ?>
                                                </div>
                                            </td>
                                        </tr>
                                    <?php endwhile; ?>
                                    <?php if (!$has_users): ?>
                                        <tr><td colspan="6" style="text-align:center; color:var(--text-muted);">No system accounts match the current filter params.</td></tr>
                                    <?php endif; ?>
                                </tbody>
                            </table>
                        </div>
                    </div>
                </div>

            <!-- TAB 3: DOCTOR MANAGEMENT -->
            <?php elseif ($tab === 'doctors'): 
                $doc_id = isset($_GET['edit_doc_id']) ? filter_var($_GET['edit_doc_id'], FILTER_VALIDATE_INT) : null;
                $sql = "SELECT u.id, u.name, u.email, u.status, d.specialization, d.contact_number, d.availability_status 
                        FROM users u 
                        LEFT JOIN doctors d ON u.id = d.doctor_id 
                        WHERE u.role IN ('Doctor', 'Clinical Staff')
                        ORDER BY u.name ASC";
                $docs_res = $db->query($sql);
            ?>

                <!-- Edit Doctor Details Panel -->
                <?php if ($doc_id): 
                    $stmt = $db->prepare("SELECT u.name, d.* FROM users u JOIN doctors d ON u.id = d.doctor_id WHERE u.id = :id");
                    $stmt->bindValue(':id', $doc_id, SQLITE3_INTEGER);
                    $d_edit = $stmt->execute()->fetchArray(SQLITE3_ASSOC);
                ?>
                    <?php if ($d_edit): ?>
                        <div class="card" style="margin-bottom: 1.5rem;">
                            <div class="card-header">
                                <h2><i class="fa-solid fa-user-doctor"></i> Edit Doctor Details: <?php echo htmlspecialchars($d_edit['name']); ?></h2>
                            </div>
                            <div class="card-body">
                                <form action="admin_dashboard.php?tab=doctors" method="POST">
                                    <input type="hidden" name="action" value="edit_doctor_details">
                                    <input type="hidden" name="doctor_id" value="<?php echo $doc_id; ?>">
                                    <div class="form-row">
                                        <div class="form-group">
                                            <label for="edit_spec">Specialization</label>
                                            <input type="text" id="edit_spec" name="specialization" class="form-control" value="<?php echo htmlspecialchars($d_edit['specialization']); ?>" required>
                                        </div>
                                        <div class="form-group">
                                            <label for="edit_cont">Contact Number</label>
                                            <input type="text" id="edit_cont" name="contact_number" class="form-control" value="<?php echo htmlspecialchars($d_edit['contact_number']); ?>">
                                        </div>
                                    </div>
                                    <div class="form-group" style="margin-bottom:1.5rem;">
                                        <label for="edit_avail">Duty Availability Status</label>
                                        <select id="edit_avail" name="availability_status" class="form-control" required>
                                            <option value="Available" <?php echo $d_edit['availability_status'] === 'Available' ? 'selected' : ''; ?>>Available</option>
                                            <option value="Unavailable" <?php echo $d_edit['availability_status'] === 'Unavailable' ? 'selected' : ''; ?>>On Leave / Off-Duty</option>
                                        </select>
                                    </div>
                                    <button type="submit" class="btn"><i class="fa-solid fa-check"></i> Save Profile</button>
                                    <a href="?tab=doctors" class="btn btn-secondary">Cancel</a>
                                </form>
                            </div>
                        </div>
                    <?php endif; ?>
                <?php endif; ?>

                <div class="card">
                    <div class="card-header">
                        <h2><i class="fa-solid fa-stethoscope"></i> Doctor Listings & Workload summary</h2>
                    </div>
                    <div class="card-body">
                        <div class="table-responsive">
                            <table class="data-table">
                                <thead>
                                    <tr>
                                        <th>Doctor Name</th>
                                        <th>Specialization</th>
                                        <th>Contact</th>
                                        <th>Availability</th>
                                        <th>Total Appointment Load</th>
                                        <th>Account Status</th>
                                        <th style="text-align:right;">Actions</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php 
                                    while ($doc = $docs_res->fetchArray(SQLITE3_ASSOC)):
                                        // Calculate total appointments
                                        $appt_load = $db->querySingle("SELECT COUNT(*) FROM appointments WHERE doctor_id = " . intval($doc['id'])) ?? 0;
                                    ?>
                                        <tr>
                                            <td><strong><?php echo htmlspecialchars($doc['name']); ?></strong><br><span style="font-size:0.75rem; color:var(--text-muted);"><?php echo htmlspecialchars($doc['email']); ?></span></td>
                                            <td><?php echo htmlspecialchars($doc['specialization'] ?? 'General Medicine'); ?></td>
                                            <td><?php echo htmlspecialchars($doc['contact_number'] ?? 'N/A'); ?></td>
                                            <td>
                                                <span class="badge badge-<?php echo ($doc['availability_status'] ?? 'Available') === 'Available' ? 'success' : 'warning'; ?>">
                                                    <?php echo htmlspecialchars($doc['availability_status'] ?? 'Available'); ?>
                                                </span>
                                            </td>
                                            <td><strong><?php echo $appt_load; ?></strong> scheduled cases</td>
                                            <td><span class="badge badge-<?php echo strtolower($doc['status'] ?? 'Active'); ?>"><?php echo htmlspecialchars($doc['status'] ?? 'Active'); ?></span></td>
                                            <td style="text-align:right;">
                                                <a href="?tab=doctors&edit_doc_id=<?php echo $doc['id']; ?>" class="btn-sm btn-success"><i class="fa-solid fa-edit"></i> Edit Details</a>
                                            </td>
                                        </tr>
                                    <?php endwhile; ?>
                                </tbody>
                            </table>
                        </div>
                    </div>
                </div>

            <!-- TAB 4: PATIENT RECORDS OVERSIGHT -->
            <?php elseif ($tab === 'patients'): 
                $profile_search_id = isset($_GET['profile_id']) ? filter_var($_GET['profile_id'], FILTER_VALIDATE_INT) : null;
                $search_query = trim($_GET['search'] ?? '');
            ?>
                
                <?php if ($profile_search_id): 
                    // Log View audit
                    log_audit_action($admin_id, $admin_name, 'Viewed Patient Record Details (Audit Access)', 'Patient ID: ' . $profile_search_id);

                    // Fetch Profile details
                    $stmt_u = $db->prepare("SELECT u.*, p.gender, p.birth_date, p.contact_details, p.medical_history, p.preferred_language 
                                            FROM users u 
                                            LEFT JOIN patients p ON u.id = p.patient_id 
                                            WHERE u.id = :pid AND u.role = 'Patient'");
                    $stmt_u->bindValue(':pid', $profile_search_id, SQLITE3_INTEGER);
                    $pat = $stmt_u->execute()->fetchArray(SQLITE3_ASSOC);
                ?>
                    <div style="margin-bottom: 1rem;">
                        <a href="?tab=patients" class="btn-sm btn-secondary"><i class="fa-solid fa-arrow-left"></i> Back to Patient Directory</a>
                    </div>
                    
                    <?php if (!$pat): ?>
                        <div class="alert alert-danger">Patient record not found.</div>
                    <?php else: ?>
                        <div class="card" style="margin-bottom: 2rem;">
                            <div class="card-header" style="display:flex; justify-content:space-between; align-items:center;">
                                <h2><i class="fa-solid fa-address-card"></i> Patient Audit: <?php echo htmlspecialchars($pat['name']); ?></h2>
                                <span class="badge badge-<?php echo strtolower($pat['status'] ?? 'Active'); ?>"><?php echo htmlspecialchars($pat['status'] ?? 'Active'); ?></span>
                            </div>
                            <div class="card-body">
                                <div style="display: grid; grid-template-columns: 1fr 1.5fr; gap: 2rem;">
                                    <div>
                                        <div class="profile-section-card">
                                            <div style="font-weight:700; color:var(--primary); margin-bottom: 0.75rem; border-bottom:1px solid var(--border-color); padding-bottom:0.25rem;">Demographics</div>
                                            <p style="margin-bottom:0.4rem;"><strong>Email:</strong> <?php echo htmlspecialchars($pat['email']); ?></p>
                                            <p style="margin-bottom:0.4rem;"><strong>Gender:</strong> <?php echo htmlspecialchars($pat['gender'] ?? 'Male'); ?></p>
                                            <p style="margin-bottom:0.4rem;"><strong>Birth Date:</strong> <?php echo htmlspecialchars($pat['birth_date'] ?? 'N/A'); ?></p>
                                            <p style="margin-bottom:0.4rem;"><strong>Contact Details:</strong> <?php echo htmlspecialchars($pat['contact_details'] ?? 'N/A'); ?></p>
                                            <p style="margin-bottom:0.4rem;"><strong>Preferred Language:</strong> <?php echo htmlspecialchars($pat['preferred_language'] ?? 'English'); ?></p>
                                            <p style="margin-bottom:0.4rem;"><strong>Medical History Notes:</strong> <?php echo htmlspecialchars($pat['medical_history'] ?? 'None'); ?></p>
                                        </div>

                                        <!-- Diagnostic Symptom Checker History -->
                                        <div class="profile-section-card">
                                            <div style="font-weight:700; color:var(--primary); margin-bottom: 0.75rem; border-bottom:1px solid var(--border-color); padding-bottom:0.25rem;">Symptom Checker History (Naive Bayes)</div>
                                            <div style="max-height: 250px; overflow-y:auto;">
                                                <?php
                                                $stmt_sym = $db->prepare("SELECT * FROM symptoms WHERE patient_id = :pid ORDER BY created_at DESC");
                                                $stmt_sym->bindValue(':pid', $profile_search_id, SQLITE3_INTEGER);
                                                $sym_res = $stmt_sym->execute();
                                                $has_sym = false;
                                                while ($s = $sym_res->fetchArray(SQLITE3_ASSOC)):
                                                    $has_sym = true;
                                                ?>
                                                    <div style="padding:0.5rem 0; border-bottom:1px solid var(--border-color); font-size:0.8rem;">
                                                        <strong>Condition:</strong> <?php echo htmlspecialchars($s['predicted_condition']); ?> (<?php echo round($s['probability_score'] * 100); ?>%)<br>
                                                        <span style="color:var(--text-muted);">Symptoms: <?php echo htmlspecialchars($s['symptoms_entered']); ?></span><br>
                                                        <span style="font-size:0.7rem; color:var(--text-muted);"><?php echo $s['created_at']; ?></span>
                                                    </div>
                                                <?php endwhile; ?>
                                                <?php if (!$has_sym): ?><p style="color:var(--text-muted); text-align:center;">No records.</p><?php endif; ?>
                                            </div>
                                        </div>
                                    </div>

                                    <div>
                                        <!-- Medical Record Log -->
                                        <div class="profile-section-card">
                                            <div style="font-weight:700; color:var(--primary); margin-bottom: 0.75rem; border-bottom:1px solid var(--border-color); padding-bottom:0.25rem;">Electronic Medical Records Summary</div>
                                            <div style="max-height: 300px; overflow-y:auto;">
                                                <?php
                                                $stmt_rec = $db->prepare("SELECT r.*, d.name as doctor_name FROM medical_records r LEFT JOIN users d ON r.doctor_id = d.id WHERE r.patient_id = :pid ORDER BY r.consultation_date DESC");
                                                $stmt_rec->bindValue(':pid', $profile_search_id, SQLITE3_INTEGER);
                                                $rec_res = $stmt_rec->execute();
                                                $has_rec = false;
                                                while ($r = $rec_res->fetchArray(SQLITE3_ASSOC)):
                                                    $has_rec = true;
                                                ?>
                                                    <div style="padding:0.75rem; background:rgba(20,184,166,0.02); border:1px solid var(--border-color); border-radius:var(--radius-sm); margin-bottom:0.75rem;">
                                                        <div style="font-weight:700; color:var(--primary); font-size:0.85rem;"><?php echo htmlspecialchars($r['diagnosis']); ?></div>
                                                        <div style="font-size:0.8rem; margin:0.25rem 0;"><strong>Treatment Plan:</strong> <?php echo htmlspecialchars($r['treatment']); ?></div>
                                                        <div style="font-size:0.8rem; color:var(--text-color);"><strong>Doctor Notes:</strong> <?php echo htmlspecialchars($r['doctor_notes'] ?? 'None'); ?></div>
                                                        <div style="font-size:0.7rem; color:var(--text-muted); margin-top:0.25rem;">Provider: <?php echo htmlspecialchars($r['doctor_name']); ?> | Date: <?php echo htmlspecialchars($r['consultation_date']); ?></div>
                                                    </div>
                                                <?php endwhile; ?>
                                                <?php if (!$has_rec): ?><p style="color:var(--text-muted); text-align:center;">No medical records audited.</p><?php endif; ?>
                                            </div>
                                        </div>

                                        <!-- Chatbot Log History -->
                                        <div class="profile-section-card">
                                            <div style="font-weight:700; color:var(--primary); margin-bottom: 0.75rem; border-bottom:1px solid var(--border-color); padding-bottom:0.25rem;">Chatbot logs</div>
                                            <div style="max-height: 250px; overflow-y:auto;">
                                                <?php
                                                $stmt_chat = $db->prepare("SELECT * FROM chatbot_logs WHERE patient_id = :pid ORDER BY timestamp DESC");
                                                $stmt_chat->bindValue(':pid', $profile_search_id, SQLITE3_INTEGER);
                                                $chat_res = $stmt_chat->execute();
                                                $has_chat = false;
                                                while ($c = $chat_res->fetchArray(SQLITE3_ASSOC)):
                                                    $has_chat = true;
                                                ?>
                                                    <div style="padding:0.5rem; border-bottom:1px solid var(--border-color); font-size:0.8rem;">
                                                        <strong>User:</strong> <?php echo htmlspecialchars($c['message']); ?><br>
                                                        <strong>Bot:</strong> <?php echo htmlspecialchars($c['response']); ?><br>
                                                        <span style="font-size:0.7rem; color:var(--text-muted);">Lang: <?php echo htmlspecialchars($c['language_used']); ?> | <?php echo $c['timestamp']; ?></span>
                                                    </div>
                                                <?php endwhile; ?>
                                                <?php if (!$has_chat): ?><p style="color:var(--text-muted); text-align:center;">No conversational logs.</p><?php endif; ?>
                                            </div>
                                        </div>
                                    </div>
                                </div>
                            </div>
                        </div>
                    <?php endif; ?>

                <?php else: 
                    // PATIENT LISTING DIRECTORY
                    $sql = "SELECT u.id, u.name, u.email, u.status, p.birth_date, p.preferred_language 
                            FROM users u 
                            LEFT JOIN patients p ON u.id = p.patient_id 
                            WHERE u.role = 'Patient'";
                    if ($search_query !== '') {
                        $sql .= " AND (u.name LIKE '%$search_query%' OR u.email LIKE '%$search_query%')";
                    }
                    $sql .= " ORDER BY u.name ASC";
                    $pats_res = $db->query($sql);
                ?>
                    <div class="card">
                        <div class="card-header">
                            <h2><i class="fa-solid fa-id-card"></i> Patient Records Audit Directory</h2>
                        </div>
                        <div class="card-body">
                            <form action="admin_dashboard.php" method="GET" style="display:flex; gap:1rem; margin-bottom:1.5rem;">
                                <input type="hidden" name="tab" value="patients">
                                <input type="text" name="search" class="form-control" placeholder="Search by patient name or email..." value="<?php echo htmlspecialchars($search_query); ?>">
                                <button type="submit" class="btn"><i class="fa-solid fa-magnifying-glass"></i> Search</button>
                                <a href="?tab=patients" class="btn btn-secondary">Clear</a>
                            </form>

                            <div class="table-responsive">
                                <table class="data-table">
                                    <thead>
                                        <tr>
                                            <th>Patient ID</th>
                                            <th>Patient Name</th>
                                            <th>Email Address</th>
                                            <th>Birth Date</th>
                                            <th>Preferred Language</th>
                                            <th>Status</th>
                                            <th style="text-align:right;">Actions</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        <?php 
                                        $has_patients = false;
                                        while ($p = $pats_res->fetchArray(SQLITE3_ASSOC)):
                                            $has_patients = true;
                                        ?>
                                            <tr>
                                                <td><strong>#<?php echo $p['id']; ?></strong></td>
                                                <td><?php echo htmlspecialchars($p['name']); ?></td>
                                                <td><?php echo htmlspecialchars($p['email']); ?></td>
                                                <td><?php echo htmlspecialchars($p['birth_date'] ?? 'N/A'); ?></td>
                                                <td><?php echo htmlspecialchars($p['preferred_language'] ?? 'English'); ?></td>
                                                <td><span class="badge badge-<?php echo strtolower($p['status'] ?? 'Active'); ?>"><?php echo htmlspecialchars($p['status'] ?? 'Active'); ?></span></td>
                                                <td style="text-align:right;">
                                                    <div style="display:inline-flex; gap:0.25rem;">
                                                        <a href="?tab=patients&profile_id=<?php echo $p['id']; ?>" class="btn-sm btn-success"><i class="fa-solid fa-address-book"></i> Profile Audit</a>
                                                        
                                                        <?php if (($p['status'] ?? 'Active') === 'Active'): ?>
                                                            <form action="admin_dashboard.php?tab=patients" method="POST" style="display:inline-block; margin:0;" onsubmit="return confirm('Soft-archive this patient records?');">
                                                                <input type="hidden" name="action" value="archive_patient">
                                                                <input type="hidden" name="patient_id" value="<?php echo $p['id']; ?>">
                                                                <button type="submit" class="btn-sm btn-danger"><i class="fa-solid fa-box-archive"></i> Archive</button>
                                                            </form>
                                                        <?php else: ?>
                                                            <form action="admin_dashboard.php?tab=patients" method="POST" style="display:inline-block; margin:0;" onsubmit="return confirm('Restore this archived patient records?');">
                                                                <input type="hidden" name="action" value="restore_patient">
                                                                <input type="hidden" name="patient_id" value="<?php echo $p['id']; ?>">
                                                                <button type="submit" class="btn-sm btn-success" style="background-color:var(--secondary);"><i class="fa-solid fa-circle-check"></i> Restore</button>
                                                            </form>
                                                        <?php endif; ?>
                                                    </div>
                                                </td>
                                            </tr>
                                        <?php endwhile; ?>
                                        <?php if (!$has_patients): ?>
                                            <tr><td colspan="7" style="text-align:center; color:var(--text-muted);">No patient profiles found.</td></tr>
                                        <?php endif; ?>
                                    </tbody>
                                </table>
                            </div>
                        </div>
                    </div>
                <?php endif; ?>

            <!-- TAB 5: REPORTS & ANALYTICS -->
            <?php elseif ($tab === 'reports'): 
                $report_type = $_GET['report_type'] ?? '';
                $doctor_filter = isset($_GET['doctor_id']) && $_GET['doctor_id'] !== '' ? filter_var($_GET['doctor_id'], FILTER_VALIDATE_INT) : null;
                $patient_filter = isset($_GET['patient_id']) && $_GET['patient_id'] !== '' ? filter_var($_GET['patient_id'], FILTER_VALIDATE_INT) : null;
                $start_date = $_GET['start_date'] ?? '';
                $end_date = $_GET['end_date'] ?? '';

                $report_title = "Select operational template to preview data";
                $preview_rows = [];
                $has_report = false;

                if ($report_type !== '') {
                    $has_report = true;
                    // Log report generation audit
                    log_audit_action($admin_id, $admin_name, 'Generated Report Preview', 'Report: ' . $report_type);

                    if ($report_type === 'appointments') {
                        $report_title = "Patient Appointment Schedule Report";
                        $sql = "SELECT a.appointment_date as col1, u.name as col2, d.name as col3, a.time_slot as col4, a.status as col5 
                                FROM appointments a 
                                JOIN users u ON a.patient_id = u.id 
                                JOIN users d ON a.doctor_id = d.id 
                                WHERE 1=1";
                        if ($doctor_filter) $sql .= " AND a.doctor_id = $doctor_filter";
                        if ($patient_filter) $sql .= " AND a.patient_id = $patient_filter";
                        if ($start_date) $sql .= " AND a.appointment_date >= '$start_date'";
                        if ($end_date) $sql .= " AND a.appointment_date <= '$end_date'";
                        $sql .= " ORDER BY a.appointment_date ASC";
                        $res = $db->query($sql);
                        while ($r = $res->fetchArray(SQLITE3_ASSOC)) {
                            $preview_rows[] = [$r['col1'], $r['col2'], $r['col3'], $r['col4'], $r['col5']];
                        }
                    } elseif ($report_type === 'consultations') {
                        $report_title = "Daily Consultation Report";
                        $sql = "SELECT r.consultation_date as col1, u.name as col2, d.name as col3, r.diagnosis as col4, r.treatment as col5 
                                FROM medical_records r 
                                JOIN users u ON r.patient_id = u.id 
                                JOIN users d ON r.doctor_id = d.id 
                                WHERE 1=1";
                        if ($doctor_filter) $sql .= " AND r.doctor_id = $doctor_filter";
                        if ($patient_filter) $sql .= " AND r.patient_id = $patient_filter";
                        if ($start_date) $sql .= " AND r.consultation_date >= '$start_date'";
                        if ($end_date) $sql .= " AND r.consultation_date <= '$end_date'";
                        $sql .= " ORDER BY r.consultation_date DESC";
                        $res = $db->query($sql);
                        while ($r = $res->fetchArray(SQLITE3_ASSOC)) {
                            $preview_rows[] = [$r['col1'], $r['col2'], $r['col3'], $r['col4'], $r['col5']];
                        }
                    } elseif ($report_type === 'records_summary') {
                        $report_title = "Electronic Medical Records Summary";
                        $sql = "SELECT u.id as col1, u.name as col2, 
                                (SELECT COUNT(*) FROM appointments WHERE patient_id = u.id) as col3,
                                (SELECT COUNT(*) FROM medical_records WHERE patient_id = u.id) as col4,
                                'Active' as col5
                                FROM users u WHERE u.role = 'Patient'";
                        if ($patient_filter) $sql .= " AND u.id = $patient_filter";
                        $sql .= " ORDER BY u.name ASC";
                        $res = $db->query($sql);
                        while ($r = $res->fetchArray(SQLITE3_ASSOC)) {
                            $preview_rows[] = [$r['col1'], $r['col2'], $r['col3'], $r['col4'], $r['col5']];
                        }
                    } elseif ($report_type === 'queue_waiting') {
                        $report_title = "Priority Queue and Waiting Time Report";
                        $sql = "SELECT a.appointment_date as col1, u.name as col2, d.name as col3, a.queue_number as col4, a.status as col5 
                                FROM appointments a 
                                JOIN users u ON a.patient_id = u.id 
                                JOIN users d ON a.doctor_id = d.id 
                                WHERE a.status IN ('Scheduled', 'Checked-in')";
                        if ($doctor_filter) $sql .= " AND a.doctor_id = $doctor_filter";
                        if ($patient_filter) $sql .= " AND a.patient_id = $patient_filter";
                        if ($start_date) $sql .= " AND a.appointment_date >= '$start_date'";
                        if ($end_date) $sql .= " AND a.appointment_date <= '$end_date'";
                        $sql .= " ORDER BY a.appointment_date ASC, a.queue_number ASC";
                        $res = $db->query($sql);
                        while ($r = $res->fetchArray(SQLITE3_ASSOC)) {
                            $preview_rows[] = [$r['col1'], $r['col2'], $r['col3'], 'Q-' . $r['col4'], $r['col5']];
                        }
                    } elseif ($report_type === 'visit_history') {
                        $report_title = "Patient Visit History Report";
                        $sql = "SELECT u.name as col1, a.appointment_date as col2, d.name as col3, a.reason as col4, a.status as col5 
                                FROM appointments a 
                                JOIN users u ON a.patient_id = u.id 
                                JOIN users d ON a.doctor_id = d.id 
                                WHERE a.status = 'Completed'";
                        if ($doctor_filter) $sql .= " AND a.doctor_id = $doctor_filter";
                        if ($patient_filter) $sql .= " AND a.patient_id = $patient_filter";
                        if ($start_date) $sql .= " AND a.appointment_date >= '$start_date'";
                        if ($end_date) $sql .= " AND a.appointment_date <= '$end_date'";
                        $sql .= " ORDER BY a.appointment_date DESC";
                        $res = $db->query($sql);
                        while ($r = $res->fetchArray(SQLITE3_ASSOC)) {
                            $preview_rows[] = [$r['col1'], $r['col2'], $r['col3'], $r['col4'], $r['col5']];
                        }
                    } elseif ($report_type === 'doctor_workload') {
                        $report_title = "Doctor Performance and Workload Report";
                        $sql = "SELECT d.name as col1,
                                SUM(CASE WHEN a.status = 'Scheduled' THEN 1 ELSE 0 END) as col2,
                                SUM(CASE WHEN a.status = 'Completed' THEN 1 ELSE 0 END) as col3,
                                SUM(CASE WHEN a.status IN ('Cancelled', 'No-Show') THEN 1 ELSE 0 END) as col4,
                                '100%' as col5
                                FROM users d
                                LEFT JOIN appointments a ON d.id = a.doctor_id
                                WHERE d.role IN ('Doctor', 'Clinical Staff')";
                        if ($doctor_filter) $sql .= " AND d.id = $doctor_filter";
                        $sql .= " GROUP BY d.id ORDER BY d.name ASC";
                        $res = $db->query($sql);
                        while ($r = $res->fetchArray(SQLITE3_ASSOC)) {
                            $preview_rows[] = [$r['col1'], $r['col2'] . ' scheduled', $r['col3'] . ' completed', $r['col4'] . ' inactive', $r['col5']];
                        }
                    } elseif ($report_type === 'noshows') {
                        $report_title = "No-Show and Cancellation Report";
                        $sql = "SELECT a.appointment_date as col1, u.name as col2, d.name as col3, a.time_slot as col4, a.status as col5 
                                FROM appointments a 
                                JOIN users u ON a.patient_id = u.id 
                                JOIN users d ON a.doctor_id = d.id 
                                WHERE a.status IN ('Cancelled', 'No-Show')";
                        if ($doctor_filter) $sql .= " AND a.doctor_id = $doctor_filter";
                        if ($patient_filter) $sql .= " AND a.patient_id = $patient_filter";
                        if ($start_date) $sql .= " AND a.appointment_date >= '$start_date'";
                        if ($end_date) $sql .= " AND a.appointment_date <= '$end_date'";
                        $sql .= " ORDER BY a.appointment_date DESC";
                        $res = $db->query($sql);
                        while ($r = $res->fetchArray(SQLITE3_ASSOC)) {
                            $preview_rows[] = [$r['col1'], $r['col2'], $r['col3'], $r['col4'], $r['col5']];
                        }
                    } elseif ($report_type === 'billing') {
                        $report_title = "Billing and Payment Report";
                        $sql = "SELECT a.appointment_date as col1, u.name as col2, d.name as col3, 'Ã¢â€šÂ±500.00' as col4, 'Paid' as col5 
                                FROM appointments a 
                                JOIN users u ON a.patient_id = u.id 
                                JOIN users d ON a.doctor_id = d.id 
                                WHERE a.status = 'Completed'";
                        if ($doctor_filter) $sql .= " AND a.doctor_id = $doctor_filter";
                        if ($patient_filter) $sql .= " AND a.patient_id = $patient_filter";
                        if ($start_date) $sql .= " AND a.appointment_date >= '$start_date'";
                        if ($end_date) $sql .= " AND a.appointment_date <= '$end_date'";
                        $sql .= " ORDER BY a.appointment_date DESC";
                        $res = $db->query($sql);
                        while ($r = $res->fetchArray(SQLITE3_ASSOC)) {
                            $preview_rows[] = [$r['col1'], $r['col2'], $r['col3'], $r['col4'], $r['col5']];
                        }
                    } elseif ($report_type === 'high_risk') {
                        $report_title = "High-Risk Patient Monitoring Report";
                        $sql = "SELECT s.created_at as col1, u.name as col2, s.symptoms_entered as col3, s.predicted_condition as col4, (s.probability_score * 100) || '%' as col5 
                                FROM symptoms s 
                                JOIN users u ON s.patient_id = u.id 
                                WHERE s.probability_score >= 0.80";
                        if ($patient_filter) $sql .= " AND s.patient_id = $patient_filter";
                        if ($start_date) $sql .= " AND s.created_at >= '$start_date'";
                        if ($end_date) $sql .= " AND s.created_at <= '$end_date'";
                        $sql .= " ORDER BY s.created_at DESC";
                        $res = $db->query($sql);
                        while ($r = $res->fetchArray(SQLITE3_ASSOC)) {
                            $preview_rows[] = [$r['col1'], $r['col2'], $r['col3'], $r['col4'], $r['col5']];
                        }
                    } elseif ($report_type === 'disease_trends') {
                        $report_title = "Disease Classification Report (Naive Bayes)";
                        $sql = "SELECT predicted_condition as col1, COUNT(*) as col2, ROUND(AVG(probability_score) * 100, 1) || '%' as col3, 'N/A' as col4, 'Active' as col5 
                                FROM symptoms 
                                GROUP BY predicted_condition ORDER BY col2 DESC";
                        $res = $db->query($sql);
                        while ($r = $res->fetchArray(SQLITE3_ASSOC)) {
                            $preview_rows[] = [$r['col1'], $r['col2'] . ' cases diagnosed', $r['col3'] . ' avg confidence', 'N/A', 'Active'];
                        }
                    }
                }
            ?>
                <div class="card">
                    <div class="card-header">
                        <h2><i class="fa-solid fa-file-invoice-dollar"></i> Reports Generator</h2>
                    </div>
                    <div class="card-body">
                        <!-- Cross Filters -->
                        <form action="admin_dashboard.php" method="GET" class="report-param-bar">
                            <input type="hidden" name="tab" value="reports">
                            
                            <div class="form-group" style="width:220px;">
                                <label for="rep_type">Report Template</label>
                                <select id="rep_type" name="report_type" class="form-control" required>
                                    <option value="" disabled <?php echo $report_type === '' ? 'selected' : ''; ?>>-- Select Report --</option>
                                    <option value="appointments" <?php echo $report_type === 'appointments' ? 'selected' : ''; ?>>Patient Appointment Schedule</option>
                                    <option value="consultations" <?php echo $report_type === 'consultations' ? 'selected' : ''; ?>>Daily Consultation</option>
                                    <option value="records_summary" <?php echo $report_type === 'records_summary' ? 'selected' : ''; ?>>Electronic Medical Records Summary</option>
                                    <option value="queue_waiting" <?php echo $report_type === 'queue_waiting' ? 'selected' : ''; ?>>Priority Queue & Waiting Time</option>
                                    <option value="disease_trends" <?php echo $report_type === 'disease_trends' ? 'selected' : ''; ?>>Disease Classification (Naive Bayes)</option>
                                    <option value="visit_history" <?php echo $report_type === 'visit_history' ? 'selected' : ''; ?>>Patient Visit History</option>
                                    <option value="doctor_workload" <?php echo $report_type === 'doctor_workload' ? 'selected' : ''; ?>>Doctor Workload & Performance</option>
                                    <option value="noshows" <?php echo $report_type === 'noshows' ? 'selected' : ''; ?>>No-Show & Cancellation</option>
                                    <option value="billing" <?php echo $report_type === 'billing' ? 'selected' : ''; ?>>Billing & Payment</option>
                                    <option value="high_risk" <?php echo $report_type === 'high_risk' ? 'selected' : ''; ?>>High-Risk Patient Monitoring</option>
                                </select>
                            </div>

                            <div class="form-group" style="width:160px;">
                                <label for="rep_doc">Doctor Filter</label>
                                <select id="rep_doc" name="doctor_id" class="form-control">
                                    <option value="">All Doctors</option>
                                    <?php foreach ($doctors_filter_list as $df): ?>
                                        <option value="<?php echo $df['id']; ?>" <?php echo $doctor_filter === $df['id'] ? 'selected' : ''; ?>><?php echo htmlspecialchars($df['name']); ?></option>
                                    <?php endforeach; ?>
                                </select>
                            </div>

                            <div class="form-group" style="width:160px;">
                                <label for="rep_pat">Patient Filter</label>
                                <select id="rep_pat" name="patient_id" class="form-control">
                                    <option value="">All Patients</option>
                                    <?php foreach ($patients_filter_list as $pf): ?>
                                        <option value="<?php echo $pf['id']; ?>" <?php echo $patient_filter === $pf['id'] ? 'selected' : ''; ?>><?php echo htmlspecialchars($pf['name']); ?></option>
                                    <?php endforeach; ?>
                                </select>
                            </div>

                            <div class="form-group" style="width:140px;">
                                <label for="rep_start">Start Date</label>
                                <input type="date" id="rep_start" name="start_date" class="form-control" value="<?php echo htmlspecialchars($start_date); ?>">
                            </div>

                            <div class="form-group" style="width:140px;">
                                <label for="rep_end">End Date</label>
                                <input type="date" id="rep_end" name="end_date" class="form-control" value="<?php echo htmlspecialchars($end_date); ?>">
                            </div>

                            <button type="submit" class="btn"><i class="fa-solid fa-magnifying-glass"></i> Preview</button>
                            <a href="?tab=reports" class="btn btn-secondary">Reset</a>
                        </form>

                        <?php if ($has_report): ?>
                            <div style="display:flex; justify-content:space-between; align-items:center; margin-bottom:1.5rem;">
                                <h3 style="color:var(--primary); font-size:1.1rem;"><i class="fa-solid fa-file-invoice"></i> <?php echo htmlspecialchars($report_title); ?></h3>
                                <div class="export-btn-group">
                                    <a href="?action=export_csv&report_type=<?php echo urlencode($report_type); ?>&doctor_id=<?php echo urlencode($doctor_filter); ?>&patient_id=<?php echo urlencode($patient_filter); ?>&start_date=<?php echo urlencode($start_date); ?>&end_date=<?php echo urlencode($end_date); ?>" class="btn btn-primary">
                                        <i class="fa-solid fa-file-csv"></i> Download CSV
                                    </a>
                                    <button onclick="window.print();" class="btn btn-secondary"><i class="fa-solid fa-print"></i> Print PDF</button>
                                </div>
                            </div>

                            <!-- Printable report view -->
                            <div id="printable-report-area" class="table-responsive">
                                <div class="print-header">
                                    <h1 class="print-title"><i class="fa-solid fa-house-medical"></i> CLINICK MEDICAL CENTER</h1>
                                    <p class="print-subtitle">Operational Audit & System Reports Portal</p>
                                    <hr class="print-divider">
                                    <h2 class="print-report-title"><?php echo htmlspecialchars($report_title); ?></h2>
                                    <p class="print-report-period">Report Period: <?php echo $start_date ?: 'Inception'; ?> to <?php echo $end_date ?: date('Y-m-d'); ?></p>
                                </div>

                                <table class="data-table">
                                    <thead>
                                        <tr>
                                            <th>Metric / Dimension 1</th>
                                            <th>Dimension 2</th>
                                            <th>Dimension 3</th>
                                            <th>Dimension 4</th>
                                            <th>Dimension 5</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        <?php if (empty($preview_rows)): ?>
                                            <tr><td colspan="5" class="table-empty">No records found in range parameters.</td></tr>
                                        <?php else: ?>
                                            <?php foreach ($preview_rows as $row): ?>
                                                <tr>
                                                    <td><?php echo htmlspecialchars($row[0] ?? ''); ?></td>
                                                    <td><?php echo htmlspecialchars($row[1] ?? ''); ?></td>
                                                    <td><?php echo htmlspecialchars($row[2] ?? ''); ?></td>
                                                    <td><?php echo htmlspecialchars($row[3] ?? ''); ?></td>
                                                    <td><?php echo htmlspecialchars($row[4] ?? ''); ?></td>
                                                </tr>
                                            <?php endforeach; ?>
                                        <?php endif; ?>
                                    </tbody>
                                </table>
                            </div>
                        <?php else: ?>
                            <p style="text-align:center; color:var(--text-muted); padding:4rem 0;">Please choose a report template and filter options to preview records.</p>
                        <?php endif; ?>
                    </div>
                </div>

            <!-- TAB 6: SYSTEM MONITORING & AUDIT LOG -->
            <?php elseif ($tab === 'monitoring'): 
                $audit_search = trim($_GET['audit_search'] ?? '');
                $action_filter = trim($_GET['action_filter'] ?? '');

                // Flag suspicious login attempts (3 or more failed logins from same email/IP in audit log)
                $suspicious_sql = "SELECT username, COUNT(*) as failed_attempts, ip_address 
                                   FROM audit_logs 
                                   WHERE action = 'Failed login attempt' 
                                   GROUP BY username, ip_address 
                                   HAVING failed_attempts >= 3";
                $susp_res = $db->query($suspicious_sql);

                // Fetch general audit logs
                $sql = "SELECT * FROM audit_logs WHERE 1=1";
                if ($audit_search !== '') {
                    $sql .= " AND (username LIKE '%$audit_search%' OR action LIKE '%$audit_search%' OR affected_record LIKE '%$audit_search%')";
                }
                if ($action_filter !== '') {
                    $sql .= " AND action = '$action_filter'";
                }
                $sql .= " ORDER BY id DESC LIMIT 100";
                $audits_res = $db->query($sql);

                // Chatbot review query
                $chats_review_res = $db->query("SELECT cl.*, u.name as patient_name FROM chatbot_logs cl JOIN users u ON cl.patient_id = u.id ORDER BY cl.log_id DESC LIMIT 50");
            ?>
                
                <!-- Security Warning Banner -->
                <?php 
                $has_susp = false;
                while ($susp = $susp_res->fetchArray(SQLITE3_ASSOC)):
                    $has_susp = true;
                ?>
                    <div class="alert alert-danger">
                        <i class="fa-solid fa-shield-halved"></i>
                        <span><strong>SUSPICIOUS LOGIN ACTIVITY FLAGGED:</strong> Email <strong><?php echo htmlspecialchars($susp['username']); ?></strong> registered <?php echo $susp['failed_attempts']; ?> failed login attempts from IP <?php echo $susp['ip_address']; ?>.</span>
                    </div>
                <?php endwhile; ?>

                <div class="dashboard-block-grid grid-split-3-2">
                    <!-- General Audit Log -->
                    <div class="card">
                        <div class="card-header">
                            <h2><i class="fa-solid fa-list-check"></i> System Audit Log</h2>
                        </div>
                        <div class="card-body">
                            <form action="admin_dashboard.php" method="GET" class="report-param-bar">
                                <input type="hidden" name="tab" value="monitoring">
                                <div class="form-group flex-fill">
                                    <input type="text" name="audit_search" class="form-control" placeholder="Search logs..." value="<?php echo htmlspecialchars($audit_search); ?>">
                                </div>
                                <div class="form-group width-160">
                                    <select name="action_filter" class="form-control">
                                        <option value="">All Actions</option>
                                        <option value="Successful login" <?php echo $action_filter === 'Successful login' ? 'selected' : ''; ?>>Successful login</option>
                                        <option value="Failed login attempt" <?php echo $action_filter === 'Failed login attempt' ? 'selected' : ''; ?>>Failed login attempt</option>
                                        <option value="Viewed Patient Record Details (Audit Access)" <?php echo $action_filter === 'Viewed Patient Record Details (Audit Access)' ? 'selected' : ''; ?>>Record View</option>
                                        <option value="Created User Account" <?php echo $action_filter === 'Created User Account' ? 'selected' : ''; ?>>Create User</option>
                                        <option value="Modified User Details" <?php echo $action_filter === 'Modified User Details' ? 'selected' : ''; ?>>Modify User</option>
                                    </select>
                                </div>
                                <button type="submit" class="btn btn-primary"><i class="fa-solid fa-filter"></i> Search</button>
                                <a href="?tab=monitoring" class="btn btn-secondary">Clear</a>
                            </form>

                            <div class="table-responsive scrollable-y">
                                <table class="data-table">
                                    <thead>
                                        <tr>
                                            <th>Timestamp</th>
                                            <th>Operator</th>
                                            <th>Action Event</th>
                                            <th>IP Address</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        <?php 
                                        $has_logs = false;
                                        while ($a = $audits_res->fetchArray(SQLITE3_ASSOC)):
                                            $has_logs = true;
                                        ?>
                                            <tr>
                                                <td><span class="text-xs text-muted"><?php echo $a['timestamp']; ?></span></td>
                                                <td><strong><?php echo htmlspecialchars($a['username'] ?: 'System Guest'); ?></strong><br><span class="text-xxs text-muted">UID: #<?php echo $a['user_id'] ?: 'None'; ?></span></td>
                                                <td>
                                                    <span class="text-primary font-semibold"><?php echo htmlspecialchars($a['action']); ?></span>
                                                    <?php if ($a['affected_record']): ?>
                                                        <div class="audit-view-details"><?php echo htmlspecialchars($a['affected_record']); ?></div>
                                                    <?php endif; ?>
                                                </td>
                                                <td><code><?php echo htmlspecialchars($a['ip_address']); ?></code></td>
                                            </tr>
                                        <?php endwhile; ?>
                                        <?php if (!$has_logs): ?>
                                            <tr><td colspan="4" class="table-empty">No log records found.</td></tr>
                                        <?php endif; ?>
                                    </tbody>
                                  </table>
                             </div>
                        </div>
                    </div>

                    <!-- Chatbot logs audit & reviews -->
                    <div class="card">
                        <div class="card-header">
                            <h2><i class="fa-solid fa-comments"></i> Chatbot Dialog Quality Control</h2>
                        </div>
                        <div class="card-body scrollable-y no-padding">
                            <?php 
                            $has_chats = false;
                            while ($chat = $chats_review_res->fetchArray(SQLITE3_ASSOC)):
                                $has_chats = true;
                            ?>
                                <div class="chat-log-item <?php echo $chat['is_flagged'] ? 'flagged' : ''; ?>">
                                    <div class="chat-log-header">
                                        <strong>Patient: <?php echo htmlspecialchars($chat['patient_name']); ?></strong>
                                        <span class="text-muted"><?php echo $chat['timestamp']; ?></span>
                                    </div>
                                    <div class="chat-log-msg query">
                                        <span class="text-primary font-bold">Q:</span> "<?php echo htmlspecialchars($chat['message']); ?>"
                                    </div>
                                    <div class="chat-log-msg response">
                                        <span class="text-secondary font-bold">A:</span> <?php echo htmlspecialchars($chat['response']); ?>
                                    </div>
                                    <div class="chat-log-footer">
                                        <span class="chat-log-lang">
                                            Lang: <?php echo htmlspecialchars($chat['language_used']); ?>
                                        </span>
                                        <form action="admin_dashboard.php?tab=monitoring" method="POST" class="inline-form">
                                            <input type="hidden" name="action" value="toggle_flag_chatbot">
                                            <input type="hidden" name="log_id" value="<?php echo $chat['log_id']; ?>">
                                            <?php if ($chat['is_flagged']): ?>
                                                <input type="hidden" name="is_flagged" value="0">
                                                <button type="submit" class="btn btn-success btn-xs"><i class="fa-solid fa-flag-check"></i> Unflag Dialogue</button>
                                            <?php else: ?>
                                                <input type="hidden" name="is_flagged" value="1">
                                                <button type="submit" class="btn btn-outline-danger btn-xs"><i class="fa-solid fa-flag"></i> Flag as Unclear</button>
                                            <?php endif; ?>
                                        </form>
                                    </div>
                                </div>
                            <?php endwhile; ?>
                            <?php if (!$has_chats): ?>
                                <p class="no-logs-msg">No logs available.</p>
                            <?php endif; ?>dif; ?>
                        </div>
                    </div>
                </div>

            <!-- TAB 7: SYSTEM CONFIGURATION -->
            <?php elseif ($tab === 'settings'): 
                $languages_val = $db->querySingle("SELECT value FROM system_settings WHERE key = 'supported_languages'") ?? '';
                $statuses_val = $db->querySingle("SELECT value FROM system_settings WHERE key = 'appointment_statuses'") ?? '';
                $symptoms_val = $db->querySingle("SELECT value FROM system_settings WHERE key = 'symptom_categories'") ?? '';
                $iso_eval_val = $db->querySingle("SELECT value FROM system_settings WHERE key = 'iso_evaluation_settings'") ?? '';
            ?>
                <div class="card">
                    <div class="card-header">
                        <h2><i class="fa-solid fa-sliders"></i> Global System Settings</h2>
                    </div>
                    <div class="card-body">
                        <form action="admin_dashboard.php?tab=settings" method="POST" onsubmit="return confirm('Apply these system configuration settings globally?');">
                            <input type="hidden" name="action" value="update_system_config">

                             <div class="config-card">
                                 <div class="config-section-title">Chatbot Languages / Dialects</div>
                                 <p class="config-section-desc">Comma separated list of supported languages available in the chatbot dropdown.</p>
                                 <input type="text" name="languages" class="form-control" value="<?php echo htmlspecialchars($languages_val); ?>" required>
                             </div>

                             <div class="config-card">
                                 <div class="config-section-title">System Appointment Statuses Reference</div>
                                 <p class="config-section-desc">Comma separated reference data categories used to validate booking transitions.</p>
                                 <input type="text" name="statuses" class="form-control" value="<?php echo htmlspecialchars($statuses_val); ?>" required>
                             </div>

                             <div class="config-card">
                                 <div class="config-section-title">Naive Bayes Symptom Classifications</div>
                                 <p class="config-section-desc">Comma separated lists of symptoms patient users can check off in the diagnostic tab.</p>
                                 <input type="text" name="symptoms" class="form-control" value="<?php echo htmlspecialchars($symptoms_val); ?>" required>
                             </div>

                             <div class="config-card">
                                 <div class="config-section-title">ISO/IEC 25010 Quality Metrics Placeholder</div>
                                 <p class="config-section-desc">JSON Configuration string mapping evaluation coefficients for future system studies.</p>
                                 <textarea name="iso_eval" class="form-control code-editor" required><?php echo htmlspecialchars($iso_eval_val); ?></textarea>
                             </div>

                             <button type="submit" class="btn btn-primary"><i class="fa-solid fa-save"></i> Save Settings Configuration</button>
                        </form>
                    </div>
                </div>
            <?php endif; ?>

        </main>
    </div>

    <script>
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
        // Init icon on load
        const savedTheme = localStorage.getItem('clinick-theme') || 'light';
        if (savedTheme === 'dark') document.documentElement.setAttribute('data-theme', 'dark');
        icon.className = savedTheme === 'dark' ? 'fa-solid fa-moon' : 'fa-solid fa-sun';
    </script>
<?php include 'chatbot-widget.php'; ?>
</body>
</html>
