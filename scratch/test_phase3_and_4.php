<?php
/**
 * Test script for Phase 3 (Edge Case Testing) and Phase 4 (Gemini Tool Enforcement)
 */

require_once __DIR__ . '/../db.php';
require_once __DIR__ . '/../clinick-chatbot-php/DiagnosisClassifier.php';
require_once __DIR__ . '/../classes/ai/AssistantFactory.php';

$db = get_db_connection();
$classifier = new DiagnosisClassifier();
$factory = new AssistantFactory($db);

echo "====================================================\n";
echo "PHASE 3: EDGE CASE MIXED-LANGUAGE TESTS\n";
echo "====================================================\n";

$phase3Inputs = [
    1 => "may chest pain ako pero hindi severe",
    2 => "di naman ako hirap huminga",
    3 => "masakit dibdib ko at medyo nahihilo",
    4 => "I have chest pain pero okay naman ako",
    5 => "wala akong chest pain pero nahihirapan huminga",
    6 => "masakit dibdib ko pero wala akong hirap sa paghinga",
    7 => "hindi ako nahihirapan huminga pero sobrang sakit ng dibdib ko",
];

foreach ($phase3Inputs as $num => $text) {
    $res = $classifier->classify($text);
    $isEmerg = $res['isEmergency'] ? "YES (EMERGENCY)" : "NO (Normal)";
    echo "Test $num: '$text'\n";
    echo "  -> Emergency Decision: $isEmerg\n";
    echo "  -> Urgency Level: " . ($res['urgencyLevel'] ?? 'N/A') . "\n";
    echo "  -> Category: " . ($res['category'] ?? 'N/A') . "\n";
    echo "  -> Confidence Tier: " . ($res['confidenceTier'] ?? 'N/A') . "\n\n";
}

echo "====================================================\n";
echo "PHASE 4: GEMINI TOOL ENFORCEMENT VERIFICATION (8 SYMPTOMS)\n";
echo "====================================================\n";

$phase4Inputs = [
    "fever"                => "I have a high fever since this morning",
    "cough"                => "I have a persistent dry cough",
    "sore throat"          => "My throat is very sore when swallowing",
    "headache"             => "I have a severe throbbing headache",
    "chest pain"           => "I am experiencing chest pain",
    "difficulty breathing" => "I have difficulty breathing right now",
    "nausea"               => "I feel nauseous and dizzy",
    "vomiting"             => "I have been vomiting since last night",
];

foreach ($phase4Inputs as $sym => $msg) {
    $res = $factory->handleMessage($msg, 1, 'Patient');
    $executed = $res['tool_calls_executed'] ?? [];
    $hasTool = in_array('check_symptoms_naive_bayes', $executed, true);
    $status = $hasTool ? "[PASS - TOOL CALLED]" : "[FAIL - TOOL BYPASSED]";
    
    echo sprintf("%-22s | %-20s | Executed: [%s]\n", $sym, $status, implode(', ', $executed));
    echo "   Reply snippet: " . substr(str_replace("\n", " ", $res['reply']), 0, 100) . "...\n\n";
}
