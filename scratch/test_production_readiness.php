<?php
/**
 * Test Suite for Production-Readiness Audit Verification
 * (Emergency Detection, Negation Engine, Classification, Audit Trail, & WAL Concurrency)
 */

require_once __DIR__ . '/../db.php';
require_once __DIR__ . '/../clinick-chatbot-php/DiagnosisClassifier.php';
require_once __DIR__ . '/../classes/ai/Tools/PatientTools.php';

$db = get_db_connection();
$classifier = new DiagnosisClassifier();
$patientTools = new PatientTools($db);

echo "====================================================\n";
echo "1. EMERGENCY DETECTION TESTS (EN, FIL, TAGLISH)\n";
echo "====================================================\n";

$emergencyCases = [
    'EN - Severe Chest Pain'     => 'I have severe chest pain and crushing sensation',
    'EN - Difficulty Breathing'  => 'I am having difficulty breathing right now',
    'EN - Unconscious'           => 'The patient is unconscious and not responding',
    'FIL - Masakit ang Dibdib'   => 'Sobrang sakit ng dibdib ko po',
    'FIL - Hirap Huminga'        => 'Nahihirapang huminga ang kapatid ko',
    'FIL - Walang Malay'         => 'Walang malay ang lola ko pakiusap tulong',
    'TAGLISH - Breathing Issue'  => 'I feel very sick and hirap huminga ako',
    'TAGLISH - Stroke Symptoms'  => 'Biglang nanghina at hindi makapagsalita si mama',
];

foreach ($emergencyCases as $label => $text) {
    $res = $classifier->classify($text);
    $status = $res['isEmergency'] ? "[PASS - EMERGENCY DETECTED]" : "[FAIL - MISSED EMERGENCY]";
    echo sprintf("%-30s | %-25s | Text: '%s'\n", $label, $status, $text);
}

echo "\n====================================================\n";
echo "2. NEGATION DETECTION TESTS (PREVENT FALSE POSITIVES)\n";
echo "====================================================\n";

$negationCases = [
    'EN - No chest pain'         => 'I have a mild fever, but no chest pain',
    'EN - Not having diff breath'=> 'I am coughing but not having difficulty breathing',
    'EN - Denies chest pain'     => 'Patient denies chest pain and denies shortness of breath',
    'FIL - Wala akong chest pain'=> 'May lagnat ako pero wala akong chest pain',
    'FIL - Hindi hirap huminga'  => 'Masakit lalamunan ko pero hindi ako hirap huminga',
    'TAGLISH - Walang pananakit' => 'Ubo at sipon lang, walang pananakit ng dibdib',
];

foreach ($negationCases as $label => $text) {
    $res = $classifier->classify($text);
    $status = !$res['isEmergency'] ? "[PASS - NEGATED, NO EMERGENCY]" : "[FAIL - FALSE POSITIVE EMERGENCY]";
    echo sprintf("%-30s | %-28s | Text: '%s'\n", $label, $status, $text);
}

echo "\n====================================================\n";
echo "3. CLASSIFICATION & QUALITATIVE CONFIDENCE TIERS\n";
echo "====================================================\n";

$classCases = [
    'Fever + Cough + Sore Throat' => 'I have fever cough and sore throat',
    'Headache + Runny Nose'      => 'I have severe headache and runny nose',
    'Abdominal Pain + Vomiting'   => 'Stomach pain nausea vomiting diarrhea',
];

foreach ($classCases as $label => $text) {
    $res = $classifier->classify($text);
    echo "Query: '$text'\n";
    echo "  -> Category: " . ($res['category'] ?? 'N/A') . "\n";
    echo "  -> Score: " . ($res['confidence'] ?? 0) . " | Tier: " . ($res['confidenceTier'] ?? 'N/A') . "\n\n";
}

echo "====================================================\n";
echo "4. MANDATORY AUDIT LOGGING VERIFICATION\n";
echo "====================================================\n";

// Execute one emergency check and one non-emergency check via PatientTools
$testUserId = (int)$db->querySingle("SELECT id FROM users LIMIT 1");
if (!$testUserId) {
    $testUserId = 1;
}

$patientTools->check_symptoms_naive_bayes(['symptom_text' => 'Emergency test: severe chest pain'], $testUserId);
$patientTools->check_symptoms_naive_bayes(['symptom_text' => 'Normal test: mild cough and sneezing'], $testUserId);

$queryLog = $db->query("SELECT symptom_id, symptoms_entered, predicted_condition, urgency_level, is_emergency, created_at FROM symptoms ORDER BY symptom_id DESC LIMIT 2");

while ($row = $queryLog->fetchArray(SQLITE3_ASSOC)) {
    echo json_encode($row, JSON_UNESCAPED_UNICODE) . "\n";
}

echo "\n====================================================\n";
echo "5. SQLITE WAL MODE CONCURRENCY VERIFICATION\n";
echo "====================================================\n";

$journalMode = $db->querySingle("PRAGMA journal_mode;");
echo "Current SQLite Journal Mode: " . strtoupper($journalMode) . "\n";
if (strtolower($journalMode) === 'wal') {
    echo "[PASS] SQLite WAL Mode enabled successfully for high-throughput concurrency.\n";
} else {
    echo "[FAIL] SQLite is not in WAL mode.\n";
}
