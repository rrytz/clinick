<?php
// Secure inclusion check
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

// Verify SQLite3 support
if (!class_exists('SQLite3')) {
    die("SQLite3 extension is not enabled in your PHP installation. Please enable it in your php.ini file.");
}

// Database Connection
try {
    $db_file = __DIR__ . '/clinick.db';
    $db = new SQLite3($db_file);
    
    // Enable foreign keys, WAL mode & busy timeout
    $db->exec("PRAGMA foreign_keys = ON;");
    $db->exec("PRAGMA journal_mode = WAL;");
    $db->exec("PRAGMA synchronous = NORMAL;");
    $db->busyTimeout(10000);
    
    // 1. Users Table
    $db->exec("CREATE TABLE IF NOT EXISTS users (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        name TEXT NOT NULL,
        email TEXT NOT NULL UNIQUE,
        password TEXT NOT NULL,
        role TEXT NOT NULL,
        created_at DATETIME DEFAULT CURRENT_TIMESTAMP
    )");

    // 2. Appointments Table
    $db->exec("CREATE TABLE IF NOT EXISTS appointments (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        patient_id INTEGER NOT NULL,
        doctor_id INTEGER NOT NULL,
        appointment_date TEXT NOT NULL,
        time_slot TEXT NOT NULL,
        reason TEXT,
        status TEXT DEFAULT 'Scheduled',
        created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
        FOREIGN KEY (patient_id) REFERENCES users(id) ON DELETE CASCADE,
        FOREIGN KEY (doctor_id) REFERENCES users(id) ON DELETE CASCADE
    )");

    // 3. Prescriptions Table
    $db->exec("CREATE TABLE IF NOT EXISTS prescriptions (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        patient_id INTEGER NOT NULL,
        doctor_id INTEGER NOT NULL,
        doctor_name TEXT NOT NULL,
        medication TEXT NOT NULL,
        dosage TEXT NOT NULL,
        frequency TEXT NOT NULL,
        created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
        FOREIGN KEY (patient_id) REFERENCES users(id) ON DELETE CASCADE,
        FOREIGN KEY (doctor_id) REFERENCES users(id) ON DELETE CASCADE
    )");

    // 4. Availability Table
    $db->exec("CREATE TABLE IF NOT EXISTS availability (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        doctor_id INTEGER NOT NULL,
        available_date TEXT NOT NULL,
        status TEXT DEFAULT 'Available',
        notes TEXT,
        created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
        UNIQUE(doctor_id, available_date),
        FOREIGN KEY (doctor_id) REFERENCES users(id) ON DELETE CASCADE
    )");

    // 5. Dynamic Schema Upgrades
    $result = $db->query("PRAGMA table_info(appointments)");
    $has_queue_number = false;
    while ($col = $result->fetchArray(SQLITE3_ASSOC)) {
        if ($col['name'] === 'queue_number') {
            $has_queue_number = true;
            break;
        }
    }
    if (!$has_queue_number) {
        $db->exec("ALTER TABLE appointments ADD COLUMN queue_number INTEGER;");
    }

    $result_users = $db->query("PRAGMA table_info(users)");
    $has_status = false;
    while ($col = $result_users->fetchArray(SQLITE3_ASSOC)) {
        if ($col['name'] === 'status') {
            $has_status = true;
            break;
        }
    }
    if (!$has_status) {
        $db->exec("ALTER TABLE users ADD COLUMN status TEXT DEFAULT 'Active';");
    }

    // 6. Reminders Table
    $db->exec("CREATE TABLE IF NOT EXISTS reminders (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        appointment_id INTEGER NOT NULL,
        reminder_type TEXT NOT NULL,
        reminder_offset TEXT NOT NULL,
        status TEXT DEFAULT 'Scheduled',
        created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
        FOREIGN KEY (appointment_id) REFERENCES appointments(id) ON DELETE CASCADE
    )");

    // 7. Patients Table
    $db->exec("CREATE TABLE IF NOT EXISTS patients (
        patient_id INTEGER PRIMARY KEY,
        name TEXT NOT NULL,
        gender TEXT,
        birth_date TEXT,
        contact_details TEXT,
        medical_history TEXT,
        preferred_language TEXT DEFAULT 'English',
        FOREIGN KEY (patient_id) REFERENCES users(id) ON DELETE CASCADE
    )");

    // 8. Doctors Table
    $db->exec("CREATE TABLE IF NOT EXISTS doctors (
        doctor_id INTEGER PRIMARY KEY,
        name TEXT NOT NULL,
        specialization TEXT DEFAULT 'General Medicine',
        contact_number TEXT,
        availability_status TEXT DEFAULT 'Available',
        FOREIGN KEY (doctor_id) REFERENCES users(id) ON DELETE CASCADE
    )");

    // 9. Medical Records Table
    $db->exec("CREATE TABLE IF NOT EXISTS medical_records (
        record_id INTEGER PRIMARY KEY AUTOINCREMENT,
        patient_id INTEGER NOT NULL,
        doctor_id INTEGER,
        diagnosis TEXT NOT NULL,
        treatment TEXT NOT NULL,
        consultation_date TEXT NOT NULL,
        doctor_notes TEXT,
        FOREIGN KEY (patient_id) REFERENCES users(id) ON DELETE CASCADE
    )");

    // 10. Chatbot Logs Table
    $db->exec("CREATE TABLE IF NOT EXISTS chatbot_logs (
        log_id INTEGER PRIMARY KEY AUTOINCREMENT,
        patient_id INTEGER NOT NULL,
        message TEXT NOT NULL,
        response TEXT NOT NULL,
        language_used TEXT NOT NULL,
        timestamp DATETIME DEFAULT CURRENT_TIMESTAMP,
        FOREIGN KEY (patient_id) REFERENCES users(id) ON DELETE CASCADE
    )");

    // 11. Symptoms Table
    $db->exec("CREATE TABLE IF NOT EXISTS symptoms (
        symptom_id INTEGER PRIMARY KEY AUTOINCREMENT,
        patient_id INTEGER NOT NULL,
        conversation_id INTEGER DEFAULT 0,
        symptoms_entered TEXT NOT NULL,
        predicted_condition TEXT NOT NULL,
        probability_score REAL NOT NULL,
        urgency_level TEXT DEFAULT 'Normal Consultation',
        is_emergency INTEGER DEFAULT 0,
        created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
        FOREIGN KEY (patient_id) REFERENCES users(id) ON DELETE CASCADE
    )");

    $res_sym = $db->query("PRAGMA table_info(symptoms)");
    $has_urgency = false;
    $has_emergency_col = false;
    $has_conv = false;
    while ($col = $res_sym->fetchArray(SQLITE3_ASSOC)) {
        if ($col['name'] === 'urgency_level') $has_urgency = true;
        if ($col['name'] === 'is_emergency') $has_emergency_col = true;
        if ($col['name'] === 'conversation_id') $has_conv = true;
    }
    if (!$has_urgency) {
        $db->exec("ALTER TABLE symptoms ADD COLUMN urgency_level TEXT DEFAULT 'Normal Consultation';");
    }
    if (!$has_emergency_col) {
        $db->exec("ALTER TABLE symptoms ADD COLUMN is_emergency INTEGER DEFAULT 0;");
    }
    if (!$has_conv) {
        $db->exec("ALTER TABLE symptoms ADD COLUMN conversation_id INTEGER DEFAULT 0;");
    }

    // Synchronize Patients and Doctors profiles from Users table (first run only)
    if ($db->querySingle("SELECT COUNT(*) FROM patients") == 0) {
        $users_res = $db->query("SELECT id, name, role FROM users");
        while ($user = $users_res->fetchArray(SQLITE3_ASSOC)) {
            if ($user['role'] === 'Patient') {
                $stmt = $db->prepare("INSERT OR IGNORE INTO patients (patient_id, name, gender, birth_date, contact_details, medical_history, preferred_language) VALUES (:id, :name, 'Male', '1995-08-15', '0917-123-4567', 'None', 'English')");
                $stmt->bindValue(':id', $user['id'], SQLITE3_INTEGER);
                $stmt->bindValue(':name', $user['name'], SQLITE3_TEXT);
                $stmt->execute();
            } elseif ($user['role'] === 'Doctor' || $user['role'] === 'Clinical Staff') {
                $stmt = $db->prepare("INSERT OR IGNORE INTO doctors (doctor_id, name, specialization, contact_number, availability_status) VALUES (:id, :name, 'General Medicine', '0918-987-6543', 'Available')");
                $stmt->bindValue(':id', $user['id'], SQLITE3_INTEGER);
                $stmt->bindValue(':name', $user['name'], SQLITE3_TEXT);
                $stmt->execute();
            }
        }
    }

    // Populate mock medical records
    $rec_count = $db->querySingle("SELECT COUNT(*) FROM medical_records");
    if ($rec_count == 0) {
        $p_res = $db->query("SELECT id FROM users WHERE role = 'Patient'");
        $d_res = $db->query("SELECT id, name FROM users WHERE role IN ('Doctor', 'Clinical Staff') LIMIT 2");
        
        $doc_list = [];
        while ($d = $d_res->fetchArray(SQLITE3_ASSOC)) {
            $doc_list[] = $d;
        }

        if (!empty($doc_list)) {
            while ($p = $p_res->fetchArray(SQLITE3_ASSOC)) {
                $pid = $p['id'];
                
                // Record 1
                $stmt1 = $db->prepare("INSERT INTO medical_records (patient_id, doctor_id, diagnosis, treatment, consultation_date, doctor_notes) VALUES (:pid, :did, 'Acute Nasopharyngitis (Common Cold)', 'Rest, increased fluid intake, Paracetamol 500mg every 6 hours as needed for fever.', '2026-06-15', 'Patient presented with runny nose, mild sore throat, and low-grade fever for 2 days. Lungs are clear.')");
                $stmt1->bindValue(':pid', $pid, SQLITE3_INTEGER);
                $stmt1->bindValue(':did', $doc_list[0]['id'], SQLITE3_INTEGER);
                $stmt1->execute();

                // Record 2
                if (isset($doc_list[1])) {
                    $stmt2 = $db->prepare("INSERT INTO medical_records (patient_id, doctor_id, diagnosis, treatment, consultation_date, doctor_notes) VALUES (:pid, :did, 'Gastroenteritis', 'Oral rehydration salts, light diet (BRAT), probiotics for 5 days.', '2026-05-10', 'Patient reports watery diarrhea and mild abdominal cramps. Advised to avoid dairy and greasy food.')");
                    $stmt2->bindValue(':pid', $pid, SQLITE3_INTEGER);
                    $stmt2->bindValue(':did', $doc_list[1]['id'], SQLITE3_INTEGER);
                    $stmt2->execute();
                }
            }
        }
    }

    // Populate mock chatbot logs
    $chat_count = $db->querySingle("SELECT COUNT(*) FROM chatbot_logs");
    if ($chat_count == 0) {
        $p_res = $db->query("SELECT id FROM users WHERE role = 'Patient'");
        while ($p = $p_res->fetchArray(SQLITE3_ASSOC)) {
            $pid = $p['id'];
            
            $stmt = $db->prepare("INSERT INTO chatbot_logs (patient_id, message, response, language_used, timestamp) VALUES (:pid, 'How do I book an appointment?', 'You can book an appointment by selecting the Appointments tab and filling out the booking form.', 'English', '2026-07-06 14:22:10')");
            $stmt->bindValue(':pid', $pid, SQLITE3_INTEGER);
            $stmt->execute();
        }
    }

    // Populate mock symptoms checks
    $sym_count = $db->querySingle("SELECT COUNT(*) FROM symptoms");
    if ($sym_count == 0) {
        $p_res = $db->query("SELECT id FROM users WHERE role = 'Patient'");
        while ($p = $p_res->fetchArray(SQLITE3_ASSOC)) {
            $pid = $p['id'];
            
            $stmt = $db->prepare("INSERT INTO symptoms (patient_id, symptoms_entered, predicted_condition, probability_score, created_at) VALUES (:pid, 'Fever, Cough, Sore Throat', 'Influenza (Flu)', 0.85, '2026-07-05 09:15:30')");
            $stmt->bindValue(':pid', $pid, SQLITE3_INTEGER);
            $stmt->execute();
        }
    }

    // 12. Audit Logs Table
    $db->exec("CREATE TABLE IF NOT EXISTS audit_logs (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        user_id INTEGER,
        username TEXT,
        action TEXT NOT NULL,
        affected_record TEXT,
        ip_address TEXT,
        timestamp DATETIME DEFAULT CURRENT_TIMESTAMP
    )");

    // 13. System Settings Table
    $db->exec("CREATE TABLE IF NOT EXISTS system_settings (
        key TEXT PRIMARY KEY,
        value TEXT NOT NULL,
        description TEXT
    )");


    // 14. AI Chat Sessions (MediBot context memory)
    $db->exec("CREATE TABLE IF NOT EXISTS chat_sessions (
        session_id   TEXT PRIMARY KEY,
        user_id      INTEGER,
        role         TEXT DEFAULT 'Patient',
        started_at   DATETIME DEFAULT CURRENT_TIMESTAMP,
        last_active  DATETIME DEFAULT CURRENT_TIMESTAMP
    )");
    $res_cs = $db->query("PRAGMA table_info(chat_sessions)");
    $has_role_col = false;
    while ($col = $res_cs->fetchArray(SQLITE3_ASSOC)) {
        if ($col["name"] === "role") {
            $has_role_col = true;
        }
    }
    if (!$has_role_col) {
        $db->exec("ALTER TABLE chat_sessions ADD COLUMN role TEXT DEFAULT 'Patient';");
    }

    // 15. AI Chat Messages (full conversation history)
    $db->exec("CREATE TABLE IF NOT EXISTS chat_messages (
        id           INTEGER PRIMARY KEY AUTOINCREMENT,
        session_id   TEXT NOT NULL,
        role         TEXT NOT NULL,
        content      TEXT NOT NULL,
        intent       TEXT,
        tool_called  TEXT,
        tokens_used  INTEGER DEFAULT 0,
        created_at   DATETIME DEFAULT CURRENT_TIMESTAMP,
        FOREIGN KEY (session_id) REFERENCES chat_sessions(session_id) ON DELETE CASCADE
    )");

    // 16. RAG Knowledge Base Chunks
    $db->exec("CREATE TABLE IF NOT EXISTS kb_chunks (
        id         INTEGER PRIMARY KEY AUTOINCREMENT,
        category   TEXT NOT NULL,
        role_scope TEXT DEFAULT 'all',
        title      TEXT NOT NULL,
        content    TEXT NOT NULL,
        keywords   TEXT,
        updated_at DATETIME DEFAULT CURRENT_TIMESTAMP
    )");
    $res_kb = $db->query("PRAGMA table_info(kb_chunks)");
    $has_role_scope = false;
    while ($col = $res_kb->fetchArray(SQLITE3_ASSOC)) {
        if ($col["name"] === "role_scope") {
            $has_role_scope = true;
        }
    }
    if (!$has_role_scope) {
        $db->exec("ALTER TABLE kb_chunks ADD COLUMN role_scope TEXT DEFAULT 'all';");
    }
    // Migrations: Check if columns exist in chatbot_logs
    $result_chatbot = $db->query("PRAGMA table_info(chatbot_logs)");
    $has_feedback = false;
    $has_flagged = false;
    while ($col = $result_chatbot->fetchArray(SQLITE3_ASSOC)) {
        if ($col['name'] === 'feedback_rating') {
            $has_feedback = true;
        }
        if ($col['name'] === 'is_flagged') {
            $has_flagged = true;
        }
    }
    if (!$has_feedback) {
        $db->exec("ALTER TABLE chatbot_logs ADD COLUMN feedback_rating INTEGER DEFAULT 0;");
    }
    if (!$has_flagged) {
        $db->exec("ALTER TABLE chatbot_logs ADD COLUMN is_flagged INTEGER DEFAULT 0;");
    }

    // Role-Based AI System Schema (Conversations, Messages, Context, Memory, Auditing)
    $db->exec("CREATE TABLE IF NOT EXISTS ai_conversations (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        user_id INTEGER NOT NULL,
        role TEXT NOT NULL,
        title TEXT DEFAULT 'New Conversation',
        status TEXT DEFAULT 'Active',
        created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
        updated_at DATETIME DEFAULT CURRENT_TIMESTAMP,
        FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
    );");
    $db->exec("CREATE INDEX IF NOT EXISTS idx_ai_conv_user ON ai_conversations(user_id, role, status);");

    $db->exec("CREATE TABLE IF NOT EXISTS ai_messages (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        conversation_id INTEGER NOT NULL,
        sender_type TEXT NOT NULL,
        content TEXT,
        tool_calls TEXT,
        tool_call_id TEXT,
        tokens_used INTEGER DEFAULT 0,
        created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
        FOREIGN KEY (conversation_id) REFERENCES ai_conversations(id) ON DELETE CASCADE
    );");
    $db->exec("CREATE INDEX IF NOT EXISTS idx_ai_msg_conv ON ai_messages(conversation_id, created_at);");

    $db->exec("CREATE TABLE IF NOT EXISTS ai_context_store (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        conversation_id INTEGER NOT NULL,
        user_id INTEGER NOT NULL,
        context_key TEXT NOT NULL,
        context_value TEXT NOT NULL,
        updated_at DATETIME DEFAULT CURRENT_TIMESTAMP,
        UNIQUE(conversation_id, context_key),
        FOREIGN KEY (conversation_id) REFERENCES ai_conversations(id) ON DELETE CASCADE,
        FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
    );");

    $db->exec("CREATE TABLE IF NOT EXISTS ai_memory_summaries (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        conversation_id INTEGER NOT NULL UNIQUE,
        summary_text TEXT NOT NULL,
        last_summarized_message_id INTEGER NOT NULL,
        updated_at DATETIME DEFAULT CURRENT_TIMESTAMP,
        FOREIGN KEY (conversation_id) REFERENCES ai_conversations(id) ON DELETE CASCADE
    );");

    $db->exec("CREATE TABLE IF NOT EXISTS ai_tool_execution_logs (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        conversation_id INTEGER NOT NULL,
        user_id INTEGER NOT NULL,
        role TEXT NOT NULL,
        tool_name TEXT NOT NULL,
        input_params TEXT,
        output_result TEXT,
        status TEXT NOT NULL,
        execution_time_ms REAL,
        created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
        FOREIGN KEY (conversation_id) REFERENCES ai_conversations(id) ON DELETE CASCADE,
        FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
    );");
    $db->exec("CREATE INDEX IF NOT EXISTS idx_ai_tool_logs_user ON ai_tool_execution_logs(user_id, created_at);");


    // Seed default settings
    $settings_count = $db->querySingle("SELECT COUNT(*) FROM system_settings");
    if ($settings_count == 0) {
        $db->exec("INSERT INTO system_settings (key, value, description) VALUES ('supported_languages', 'English, Filipino, Cebuano', 'Supported languages/dialects in the chatbot widget')");
        $db->exec("INSERT INTO system_settings (key, value, description) VALUES ('appointment_statuses', 'Scheduled, Approved, Cancelled, Completed, No-Show', 'Allowed appointment statuses in the system')");
        $db->exec("INSERT INTO system_settings (key, value, description) VALUES ('symptom_categories', 'Fever, Cough, Sore Throat, Diarrhea, Vomiting, Headache, Shortness of Breath, Skin Rash', 'Standard symptom classifications')");
        $db->exec("INSERT INTO system_settings (key, value, description) VALUES ('iso_evaluation_settings', '{\"usability\":0.90, \"reliability\":0.85, \"security\":0.95, \"performance\":0.88}', 'ISO/IEC 25010 metrics configuration')");
    }

    // Seed default super admin
    $admin_exists = $db->querySingle("SELECT COUNT(*) FROM users WHERE email = 'admin@clinick.com'");
    if ($admin_exists == 0) {
        $hashed_pw = password_hash('password123', PASSWORD_DEFAULT);
        $db->exec("INSERT INTO users (name, email, password, role, status) VALUES ('Super Admin', 'admin@clinick.com', '$hashed_pw', 'Admin', 'Active')");
    }

} catch (Exception $e) {
    die("Database initialization failed: " . $e->getMessage());
}

// Shared helper functions
function get_db_connection() {
    global $db;
    return $db;
}

// Audit logger helper
function log_audit_action($user_id, $username, $action, $affected_record = null) {
    global $db;
    if (!$db) {
        $db = get_db_connection();
    }
    $ip = $_SERVER['REMOTE_ADDR'] ?? '127.0.0.1';
    $stmt = $db->prepare("INSERT INTO audit_logs (user_id, username, action, affected_record, ip_address) VALUES (:user_id, :username, :action, :affected_record, :ip_address)");
    $stmt->bindValue(':user_id', $user_id, SQLITE3_INTEGER);
    $stmt->bindValue(':username', $username, SQLITE3_TEXT);
    $stmt->bindValue(':action', $action, SQLITE3_TEXT);
    $stmt->bindValue(':affected_record', $affected_record, SQLITE3_TEXT);
    $stmt->bindValue(':ip_address', $ip, SQLITE3_TEXT);
    $stmt->execute();
}

// Redirect helpers
function check_auth($allowed_roles = []) {
    if (!session_id()) {
        session_start();
    }
    if (!isset($_SESSION['user_id'])) {
        header("Location: index.php");
        exit();
    }
    
    if (!empty($allowed_roles) && !in_array($_SESSION['user_role'], $allowed_roles)) {
        // Redirect to their respective dashboards if they access the wrong one
        if ($_SESSION['user_role'] === 'Patient') {
            header("Location: patient_dashboard.php");
        } elseif (in_array($_SESSION['user_role'], ['Staff', 'Clinical Staff'])) {
            header("Location: staff_dashboard.php");
        } elseif ($_SESSION['user_role'] === 'Admin') {
            header("Location: admin_dashboard.php");
        } else {
            header("Location: doctor_dashboard.php");
        }
        exit();
    }
}
?>
