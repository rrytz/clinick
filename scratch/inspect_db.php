<?php
require_once __DIR__ . '/../db.php';
$db = get_db_connection();

echo "=== USERS TABLE ===\n";
$r = $db->query("SELECT id, name, email, role FROM users ORDER BY id");
while ($row = $r->fetchArray(SQLITE3_ASSOC)) {
    echo json_encode($row) . "\n";
}

echo "\n=== APPOINTMENTS TABLE ===\n";
$r2 = $db->query("SELECT id, patient_id, doctor_id, appointment_date, time_slot, status, queue_number FROM appointments ORDER BY id LIMIT 20");
while ($row = $r2->fetchArray(SQLITE3_ASSOC)) {
    echo json_encode($row) . "\n";
}

echo "\n=== MEDICAL_RECORDS TABLE ===\n";
$r3 = $db->query("SELECT record_id, patient_id, doctor_id, diagnosis, treatment, consultation_date FROM medical_records ORDER BY record_id LIMIT 10");
while ($row = $r3->fetchArray(SQLITE3_ASSOC)) {
    echo json_encode($row) . "\n";
}

echo "\n=== PRESCRIPTIONS TABLE ===\n";
$r4 = $db->query("SELECT id, patient_id, doctor_id, doctor_name, medication, dosage, frequency FROM prescriptions ORDER BY id LIMIT 10");
while ($row = $r4->fetchArray(SQLITE3_ASSOC)) {
    echo json_encode($row) . "\n";
}
