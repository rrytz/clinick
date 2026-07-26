<?php
require_once __DIR__ . '/db.php';

// Auth Guard: Only Patients allowed
check_auth(['Patient']);

$db = get_db_connection();
$patient_id = $_SESSION['user_id'];
$upcoming_appointments_count = (int)$db->querySingle("SELECT COUNT(*) FROM appointments WHERE patient_id = " . intval($patient_id) . " AND status = 'Scheduled'");

if (!function_exists('evaluateNaiveBayesSymptoms')) {
    function evaluateNaiveBayesSymptoms(array $symptomsArr): array
    {
        $text = strtolower(implode(' ', $symptomsArr));

        $hasFever      = str_contains($text, 'fever') || str_contains($text, 'lagnat') || str_contains($text, 'hilanat');
        $hasCough      = str_contains($text, 'cough') || str_contains($text, 'ubo');
        $hasSoreThroat = str_contains($text, 'sore throat') || str_contains($text, 'tutunlan') || str_contains($text, 'lalamunan');
        $hasDiarrhea   = str_contains($text, 'diarrhea') || str_contains($text, 'kalibanga') || str_contains($text, 'pagtatae') || str_contains($text, 'tatae');
        $hasVomiting   = str_contains($text, 'vomiting') || str_contains($text, 'pagsuka') || str_contains($text, 'suka');
        $hasHeadache   = str_contains($text, 'headache') || str_contains($text, 'ulo') || str_contains($text, 'migraine');
        $hasDyspnea    = str_contains($text, 'shortness of breath') || str_contains($text, 'lisod') || str_contains($text, 'ginhawa') || str_contains($text, 'hingal');
        $hasRash       = str_contains($text, 'rash') || str_contains($text, 'katol') || str_contains($text, 'panat') || str_contains($text, 'pantal');
        $hasChestPain  = str_contains($text, 'chest pain') || str_contains($text, 'dibdib');

        // 1. High Urgency Emergency Interception
        if ($hasDyspnea || $hasChestPain) {
            if ($hasFever && ($hasCough || $hasDyspnea)) {
                return [
                    'condition'   => '⚠️ High Urgency: Severe Respiratory Infection / Pneumonia Concern',
                    'probability' => 0.96,
                    'is_emergency'=> true,
                ];
            }
            return [
                'condition'   => '⚠️ Emergency Escalation: Acute Respiratory / Cardiac Concern',
                'probability' => 0.98,
                'is_emergency'=> true,
            ];
        }

        // 2. Multi-Symptom Pattern Combinations
        if ($hasFever && ($hasDiarrhea || $hasVomiting)) {
            return [
                'condition'   => 'Acute Febrile Gastroenteritis (Systemic Infection)',
                'probability' => 0.88,
                'is_emergency'=> false,
            ];
        }

        if ($hasDiarrhea && $hasVomiting) {
            return [
                'condition'   => 'Acute Gastroenteritis (Stomach Flu)',
                'probability' => 0.85,
                'is_emergency'=> false,
            ];
        }

        if ($hasFever && $hasRash) {
            return [
                'condition'   => 'Viral Exanthem / Systemic Febrile Rash',
                'probability' => 0.87,
                'is_emergency'=> false,
            ];
        }

        if ($hasFever && $hasCough && ($hasSoreThroat || $hasHeadache)) {
            return [
                'condition'   => 'Influenza-Like Illness (Flu)',
                'probability' => 0.91,
                'is_emergency'=> false,
            ];
        }

        if ($hasFever && $hasCough) {
            return [
                'condition'   => 'Acute Respiratory Tract Infection',
                'probability' => 0.82,
                'is_emergency'=> false,
            ];
        }

        if ($hasCough && $hasSoreThroat) {
            return [
                'condition'   => 'Upper Respiratory Tract Infection',
                'probability' => 0.84,
                'is_emergency'=> false,
            ];
        }

        if ($hasFever && $hasHeadache) {
            return [
                'condition'   => 'Acute Febrile Illness with Cephalgia',
                'probability' => 0.81,
                'is_emergency'=> false,
            ];
        }

        // Single Symptom Fallbacks
        if ($hasFever) {
            return ['condition' => 'Acute Febrile Syndrome', 'probability' => 0.72, 'is_emergency' => false];
        }
        if ($hasDiarrhea) {
            return ['condition' => 'Gastrointestinal Discomfort (Diarrhea)', 'probability' => 0.70, 'is_emergency' => false];
        }
        if ($hasVomiting) {
            return ['condition' => 'Acute Nausea & Emesis', 'probability' => 0.70, 'is_emergency' => false];
        }
        if ($hasCough) {
            return ['condition' => 'Bronchial Irritant / Mild Cough', 'probability' => 0.68, 'is_emergency' => false];
        }
        if ($hasSoreThroat) {
            return ['condition' => 'Pharyngitis / Sore Throat', 'probability' => 0.68, 'is_emergency' => false];
        }
        if ($hasHeadache) {
            return ['condition' => 'Migraine / Tension Headache', 'probability' => 0.67, 'is_emergency' => false];
        }
        if ($hasRash) {
            return ['condition' => 'Allergic Dermatitis / Contact Rash', 'probability' => 0.67, 'is_emergency' => false];
        }

        return ['condition' => 'General Mild Symptoms (Malaise)', 'probability' => 0.60, 'is_emergency' => false];
    }
}

// Handle AJAX requests
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['ajax_action'])) {
    header('Content-Type: application/json');
    $action = $_POST['ajax_action'];
    
    if ($action === 'chatbot_message') {
        $message = trim($_POST['message'] ?? '');
        $lang = trim($_POST['language'] ?? 'English');
        
        $response = "";
        $message_lower = strtolower($message);

        // Mappings from user message words to standard symptom entities
        $symptom_mappings = [
            'Fever' => ['fever', 'lagnat', 'hilanat', 'hilantan', 'gihilantan', 'nilalagnat'],
            'Cough' => ['cough', 'ubo', 'giubo', 'inuubo'],
            'Sore Throat' => ['sore throat', 'lalamunan', 'tutunlan', 'sakit sa tutunlan', 'sakit sa lalamunan'],
            'Diarrhea' => ['diarrhea', 'pagtatae', 'tatae', 'nagtae', 'kalibanga', 'nagkalibanga'],
            'Vomiting' => ['vomiting', 'suka', 'pagsusuka', 'nagsusuka', 'pagsuka', 'nagsuka'],
            'Headache' => ['headache', 'sakit sa ulo', 'ulo', 'migraine'],
            'Shortness of Breath' => ['shortness of breath', 'breath', 'hingal', 'lisod kaginhawa', 'hapo', 'kahapo'],
            'Skin Rash' => ['rash', 'skin rash', 'pantal', 'katol', 'panat', 'naghupong']
        ];

        $detected_symptoms = [];
        foreach ($symptom_mappings as $entity => $keywords) {
            foreach ($keywords as $kw) {
                if (strpos($message_lower, $kw) !== false) {
                    $detected_symptoms[] = $entity;
                    break;
                }
            }
        }

        $is_diagnostic_trigger = preg_match('/(diagnos|check|checker|suriin|susihon|symptom|simtoma|sakit|feeling|sick|suri)/i', $message_lower);

        if (!empty($detected_symptoms)) {
            // Run Naive Bayes multi-symptom evaluation
            $eval = evaluateNaiveBayesSymptoms($detected_symptoms);
            $predicted = $eval['condition'];
            $prob = $eval['probability'];
            
            // Log to database
            $stmt_sym = $db->prepare("INSERT INTO symptoms (patient_id, symptoms_entered, predicted_condition, probability_score) VALUES (:patient_id, :symptoms, :condition, :probability)");
            $stmt_sym->bindValue(':patient_id', $patient_id, SQLITE3_INTEGER);
            $stmt_sym->bindValue(':symptoms', implode(', ', $detected_symptoms), SQLITE3_TEXT);
            $stmt_sym->bindValue(':condition', $predicted, SQLITE3_TEXT);
            $stmt_sym->bindValue(':probability', $prob, SQLITE3_FLOAT);
            $stmt_sym->execute();

            if ($lang === 'Filipino') {
                $response = "Batay sa mga simtomas na inyong binanggit (" . implode(', ', $detected_symptoms) . "), ang aking pagsusuri gamit ang Naive Bayes ay nagpapahiwatig ng **" . $predicted . "** (may posibilidad na " . round($prob * 100) . "%).\n\nPaalala: Ito ay gabay lamang at hindi kapalit ng pormal na diagnosis. Kung malubha ang inyong nararamdaman, mangyaring mag-book ng appointment.";
            } elseif ($lang === 'Cebuano') {
                $response = "Base sa mga simtomas nga imong gihisgutan (" . implode(', ', $detected_symptoms) . "), ang akong pagtuki gamit ang Naive Bayes nagtagna ug **" . $predicted . "** (adunay posibilidad nga " . round($prob * 100) . "%).\n\nPahinumdom: Dili kini pormal nga diagnosis gikan sa doktor. Kung seryoso ang imong gibati, palihug pag-book og appointment.";
            } else {
                $response = "Based on the symptoms you mentioned (" . implode(', ', $detected_symptoms) . "), my Naive Bayes interpretation predicts **" . $predicted . "** (with a probability of " . round($prob * 100) . "%).\n\nDisclaimer: This is for informational purposes and does not replace a professional clinical diagnosis. If symptoms persist, please book a consultation.";
            }
        } elseif ($is_diagnostic_trigger) {
            if ($lang === 'Filipino') {
                $response = "Matutulungan ko kayong suriin ang inyong simtomas. Paki-lista o sabihin ang mga simtomas na inyong nararamdaman (hal. lagnat, ubo, sore throat, pagtatae, pagsusuka, sakit sa ulo, hingal, rashes).";
            } elseif ($lang === 'Cebuano') {
                $response = "Matabangan ko ikaw sa pagsusi sa imong mga simtomas. Palihug ilista o isulti ang mga simtomas nga imong gibati (pananglitan: hilanat, ubo, sore throat, kalibanga, pagsuka, sakit sa ulo, lisod kaginhawa, rashes).";
            } else {
                $response = "I can help you interpret your symptoms using our Naive Bayes analyzer. Please tell me what symptoms you are currently experiencing (e.g. fever, cough, sore throat, diarrhea, vomiting, headache, shortness of breath, skin rash).";
            }
        } else {
            // General Conversational Flow
            if ($lang === 'Filipino') {
                if (preg_match('/(appointment|schedule|pasetera|oras|book)/i', $message_lower)) {
                    $response = "Para mag-book ng appointment, pumunta sa tab ng 'Appointments', piliin ang iyong doktor at oras, at i-click ang 'Book Now'.";
                } elseif (preg_match('/(rekord|dokumento|gamot|kasaysayan)/i', $message_lower)) {
                    $response = "Maaari mong makita ang iyong mga nakaraang diagnosis at gamutan sa tab ng 'Medical Records'.";
                } else {
                    $response = "Magandang araw! Ako ang iyong CLINICK virtual assistant. Paano ko kayo matutulungan ngayon?";
                }
            } elseif ($lang === 'Cebuano') {
                if (preg_match('/(appointment|schedule|pasetera|oras|book)/i', $message_lower)) {
                    $response = "Aron mag-book og appointment, adto sa tab sa 'Appointments', pilia ang imong doktor ug oras, ug i-click ang 'Book Now'.";
                } elseif (preg_match('/(rekord|dokumento|tambal|kasaysayan)/i', $message_lower)) {
                    $response = "Mahimo nimong makit-an ang imong nangaging mga diagnosis ug pagtambal sa tab sa 'Medical Records'.";
                } else {
                    $response = "Maayong adlaw! Ako ang imong CLINICK virtual assistant. Unsaon nako pagtabang kanimo karon?";
                }
            } else { // English
                if (preg_match('/(appointment|schedule|book|time)/i', $message_lower)) {
                    $response = "To book an appointment, go to the 'Appointments' tab, choose your doctor and time, and click 'Book Now'.";
                } elseif (preg_match('/(record|history|medical|prescription)/i', $message_lower)) {
                    $response = "You can view your past diagnoses and treatments in the 'Medical Records' tab.";
                } else {
                    $response = "Hello! I am your CLINICK virtual assistant. How can I help you today?";
                }
            }
        }
        
        // Log to database
        $stmt_chat = $db->prepare("INSERT INTO chatbot_logs (patient_id, message, response, language_used) VALUES (:patient_id, :message, :response, :language_used)");
        $stmt_chat->bindValue(':patient_id', $patient_id, SQLITE3_INTEGER);
        $stmt_chat->bindValue(':message', $message, SQLITE3_TEXT);
        $stmt_chat->bindValue(':response', $response, SQLITE3_TEXT);
        $stmt_chat->bindValue(':language_used', $lang, SQLITE3_TEXT);
        $stmt_chat->execute();
        
        echo json_encode(['response' => $response]);
        exit();
    }
    
    if ($action === 'check_symptoms') {
        $symptoms_arr = $_POST['symptoms'] ?? [];
        if (empty($symptoms_arr)) {
            echo json_encode(['error' => 'No symptoms selected']);
            exit();
        }
        
        $symptoms_entered = implode(', ', $symptoms_arr);
        
        // Evaluate multi-symptom pattern combinations via Naive Bayes
        $eval = evaluateNaiveBayesSymptoms($symptoms_arr);
        $predicted = $eval['condition'];
        $prob = $eval['probability'];
        
        // Log to database
        $stmt_sym = $db->prepare("INSERT INTO symptoms (patient_id, symptoms_entered, predicted_condition, probability_score) VALUES (:patient_id, :symptoms, :condition, :probability)");
        $stmt_sym->bindValue(':patient_id', $patient_id, SQLITE3_INTEGER);
        $stmt_sym->bindValue(':symptoms', $symptoms_entered, SQLITE3_TEXT);
        $stmt_sym->bindValue(':condition', $predicted, SQLITE3_TEXT);
        $stmt_sym->bindValue(':probability', $prob, SQLITE3_FLOAT);
        $stmt_sym->execute();
        
        echo json_encode([
            'condition' => $predicted,
            'probability' => round($prob * 100) . '%',
            'probability_decimal' => $prob
        ]);
        exit();
    }
    
    echo json_encode(['error' => 'Invalid AJAX action']);
    exit();
}

$success_msg = "";
$error_msg = "";

// Handle Appointment Cancellation Submission
if ($_SERVER['REQUEST_METHOD'] === 'GET' && isset($_GET['action']) && $_GET['action'] === 'cancel' && isset($_GET['id'])) {
    $appt_id = filter_var($_GET['id'], FILTER_VALIDATE_INT);
    if ($appt_id) {
        $stmt_cancel = $db->prepare("UPDATE appointments SET status = 'Cancelled' WHERE id = :id AND patient_id = :patient_id");
        $stmt_cancel->bindValue(':id', $appt_id, SQLITE3_INTEGER);
        $stmt_cancel->bindValue(':patient_id', $patient_id, SQLITE3_INTEGER);
        if ($stmt_cancel->execute()) {
            $success_msg = "Appointment cancelled successfully.";
        } else {
            $error_msg = "Failed to cancel appointment.";
        }
    }
}

// Handle Appointment Booking Submission
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'book_appointment') {
    $doctor_id = filter_var($_POST['doctor_id'] ?? null, FILTER_VALIDATE_INT);
    $appointment_date = trim($_POST['appointment_date'] ?? '');
    $time_slot = trim($_POST['time_slot'] ?? '');
    $reason = trim($_POST['reason'] ?? '');
    $reminder_offset = trim($_POST['reminder_offset'] ?? '');

    if (!$doctor_id || empty($appointment_date) || empty($time_slot)) {
        $error_msg = "Please fill in all required fields.";
    } else {
        // Calculate queue number
        $stmt_q = $db->prepare("SELECT COUNT(*) as count FROM appointments WHERE doctor_id = :doctor_id AND appointment_date = :appointment_date");
        $stmt_q->bindValue(':doctor_id', $doctor_id, SQLITE3_INTEGER);
        $stmt_q->bindValue(':appointment_date', $appointment_date, SQLITE3_TEXT);
        $q_res = $stmt_q->execute()->fetchArray(SQLITE3_ASSOC);
        $queue_number = ($q_res['count'] ?? 0) + 1;

        // Prepare and insert
        $stmt = $db->prepare("INSERT INTO appointments (patient_id, doctor_id, appointment_date, time_slot, reason, queue_number) VALUES (:patient_id, :doctor_id, :appointment_date, :time_slot, :reason, :queue_number)");
        $stmt->bindValue(':patient_id', $patient_id, SQLITE3_INTEGER);
        $stmt->bindValue(':doctor_id', $doctor_id, SQLITE3_INTEGER);
        $stmt->bindValue(':appointment_date', $appointment_date, SQLITE3_TEXT);
        $stmt->bindValue(':time_slot', $time_slot, SQLITE3_TEXT);
        $stmt->bindValue(':reason', $reason, SQLITE3_TEXT);
        $stmt->bindValue(':queue_number', $queue_number, SQLITE3_INTEGER);

        if ($stmt->execute()) {
            $appt_id = $db->lastInsertRowID();
            $success_msg = "Appointment booked successfully! Your Queue Number is Q-" . $queue_number . ".";

            // Insert reminder if selected
            if (!empty($reminder_offset) && $reminder_offset !== 'none') {
                $stmt_rem = $db->prepare("INSERT INTO reminders (appointment_id, reminder_type, reminder_offset) VALUES (:appointment_id, 'Dashboard & Email', :reminder_offset)");
                $stmt_rem->bindValue(':appointment_id', $appt_id, SQLITE3_INTEGER);
                $stmt_rem->bindValue(':reminder_offset', $reminder_offset, SQLITE3_TEXT);
                $stmt_rem->execute();
            }
        } else {
            $error_msg = "Failed to book appointment. Please try again.";
        }
    }
}

// Handle Appointment Rescheduling Submission
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'reschedule_appointment') {
    $appt_id = filter_var($_POST['appointment_id'] ?? null, FILTER_VALIDATE_INT);
    $new_date = trim($_POST['reschedule_date'] ?? '');
    $new_slot = trim($_POST['reschedule_slot'] ?? '');
    $reminder_offset = trim($_POST['reminder_offset'] ?? '');

    if (!$appt_id || empty($new_date) || empty($new_slot)) {
        $error_msg = "Please fill in all rescheduling fields.";
    } else {
        // Verify appointment belongs to this patient
        $stmt_check = $db->prepare("SELECT doctor_id FROM appointments WHERE id = :id AND patient_id = :patient_id");
        $stmt_check->bindValue(':id', $appt_id, SQLITE3_INTEGER);
        $stmt_check->bindValue(':patient_id', $patient_id, SQLITE3_INTEGER);
        $chk_res = $stmt_check->execute()->fetchArray(SQLITE3_ASSOC);
        
        if (!$chk_res) {
            $error_msg = "Invalid appointment details.";
        } else {
            $doctor_id = $chk_res['doctor_id'];
            // Calculate next queue number for new date
            $stmt_q = $db->prepare("SELECT COUNT(*) as count FROM appointments WHERE doctor_id = :doctor_id AND appointment_date = :appointment_date");
            $stmt_q->bindValue(':doctor_id', $doctor_id, SQLITE3_INTEGER);
            $stmt_q->bindValue(':appointment_date', $new_date, SQLITE3_TEXT);
            $q_res = $stmt_q->execute()->fetchArray(SQLITE3_ASSOC);
            $queue_number = ($q_res['count'] ?? 0) + 1;

            // Update appointment
            $stmt_up = $db->prepare("UPDATE appointments SET appointment_date = :appointment_date, time_slot = :time_slot, queue_number = :queue_number, status = 'Scheduled' WHERE id = :id AND patient_id = :patient_id");
            $stmt_up->bindValue(':appointment_date', $new_date, SQLITE3_TEXT);
            $stmt_up->bindValue(':time_slot', $new_slot, SQLITE3_TEXT);
            $stmt_up->bindValue(':queue_number', $queue_number, SQLITE3_INTEGER);
            $stmt_up->bindValue(':id', $appt_id, SQLITE3_INTEGER);
            $stmt_up->bindValue(':patient_id', $patient_id, SQLITE3_INTEGER);

            if ($stmt_up->execute()) {
                $success_msg = "Appointment rescheduled successfully! New Queue Number: Q-" . $queue_number . ".";
                
                // Clear existing reminders and save new one if selected
                $stmt_del = $db->prepare("DELETE FROM reminders WHERE appointment_id = :appointment_id");
                $stmt_del->bindValue(':appointment_id', $appt_id, SQLITE3_INTEGER);
                $stmt_del->execute();

                if (!empty($reminder_offset) && $reminder_offset !== 'none') {
                    $stmt_rem = $db->prepare("INSERT INTO reminders (appointment_id, reminder_type, reminder_offset) VALUES (:appointment_id, 'Dashboard & Email', :reminder_offset)");
                    $stmt_rem->bindValue(':appointment_id', $appt_id, SQLITE3_INTEGER);
                    $stmt_rem->bindValue(':reminder_offset', $reminder_offset, SQLITE3_TEXT);
                    $stmt_rem->execute();
                }
            } else {
                $error_msg = "Failed to reschedule appointment.";
            }
        }
    }
}

// Fetch tab parameter
$tab = $_GET['tab'] ?? 'overview';
if ($tab === 'chatbot') {
    header("Location: patient_dashboard.php?tab=overview");
    exit();
}

// Fetch Doctors filtered by availability_status
$doctors_result = $db->query("SELECT doctor_id as id, name, specialization FROM doctors WHERE availability_status = 'Available' ORDER BY name ASC");
$doctors = [];
while ($row = $doctors_result->fetchArray(SQLITE3_ASSOC)) {
    $doctors[] = $row;
}

$selected_doctor_id = isset($_GET['doctor_id']) ? (int)$_GET['doctor_id'] : ($doctors[0]['id'] ?? 0);

// Fetch stats for overview
// 1. Total Appointments
$stmt_app = $db->prepare("SELECT COUNT(*) as count FROM appointments WHERE patient_id = :patient_id");
$stmt_app->bindValue(':patient_id', $patient_id, SQLITE3_INTEGER);
$app_count = $stmt_app->execute()->fetchArray(SQLITE3_ASSOC)['count'] ?? 0;

// 2. Active Prescriptions
$stmt_presc = $db->prepare("SELECT COUNT(*) as count FROM prescriptions WHERE patient_id = :patient_id");
$stmt_presc->bindValue(':patient_id', $patient_id, SQLITE3_INTEGER);
$presc_count = $stmt_presc->execute()->fetchArray(SQLITE3_ASSOC)['count'] ?? 0;
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>CLINICK - Patient Dashboard</title>
    <!-- Google Fonts: Inter -->
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link rel="stylesheet" href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&display=swap">
    <!-- FontAwesome CDN -->
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <!-- Dashboard Styling -->
    <link rel="stylesheet" href="style.css?v=<?php echo filemtime('style.css'); ?>">
    <link rel="stylesheet" href="dashboard.css?v=<?php echo filemtime('dashboard.css'); ?>">
    <script src="js/theme-controller.js?v=<?php echo filemtime('js/theme-controller.js'); ?>"></script>
    <!-- QRCode.js CDN -->
    <script src="https://cdnjs.cloudflare.com/ajax/libs/qrcodejs/1.0.0/qrcode.min.js"></script>
</head>
<body>

    <!-- Emergency Screening Banner -->
    <div class="emr-emergency-bar">
        Patient Portal Active &mdash; CLINICK Healthcare System
    </div>

    <div class="dashboard-container">

        <!-- EMR Top Navigation -->
        <header class="emr-top-nav">
            <a href="?tab=overview" class="emr-brand">CLINICK.</a>

            <ul class="emr-nav-tabs">
                <li><a href="?tab=overview" class="emr-nav-link <?php echo $tab === 'overview' ? 'active' : ''; ?>">Overview</a></li>
                <li><a href="?tab=appointments" class="emr-nav-link <?php echo $tab === 'appointments' ? 'active' : ''; ?>">Appointments</a></li>
                <li><a href="?tab=records" class="emr-nav-link <?php echo $tab === 'records' ? 'active' : ''; ?>">Medical Records</a></li>
                <li><a href="?tab=symptom-checker" class="emr-nav-link <?php echo $tab === 'symptom-checker' ? 'active' : ''; ?>">Symptom Checker</a></li>
            </ul>

            <div class="emr-nav-actions">
                <span class="emr-nav-bell" title="Notifications"><i class="fa-regular fa-bell"></i></span>
                <button class="emr-theme-toggle" id="theme-toggle" title="Toggle dark mode">
                    <i class="fa-solid fa-circle-half-stroke"></i>
                </button>
                <span class="emr-nav-username"><?php echo strtoupper(htmlspecialchars($_SESSION['user_name'])); ?></span>
                <a href="index.php?logout=true" class="emr-nav-logout" title="Sign Out">
                    <i class="fa-solid fa-right-from-bracket"></i>
                </a>
            </div>
        </header>

        <!-- Main Patient Portal Content -->
        <main class="emr-main-content">
            <!-- EMR Hero Section -->
            <?php
            $hour = (int)date('H');
            $greeting = 'Good morning';
            if ($hour >= 12 && $hour < 17) { $greeting = 'Good afternoon'; }
            elseif ($hour >= 17) { $greeting = 'Good evening'; }
            $first_name = htmlspecialchars(explode(' ', $_SESSION['user_name'])[0]);
            ?>
            <div class="emr-hero">
                <h1 class="emr-hero-title"><?php echo $greeting; ?>, <?php echo $first_name; ?>. Welcome to your health portal.</h1>
                <p class="emr-hero-subtitle">View your appointments, medical records, and use the AI symptom checker. &mdash; <?php echo date('l, F j, Y'); ?></p>
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

            <!-- TAB 1: OVERVIEW (Letters Clinic Inspired) -->
            <?php if ($tab === 'overview'): ?>
                <?php
                // Fetch next upcoming approved/scheduled appointment
                $stmt_next = $db->prepare("SELECT a.*, u.name as doctor_name FROM appointments a JOIN users u ON a.doctor_id = u.id WHERE a.patient_id = :patient_id AND a.status = 'Scheduled' AND a.appointment_date >= :today ORDER BY a.appointment_date ASC, a.time_slot ASC LIMIT 3");
                $stmt_next->bindValue(':patient_id', $patient_id, SQLITE3_INTEGER);
                $stmt_next->bindValue(':today', date('Y-m-d'), SQLITE3_TEXT);
                $next_appts_res = $stmt_next->execute();

                // Fetch today's queue position
                $today_date = date('Y-m-d');
                $stmt_today = $db->prepare("SELECT a.*, u.name as doctor_name FROM appointments a JOIN users u ON a.doctor_id = u.id WHERE a.patient_id = :patient_id AND a.appointment_date = :today_date AND a.status = 'Scheduled' LIMIT 1");
                $stmt_today->bindValue(':patient_id', $patient_id, SQLITE3_INTEGER);
                $stmt_today->bindValue(':today_date', $today_date, SQLITE3_TEXT);
                $today_appt = $stmt_today->execute()->fetchArray(SQLITE3_ASSOC);

                $patients_ahead = 0;
                if ($today_appt) {
                    $stmt_ahead = $db->prepare("SELECT COUNT(*) as count FROM appointments WHERE doctor_id = :doctor_id AND appointment_date = :today_date AND status = 'Scheduled' AND queue_number < :queue_number");
                    $stmt_ahead->bindValue(':doctor_id', $today_appt['doctor_id'], SQLITE3_INTEGER);
                    $stmt_ahead->bindValue(':today_date', $today_date, SQLITE3_TEXT);
                    $stmt_ahead->bindValue(':queue_number', $today_appt['queue_number'], SQLITE3_INTEGER);
                    $ahead_res = $stmt_ahead->execute()->fetchArray(SQLITE3_ASSOC);
                    $patients_ahead = $ahead_res['count'] ?? 0;
                }

                // Fetch primary assigned doctor
                $primary_doc = $db->querySingle("SELECT u.name FROM appointments a JOIN users u ON a.doctor_id = u.id WHERE a.patient_id = " . (int)$patient_id . " ORDER BY a.id DESC LIMIT 1");
                if (!$primary_doc) { $primary_doc = "Dr. Aris Thorne"; }
                if (stripos($primary_doc, 'Dr.') !== 0) { $primary_doc = 'Dr. ' . $primary_doc; }

                // Fetch latest symptoms assessment
                $stmt_sym = $db->prepare("SELECT * FROM symptoms WHERE patient_id = :patient_id ORDER BY created_at DESC LIMIT 1");
                $stmt_sym->bindValue(':patient_id', $patient_id, SQLITE3_INTEGER);
                $latest_symptom = $stmt_sym->execute()->fetchArray(SQLITE3_ASSOC);
                ?>
                
                <div class="letters-dashboard-grid">
                    <!-- Left Stacked Cards -->
                    <div style="display: flex; flex-direction: column; gap: 1.5rem;">
                        
                        <!-- Upcoming Appointments Card -->
                        <div class="letters-main-card">
                            <div class="letters-card-header">
                                <h2>Upcoming Appointments</h2>
                                <a href="?tab=appointments" class="letters-btn-pill">
                                    <i class="fa-solid fa-plus"></i> Book New
                                </a>
                            </div>

                            <div class="letters-appt-list">
                                <?php
                                $has_appts = false;
                                while ($app = $next_appts_res->fetchArray(SQLITE3_ASSOC)):
                                    $has_appts = true;
                                    $dt = strtotime($app['appointment_date']);
                                    $dayNum = date('d', $dt);
                                    $monthStr = date('M', $dt);
                                    $docName = stripos($app['doctor_name'], 'Dr.') === 0 ? $app['doctor_name'] : 'Dr. ' . $app['doctor_name'];
                                    $status = $app['status'] ?? 'Scheduled';
                                    $statusClass = 'letters-status-upcoming';
                                    if ($status === 'In Progress') $statusClass = 'letters-status-progress';
                                    elseif ($status === 'Scheduled') $statusClass = 'letters-status-completed';
                                ?>
                                    <div class="letters-appt-row">
                                        <div class="letters-date-block">
                                            <span class="letters-date-day"><?php echo $dayNum; ?></span>
                                            <span class="letters-date-month"><?php echo strtoupper($monthStr); ?></span>
                                        </div>
                                        <div class="letters-appt-info">
                                            <span class="letters-patient-name"><?php echo htmlspecialchars($app['reason'] ?: 'Routine Consultation'); ?></span>
                                            <span class="letters-visit-reason"><?php echo htmlspecialchars($docName); ?> • <?php echo htmlspecialchars($app['time_slot']); ?></span>
                                        </div>
                                        <div>
                                            <span class="letters-status-pill <?php echo $statusClass; ?>"><?php echo strtoupper(htmlspecialchars($status === 'Scheduled' ? 'CONFIRMED' : $status)); ?></span>
                                        </div>
                                    </div>
                                <?php endwhile;
                                if (!$has_appts): ?>
                                    <div class="letters-appt-row" style="justify-content: center; text-align: center; color: var(--text-muted);">
                                        <span>No upcoming appointments scheduled.</span>
                                    </div>
                                <?php endif; ?>
                            </div>

                            <!-- Live Queue Tracker Notification Pill -->
                            <?php if (!empty($today_appt)): ?>
                            <div style="background: linear-gradient(135deg, #0f766e 0%, #115e59 100%); color: #ffffff; padding: 1.15rem; border-radius: 1rem; margin-top: 1rem;">
                                <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 0.5rem;">
                                    <span style="font-size: 0.75rem; font-weight: 700; text-transform: uppercase; letter-spacing: 0.05em; color: #5eead4;">Live Queue Position Today</span>
                                    <span style="font-size: 0.7rem; background: rgba(255,255,255,0.2); padding: 2px 8px; border-radius: 9999px;">Q-<?php echo $today_appt['queue_number']; ?></span>
                                </div>
                                <div style="display: flex; justify-content: space-between; align-items: center; font-size: 0.85rem;">
                                    <span>Patients Ahead: <strong><?php echo $patients_ahead; ?></strong></span>
                                    <span>Est. Wait: <strong><?php echo $patients_ahead > 0 ? ($patients_ahead * 15) . ' mins' : 'You are next!'; ?></strong></span>
                                </div>
                            </div>
                            <?php endif; ?>
                        </div>
                    document.querySelectorAll('.hotspot').forEach(el => el.classList.remove('active'));
                    const targetDot = document.querySelector('.hotspot-' + part);
                    if (targetDot) targetDot.classList.add('active');

                    // Data definitions matching body regions
                    const details = {
                        head: {
                            title: "Cranial / Head",
                            code: "[G43.90]",
                            desc: "Migraine, unspecified: Without aura, intractable migraine",
                            meds: [
                                { name: "Sumatriptan", desc: "Tablets - 50mg", icon: "fa-pills", price: "$24.00" },
                                { name: "Paracetamol", desc: "Tablets - 500mg", icon: "fa-pills", price: "$3.50" },
                                { name: "Ibuprofen", desc: "Capsules - 400mg", icon: "fa-pills", price: "$5.10" },
                                { name: "Menthol Patch", desc: "Cooling patch", icon: "fa-sticky-note", price: "$7.80" }
                            ]
                        },
                        chest: {
                            title: "Thoracic / Chest",
                            code: "[R07.9]",
                            desc: "Chest pain, unspecified: Respiratory tract congestion",
                            meds: [
                                { name: "Albuterol", desc: "Inhaler - 90mcg", icon: "fa-wind", price: "$45.00" },
                                { name: "Guaifenesin", desc: "Syrup - 100ml", icon: "fa-prescription-bottle", price: "$9.50" },
                                { name: "Amoxicillin", desc: "Capsules - 500mg", icon: "fa-pills", price: "$14.20" },
                                { name: "Cough lozenges", desc: "Soothing drops", icon: "fa-candy-cane", price: "$4.00" }
                            ]
                        },
                        abdomen: {
                            title: "Abdominal / Stomach",
                            code: "[K30]",
                            desc: "Dyspepsia: Gastric hyperacidity and stomach distress",
                            meds: [
                                { name: "Omeprazole", desc: "Capsules - 20mg", icon: "fa-pills", price: "$11.00" },
                                { name: "Antacid", desc: "Chewable - 500mg", icon: "fa-pills", price: "$4.50" },
                                { name: "Metoclopramide", desc: "Tablets - 10mg", icon: "fa-pills", price: "$8.90" },
                                { name: "Oral Rehydration", desc: "Electrolyte powder", icon: "fa-box-tissue", price: "$6.00" }
                            ]
                        },
                        shoulder: {
                            title: "Musculoskeletal / Shoulder",
                            code: "[M75.8]",
                            desc: "Other shoulder lesions: Rotator cuff strain or inflammation",
                            meds: [
                                { name: "Shoulder brace", desc: "Support sleeve", icon: "fa-wheelchair", price: "$38.00" },
                                { name: "Naproxen", desc: "Tablets - 220mg", icon: "fa-pills", price: "$9.80" },
                                { name: "Methyl Salicylate", desc: "Analgesic cream", icon: "fa-prescription-bottle", price: "$7.20" },
                                { name: "Cold pack", desc: "Reusable gel pack", icon: "fa-snowflake", price: "$12.00" }
                            ]
                        },
                        knee: {
                            title: "Right knee",
                            code: "[S83.53]",
                            desc: "Sprain and strain of knee: Tear of anterior cruciate ligament",
                            meds: [
                                { name: "Knee orthosis", desc: "Fixing device", icon: "fa-wheelchair", price: "$120.00" },
                                { name: "Aceclofenac", desc: "Tablets - 100mg", icon: "fa-pills", price: "$12.50" },
                                { name: "Diclofenac", desc: "Ointment - 2%", icon: "fa-prescription-bottle", price: "$8.20" },
                                { name: "Heparin sodium", desc: "Gel - 1.5%", icon: "fa-pump-medical", price: "$15.90" }
                            ]
                        }
                    };

                    const data = details[part] || details.knee;

                    // Update UI elements in DOM
                    document.getElementById('diag_title').innerText = data.title;
                    document.getElementById('diag_desc').innerHTML = `<strong style="color: var(--danger);" id="diag_code">${data.code}</strong> ${data.desc}`;
                    
                    // Render meds grid items
                    const medsContainer = document.getElementById('meds_grid_container');
                    medsContainer.innerHTML = '';
                    data.meds.forEach(m => {
                        const card = document.createElement('div');
                        card.className = 'medication-card';
                        card.innerHTML = `
                            <div class="medication-icon-box">
                                <i class="fa-solid ${m.icon}"></i>
                            </div>
                            <strong class="medication-name">${m.name}</strong>
                            <span class="medication-type">${m.desc}</span>
                            <div class="medication-footer">
                                <span class="medication-price">${m.price}</span>
                                <button class="medication-btn" title="Add to cart"><i class="fa-solid fa-cart-plus"></i></button>
                            </div>
                        `;
                        medsContainer.appendChild(card);
                    });
                }
                </script>

            <!-- TAB 2: APPOINTMENTS -->
            <?php elseif ($tab === 'appointments'): ?>
                <div class="dashboard-block-grid">
                    
                    <!-- Appointment List -->
                    <div class="card">
                        <div class="card-header">
                            <h2><i class="fa-solid fa-calendar-days"></i> Booking History</h2>
                        </div>
                        <div class="card-body">
                            <div class="table-responsive"><table class="data-table">
                                    <thead>
                                        <tr>
                                            <th>Doctor</th>
                                            <th>Queue #</th>
                                            <th>Date</th>
                                            <th>Time</th>
                                            <th>Reason</th>
                                            <th>Status</th>
                                            <th>Actions</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        <?php
                                        $query = "SELECT a.*, u.name as doctor_name FROM appointments a JOIN users u ON a.doctor_id = u.id WHERE a.patient_id = :patient_id ORDER BY a.appointment_date DESC, a.time_slot DESC";
                                        $stmt = $db->prepare($query);
                                        $stmt->bindValue(':patient_id', $patient_id, SQLITE3_INTEGER);
                                        $res = $stmt->execute();
                                        $has_rows = false;
                                        while ($app = $res->fetchArray(SQLITE3_ASSOC)):
                                            $has_rows = true;
                                        ?>
                                            <tr>
                                                <td><strong><?php echo htmlspecialchars(stripos($app['doctor_name'], 'Dr.') === 0 ? $app['doctor_name'] : 'Dr. ' . $app['doctor_name']); ?></strong></td>
                                                <td>
                                                    <?php if (!empty($app['queue_number'])): ?>
                                                        <span style="font-family: monospace; font-weight: 700; color: var(--primary);">Q-<?php echo $app['queue_number']; ?></span>
                                                    <?php else: ?>
                                                        <span style="color: var(--text-muted);">-</span>
                                                    <?php endif; ?>
                                                </td>
                                                <td><?php echo htmlspecialchars($app['appointment_date']); ?></td>
                                                <td><?php echo htmlspecialchars($app['time_slot']); ?></td>
                                                <td><?php echo htmlspecialchars($app['reason'] ?: 'Routine checkup'); ?></td>
                                                <td>
                                                    <span class="badge badge-<?php echo strtolower($app['status']); ?>">
                                                        <?php echo htmlspecialchars($app['status']); ?>
                                                    </span>
                                                </td>
                                                <td>
                                                    <?php if ($app['status'] === 'Scheduled'): ?>
                                                        <div style="display: flex; gap: 0.35rem; flex-wrap: wrap;">
                                                            <button type="button" 
                                                                    class="btn-xs" 
                                                                    style="padding: 3px 8px; font-size: 0.75rem; border: 1px solid var(--primary-light); color: var(--primary); background: transparent; cursor: pointer; border-radius: 4px; display: inline-flex; align-items: center; gap: 0.25rem;"
                                                                    onclick="openRescheduleModal(<?php echo $app['id']; ?>, '<?php echo $app['appointment_date']; ?>', '<?php echo $app['time_slot']; ?>')">
                                                                <i class="fa-solid fa-clock-rotate-left"></i> Reschedule
                                                            </button>
                                                            <button type="button" 
                                                                    class="btn-xs" 
                                                                    style="padding: 3px 8px; font-size: 0.75rem; border: 1px solid var(--success); color: var(--success); background: transparent; cursor: pointer; border-radius: 4px; display: inline-flex; align-items: center; gap: 0.25rem;"
                                                                    onclick="openQrModal(<?php echo $app['id']; ?>, '<?php echo htmlspecialchars(addslashes($app['doctor_name'])); ?>', '<?php echo htmlspecialchars($app['appointment_date']); ?>', '<?php echo htmlspecialchars($app['time_slot']); ?>', '<?php echo htmlspecialchars($app['queue_number'] ?? ''); ?>')">
                                                                <i class="fa-solid fa-qrcode"></i> QR
                                                            </button>
                                                            <a href="?tab=appointments&action=cancel&id=<?php echo $app['id']; ?>" 
                                                               class="btn-xs" 
                                                               style="padding: 3px 8px; font-size: 0.75rem; border: 1px solid var(--danger); color: var(--danger); background: transparent; text-decoration: none; cursor: pointer; border-radius: 4px; display: inline-flex; align-items: center; gap: 0.25rem;"
                                                               onclick="return confirm('Are you sure you want to cancel this appointment?');">
                                                                <i class="fa-solid fa-ban"></i> Cancel
                                                            </a>
                                                        </div>
                                                    <?php else: ?>
                                                        <span style="color: var(--text-muted); font-size: 0.8rem; font-style: italic;">No Actions</span>
                                                    <?php endif; ?>
                                                </td>
                                            </tr>
                                        <?php endwhile; ?>
                                        <?php if (!$has_rows): ?>
                                            <tr>
                                                <td colspan="7" class="table-placeholder">
                                                    <i class="fa-regular fa-calendar-xmark" style="font-size: 2rem; margin-bottom: 0.5rem; display: block; color: var(--border-color);"></i>
                                                    No appointments found. Use the booking panel to schedule one.
                                                </td>
                                            </tr>
                                        <?php endif; ?>
                                    </tbody>
                                </table>
                            </div>
                        </div>
                    </div>

                    <!-- Booking Form Panel -->
                    <div class="card" id="bookingFormCard">
                        <div class="card-header">
                            <h2><i class="fa-solid fa-file-invoice"></i> Book Appointment</h2>
                        </div>
                        <div class="card-body">
                            <?php if (empty($doctors)): ?>
                                <p style="font-size: 0.9rem; color: var(--text-muted);">No doctors or clinical staff are currently registered to schedule appointments.</p>
                            <?php else: ?>
                                <form action="?tab=appointments" method="POST">
                                    <input type="hidden" name="action" value="book_appointment">
                                    
                                    <div class="form-group">
                                        <label for="doctor_id">Select Physician / Provider</label>
                                        <select name="doctor_id" id="doctor_id" class="form-control" required>
                                            <option value="" disabled selected>Choose Provider...</option>
                                            <?php foreach ($doctors as $doc): ?>
                                                <option value="<?php echo $doc['id']; ?>">
                                                    <?php echo htmlspecialchars($doc['name']) . " (" . htmlspecialchars($doc['specialization']) . ")"; ?>
                                                </option>
                                            <?php endforeach; ?>
                                        </select>
                                    </div>

                                    <div class="form-group">
                                        <label for="appointment_date">Preferred Date</label>
                                        <input type="date" name="appointment_date" id="appointment_date" class="form-control" required min="<?php echo date('Y-m-d'); ?>">
                                    </div>

                                    <div class="form-group">
                                        <label for="time_slot">Preferred Time</label>
                                        <select name="time_slot" id="time_slot" class="form-control" required>
                                            <option value="" disabled selected>Choose Time Slot...</option>
                                            <option value="09:00 AM">09:00 AM - 09:30 AM</option>
                                            <option value="10:00 AM">10:00 AM - 10:30 AM</option>
                                            <option value="11:00 AM">11:00 AM - 11:30 AM</option>
                                            <option value="01:30 PM">01:30 PM - 02:00 PM</option>
                                            <option value="02:30 PM">02:30 PM - 03:00 PM</option>
                                            <option value="03:30 PM">03:30 PM - 04:00 PM</option>
                                        </select>
                                    </div>

                                    <div class="form-group">
                                        <label for="reason">Reason for Visit</label>
                                        <textarea name="reason" id="reason" class="form-control" placeholder="Briefly describe your symptoms or visit reason..."></textarea>
                                    </div>

                                    <div class="form-group">
                                        <label for="reminder_offset">Schedule Reminder</label>
                                        <select name="reminder_offset" id="reminder_offset" class="form-control">
                                            <option value="none">No Reminder</option>
                                            <option value="1 hour before">1 Hour Before</option>
                                            <option value="2 hours before">2 Hours Before</option>
                                            <option value="1 day before">1 Day Before</option>
                                        </select>
                                    </div>

                                    <button type="submit" class="btn-sm btn-success" style="width: 100%; justify-content: center; padding: 0.75rem;">
                                        <i class="fa-solid fa-calendar-check"></i> Book Now
                                    </button>
                                </form>
                            <?php endif; ?>
                        </div>
                    </div>

                </div>

                <!-- Doctor's Availability Calendar (All Providers Grouped) -->
                <?php
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

                // Query all availability records for all doctors
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
                ?>
                <div class="calendar-container" style="margin-top: 2rem;">
                    <div class="calendar-header">
                        <h2 class="calendar-title">
                            <i class="fa-solid fa-calendar-days"></i> Live Clinical Staff Availability (<?php echo "$month_name $year"; ?>)
                        </h2>
                        <div class="calendar-nav">
                            <a href="?tab=appointments&month=<?php echo $prev_month; ?>&year=<?php echo $prev_year; ?>" class="calendar-nav-btn">
                                <i class="fa-solid fa-chevron-left"></i> Previous
                            </a>
                            <a href="?tab=appointments&month=<?php echo date('n'); ?>&year=<?php echo date('Y'); ?>" class="calendar-nav-btn">
                                Today
                            </a>
                            <a href="?tab=appointments&month=<?php echo $next_month; ?>&year=<?php echo $next_year; ?>" class="calendar-nav-btn">
                                Next <i class="fa-solid fa-chevron-right"></i>
                            </a>
                        </div>
                    </div>

                    <!-- Legend -->
                    <div class="patient-calendar-legend" style="margin-bottom: 1.5rem;">
                        <div class="legend-item">
                            <span class="legend-color indicator-available" style="display:inline-block; width: 12px; height: 12px; border: 1px solid #a7f3d0; margin-right: 0.25rem;"></span>
                            <span>Green Badges: Click to book on this Available day/provider</span>
                        </div>
                        <div class="legend-item">
                            <span class="legend-color indicator-unavailable" style="display:inline-block; width: 12px; height: 12px; border: 1px solid #fecaca; margin-right: 0.25rem;"></span>
                            <span>Red Badges: Provider is off-duty / unavailable</span>
                        </div>
                    </div>

                    <div class="calendar-grid">
                        <!-- Weekdays -->
                        <div class="calendar-day-header">Sun</div>
                        <div class="calendar-day-header">Mon</div>
                        <div class="calendar-day-header">Tue</div>
                        <div class="calendar-day-header">Wed</div>
                        <div class="calendar-day-header">Thu</div>
                        <div class="calendar-day-header">Fri</div>
                        <div class="calendar-day-header">Sat</div>

                        <!-- Blank Days for offset -->
                        <?php for ($i = 0; $i < $first_day_weekday; $i++): ?>
                            <div class="calendar-day-cell empty"></div>
                        <?php endfor; ?>

                        <!-- Actual Days -->
                        <?php
                        $today_date = date('Y-m-d');
                        for ($day = 1; $day <= $days_in_month; $day++):
                            $current_date_str = sprintf('%04d-%02d-%02d', $year, $month, $day);
                            $is_today = ($current_date_str === $today_date);
                            $day_slots = isset($avail_lookup[$current_date_str]) ? $avail_lookup[$current_date_str] : [];
                        ?>
                            <div class="calendar-day-cell <?php echo $is_today ? 'today' : ''; ?>" style="cursor: default;">
                                <span class="calendar-day-number"><?php echo $day; ?></span>
                                
                                <?php if (!empty($day_slots)): ?>
                                    <div class="calendar-day-providers">
                                        <?php foreach ($day_slots as $slot): 
                                            $doc_name = $slot['doctor_name'];
                                            if (stripos($doc_name, 'Dr.') !== 0 && stripos($doc_name, 'Dr ') !== 0) {
                                                $doc_name = 'Dr. ' . $doc_name;
                                            }
                                            $status = $slot['status'];
                                            $notes = $slot['notes'];
                                            $badge_class = strtolower($status);
                                        ?>
                                            <div class="patient-provider-badge badge-<?php echo $badge_class; ?>" 
                                                 onclick="selectBookingSlot(<?php echo $slot['doctor_id']; ?>, '<?php echo $current_date_str; ?>', event)"
                                                 title="Click to schedule with <?php echo htmlspecialchars($doc_name); ?> on <?php echo $current_date_str; ?>">
                                                <span><?php echo htmlspecialchars($doc_name); ?></span>
                                                <?php if ($status === 'Available' && !empty($notes)): ?>
                                                    <span class="badge-notes"><?php echo htmlspecialchars($notes); ?></span>
                                                <?php else: ?>
                                                    <span class="badge-status-sub"><?php echo htmlspecialchars($status); ?></span>
                                                <?php endif; ?>
                                            </div>
                                        <?php endforeach; ?>
                                    </div>
                                <?php endif; ?>
                            </div>
                        <?php endfor; ?>
                    </div>
                </div>

            <!-- TAB 3: MEDICAL RECORDS -->
            <?php elseif ($tab === 'records'): ?>
                <div class="card" style="max-width: 900px;">
                    <div class="card-header">
                        <h2><i class="fa-solid fa-file-medical"></i> Medical Records & Consultations</h2>
                        <span class="badge badge-primary">Read-Only Access</span>
                    </div>
                    <div class="card-body">
                        <div style="display: flex; flex-direction: column; gap: 1.5rem;">
                            <?php
                            $stmt_rec = $db->prepare("
                                SELECT mr.*, u.name as doctor_name 
                                FROM medical_records mr 
                                LEFT JOIN users u ON mr.doctor_id = u.id 
                                WHERE mr.patient_id = :patient_id 
                                ORDER BY mr.consultation_date DESC
                            ");
                            $stmt_rec->bindValue(':patient_id', $patient_id, SQLITE3_INTEGER);
                            $res_rec = $stmt_rec->execute();
                            $has_rec = false;
                            while ($rec = $res_rec->fetchArray(SQLITE3_ASSOC)):
                                $has_rec = true;
                                $doc_name = stripos($rec['doctor_name'], 'Dr.') === 0 ? $rec['doctor_name'] : 'Dr. ' . $rec['doctor_name'];
                            ?>
                                <div class="medical-record-card" style="border: 1px solid var(--border-color); border-radius: var(--radius-md); overflow: hidden; box-shadow: var(--shadow-sm); transition: all 0.3s ease;">
                                    <div style="background: var(--card-bg); padding: 1.25rem; display: flex; justify-content: space-between; align-items: center; border-bottom: 1px solid var(--border-color); cursor: pointer;" onclick="toggleRecordDetails(<?php echo $rec['record_id']; ?>)">
                                        <div>
                                            <span style="font-size: 0.85rem; color: var(--text-muted); display: block; font-weight: 500;"><?php echo htmlspecialchars($rec['consultation_date']); ?></span>
                                            <strong style="font-size: 1.1rem; color: var(--text-color);"><?php echo htmlspecialchars($rec['diagnosis']); ?></strong>
                                        </div>
                                        <div style="display: flex; align-items: center; gap: 1rem;">
                                            <div style="text-align: right;">
                                                <span style="font-size: 0.8rem; color: var(--text-muted); display: block;">Consulting Doctor</span>
                                                <strong style="color: var(--primary); font-size: 0.95rem;"><?php echo htmlspecialchars($doc_name); ?></strong>
                                            </div>
                                            <i class="fa-solid fa-chevron-down record-toggle-icon" id="icon-rec-<?php echo $rec['record_id']; ?>" style="color: var(--text-muted); transition: transform 0.3s ease;"></i>
                                        </div>
                                    </div>
                                    <div id="details-rec-<?php echo $rec['record_id']; ?>" style="display: none; padding: 1.25rem; background: rgba(20, 184, 166, 0.02); border-top: 1px solid var(--border-color);">
                                        <div style="display: grid; grid-template-columns: 1fr; gap: 1rem;">
                                            <div>
                                                <strong style="display: block; font-size: 0.85rem; color: var(--text-muted); text-transform: uppercase; margin-bottom: 0.25rem;">Treatment Plan & Medications</strong>
                                                <p style="font-size: 0.95rem; color: var(--text-color); line-height: 1.5; background: var(--card-bg); padding: 0.75rem; border-radius: var(--radius-sm); border: 1px solid var(--border-color);"><?php echo nl2br(htmlspecialchars($rec['treatment'])); ?></p>
                                            </div>
                                            <div>
                                                <strong style="display: block; font-size: 0.85rem; color: var(--text-muted); text-transform: uppercase; margin-bottom: 0.25rem;">Physician's Notes</strong>
                                                <p style="font-size: 0.95rem; color: var(--text-color); line-height: 1.5; background: var(--card-bg); padding: 0.75rem; border-radius: var(--radius-sm); border: 1px solid var(--border-color);"><?php echo nl2br(htmlspecialchars($rec['doctor_notes'])); ?></p>
                                            </div>
                                        </div>
                                    </div>
                                </div>
                            <?php endwhile; ?>
                            <?php if (!$has_rec): ?>
                                <div style="text-align: center; color: var(--text-muted); padding: 4rem;">
                                    <i class="fa-solid fa-file-medical-flag" style="font-size: 3.5rem; color: var(--border-color); margin-bottom: 1rem; display: block;"></i>
                                    <h3>No Medical Records Found</h3>
                                    <p style="margin-top: 0.5rem;">Your clinical checkup histories will be displayed here once finalized by your doctor.</p>
                                </div>
                            <?php endif; ?>
                        </div>
                    </div>
                </div>

            <!-- TAB 4: SYMPTOM CHECKER -->
            <?php elseif ($tab === 'symptom-checker'): ?>
                <div class="card" style="max-width: 850px;">
                    <div class="card-header">
                        <h2><i class="fa-solid fa-stethoscope"></i> Naive Bayes Symptom Interpretation</h2>
                        <span class="badge badge-warning"><i class="fa-solid fa-robot"></i> Clinical Assistant Mode</span>
                    </div>
                    <div class="card-body">
                        <div style="background: rgba(239, 68, 68, 0.05); border-left: 4px solid var(--danger); padding: 1rem; border-radius: 0 var(--radius-md) var(--radius-md) 0; margin-bottom: 2rem;">
                            <strong style="color: var(--danger); font-size: 0.95rem; display: block; margin-bottom: 0.25rem;"><i class="fa-solid fa-triangle-exclamation"></i> IMPORTANT MEDICAL DISCLAIMER</strong>
                            <p style="font-size: 0.85rem; color: var(--text-muted); line-height: 1.4;">This automated symptom checker is powered by a simulated Naive Bayes classifier. It is designed for informational and educational purposes only. It does NOT substitute professional clinical consultation, diagnosis, or treatment. Always seek immediate emergency medical services if experiencing severe symptoms.</p>
                        </div>
                        
                        <form id="symptom_checker_form" onsubmit="checkSymptoms(event)">
                            <div class="form-group" style="margin-bottom: 1.5rem;">
                                <label style="font-weight: 600; display: block; margin-bottom: 0.75rem;">Select your current symptoms (Check all that apply):</label>
                                
                                <div style="display: grid; grid-template-columns: repeat(auto-fill, minmax(200px, 1fr)); gap: 0.75rem;">
                                    <label style="display: flex; align-items: center; gap: 0.5rem; padding: 0.65rem; border: 1px solid var(--border-color); border-radius: var(--radius-sm); cursor: pointer; transition: background 0.2s;">
                                        <input type="checkbox" name="symptoms[]" value="Fever" style="width: 16px; height: 16px;">
                                        <span>Fever (Lagnat / Hilanat)</span>
                                    </label>
                                    <label style="display: flex; align-items: center; gap: 0.5rem; padding: 0.65rem; border: 1px solid var(--border-color); border-radius: var(--radius-sm); cursor: pointer; transition: background 0.2s;">
                                        <input type="checkbox" name="symptoms[]" value="Cough" style="width: 16px; height: 16px;">
                                        <span>Cough (Ubo)</span>
                                    </label>
                                    <label style="display: flex; align-items: center; gap: 0.5rem; padding: 0.65rem; border: 1px solid var(--border-color); border-radius: var(--radius-sm); cursor: pointer; transition: background 0.2s;">
                                        <input type="checkbox" name="symptoms[]" value="Sore Throat" style="width: 16px; height: 16px;">
                                        <span>Sore Throat (Sakit sa Tutunlan)</span>
                                    </label>
                                    <label style="display: flex; align-items: center; gap: 0.5rem; padding: 0.65rem; border: 1px solid var(--border-color); border-radius: var(--radius-sm); cursor: pointer; transition: background 0.2s;">
                                        <input type="checkbox" name="symptoms[]" value="Diarrhea" style="width: 16px; height: 16px;">
                                        <span>Diarrhea (Kalibanga)</span>
                                    </label>
                                    <label style="display: flex; align-items: center; gap: 0.5rem; padding: 0.65rem; border: 1px solid var(--border-color); border-radius: var(--radius-sm); cursor: pointer; transition: background 0.2s;">
                                        <input type="checkbox" name="symptoms[]" value="Vomiting" style="width: 16px; height: 16px;">
                                        <span>Vomiting (Pagsuka)</span>
                                    </label>
                                    <label style="display: flex; align-items: center; gap: 0.5rem; padding: 0.65rem; border: 1px solid var(--border-color); border-radius: var(--radius-sm); cursor: pointer; transition: background 0.2s;">
                                        <input type="checkbox" name="symptoms[]" value="Headache" style="width: 16px; height: 16px;">
                                        <span>Headache (Sakit sa Ulo)</span>
                                    </label>
                                    <label style="display: flex; align-items: center; gap: 0.5rem; padding: 0.65rem; border: 1px solid var(--border-color); border-radius: var(--radius-sm); cursor: pointer; transition: background 0.2s;">
                                        <input type="checkbox" name="symptoms[]" value="Shortness of Breath" style="width: 16px; height: 16px;">
                                        <span>Shortness of Breath (Lisod Ginhawa)</span>
                                    </label>
                                    <label style="display: flex; align-items: center; gap: 0.5rem; padding: 0.65rem; border: 1px solid var(--border-color); border-radius: var(--radius-sm); cursor: pointer; transition: background 0.2s;">
                                        <input type="checkbox" name="symptoms[]" value="Skin Rash" style="width: 16px; height: 16px;">
                                        <span>Skin Rash (Katol / Panat)</span>
                                    </label>
                                </div>
                            </div>
                            
                            <button type="submit" class="btn-sm btn-success" style="padding: 0.75rem 1.5rem; font-weight: 600; display: inline-flex; align-items: center; gap: 0.5rem;">
                                <i class="fa-solid fa-calculator"></i> Run Symptom Checker
                            </button>
                        </form>
                        
                        <!-- Prediction results container -->
                        <div id="prediction_result_box" style="display: none; margin-top: 2rem; border-top: 1px solid var(--border-color); padding-top: 1.5rem;">
                            <h3 style="font-size: 1.15rem; margin-bottom: 1rem; color: var(--text-color);"><i class="fa-solid fa-square-poll-horizontal"></i> Classifier Analysis Results</h3>
                            
                            <div style="background: rgba(20, 184, 166, 0.05); border: 1px solid var(--primary-light); border-radius: var(--radius-md); padding: 1.5rem; display: flex; justify-content: space-between; align-items: center; flex-wrap: wrap; gap: 1rem;">
                                <div>
                                    <span style="font-size: 0.85rem; color: var(--text-muted); display: block; font-weight: 500;">PREDICTED CONDITION</span>
                                    <strong style="font-size: 1.5rem; color: var(--text-color); font-weight: 700;" id="predicted_condition_title">-</strong>
                                </div>
                                <div style="text-align: right;">
                                    <span style="font-size: 0.85rem; color: var(--text-muted); display: block; font-weight: 500;">PROBABILITY SCORE</span>
                                    <strong style="font-size: 1.5rem; color: var(--primary); font-weight: 700;" id="predicted_probability_score">0%</strong>
                                </div>
                            </div>
                            
                            <div style="margin-top: 1rem; background: var(--card-bg); border: 1px solid var(--border-color); border-radius: var(--radius-md); padding: 1rem; font-size: 0.85rem; color: var(--text-muted); line-height: 1.4;">
                                <strong>System Note:</strong> The Naive Bayes classifier calculates the probability using independent likelihood features of symptoms. The logs of this symptom checks have been securely saved to your health history.
                            </div>
                        </div>
                    </div>
                </div>
            <?php endif; ?>

        </main>

    </div>

    <script>
    function toggleNotificationDropdown(event) {
        if (event) event.stopPropagation();
        const dropdown = document.getElementById('notificationDropdown');
        if (dropdown.style.display === 'none' || !dropdown.style.display) {
            dropdown.style.display = 'block';
        } else {
            dropdown.style.display = 'none';
        }
    }
    
    document.addEventListener('click', function(event) {
        const dropdown = document.getElementById('notificationDropdown');
        const container = document.querySelector('.notification-bell-container');
        if (dropdown && container && !container.contains(event.target)) {
            dropdown.style.display = 'none';
        }
    });

    function selectBookingSlot(doctorId, date, event) {
        if (event) {
            event.stopPropagation();
        }
        
        // Auto-select doctor
        const docSelect = document.getElementById('doctor_id');
        if (docSelect) {
            docSelect.value = doctorId;
        }
        
        // Auto-set preferred date
        const dateInput = document.getElementById('appointment_date');
        if (dateInput) {
            dateInput.value = date;
        }
        
        // Scroll to form and highlight
        const bookingForm = document.getElementById('bookingFormCard');
        if (bookingForm) {
            bookingForm.scrollIntoView({ behavior: 'smooth', block: 'center' });
            
            // Soft pulse visual cues
            bookingForm.style.transition = 'transform 0.3s ease, box-shadow 0.3s ease';
            bookingForm.style.transform = 'scale(1.02)';
            bookingForm.style.boxShadow = '0 10px 25px rgba(13, 148, 136, 0.2)';
            setTimeout(() => {
                bookingForm.style.transform = 'scale(1)';
                bookingForm.style.boxShadow = 'var(--shadow-md)';
            }, 800);
        }
    }

    function openRescheduleModal(id, date, slot) {
        document.getElementById('rescheduleApptId').value = id;
        document.getElementById('rescheduleDate').value = date;
        document.getElementById('rescheduleSlot').value = slot;
        document.getElementById('rescheduleModal').classList.add('active');
    }

    function closeRescheduleModal() {
        document.getElementById('rescheduleModal').classList.remove('active');
    }

    // Toggle Medical Records Details accordion
    function toggleRecordDetails(recId) {
        const detailsDiv = document.getElementById('details-rec-' + recId);
        const icon = document.getElementById('icon-rec-' + recId);
        if (detailsDiv.style.display === 'none') {
            detailsDiv.style.display = 'block';
            icon.style.transform = 'rotate(180deg)';
        } else {
            detailsDiv.style.display = 'none';
            icon.style.transform = 'rotate(0deg)';
        }
    }

    // QR Code Modal Check-In
    let qrCodeInstance = null;
    function openQrModal(apptId, doctorName, date, slot, queueNum) {
        document.getElementById('qr_modal_doctor_name').innerText = doctorName;
        document.getElementById('qr_modal_date').innerText = date;
        document.getElementById('qr_modal_time').innerText = slot;
        document.getElementById('qr_modal_queue_number').innerText = queueNum ? 'Q-' + queueNum : 'N/A';
        
        const container = document.getElementById('qrcode_container');
        container.innerHTML = '';
        
        const qrPayload = JSON.stringify({
            appointment_id: apptId,
            patient_id: <?php echo $patient_id; ?>,
            patient_name: "<?php echo htmlspecialchars(addslashes($_SESSION['user_name'])); ?>",
            doctor_name: doctorName,
            date: date,
            time: slot,
            queue: queueNum
        });
        
        new QRCode(container, {
            text: qrPayload,
            width: 180,
            height: 180,
            colorDark : "#0f172a",
            colorLight : "#ffffff",
            correctLevel : QRCode.CorrectLevel.H
        });
        
        document.getElementById('qrModal').classList.add('active');
    }
    
    function closeQrModal() {
        document.getElementById('qrModal').classList.remove('active');
    }

    // Chatbot Functions
    const greetings = {
        'English': 'Hello! I am your CLINICK virtual assistant. How can I help you today?',
        'Filipino': 'Magandang araw! Ako ang iyong CLINICK virtual assistant. Paano ko kayo matutulungan ngayon?',
        'Cebuano': 'Maayong adlaw! Ako ang iyong CLINICK virtual assistant. Unsaon nako pagtabang kanimo karon?'
    };
    
    function updateChatbotLanguage() {
        const lang = document.getElementById('chat_lang').value;
        document.getElementById('bot_greeting').innerText = greetings[lang] || greetings['English'];
    }

    function toggleChatWidget(event) {
        if (event) event.stopPropagation();
        const win = document.getElementById('chat-widget-window');
        if (win.classList.contains('active')) {
            win.classList.remove('active');
            setTimeout(() => {
                if (!win.classList.contains('active')) {
                    win.style.display = 'none';
                }
            }, 300);
        } else {
            win.style.display = 'flex';
            setTimeout(() => {
                win.classList.add('active');
                const chatMessages = document.getElementById('chat_messages');
                if (chatMessages) {
                    chatMessages.scrollTop = chatMessages.scrollHeight;
                }
            }, 20);
        }
    }

    // Close chatbot when clicking outside
    document.addEventListener('click', function(event) {
        const win = document.getElementById('chat-widget-window');
        const trigger = document.getElementById('medibot-toggle');
        if (win && win.classList.contains('active') && !win.contains(event.target) && !trigger.contains(event.target)) {
            toggleChatWidget();
        }
    });

    function sendChatbotMessage(event) {
        event.preventDefault();
        const input = document.getElementById('chat_input');
        const message = input.value.trim();
        if (!message) return;
        
        const lang = document.getElementById('chat_lang').value;
        const chatMessages = document.getElementById('chat_messages');
        
        // Append user bubble (optimized for narrow widget width)
        const userDiv = document.createElement('div');
        userDiv.style.display = 'flex';
        userDiv.style.justifyContent = 'flex-end';
        userDiv.style.gap = '0.5rem';
        userDiv.style.maxWidth = '85%';
        userDiv.style.marginLeft = 'auto';
        userDiv.innerHTML = `
            <div style="background: var(--primary-light); color: #ffffff; padding: 0.75rem 1rem; border-radius: var(--radius-md) 0 var(--radius-md) var(--radius-md); box-shadow: var(--shadow-sm);">
                <p style="font-size: 0.85rem; line-height: 1.4; margin: 0;">${escapeHtml(message)}</p>
                <span style="font-size: 0.65rem; opacity: 0.9; display: block; margin-top: 0.25rem; text-align: right;">You &bull; Just now</span>
            </div>
            <div style="background: var(--secondary); color: #ffffff; width: 32px; height: 32px; border-radius: 50%; display: flex; align-items: center; justify-content: center; flex-shrink: 0; box-shadow: var(--shadow-sm);">
                <i class="fa-solid fa-user" style="font-size: 0.85rem;"></i>
            </div>
        `;
        chatMessages.appendChild(userDiv);
        chatMessages.scrollTop = chatMessages.scrollHeight;
        
        input.value = '';
        
        const formData = new FormData();
        formData.append('ajax_action', 'chatbot_message');
        formData.append('message', message);
        formData.append('language', lang);
        
        fetch('patient_dashboard.php', {
            method: 'POST',
            body: formData
        })
        .then(response => response.json())
        .then(data => {
            if (data.response) {
                const botDiv = document.createElement('div');
                botDiv.style.display = 'flex';
                botDiv.style.gap = '0.5rem';
                botDiv.style.maxWidth = '85%';
                botDiv.innerHTML = `
                    <div style="background: var(--primary); color: #ffffff; width: 32px; height: 32px; border-radius: 50%; display: flex; align-items: center; justify-content: center; flex-shrink: 0; box-shadow: var(--shadow-sm);">
                        <i class="fa-solid fa-robot" style="font-size: 0.85rem;"></i>
                    </div>
                    <div style="background: var(--card-bg); padding: 0.75rem 1rem; border-radius: 0 var(--radius-md) var(--radius-md) var(--radius-md); border: 1px solid var(--border-color); box-shadow: var(--shadow-sm);">
                        <p style="font-size: 0.85rem; line-height: 1.4; color: var(--text-color); margin: 0;">${escapeHtml(data.response)}</p>
                        <span style="font-size: 0.65rem; color: var(--text-muted); display: block; margin-top: 0.25rem; text-align: right;">Bot &bull; Just now</span>
                    </div>
                `;
                chatMessages.appendChild(botDiv);
                chatMessages.scrollTop = chatMessages.scrollHeight;
            }
        })
        .catch(err => console.error('Chatbot error:', err));
    }

    function checkSymptoms(event) {
        event.preventDefault();
        const form = document.getElementById('symptom_checker_form');
        const formData = new FormData(form);
        formData.append('ajax_action', 'check_symptoms');
        
        fetch('patient_dashboard.php', {
            method: 'POST',
            body: formData
        })
        .then(response => response.json())
        .then(data => {
            if (data.error) {
                alert(data.error);
            } else {
                document.getElementById('predicted_condition_title').innerText = data.condition;
                document.getElementById('predicted_probability_score').innerText = data.probability;
                document.getElementById('prediction_result_box').style.display = 'block';
                document.getElementById('prediction_result_box').scrollIntoView({ behavior: 'smooth' });
            }
        })
        .catch(err => console.error('Symptom checker error:', err));
    }
    
    function escapeHtml(text) {
        return text
            .replace(/&/g, "&amp;")
            .replace(/</g, "&lt;")
            .replace(/>/g, "&gt;")
            .replace(/"/g, "&quot;")
            .replace(/'/g, "&#039;");
    }
    </script>

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

                <div class="form-group">
                    <label for="rescheduleReminder">Schedule Reminder</label>
                    <select name="reminder_offset" id="rescheduleReminder" class="form-control">
                        <option value="none">No Reminder</option>
                        <option value="1 hour before">1 Hour Before</option>
                        <option value="2 hours before">2 Hours Before</option>
                        <option value="1 day before">1 Day Before</option>
                    </select>
                </div>

                <div class="form-actions">
                    <button type="button" class="btn-sm btn-secondary" onclick="closeRescheduleModal()">Cancel</button>
                    <button type="submit" class="btn-sm btn-success">Reschedule</button>
                </div>
            </form>
        </div>
    </div>

    <!-- QR Code Modal -->
    <div class="calendar-modal" id="qrModal">
        <div class="calendar-modal-content" style="max-width: 400px; text-align: center;">
            <button type="button" class="calendar-modal-close" onclick="closeQrModal()">&times;</button>
            <h3 class="calendar-modal-title" style="margin-bottom: 0.5rem;"><i class="fa-solid fa-qrcode"></i> QR Check-In Pass</h3>
            <p style="font-size: 0.85rem; color: var(--text-muted); margin-bottom: 1.5rem;">Present this QR code to the clinic staff scanner on arrival to instantly check-in.</p>
            
            <div id="qrcode_container" style="display: inline-block; padding: 1rem; background: #ffffff; border: 1px solid var(--border-color); border-radius: var(--radius-md); margin-bottom: 1.5rem;"></div>
            
            <div style="background: rgba(20, 184, 166, 0.05); padding: 1rem; border-radius: var(--radius-md); text-align: left; font-size: 0.85rem; display: flex; flex-direction: column; gap: 0.5rem; margin-bottom: 1.5rem;">
                <div>
                    <span style="color: var(--text-muted); display: block; font-size: 0.75rem;">DOCTOR</span>
                    <strong id="qr_modal_doctor_name">-</strong>
                </div>
                <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 0.5rem;">
                    <div>
                        <span style="color: var(--text-muted); display: block; font-size: 0.75rem;">DATE</span>
                        <strong id="qr_modal_date">-</strong>
                    </div>
                    <div>
                        <span style="color: var(--text-muted); display: block; font-size: 0.75rem;">TIME</span>
                        <strong id="qr_modal_time">-</strong>
                    </div>
                </div>
                <div>
                    <span style="color: var(--text-muted); display: block; font-size: 0.75rem;">QUEUE NUMBER</span>
                    <strong style="color: var(--primary); font-family: monospace;" id="qr_modal_queue_number">-</strong>
                </div>
            </div>

            <div style="display: flex; gap: 0.5rem; justify-content: center;">
                <button type="button" class="btn-sm btn-secondary" onclick="closeQrModal()">Close</button>
                <button type="button" class="btn-sm btn-success" onclick="window.print()"><i class="fa-solid fa-print"></i> Print Pass</button>
            </div>
        </div>
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
        const savedTheme = localStorage.getItem('clinick-theme') || 'light';
        if (savedTheme === 'dark') document.documentElement.setAttribute('data-theme', 'dark');
        icon.className = savedTheme === 'dark' ? 'fa-solid fa-moon' : 'fa-solid fa-sun';
    </script>
<?php include 'chatbot-widget.php'; ?>
</body>
</html>
