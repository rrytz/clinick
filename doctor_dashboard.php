<?php
require_once __DIR__ . '/db.php';

// Auth Guard: Only Doctor, Staff, and Clinical Staff allowed
check_auth(['Doctor', 'Staff', 'Clinical Staff']);

$db = get_db_connection();
$doctor_id = $_SESSION['user_id'];
$doctor_name = $_SESSION['user_name'];
$success_msg = "";
$error_msg = "";

// 1. Handle Appointment Status Changes (Complete / Cancel)
if ($_SERVER['REQUEST_METHOD'] === 'GET' && isset($_GET['action']) && isset($_GET['id'])) {
    $appt_id = filter_var($_GET['id'], FILTER_VALIDATE_INT);
    $status_action = $_GET['action'];

    if ($appt_id && in_array($status_action, ['complete', 'cancel'])) {
        $new_status = ($status_action === 'complete') ? 'Completed' : 'Cancelled';
        $stmt = $db->prepare("UPDATE appointments SET status = :status WHERE id = :id AND doctor_id = :doctor_id");
        $stmt->bindValue(':status', $new_status, SQLITE3_TEXT);
        $stmt->bindValue(':id', $appt_id, SQLITE3_INTEGER);
        $stmt->bindValue(':doctor_id', $doctor_id, SQLITE3_INTEGER);
        
        if ($stmt->execute()) {
            $success_msg = "Appointment status updated to " . $new_status . ".";
        } else {
            $error_msg = "Failed to update appointment status.";
        }
    }
}

// Handle Appointment Rescheduling by Doctor / Staff
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'reschedule_appointment') {
    $appt_id = filter_var($_POST['appointment_id'] ?? null, FILTER_VALIDATE_INT);
    $new_date = trim($_POST['reschedule_date'] ?? '');
    $new_slot = trim($_POST['reschedule_slot'] ?? '');

    if (!$appt_id || empty($new_date) || empty($new_slot)) {
        $error_msg = "Please fill in all rescheduling fields.";
    } else {
        // Find current appointment's doctor
        $stmt_check = $db->prepare("SELECT doctor_id FROM appointments WHERE id = :id AND doctor_id = :doctor_id");
        $stmt_check->bindValue(':id', $appt_id, SQLITE3_INTEGER);
        $stmt_check->bindValue(':doctor_id', $doctor_id, SQLITE3_INTEGER);
        $chk_res = $stmt_check->execute()->fetchArray(SQLITE3_ASSOC);
        
        if (!$chk_res) {
            $error_msg = "Invalid appointment details.";
        } else {
            $appt_doctor_id = $chk_res['doctor_id'];
            
            // Calculate next queue number for new date
            $stmt_q = $db->prepare("SELECT COUNT(*) as count FROM appointments WHERE doctor_id = :doctor_id AND appointment_date = :appointment_date");
            $stmt_q->bindValue(':doctor_id', $appt_doctor_id, SQLITE3_INTEGER);
            $stmt_q->bindValue(':appointment_date', $new_date, SQLITE3_TEXT);
            $q_res = $stmt_q->execute()->fetchArray(SQLITE3_ASSOC);
            $queue_number = ($q_res['count'] ?? 0) + 1;

            // Update appointment
            $stmt_up = $db->prepare("UPDATE appointments SET appointment_date = :appointment_date, time_slot = :time_slot, queue_number = :queue_number, status = 'Scheduled' WHERE id = :id");
            $stmt_up->bindValue(':appointment_date', $new_date, SQLITE3_TEXT);
            $stmt_up->bindValue(':time_slot', $new_slot, SQLITE3_TEXT);
            $stmt_up->bindValue(':queue_number', $queue_number, SQLITE3_INTEGER);
            $stmt_up->bindValue(':id', $appt_id, SQLITE3_INTEGER);

            if ($stmt_up->execute()) {
                $success_msg = "Appointment rescheduled successfully! New Queue Number: Q-" . $queue_number . ".";
                
                // Clear existing reminders
                $stmt_del = $db->prepare("DELETE FROM reminders WHERE appointment_id = :appointment_id");
                $stmt_del->bindValue(':appointment_id', $appt_id, SQLITE3_INTEGER);
                $stmt_del->execute();
            } else {
                $error_msg = "Failed to reschedule appointment.";
            }
        }
    }
}

// 2. Handle Prescription Writing Submission
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'prescribe') {
    $patient_id = filter_var($_POST['patient_id'] ?? null, FILTER_VALIDATE_INT);
    $medication = trim($_POST['medication'] ?? '');
    $dosage = trim($_POST['dosage'] ?? '');
    $frequency = trim($_POST['frequency'] ?? '');

    if (!$patient_id || empty($medication) || empty($dosage) || empty($frequency)) {
        $error_msg = "Please fill in all prescription fields.";
    } else {
        // RBAC: Verify doctor has at least one appointment with this patient
        $stmtAssign = $db->prepare("SELECT COUNT(*) as cnt FROM appointments WHERE doctor_id = :doctor_id AND patient_id = :patient_id");
        $stmtAssign->bindValue(':doctor_id', $doctor_id, SQLITE3_INTEGER);
        $stmtAssign->bindValue(':patient_id', $patient_id, SQLITE3_INTEGER);
        $assignRes = $stmtAssign->execute()->fetchArray(SQLITE3_ASSOC);
        if (($assignRes['cnt'] ?? 0) == 0) {
            $error_msg = "Cannot prescribe to a patient not assigned to you.";
        } else {
        $stmt = $db->prepare("INSERT INTO prescriptions (patient_id, doctor_id, doctor_name, medication, dosage, frequency) VALUES (:patient_id, :doctor_id, :doctor_name, :medication, :dosage, :frequency)");
        $stmt->bindValue(':patient_id', $patient_id, SQLITE3_INTEGER);
        $stmt->bindValue(':doctor_id', $doctor_id, SQLITE3_INTEGER);
        $stmt->bindValue(':doctor_name', $doctor_name, SQLITE3_TEXT);
        $stmt->bindValue(':medication', $medication, SQLITE3_TEXT);
        $stmt->bindValue(':dosage', $dosage, SQLITE3_TEXT);
        $stmt->bindValue(':frequency', $frequency, SQLITE3_TEXT);

        if ($stmt->execute()) {
            $success_msg = "Prescription written and saved successfully.";
        } else {
            $error_msg = "Failed to record prescription.";
        }
        } // end assignment check else
    }
}

// 3. Handle Availability Save/Delete
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'save_availability') {
    $avail_date = trim($_POST['available_date'] ?? '');
    $status = trim($_POST['status'] ?? 'Available');
    $notes = trim($_POST['notes'] ?? '');

    if (!empty($avail_date)) {
        $stmt = $db->prepare("INSERT OR REPLACE INTO availability (doctor_id, available_date, status, notes) VALUES (:doctor_id, :available_date, :status, :notes)");
        $stmt->bindValue(':doctor_id', $doctor_id, SQLITE3_INTEGER);
        $stmt->bindValue(':available_date', $avail_date, SQLITE3_TEXT);
        $stmt->bindValue(':status', $status, SQLITE3_TEXT);
        $stmt->bindValue(':notes', $notes, SQLITE3_TEXT);

        if ($stmt->execute()) {
            $success_msg = "Availability set for " . htmlspecialchars($avail_date) . ".";
        } else {
            $error_msg = "Failed to save availability.";
        }
    } else {
        $error_msg = "Invalid date provided.";
    }
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'delete_availability') {
    $avail_date = trim($_POST['available_date'] ?? '');

    if (!empty($avail_date)) {
        $stmt = $db->prepare("DELETE FROM availability WHERE doctor_id = :doctor_id AND available_date = :available_date");
        $stmt->bindValue(':doctor_id', $doctor_id, SQLITE3_INTEGER);
        $stmt->bindValue(':available_date', $avail_date, SQLITE3_TEXT);

        if ($stmt->execute()) {
            $success_msg = "Availability removed for " . htmlspecialchars($avail_date) . ".";
        } else {
            $error_msg = "Failed to clear availability.";
        }
    } else {
        $error_msg = "Invalid date provided.";
    }
}


// Fetch tab parameter
$tab = $_GET['tab'] ?? 'overview';

// Fetch assigned patients for forms (only patients with at least one appointment with this doctor)
$stmt_assigned_patients = $db->prepare("
    SELECT DISTINCT u.id, u.name, u.email
    FROM users u
    JOIN appointments a ON u.id = a.patient_id
    WHERE u.role = 'Patient' AND a.doctor_id = :doctor_id
    ORDER BY u.name ASC
");
$stmt_assigned_patients->bindValue(':doctor_id', $doctor_id, SQLITE3_INTEGER);
$patients_res = $stmt_assigned_patients->execute();
$patients = [];
while ($row = $patients_res->fetchArray(SQLITE3_ASSOC)) {
    $patients[] = $row;
}

// Calculations for Stats
// A. Today's Date
$today_str = date('Y-m-d');

// B. Today's Appointments Count
$stmt_today = $db->prepare("SELECT COUNT(*) as count FROM appointments WHERE appointment_date = :today_date AND status = 'Scheduled'");
$stmt_today->bindValue(':today_date', $today_str, SQLITE3_TEXT);
$today_count = $stmt_today->execute()->fetchArray(SQLITE3_ASSOC)['count'] ?? 0;

// C. Total Registered Patients Count
$patient_count = $db->querySingle("SELECT COUNT(*) FROM users WHERE role = 'Patient'") ?? 0;

// D. Total Prescriptions Prescribed
$presc_count = $db->querySingle("SELECT COUNT(*) FROM prescriptions") ?? 0;

// Trend calculations
$yesterday_str = date('Y-m-d', strtotime('-1 day'));
$stmt_yest = $db->prepare("SELECT COUNT(*) as count FROM appointments WHERE appointment_date = :yesterday AND status = 'Scheduled'");
$stmt_yest->bindValue(':yesterday', $yesterday_str, SQLITE3_TEXT);
$yesterday_count = $stmt_yest->execute()->fetchArray(SQLITE3_ASSOC)['count'] ?? 0;

// Patient trend (last 7 vs prior 7)
$recent_patients = $db->querySingle("SELECT COUNT(*) FROM users WHERE role = 'Patient' AND created_at >= date('now', '-7 days')") ?? 0;
$prior_patients = $db->querySingle("SELECT COUNT(*) FROM users WHERE role = 'Patient' AND created_at >= date('now', '-14 days') AND created_at < date('now', '-7 days')") ?? 0;

// Prescriptions trend (last 7 vs prior 7)
$recent_presc = $db->querySingle("SELECT COUNT(*) FROM prescriptions WHERE created_at >= date('now', '-7 days')") ?? 0;
$prior_presc = $db->querySingle("SELECT COUNT(*) FROM prescriptions WHERE created_at >= date('now', '-14 days') AND created_at < date('now', '-7 days')") ?? 0;

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
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>CLINICK - Clinical Dashboard</title>
    <!-- Google Fonts: Inter -->
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link rel="stylesheet" href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&display=swap">
    <!-- FontAwesome CDN -->
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <!-- Dashboard Styling -->
    <link rel="stylesheet" href="dashboard.css?v=<?php echo filemtime('dashboard.css'); ?>">
    <script src="js/theme-controller.js?v=<?php echo filemtime('js/theme-controller.js'); ?>"></script>
</head>
<body>

    <!-- Emergency Screening Banner -->
    <div class="emr-emergency-bar">
        Emergency Screening System Active &mdash; Protocol 4-A
    </div>

    <div class="dashboard-container">

        <!-- EMR Top Navigation -->
        <header class="emr-top-nav">
            <a href="?tab=overview" class="emr-brand">CLINICK.</a>

            <ul class="emr-nav-tabs">
                <li><a href="?tab=overview" class="emr-nav-link <?php echo $tab === 'overview' ? 'active' : ''; ?>">Overview</a></li>
                <li><a href="?tab=appointments" class="emr-nav-link <?php echo $tab === 'appointments' ? 'active' : ''; ?>">Schedule</a></li>
                <li><a href="?tab=prescribe" class="emr-nav-link <?php echo $tab === 'prescribe' ? 'active' : ''; ?>">Prescription</a></li>
                <li><a href="?tab=patients" class="emr-nav-link <?php echo $tab === 'patients' ? 'active' : ''; ?>">Patients</a></li>
                <li><a href="?tab=availability" class="emr-nav-link <?php echo $tab === 'availability' ? 'active' : ''; ?>">Availability</a></li>
            </ul>

            <div class="emr-nav-actions">
                <span class="emr-nav-bell" title="Notifications"><i class="fa-regular fa-bell"></i></span>
                <button class="emr-theme-toggle" id="theme-toggle" title="Toggle dark mode">
                    <i class="fa-solid fa-circle-half-stroke"></i>
                </button>
                <span class="emr-nav-username"><?php
                    $dname = htmlspecialchars($_SESSION['user_name']);
                    echo (stripos($dname, 'Dr.') === 0) ? strtoupper($dname) : 'DR. ' . strtoupper($dname);
                ?></span>
                <a href="index.php?logout=true" class="emr-nav-logout" title="Sign Out">
                    <i class="fa-solid fa-right-from-bracket"></i>
                </a>
            </div>
        </header>

        <!-- EMR Main Content -->
        <main class="emr-main-content">

            <!-- EMR Hero Section -->
            <?php
            $tab_heroes = [
                'overview'     => ['Enhancing clinical precision through data-driven workflows.', 'Clinic overview for ' . date('F j, Y') . '. All staff members are currently on duty.'],
                'appointments' => ['Patient schedule & consultation queue.', 'Manage appointments, reschedule visits, and update consultation status.'],
                'prescribe'    => ['Write and manage patient prescriptions.', 'Issue medication orders and review your prescription history.'],
                'patients'     => ['Registered patient index.', 'View all patients assigned to your care and their clinical profiles.'],
                'availability' => ['Work availability & scheduling.', 'Set your available dates and manage your clinical calendar.'],
            ];
            $hero_data = $tab_heroes[$tab] ?? ['Clinical Dashboard', date('l, F j, Y')];
            ?>
            <div class="emr-hero">
                <h1 class="emr-hero-title"><?php echo htmlspecialchars($hero_data[0]); ?></h1>
                <p class="emr-hero-subtitle"><?php echo htmlspecialchars($hero_data[1]); ?></p>
            </div>

            <?php if (!empty($success_msg)): ?>
                <div class="emr-alert emr-alert-success">
                    <i class="fa-solid fa-circle-check"></i>
                    <span><?php echo htmlspecialchars($success_msg); ?></span>
                </div>
            <?php endif; ?>

            <?php if (!empty($error_msg)): ?>
                <div class="emr-alert emr-alert-danger">
                    <i class="fa-solid fa-triangle-exclamation"></i>
                    <span><?php echo htmlspecialchars($error_msg); ?></span>
                </div>
            <?php endif; ?>

            <!-- TAB 1: OVERVIEW — EMR 2.0 Layout -->
            <?php if ($tab === 'overview'): ?>
                <?php
                $staff_on_duty = (int)($db->querySingle("SELECT COUNT(*) FROM users WHERE role IN ('Staff', 'Clinical Staff')") ?? 4);
                $today_appts_res = $db->query("SELECT a.*, u.name as patient_name FROM appointments a JOIN users u ON a.patient_id = u.id ORDER BY a.appointment_date ASC, a.time_slot ASC LIMIT 8");
                $rec_presc = $db->query("SELECT p.*, u.name as patient_name FROM prescriptions p JOIN users u ON p.patient_id = u.id ORDER BY p.id DESC LIMIT 4");
                ?>
                <div class="emr-content-grid">

                    <!-- Left Column: Table + Records -->
                    <div>
                        <!-- Today's Appointments Table -->
                        <div class="emr-section-label">Today's Appointments</div>
                        <table class="emr-table">
                            <thead>
                                <tr>
                                    <th>Time</th>
                                    <th>Patient</th>
                                    <th>Condition / Reason</th>
                                    <th>Status</th>
                                    <th>Actions</th>
                                </tr>
                            </thead>
                            <tbody>
                            <?php
                            $has_today_appts = false;
                            while ($app = $today_appts_res->fetchArray(SQLITE3_ASSOC)):
                                $has_today_appts = true;
                                $status = $app['status'] ?? 'Scheduled';
                                $badgeClass = 'emr-badge-scheduled';
                                if ($status === 'In Progress') $badgeClass = 'emr-badge-inprogress';
                                elseif ($status === 'Completed') $badgeClass = 'emr-badge-completed';
                                elseif ($status === 'Cancelled') $badgeClass = 'emr-badge-cancelled';
                                elseif ($status === 'No-Show') $badgeClass = 'emr-badge-noshow';
                            ?>
                                <tr>
                                    <td class="emr-time-cell"><?php echo htmlspecialchars($app['time_slot']); ?></td>
                                    <td class="emr-patient-cell"><?php echo htmlspecialchars($app['patient_name']); ?></td>
                                    <td class="emr-condition-cell"><?php echo htmlspecialchars($app['reason'] ?: 'General Consultation'); ?></td>
                                    <td><span class="emr-badge <?php echo $badgeClass; ?>"><?php echo strtoupper($status); ?></span></td>
                                    <td>
                                        <div class="emr-btn-actions">
                                        <?php if ($status === 'Scheduled'): ?>
                                            <a href="?tab=appointments&action=complete&id=<?php echo $app['id']; ?>" class="emr-btn emr-btn-teal">Check-In</a>
                                            <a href="?tab=appointments&action=cancel&id=<?php echo $app['id']; ?>" class="emr-btn emr-btn-danger" onclick="return confirm('Cancel this appointment?')">Cancel</a>
                                        <?php elseif ($status === 'In Progress'): ?>
                                            <a href="?tab=appointments&action=complete&id=<?php echo $app['id']; ?>" class="emr-btn emr-btn-solid">Complete</a>
                                        <?php else: ?>
                                            <span style="font-size:0.72rem;color:#94a3b8;">Locked</span>
                                        <?php endif; ?>
                                        </div>
                                    </td>
                                </tr>
                            <?php endwhile; ?>
                            <?php if (!$has_today_appts): ?>
                                <tr class="emr-empty-row"><td colspan="5">No appointments scheduled. <a href="?tab=appointments" class="emr-btn" style="margin-left:8px;">View All</a></td></tr>
                            <?php endif; ?>
                            </tbody>
                        </table>

                        <!-- Recent Medical Records -->
                        <div class="emr-section-label">Recent Medical Records</div>
                        <div class="emr-records-grid">
                            <?php
                            $has_rec = false;
                            while ($pr = $rec_presc->fetchArray(SQLITE3_ASSOC)):
                                $has_rec = true;
                                $recDate = date('M j, Y', strtotime($pr['created_at'] ?? 'now'));
                            ?>
                            <div class="emr-record-card">
                                <div class="emr-record-date"><?php echo $recDate; ?></div>
                                <div class="emr-record-title">Prescription: <?php echo htmlspecialchars($pr['medication']); ?></div>
                                <div class="emr-record-desc">Patient: <?php echo htmlspecialchars($pr['patient_name']); ?>. Status: <?php echo htmlspecialchars($pr['dosage']); ?> &mdash; <?php echo htmlspecialchars($pr['frequency']); ?>.</div>
                            </div>
                            <?php endwhile; ?>
                            <?php if (!$has_rec): ?>
                            <div class="emr-record-card" style="grid-column:1/-1;">
                                <div class="emr-record-desc" style="color:#94a3b8;">No recent prescription records found.</div>
                            </div>
                            <?php endif; ?>
                        </div>
                    </div>

                    <!-- Right Column: Clinic Metrics -->
                    <div class="emr-metrics-col">
                        <div class="emr-section-label">Clinic Metrics</div>

                        <div class="emr-metric-item">
                            <div class="emr-metric-number"><?php echo str_pad($today_count, 2, '0', STR_PAD_LEFT); ?></div>
                            <div class="emr-metric-label">Daily Consultations</div>
                        </div>

                        <div class="emr-metric-item">
                            <div class="emr-metric-number">+14%</div>
                            <div class="emr-metric-label">Efficiency Gain</div>
                        </div>

                        <div class="emr-metric-item">
                            <div class="emr-metric-number"><?php echo str_pad($staff_on_duty, 2, '0', STR_PAD_LEFT); ?></div>
                            <div class="emr-metric-label">Staff on Duty</div>
                        </div>

                        <a href="?tab=appointments" class="emr-cta-btn">Generate Daily Report</a>
                    </div>

                </div>
            <?php elseif ($tab === 'appointments'): ?>
                <div class="emr-section-label">Patient Schedule &amp; Actions</div>
                <div style="overflow-x:auto;">
                <table class="emr-table">
                    <thead><tr>
                        <th>Patient</th>
                        <th>Queue</th>
                        <th>Date</th>
                        <th>Time</th>
                        <th>Reason</th>
                        <th>Status</th>
                        <th style="text-align:right;">Actions</th>
                    </tr></thead>
                    <tbody>
                                <thead>
                                    <tr>
                                        <th>Patient Name</th>
                                        <th>Queue #</th>
                                        <th>Date</th>
                                        <th>Time Slot</th>
                                        <th>Reason</th>
                                        <th>Status</th>
                                        <th style="text-align: right; width: 320px;">Action Control</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php
                                    $query = "SELECT a.*, u.name as patient_name FROM appointments a JOIN users u ON a.patient_id = u.id ORDER BY a.appointment_date ASC, a.time_slot ASC";
                                    $res = $db->query($query);
                                    $has_rows = false;
                                    while ($app = $res->fetchArray(SQLITE3_ASSOC)):
                                        $has_rows = true;
                                    ?>
                                    <tr>
                                        <td class="emr-patient-cell"><strong><?php echo htmlspecialchars($app['patient_name']); ?></strong></td>
                                        <td><?php if (!empty($app['queue_number'])): ?><span style="font-weight:700;color:#0f766e;">Q-<?php echo $app['queue_number']; ?></span><?php else: ?><span style="color:#94a3b8;">—</span><?php endif; ?></td>
                                        <td><?php echo htmlspecialchars($app['appointment_date']); ?></td>
                                        <td class="emr-time-cell"><?php echo htmlspecialchars($app['time_slot']); ?></td>
                                        <td class="emr-condition-cell"><?php echo htmlspecialchars($app['reason'] ?: 'Routine checkup'); ?></td>
                                        <td>
                                            <?php
                                            $sc = $app['status'];
                                            $bc2 = 'emr-badge-scheduled';
                                            if ($sc==='In Progress') $bc2='emr-badge-inprogress';
                                            elseif ($sc==='Completed') $bc2='emr-badge-completed';
                                            elseif ($sc==='Cancelled') $bc2='emr-badge-cancelled';
                                            elseif ($sc==='No-Show') $bc2='emr-badge-noshow';
                                            ?>
                                            <span class="emr-badge <?php echo $bc2; ?>"><?php echo strtoupper(htmlspecialchars($app['status'])); ?></span>
                                        </td>
                                        <td style="text-align:right;">
                                            <?php if ($app['status'] === 'Scheduled'): ?>
                                            <div class="emr-btn-actions" style="justify-content:flex-end;">
                                                <button type="button" class="emr-btn" onclick="openRescheduleModal(<?php echo $app['id']; ?>, '<?php echo $app['appointment_date']; ?>', '<?php echo $app['time_slot']; ?>')">Reschedule</button>
                                                <a href="?tab=appointments&action=complete&id=<?php echo $app['id']; ?>" class="emr-btn emr-btn-teal">Complete</a>
                                                <a href="?tab=appointments&action=cancel&id=<?php echo $app['id']; ?>" class="emr-btn emr-btn-danger" onclick="return confirm('Cancel this appointment?')">Cancel</a>
                                            </div>
                                            <?php else: ?>
                                                <span style="font-size:0.72rem;color:#94a3b8;">Locked</span>
                                            <?php endif; ?>
                                        </td>
                                    </tr>
                                <?php endwhile; ?>
                                <?php if (!$has_rows): ?>
                                    <tr class="emr-empty-row"><td colspan="7">No appointments scheduled in the clinic.</td></tr>
                                <?php endif; ?>
                            </tbody>
                        </table>
                        </div>

            <!-- TAB 3: PRESCRIBE -->
            <?php elseif ($tab === 'prescribe'): ?>
                <div class="emr-tab-grid">

                    <!-- Prescribe Form -->
                    <div class="emr-form-section">
                        <h3>Write New Prescription</h3>
                            <?php if (empty($patients)): ?>
                                <p style="font-size: 0.95rem; color: var(--text-muted);">No patients are registered in the database to prescribe medication to.</p>
                            <?php else: ?>
                                <form action="?tab=prescribe" method="POST">
                                    <input type="hidden" name="action" value="prescribe">
                                    
                                    <div class="form-group">
                                        <label for="patient_id">Patient</label>
                                        <select name="patient_id" id="patient_id" class="form-control" required>
                                            <option value="" disabled selected>Select Patient...</option>
                                            <?php foreach ($patients as $pat): ?>
                                                <option value="<?php echo $pat['id']; ?>">
                                                    <?php echo htmlspecialchars($pat['name']) . " (" . htmlspecialchars($pat['email']) . ")"; ?>
                                                </option>
                                            <?php endforeach; ?>
                                        </select>
                                    </div>

                                    <div class="form-group">
                                        <label for="medication">Medication Name</label>
                                        <input type="text" name="medication" id="medication" class="form-control" placeholder="e.g. Amoxicillin 500mg" required>
                                    </div>

                                    <div class="form-group">
                                        <label for="dosage">Dosage Instructions</label>
                                        <input type="text" name="dosage" id="dosage" class="form-control" placeholder="e.g. 1 Tablet" required>
                                    </div>

                                    <div class="form-group">
                                        <label for="frequency">Frequency / Duration</label>
                                        <input type="text" name="frequency" id="frequency" class="form-control" placeholder="e.g. Three times daily for 7 days" required>
                                    </div>

                                    <button type="submit" class="btn-sm btn-success" style="padding: 0.75rem 1.5rem; font-size: 0.95rem;">
                                        <i class="fa-solid fa-notes-medical"></i> Save & Send Prescription
                                    </button>
                                </form>
                            <?php endif; ?>
                        </div>
                    </div>

                    <!-- Prescriptions History -->
                    <div class="emr-form-section">
                        <h3>Prescribed Log</h3>
                        <div class="scrollable-y" style="max-height:480px;overflow-y:auto;">
                            <?php
                            $query = "SELECT p.*, u.name as patient_name FROM prescriptions p JOIN users u ON p.patient_id = u.id WHERE p.doctor_id = :doctor_id ORDER BY p.created_at DESC";
                            $stmt_presc_hist = $db->prepare($query);
                            $stmt_presc_hist->bindValue(':doctor_id', $doctor_id, SQLITE3_INTEGER);
                            $res = $stmt_presc_hist->execute();
                            $has_presc = false;
                            while ($pr = $res->fetchArray(SQLITE3_ASSOC)):
                                $has_presc = true;
                            ?>
                                <div class="prescription-list-item">
                                    <div class="prescription-med"><?php echo htmlspecialchars($pr['medication']); ?></div>
                                    <div style="font-size: 0.85rem; color: var(--text-main); margin-top: 0.25rem;">
                                        Patient: <strong><?php echo htmlspecialchars($pr['patient_name']); ?></strong>
                                    </div>
                                    <div class="prescription-meta">
                                        <span><?php echo htmlspecialchars($pr['dosage']); ?> &bull; <?php echo htmlspecialchars($pr['frequency']); ?></span><br>
                                        <span style="font-size: 0.75rem; color: var(--text-muted);">By: <?php echo (stripos($pr['doctor_name'], 'Dr.') === 0) ? htmlspecialchars($pr['doctor_name']) : 'Dr. ' . htmlspecialchars($pr['doctor_name']); ?> &bull; <?php echo date('M d, Y', strtotime($pr['created_at'])); ?></span>
                                    </div>
                                </div>
                            <?php endwhile; ?>
                            <?php if (!$has_presc): ?>
                                <p style="text-align: center; color: var(--text-muted); padding: 2rem;">No prescriptions issued yet.</p>
                            <?php endif; ?>
                        </div>
                    </div>

                </div>

            <!-- TAB 4: PATIENTS -->
            <?php elseif ($tab === 'patients'): ?>
                <div class="emr-section-label">Registered Patients Index</div>
                <div style="overflow-x:auto;">
                <table class="emr-table">
                    <thead><tr>
                        <th>Patient Name</th>
                        <th>Email</th>
                        <th>Registered Date</th>
                    </tr></thead>
                    <tbody>
                                    <?php if (empty($patients)): ?>
                                        <tr>
                                            <td colspan="3" style="text-align: center; color: var(--text-muted); padding: 2rem;">
                                                No patients registered in the system.
                                            </td>
                                        </tr>
                                    <?php else: ?>
                                        <?php foreach ($patients as $pat): 
                                            // Get registration date
                                            $stmt_date = $db->prepare("SELECT created_at FROM users WHERE id = :id");
                                            $stmt_date->bindValue(':id', $pat['id'], SQLITE3_INTEGER);
                                            $created_at = $stmt_date->execute()->fetchArray(SQLITE3_ASSOC)['created_at'] ?? 'N/A';
                                        ?>
                                            <tr>
                                                <td><strong><?php echo htmlspecialchars($pat['name']); ?></strong></td>
                                                <td><?php echo htmlspecialchars($pat['email']); ?></td>
                                                <td><?php echo date('M d, Y (h:i A)', strtotime($created_at)); ?></td>
                                            </tr>
                        <?php endforeach; ?>
                    <?php endif; ?>
                    </tbody>
                </table>
                </div>

            <!-- TAB 5: AVAILABILITY -->
            <?php elseif ($tab === 'availability'):
                // Get month & year from query params or default to current
                $month = isset($_GET['month']) ? filter_var($_GET['month'], FILTER_VALIDATE_INT) : null;
                $year = isset($_GET['year']) ? filter_var($_GET['year'], FILTER_VALIDATE_INT) : null;

                if (!$month || $month < 1 || $month > 12) {
                    $month = (int)date('n');
                }
                if (!$year || $year < 1970 || $year > 2100) {
                    $year = (int)date('Y');
                }

                // Date calculations
                $first_day_of_month = mktime(0, 0, 0, $month, 1, $year);
                $days_in_month = (int)date('t', $first_day_of_month);
                $first_day_weekday = (int)date('w', $first_day_of_month); // 0 (Sunday) to 6 (Saturday)
                $month_name = date('F', $first_day_of_month);

                // Previous/Next month links calculations
                $prev_month = $month - 1;
                $prev_year = $year;
                if ($prev_month < 1) {
                    $prev_month = 12;
                    $prev_year--;
                }

                $next_month = $month + 1;
                $next_year = $year;
                if ($next_month > 12) {
                    $next_month = 1;
                    $next_year++;
                }

                // Query all availability records for all doctors/clinical staff
                $start_date = sprintf('%04d-%02d-01', $year, $month);
                $end_date = sprintf('%04d-%02d-%02d', $year, $month, $days_in_month);

                $query_all = "SELECT a.*, u.name as doctor_name FROM availability a JOIN users u ON a.doctor_id = u.id WHERE a.available_date BETWEEN :start_date AND :end_date ORDER BY a.available_date ASC, u.name ASC";
                $stmt_avail = $db->prepare($query_all);
                $stmt_avail->bindValue(':start_date', $start_date, SQLITE3_TEXT);
                $stmt_avail->bindValue(':end_date', $end_date, SQLITE3_TEXT);
                $res_avail = $stmt_avail->execute();

                $avail_lookup = [];
                while ($row = $res_avail->fetchArray(SQLITE3_ASSOC)) {
                    $avail_lookup[$row['available_date']][] = $row;
                }

                // ---- Appointment counts per day (this doctor, this month) ----
                $appt_counts = [];
                $stmt_ac = $db->prepare("SELECT appointment_date, COUNT(*) as cnt FROM appointments WHERE doctor_id = :did AND appointment_date BETWEEN :start_date AND :end_date AND status != 'Cancelled' GROUP BY appointment_date");
                $stmt_ac->bindValue(':did', $doctor_id, SQLITE3_INTEGER);
                $stmt_ac->bindValue(':start_date', $start_date, SQLITE3_TEXT);
                $stmt_ac->bindValue(':end_date', $end_date, SQLITE3_TEXT);
                $res_ac = $stmt_ac->execute();
                while ($row = $res_ac->fetchArray(SQLITE3_ASSOC)) {
                    $appt_counts[$row['appointment_date']] = (int)$row['cnt'];
                }

                // ---- Summary metrics (this doctor, this month) ----
                $my_available_days = 0;
                $my_unavailable_days = 0;
                foreach ($avail_lookup as $date => $slots) {
                    foreach ($slots as $s) {
                        if ($s['doctor_id'] == $doctor_id) {
                            if ($s['status'] === 'Available') { $my_available_days++; }
                            else { $my_unavailable_days++; }
                        }
                    }
                }
                $booked_appts_month = 0;
                foreach ($appt_counts as $c) { $booked_appts_month += $c; }

                // Utilization = booked appts / capacity (available days * assumed slots/day)
                $slots_per_day = 8;
                $capacity = max(1, $my_available_days * $slots_per_day);
                $utilization = min(100, (int)round(($booked_appts_month / $capacity) * 100));

                // ---- Today's availability (this doctor) ----
                $today_date = date('Y-m-d');
                $stmt_today_av = $db->prepare("SELECT status, notes FROM availability WHERE doctor_id = :did AND available_date = :d");
                $stmt_today_av->bindValue(':did', $doctor_id, SQLITE3_INTEGER);
                $stmt_today_av->bindValue(':d', $today_date, SQLITE3_TEXT);
                $today_av = $stmt_today_av->execute()->fetchArray(SQLITE3_ASSOC) ?: null;

                $stmt_today_ap = $db->prepare("SELECT COUNT(*) as c FROM appointments WHERE doctor_id = :did AND appointment_date = :d AND status != 'Cancelled'");
                $stmt_today_ap->bindValue(':did', $doctor_id, SQLITE3_INTEGER);
                $stmt_today_ap->bindValue(':d', $today_date, SQLITE3_TEXT);
                $today_appt_count = (int)($stmt_today_ap->execute()->fetchArray(SQLITE3_ASSOC)['c'] ?? 0);

                // ---- Upcoming schedule (next appointments for this doctor) ----
                $stmt_up = $db->prepare("SELECT a.appointment_date, a.time_slot, a.status, u.name as patient_name FROM appointments a JOIN users u ON a.patient_id = u.id WHERE a.doctor_id = :did AND a.appointment_date >= :d AND a.status != 'Cancelled' ORDER BY a.appointment_date ASC, a.time_slot ASC LIMIT 6");
                $stmt_up->bindValue(':did', $doctor_id, SQLITE3_INTEGER);
                $stmt_up->bindValue(':d', $today_date, SQLITE3_TEXT);
                $res_up = $stmt_up->execute();
                $upcoming = [];
                while ($row = $res_up->fetchArray(SQLITE3_ASSOC)) { $upcoming[] = $row; }
            ?>
                <div id="wa-app" style="background: var(--wa-bg);" class="-mx-6 -mb-6 px-4 sm:px-6 lg:px-8 pt-2 pb-8">
                    <!-- Header/toolbar row -->
                    <div class="wa-header-row">
                        <div>
                            <h2 style="font-size: 1.5rem; font-weight: 700; display: flex; align-items: center; gap: 0.5rem; margin: 0;" class="wa-text-main">
                                <i class="fa-solid fa-calendar-days" style="color: var(--primary);"></i>
                                <span><?php echo "$month_name $year"; ?></span>
                            </h2>
                            <p class="wa-text-muted" style="font-size: 0.875rem; margin-top: 0.25rem;">Manage your clinical shifts, daily availability, and schedules.</p>
                        </div>
                        
                        <div style="display: flex; flex-wrap: wrap; align-items: center; gap: 0.75rem;">
                            <!-- Navigation Buttons -->
                            <div class="wa-btn-group">
                                <a href="?tab=availability&month=<?php echo $prev_month; ?>&year=<?php echo $prev_year; ?>" class="wa-btn-item">
                                    <i class="fa-solid fa-chevron-left" style="margin-right: 4px;"></i> Prev
                                </a>
                                <a href="?tab=availability&month=<?php echo date('n'); ?>&year=<?php echo date('Y'); ?>" class="wa-btn-item">
                                    Today
                                </a>
                                <a href="?tab=availability&month=<?php echo $next_month; ?>&year=<?php echo $next_year; ?>" class="wa-btn-item">
                                    Next <i class="fa-solid fa-chevron-right" style="margin-left: 4px;"></i>
                                </a>
                            </div>
                            
                            <!-- View Toggle Buttons -->
                            <div class="wa-btn-group">
                                <button onclick="setWAView('month')" id="btn-view-month" class="wa-btn-item active">
                                    Month
                                </button>
                                <button onclick="setWAView('week')" id="btn-view-week" class="wa-btn-item">
                                    Week
                                </button>
                                <button onclick="setWAView('list')" id="btn-view-list" class="wa-btn-item">
                                    List
                                </button>
                            </div>
                        </div>
                    </div>

                    <!-- Summary Cards Row -->
                    <div class="wa-summary-grid">
                        <!-- Available Days Card -->
                        <div class="wa-card p-4 rounded-xl flex items-center gap-4">
                            <div class="w-10 h-10 rounded-lg wa-pill-avail flex items-center justify-center">
                                <i class="fa-solid fa-circle-check text-lg"></i>
                            </div>
                            <div>
                                <span class="text-xs wa-text-muted block font-semibold">Available Days</span>
                                <span class="text-xl font-bold wa-text-main"><?php echo $my_available_days; ?></span>
                            </div>
                        </div>

                        <!-- Unavailable Days Card -->
                        <div class="wa-card p-4 rounded-xl flex items-center gap-4">
                            <div class="w-10 h-10 rounded-lg wa-pill-unavail flex items-center justify-center">
                                <i class="fa-solid fa-circle-xmark text-lg"></i>
                            </div>
                            <div>
                                <span class="text-xs wa-text-muted block font-semibold">Days Off / Unavailable</span>
                                <span class="text-xl font-bold wa-text-main"><?php echo $my_unavailable_days; ?></span>
                            </div>
                        </div>

                        <!-- Booked Appointments Card -->
                        <div class="wa-card p-4 rounded-xl flex items-center gap-4">
                            <div class="w-10 h-10 rounded-lg wa-pill-avail flex items-center justify-center">
                                <i class="fa-solid fa-calendar-check text-lg"></i>
                            </div>
                            <div>
                                <span class="text-xs wa-text-muted block font-semibold">Booked Appointments</span>
                                <span class="text-xl font-bold wa-text-main"><?php echo $booked_appts_month; ?></span>
                            </div>
                        </div>

                        <!-- Utilization Rate Card -->
                        <div class="wa-card p-4 rounded-xl flex items-center gap-4">
                            <div class="w-10 h-10 rounded-lg wa-pill-avail flex items-center justify-center">
                                <i class="fa-solid fa-chart-pie text-lg"></i>
                            </div>
                            <div>
                                <span class="text-xs wa-text-muted block font-semibold">Utilization Rate</span>
                                <span class="text-xl font-bold wa-text-main"><?php echo $utilization; ?>%</span>
                            </div>
                        </div>
                    </div>

                    <!-- Two-Column Layout Grid -->
                    <div class="wa-layout-grid">
                        <!-- Left Column: Calendar Views -->
                        <div style="display: flex; flex-direction: column; gap: 1.5rem;">
                            
                            <!-- MONTH VIEW (id="wa-month") -->
                            <div id="wa-month" class="wa-card p-4">
                                <!-- Weekday Headers -->
                                <div class="wa-calendar-weekdays">
                                    <div>Sun</div>
                                    <div>Mon</div>
                                    <div>Tue</div>
                                    <div>Wed</div>
                                    <div>Thu</div>
                                    <div>Fri</div>
                                    <div>Sat</div>
                                </div>
                                
                                <!-- Days Grid -->
                                <div class="wa-calendar-grid">
                                    <!-- Blank offset days -->
                                    <?php for ($i = 0; $i < $first_day_weekday; $i++): ?>
                                        <div class="wa-cell-blank"></div>
                                    <?php endfor; ?>
                                    
                                    <!-- Day cells -->
                                    <?php
                                    for ($day = 1; $day <= $days_in_month; $day++):
                                        $current_date_str = sprintf('%04d-%02d-%02d', $year, $month, $day);
                                        $is_today = ($current_date_str === $today_date);
                                        $day_slots = isset($avail_lookup[$current_date_str]) ? $avail_lookup[$current_date_str] : [];
                                        
                                        // Find logged-in doctor's own slot
                                        $my_avail = null;
                                        foreach ($day_slots as $slot) {
                                            if ($slot['doctor_id'] == $doctor_id) {
                                                $my_avail = $slot;
                                                break;
                                            }
                                        }
                                        
                                        $status = $my_avail ? $my_avail['status'] : '';
                                        $notes = $my_avail ? $my_avail['notes'] : '';
                                        $has_avail = ($my_avail !== null);
                                        $appts_today = isset($appt_counts[$current_date_str]) ? (int)$appt_counts[$current_date_str] : 0;
                                        
                                        // Render status pill options
                                        $pill_class = "";
                                        if ($status === 'Available') {
                                            $pill_class = "wa-pill-avail";
                                        } elseif ($status === 'Unavailable') {
                                            $pill_class = "wa-pill-unavail";
                                        }
                                    ?>
                                        <div onclick="openAvailabilityModal(this)"
                                             data-date="<?php echo $current_date_str; ?>"
                                             data-status="<?php echo htmlspecialchars($status); ?>"
                                             data-notes="<?php echo htmlspecialchars($notes); ?>"
                                             class="wa-cell <?php echo $is_today ? 'style="border-color: var(--primary) !important;"' : ''; ?>">
                                            
                                            <div style="display: flex; align-items: center; justify-content: space-between;">
                                                <span style="font-size: 0.85rem; font-weight: 700;" class="wa-text-main">
                                                    <?php echo $day; ?>
                                                </span>
                                                <?php if ($appts_today > 0): ?>
                                                    <span class="wa-pill-avail" style="padding: 2px 6px; border-radius: 9999px; font-size: 0.7rem; font-weight: 600;" title="<?php echo $appts_today; ?> appointments booked">
                                                        <?php echo $appts_today; ?> Appt
                                                    </span>
                                                <?php endif; ?>
                                            </div>
                                            
                                            <div style="display: flex; flex-direction: column; gap: 4px; margin-top: auto;">
                                                <?php if ($status): ?>
                                                    <span style="padding: 2px 4px; font-size: 0.7rem; font-weight: 600; border-radius: 4px; text-align: center;" class="<?php echo $pill_class; ?>">
                                                        <?php echo $status; ?>
                                                    </span>
                                                <?php endif; ?>
                                                
                                                <?php if ($notes): ?>
                                                    <span class="wa-text-muted" style="font-size: 0.7rem; white-space: nowrap; overflow: hidden; text-overflow: ellipsis;" title="<?php echo htmlspecialchars($notes); ?>">
                                                        <?php echo htmlspecialchars($notes); ?>
                                                    </span>
                                                <?php endif; ?>
                                            </div>
                                        </div>
                                    <?php endfor; ?>
                                </div>
                            </div>

                            <!-- WEEK VIEW (id="wa-week", hidden by default) -->
                            <div id="wa-week" class="hidden" style="display: none;">
                                <?php
                                $today_ts = strtotime($today_date);
                                $start_of_week = strtotime('sunday this week', $today_ts);
                                
                                for ($w = 0; $w < 7; $w++):
                                    $w_date = date('Y-m-d', strtotime("+$w days", $start_of_week));
                                    $w_day_name = date('l', strtotime($w_date));
                                    $w_day_num = date('j', strtotime($w_date));
                                    $w_month_lbl = date('M', strtotime($w_date));
                                    
                                    $w_slots = isset($avail_lookup[$w_date]) ? $avail_lookup[$w_date] : [];
                                    $w_avail = null;
                                    foreach ($w_slots as $slot) {
                                        if ($slot['doctor_id'] == $doctor_id) {
                                            $w_avail = $slot;
                                            break;
                                        }
                                    }
                                    $w_status = $w_avail ? $w_avail['status'] : '';
                                    $w_notes = $w_avail ? $w_avail['notes'] : '';
                                    $w_appts = isset($appt_counts[$w_date]) ? (int)$appt_counts[$w_date] : 0;
                                ?>
                                    <div onclick="openAvailabilityModal(this)"
                                         data-date="<?php echo $w_date; ?>"
                                         data-status="<?php echo htmlspecialchars($w_status); ?>"
                                         data-notes="<?php echo htmlspecialchars($w_notes); ?>"
                                         class="wa-card p-4 rounded-xl cursor-pointer" style="display: flex; align-items: center; justify-content: space-between; margin-bottom: 0.75rem;">
                                        
                                        <div style="display: flex; align-items: center; gap: 1rem;">
                                            <div style="text-align: center; width: 48px; padding: 6px; border-radius: 8px; background: rgba(241, 245, 249, 0.6); display: flex; flex-direction: column; justify-content: center;">
                                                <span style="font-size: 0.65rem; text-transform: uppercase; font-weight: 700;" class="wa-text-muted"><?php echo substr($w_day_name, 0, 3); ?></span>
                                                <span style="font-size: 1.1rem; font-weight: 800;" class="wa-text-main"><?php echo $w_day_num; ?></span>
                                            </div>
                                            <div>
                                                <span style="font-size: 0.9rem; font-weight: 700; display: block;" class="wa-text-main"><?php echo "$w_month_lbl $w_day_num, " . date('Y', strtotime($w_date)); ?></span>
                                                <span style="font-size: 0.75rem;" class="wa-text-muted"><?php echo htmlspecialchars($w_notes ?: 'No specific hours set'); ?></span>
                                            </div>
                                        </div>
                                        
                                        <div style="display: flex; align-items: center; gap: 0.75rem;">
                                            <?php if ($w_appts > 0): ?>
                                                <span class="wa-pill-avail" style="padding: 4px 10px; font-size: 0.75rem; font-weight: 700; border-radius: 9999px;">
                                                    <?php echo $w_appts; ?> Booked Visits
                                                </span>
                                            <?php endif; ?>
                                            
                                            <?php if ($w_status): ?>
                                                <span style="padding: 4px 10px; font-size: 0.75rem; font-weight: 700; border-radius: 6px;" class="<?php echo $w_status === 'Available' ? 'wa-pill-avail' : 'wa-pill-unavail'; ?>">
                                                    <?php echo $w_status; ?>
                                                </span>
                                            <?php else: ?>
                                                <span class="wa-text-muted" style="padding: 4px 10px; font-size: 0.75rem; border: 1px dashed var(--border-color); border-radius: 6px;">
                                                    Not Set
                                                </span>
                                            <?php endif; ?>
                                        </div>
                                    </div>
                                <?php endfor; ?>
                            </div>

                            <!-- LIST VIEW (id="wa-list", hidden by default) -->
                            <div id="wa-list" class="hidden wa-card p-4" style="display: none;">
                                <div class="table-responsive">
                                    <table class="data-table w-full text-sm text-left">
                                        <thead>
                                            <tr class="border-b border-slate-200 dark:border-slate-800 text-slate-500 font-semibold">
                                                <th class="py-2.5 px-3">Date</th>
                                                <th class="py-2.5 px-3">Shifts / Status</th>
                                                <th class="py-2.5 px-3">Working Details</th>
                                                <th class="py-2.5 px-3 text-right">Visits Booked</th>
                                            </tr>
                                        </thead>
                                        <tbody>
                                            <?php
                                            $list_count = 0;
                                            for ($day = 1; $day <= $days_in_month; $day++):
                                                $l_date = sprintf('%04d-%02d-%02d', $year, $month, $day);
                                                $l_slots = isset($avail_lookup[$l_date]) ? $avail_lookup[$l_date] : [];
                                                
                                                $l_avail = null;
                                                foreach ($l_slots as $slot) {
                                                    if ($slot['doctor_id'] == $doctor_id) {
                                                        $l_avail = $slot;
                                                        break;
                                                    }
                                                }
                                                $l_status = $l_avail ? $l_avail['status'] : '';
                                                $l_notes = $l_avail ? $l_avail['notes'] : '';
                                                $l_appts = isset($appt_counts[$l_date]) ? (int)$appt_counts[$l_date] : 0;
                                                
                                                if ($l_status || $l_appts > 0):
                                                    $list_count++;
                                            ?>
                                                <tr onclick="openAvailabilityModal(this)"
                                                    data-date="<?php echo $l_date; ?>"
                                                    data-status="<?php echo htmlspecialchars($l_status); ?>"
                                                    data-notes="<?php echo htmlspecialchars($l_notes); ?>"
                                                    class="border-b border-slate-100 dark:border-slate-800/50 hover:bg-slate-50 dark:hover:bg-slate-850 cursor-pointer transition">
                                                    <td class="py-3 px-3 font-semibold text-slate-800 dark:text-slate-205">
                                                        <?php echo date('D, M j, Y', strtotime($l_date)); ?>
                                                    </td>
                                                    <td class="py-3 px-3">
                                                        <?php if ($l_status): ?>
                                                            <span class="inline-flex px-2 py-0.5 text-xs font-semibold rounded-md border <?php echo $l_status === 'Available' ? 'bg-teal-50 text-teal-700 border-teal-200 dark:bg-teal-900/20 dark:text-teal-400' : 'bg-rose-50 text-rose-700 border-rose-200 dark:bg-rose-900/20 dark:text-rose-400'; ?>">
                                                                <?php echo $l_status; ?>
                                                            </span>
                                                        <?php else: ?>
                                                            <span class="text-slate-400 font-normal">Off</span>
                                                        <?php endif; ?>
                                                    </td>
                                                    <td class="py-3 px-3 text-slate-500 dark:text-slate-400">
                                                        <?php echo htmlspecialchars($l_notes ?: '-'); ?>
                                                    </td>
                                                    <td class="py-3 px-3 text-right font-semibold text-slate-800 dark:text-slate-205">
                                                        <?php echo $l_appts > 0 ? $l_appts : '-'; ?>
                                                    </td>
                                                </tr>
                                            <?php 
                                                endif;
                                            endfor; 
                                            if ($list_count === 0):
                                            ?>
                                                <tr>
                                                    <td colspan="4" class="py-8 text-center text-slate-400 dark:text-slate-500">
                                                        No availability shifts configured for this month.
                                                    </td>
                                                </tr>
                                            <?php endif; ?>
                                        </tbody>
                                    </table>
                                </div>
                            </div>
                        </div>
                        
                        <!-- Right Column: Side Info Panel -->
                        <div style="display: flex; flex-direction: column; gap: 1.5rem;">
                            <!-- Panel 1: Today's Availability -->
                            <div class="wa-card p-4">
                                <h3 style="font-size: 0.95rem; font-weight: 700; display: flex; align-items: center; gap: 0.5rem; margin-bottom: 0.75rem;" class="wa-text-main">
                                    <i class="fa-solid fa-clock" style="color: var(--primary);"></i> Today's Schedule Overview
                                </h3>
                                
                                <div class="wa-date-status-box">
                                    <div style="display: flex; align-items: center; justify-content: space-between; margin-bottom: 0.5rem;">
                                        <span class="wa-text-muted" style="font-size: 0.8rem; font-weight: 600;">Date</span>
                                        <span class="wa-text-main" style="font-size: 0.8rem; font-weight: 700;"><?php echo date('l, M j'); ?></span>
                                    </div>
                                    <div style="display: flex; align-items: center; justify-content: space-between; margin-bottom: 0.5rem;">
                                        <span class="wa-text-muted" style="font-size: 0.8rem; font-weight: 600;">Shifts Status</span>
                                        <?php if ($today_av): ?>
                                            <span style="padding: 3px 8px; font-size: 0.7rem; font-weight: 700; border-radius: 6px;" class="<?php echo $today_av['status'] === 'Available' ? 'wa-pill-avail' : 'wa-pill-unavail'; ?>">
                                                <?php echo $today_av['status']; ?>
                                            </span>
                                        <?php else: ?>
                                            <span class="wa-text-muted" style="padding: 3px 8px; font-size: 0.7rem; font-weight: 600; border-radius: 6px; border: 1px solid var(--border-color);">Not Set</span>
                                        <?php endif; ?>
                                    </div>
                                    <?php if ($today_av && $today_av['notes']): ?>
                                        <div class="wa-text-muted" style="font-size: 0.75rem; border-top: 1px solid var(--border-color); padding-top: 0.5rem; margin-top: 0.5rem;">
                                            <strong>Hours:</strong> <?php echo htmlspecialchars($today_av['notes']); ?>
                                        </div>
                                    <?php endif; ?>
                                </div>
                                
                                <div style="display: flex; align-items: center; justify-content: space-between; font-size: 0.8rem; border-top: 1px solid var(--border-color); padding-top: 0.75rem;">
                                    <span class="wa-text-muted">Active Bookings Today</span>
                                    <span class="wa-text-main" style="font-weight: 700;"><?php echo $today_appt_count; ?> appointments</span>
                                </div>
                            </div>

                            <!-- Panel 2: Upcoming Schedule -->
                            <div class="wa-card p-4">
                                <h3 style="font-size: 0.95rem; font-weight: 700; display: flex; align-items: center; gap: 0.5rem; margin-bottom: 0.75rem;" class="wa-text-main">
                                    <i class="fa-solid fa-list-check" style="color: #6366f1;"></i> Upcoming Patient Visits
                                </h3>
                                
                                <div style="display: flex; flex-direction: column; gap: 0.5rem; max-height: 240px; overflow-y: auto;">
                                    <?php if (empty($upcoming)): ?>
                                        <div style="text-align: center; padding: 1.5rem 0.5rem;" class="wa-text-muted">
                                            <i class="fa-solid fa-calendar-xmark" style="font-size: 1.5rem; margin-bottom: 0.5rem; display: block; opacity: 0.5;"></i>
                                            <span style="font-size: 0.8rem;">No upcoming bookings scheduled.</span>
                                        </div>
                                    <?php else: 
                                        foreach ($upcoming as $up):
                                    ?>
                                        <div class="wa-date-status-box" style="margin-bottom: 0; padding: 0.65rem 0.85rem; display: flex; align-items: center; justify-content: space-between;">
                                            <div style="min-width: 0;">
                                                <span class="wa-text-main" style="font-size: 0.825rem; font-weight: 700; display: block; white-space: nowrap; overflow: hidden; text-overflow: ellipsis;"><?php echo htmlspecialchars($up['patient_name']); ?></span>
                                                <span class="wa-text-muted" style="font-size: 0.7rem; display: block;"><?php echo date('M d', strtotime($up['appointment_date'])) . ' at ' . htmlspecialchars($up['time_slot']); ?></span>
                                            </div>
                                            <span class="wa-pill-avail" style="padding: 2px 6px; font-size: 0.7rem; font-weight: 700; border-radius: 9999px;">
                                                <?php echo $up['status']; ?>
                                            </span>
                                        </div>
                                    <?php 
                                        endforeach;
                                    endif; 
                                    ?>
                                </div>
                            </div>

                            <!-- Panel 3: Quick Actions -->
                            <div class="wa-card p-4">
                                <h3 style="font-size: 0.95rem; font-weight: 700; display: flex; align-items: center; gap: 0.5rem; margin-bottom: 0.75rem;" class="wa-text-main">
                                    <i class="fa-solid fa-wand-magic-sparkles" style="color: #f59e0b;"></i> Quick Actions
                                </h3>
                                
                                <div style="display: flex; flex-direction: column; gap: 0.5rem;">
                                    <button onclick="openAvailabilityForDate('<?php echo $today_date; ?>', 'Available')" class="wa-action-btn">
                                        <span>Set Today Available</span>
                                        <i class="fa-solid fa-circle-check" style="color: #10b981;"></i>
                                    </button>
                                    <button onclick="openAvailabilityForDate('<?php echo $today_date; ?>', 'Unavailable')" class="wa-action-btn">
                                        <span>Mark Today Off (Unavailable)</span>
                                        <i class="fa-solid fa-circle-xmark" style="color: #ef4444;"></i>
                                    </button>
                                    <a href="?tab=appointments" class="wa-action-btn btn-primary-pill">
                                        <i class="fa-solid fa-calendar-check"></i> Go to Patient Schedule
                                    </a>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- Interactive Edit Modal -->
                <div class="calendar-modal" id="availabilityModal">
                    <div class="calendar-modal-content">
                        <button type="button" class="calendar-modal-close" onclick="closeAvailabilityModal()">&times;</button>
                        <h3 class="calendar-modal-title" id="modalTitle">Manage Availability</h3>
                        
                        <form action="?tab=availability&month=<?php echo $month; ?>&year=<?php echo $year; ?>" method="POST" id="availForm">
                            <input type="hidden" name="action" id="formAction" value="save_availability">
                            <input type="hidden" name="available_date" id="formDate" value="">
                            
                            <div class="form-group">
                                <label for="statusSelect">Availability Status</label>
                                <select name="status" id="statusSelect" class="form-control" onchange="toggleNotesField()">
                                    <option value="Available">Available</option>
                                    <option value="Unavailable">Unavailable / Off</option>
                                </select>
                            </div>

                            <div class="form-group" id="notesGroup">
                                <label for="notesInput">Availability Note / Hours</label>
                                <input type="text" name="notes" id="notesInput" class="form-control" placeholder="e.g. 9:00 AM - 5:00 PM or Morning only">
                            </div>

                            <div class="calendar-modal-footer">
                                <button type="button" class="btn-sm btn-danger" id="deleteBtn" style="margin-right: auto; display: none;" onclick="submitDelete()">
                                    <i class="fa-solid fa-trash"></i> Remove
                                </button>
                                <button type="button" class="btn-sm" style="background-color: var(--text-muted); color: white;" onclick="closeAvailabilityModal()">Cancel</button>
                                <button type="submit" class="btn-sm btn-success">
                                    <i class="fa-solid fa-floppy-disk"></i> Save
                                </button>
                            </div>
                        </form>
                    </div>
                </div>

                <script>
                function setWAView(v) {
                    const views = ['month', 'week', 'list'];
                    views.forEach(view => {
                        const el = document.getElementById('wa-' + view);
                        const btn = document.getElementById('btn-view-' + view);
                        if (el) {
                            if (view === v) {
                                el.classList.remove('hidden');
                                el.style.display = 'block';
                            } else {
                                el.classList.add('hidden');
                                el.style.display = 'none';
                            }
                        }
                        if (btn) {
                            if (view === v) {
                                btn.className = 'wa-btn-item active';
                            } else {
                                btn.className = 'wa-btn-item';
                            }
                        }
                    });
                }

                function openAvailabilityForDate(date, status) {
                    const dummyCell = document.createElement('div');
                    dummyCell.setAttribute('data-date', date);
                    dummyCell.setAttribute('data-status', status);
                    dummyCell.setAttribute('data-notes', '');
                    openAvailabilityModal(dummyCell);
                }

                function openAvailabilityModal(cell) {
                    const date = cell.getAttribute('data-date');
                    const status = cell.getAttribute('data-status');
                    const notes = cell.getAttribute('data-notes');
                    
                    document.getElementById('formDate').value = date;
                    document.getElementById('modalTitle').textContent = 'Availability for ' + date;
                    
                    const select = document.getElementById('statusSelect');
                    const notesInput = document.getElementById('notesInput');
                    const deleteBtn = document.getElementById('deleteBtn');
                    
                    if (status) {
                        select.value = status;
                        notesInput.value = notes;
                        deleteBtn.style.display = 'inline-flex';
                    } else {
                        select.value = 'Available';
                        notesInput.value = '';
                        deleteBtn.style.display = 'none';
                    }
                    
                    toggleNotesField();
                    
                    document.getElementById('availabilityModal').classList.add('active');
                }

                function closeAvailabilityModal() {
                    document.getElementById('availabilityModal').classList.remove('active');
                }

                function toggleNotesField() {
                    const select = document.getElementById('statusSelect');
                    const notesGroup = document.getElementById('notesGroup');
                    if (select.value === 'Unavailable') {
                        notesGroup.style.display = 'none';
                    } else {
                        notesGroup.style.display = 'block';
                    }
                }

                function submitDelete() {
                    if (confirm('Are you sure you want to remove availability for this day?')) {
                        document.getElementById('formAction').value = 'delete_availability';
                        document.getElementById('availForm').submit();
                    }
                }

                // Close modal on background click
                window.onclick = function(event) {
                    const modal = document.getElementById('availabilityModal');
                    if (event.target === modal) {
                        closeAvailabilityModal();
                    }
                }
                </script>
            <?php endif; ?>

        </main>

    </div>

    <!-- Reschedule Modal -->
    <div class="calendar-modal" id="rescheduleModal">
        <div class="calendar-modal-content">
            <button type="button" class="calendar-modal-close" onclick="closeRescheduleModal()">&times;</button>
            <h3 class="calendar-modal-title">Reschedule Patient Appointment</h3>
            
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
    function openRescheduleModal(id, date, slot) {
        document.getElementById('rescheduleApptId').value = id;
        document.getElementById('rescheduleDate').value = date;
        document.getElementById('rescheduleSlot').value = slot;
        document.getElementById('rescheduleModal').classList.add('active');
    }

    function closeRescheduleModal() {
        document.getElementById('rescheduleModal').classList.remove('active');
    }

    // Close modal on background click
    const originalWindowClick = window.onclick;
    window.onclick = function(event) {
        if (originalWindowClick) {
            originalWindowClick(event);
        }
        const reschModal = document.getElementById('rescheduleModal');
        if (event.target === reschModal) {
            closeRescheduleModal();
        }
    }
    
    const sbBtn = document.getElementById('sidebar-toggle-btn'); if (sbBtn) { sbBtn.addEventListener('click', () => { document.body.classList.toggle('sidebar-collapsed'); }); }
    </script>
<?php include 'chatbot-widget.php'; ?>
</body>
</html>
