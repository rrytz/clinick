<?php
// Mock session for Admin login
session_start();
$_SESSION['user_id'] = 10; // Super Admin ID
$_SESSION['user_name'] = 'Super Admin';
$_SESSION['user_role'] = 'Admin';

// Include db connection helper
require_once __DIR__ . '/db.php';
$db = get_db_connection();

echo "1. VERIFYING USER DEACTIVATION BACKEND:\n";
// Let's create a test user first
$email = 'test_deact@example.com';
$db->exec("DELETE FROM users WHERE email = '$email'");
$hashed_pw = password_hash('password123', PASSWORD_DEFAULT);
$db->exec("INSERT INTO users (name, email, password, role, status) VALUES ('Deact User', '$email', '$hashed_pw', 'Staff', 'Active')");
$uid = $db->querySingle("SELECT id FROM users WHERE email = '$email'");

echo " - Created user ID: $uid, status: " . $db->querySingle("SELECT status FROM users WHERE id = $uid") . "\n";

// Mock $_POST for deactivation
$_POST['action'] = 'deactivate_user';
$_POST['user_id'] = $uid;
$_SERVER['REQUEST_METHOD'] = 'POST';

// Execute the post action by requiring admin_dashboard.php (buffering output to avoid rendering)
ob_start();
include __DIR__ . '/admin_dashboard.php';
ob_end_clean();

$status_after = $db->querySingle("SELECT status FROM users WHERE id = $uid");
echo " - After deactivation action, status: $status_after\n";

if ($status_after === 'Inactive') {
    echo " -> DEACTIVATION ACTION SUCCESS!\n";
} else {
    echo " -> DEACTIVATION ACTION FAILED!\n";
}

echo "\n2. VERIFYING DOCTOR DETAILS EDIT BACKEND:\n";
// Find a doctor user
$doc_id = $db->querySingle("SELECT doctor_id FROM doctors LIMIT 1");
if ($doc_id) {
    echo " - Found doctor ID: $doc_id\n";
    $orig_spec = $db->querySingle("SELECT specialization FROM doctors WHERE doctor_id = $doc_id");
    echo " - Original specialization: $orig_spec\n";

    // Mock $_POST for doctor edit
    $_POST['action'] = 'edit_doctor_details';
    $_POST['doctor_id'] = $doc_id;
    $_POST['specialization'] = 'Cardiology Test';
    $_POST['contact_number'] = '123-456-7890';
    $_POST['availability_status'] = 'Unavailable';

    ob_start();
    include __DIR__ . '/admin_dashboard.php';
    ob_end_clean();

    $new_spec = $db->querySingle("SELECT specialization FROM doctors WHERE doctor_id = $doc_id");
    $new_avail = $db->querySingle("SELECT availability_status FROM doctors WHERE doctor_id = $doc_id");
    echo " - New specialization: $new_spec, Availability: $new_avail\n";

    if ($new_spec === 'Cardiology Test' && $new_avail === 'Unavailable') {
        echo " -> DOCTOR EDIT SUCCESS!\n";
        // Restore original
        $db->exec("UPDATE doctors SET specialization = '$orig_spec', availability_status = 'Available' WHERE doctor_id = $doc_id");
    } else {
        echo " -> DOCTOR EDIT FAILED!\n";
    }
} else {
    echo " - No doctor records found.\n";
}

echo "\n3. VERIFYING PATIENT ARCHIVING BACKEND:\n";
$pat_id = $db->querySingle("SELECT patient_id FROM patients LIMIT 1");
if ($pat_id) {
    $orig_status = $db->querySingle("SELECT status FROM users WHERE id = $pat_id");
    echo " - Found patient ID: $pat_id, original status: $orig_status\n";

    // Mock $_POST for patient archiving
    $_POST['action'] = 'archive_patient';
    $_POST['patient_id'] = $pat_id;

    ob_start();
    include __DIR__ . '/admin_dashboard.php';
    ob_end_clean();

    $new_status = $db->querySingle("SELECT status FROM users WHERE id = $pat_id");
    echo " - After archiving action, status: $new_status\n";

    if ($new_status === 'Archived') {
        echo " -> PATIENT ARCHIVING SUCCESS!\n";
        // Restore status
        $db->exec("UPDATE users SET status = '$orig_status' WHERE id = $pat_id");
    } else {
        echo " -> PATIENT ARCHIVING FAILED!\n";
    }
} else {
    echo " - No patient records found.\n";
}

echo "\n4. VERIFYING SYSTEM CONFIGURATION BACKEND:\n";
// Get original settings
$orig_langs = $db->querySingle("SELECT value FROM system_settings WHERE key = 'supported_languages'");
echo " - Original languages: $orig_langs\n";

// Mock $_POST for settings update
$_POST['action'] = 'update_system_config';
$_POST['languages'] = 'English, Cebuano, Tagalog, Spanish';
$_POST['statuses'] = 'Scheduled, Completed, Cancelled, No-Show';
$_POST['symptoms'] = 'Fever, Cough, Headache';
$_POST['iso_eval'] = '{"security": 0.95, "usability": 0.90}';

ob_start();
include __DIR__ . '/admin_dashboard.php';
ob_end_clean();

$new_langs = $db->querySingle("SELECT value FROM system_settings WHERE key = 'supported_languages'");
echo " - New languages after settings update: $new_langs\n";

if ($new_langs === 'English, Cebuano, Tagalog, Spanish') {
    echo " -> SYSTEM SETTINGS UPDATE SUCCESS!\n";
    // Restore original settings
    $db->exec("UPDATE system_settings SET value = '$orig_langs' WHERE key = 'supported_languages'");
} else {
    echo " -> SYSTEM SETTINGS UPDATE FAILED!\n";
}

echo "\n5. VERIFYING USER DELETION BACKEND:\n";
$del_email = 'test_del@example.com';
$db->exec("DELETE FROM users WHERE email = '$del_email'");
$db->exec("INSERT INTO users (name, email, password, role, status) VALUES ('Del User', '$del_email', '$hashed_pw', 'Patient', 'Active')");
$del_uid = $db->querySingle("SELECT id FROM users WHERE email = '$del_email'");

// Trigger the trigger sync to patients table
$db->exec("INSERT OR IGNORE INTO patients (patient_id, name) VALUES ($del_uid, 'Del User')");

echo " - Created user ID: $del_uid, checking exist in users table: " . ($db->querySingle("SELECT COUNT(*) FROM users WHERE id = $del_uid") ? 'Yes' : 'No') . "\n";
echo " - Checking exist in patients table: " . ($db->querySingle("SELECT COUNT(*) FROM patients WHERE patient_id = $del_uid") ? 'Yes' : 'No') . "\n";

$_POST['action'] = 'delete_user';
$_POST['user_id'] = $del_uid;
$_SERVER['REQUEST_METHOD'] = 'POST';

ob_start();
include __DIR__ . '/admin_dashboard.php';
ob_end_clean();

$exists_users = $db->querySingle("SELECT COUNT(*) FROM users WHERE id = $del_uid");
$exists_patients = $db->querySingle("SELECT COUNT(*) FROM patients WHERE patient_id = $del_uid");
echo " - After deletion action, exist in users table: " . ($exists_users ? 'Yes' : 'No') . "\n";
echo " - Exist in patients table: " . ($exists_patients ? 'Yes' : 'No') . "\n";

if ($exists_users === 0 && $exists_patients === 0) {
    echo " -> DELETION ACTION SUCCESS!\n";
} else {
    echo " -> DELETION ACTION FAILED!\n";
}

// Clean up test user
$db->exec("DELETE FROM users WHERE email = '$email'");
$db->exec("DELETE FROM users WHERE email = '$del_email'");
?>
