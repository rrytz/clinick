<?php
/**
 * Test script for Distress Intent Detection & Hyperbolic False-Positive Filtering
 */

require_once __DIR__ . '/../db.php';
require_once __DIR__ . '/../clinick-chatbot-php/DiagnosisClassifier.php';
require_once __DIR__ . '/../classes/ai/AssistantFactory.php';

$db = get_db_connection();
$classifier = new DiagnosisClassifier();
$factory = new AssistantFactory($db);

echo "====================================================\n";
echo "MUST TRIGGER DISTRESS / EMERGENCY TESTS\n";
echo "====================================================\n";

$mustTrigger = [
    "i thought im gonna die",
    "i think im dying",
    "im dying",
    "help me",
    "pakiramdam ko mamamatay na ako",
    "mamamatay na ako",
    "parang mamamatay ako",
    "di ko na kaya huminga",
];

$passedTrigger = 0;
foreach ($mustTrigger as $text) {
    $res = $classifier->classify($text);
    $facRes = $factory->handleMessage($text, 1, 'Patient');
    $executed = $facRes['tool_calls_executed'] ?? [];
    $isEmerg = !empty($res['isEmergency']);
    $hasTool = in_array('check_symptoms_naive_bayes', $executed, true);
    
    $pass = ($isEmerg && $hasTool);
    if ($pass) $passedTrigger++;

    $status = $pass ? "[PASS - EMERGENCY ESCALATED & TOOL CALLED]" : "[FAIL]";
    echo sprintf("%-35s | %-45s\n", "'$text'", $status);
    echo "   Classifier Result: " . ($isEmerg ? "Emergency" : "Normal") . " | Tools Executed: [" . implode(', ', $executed) . "]\n";
    echo "   Reply snippet: " . substr(str_replace("\n", " ", $facRes['reply']), 0, 90) . "...\n\n";
}

echo "====================================================\n";
echo "MUST NOT TRIGGER (HYPERBOLIC FALSE POSITIVE) TESTS\n";
echo "====================================================\n";

$mustNotTrigger = [
    "i almost died laughing",
    "this workload is killing me",
    "that exam nearly killed me",
    "the movie scared me to death",
];

$passedNonTrigger = 0;
foreach ($mustNotTrigger as $text) {
    $res = $classifier->classify($text);
    $facRes = $factory->handleMessage($text, 1, 'Patient');
    $executed = $facRes['tool_calls_executed'] ?? [];
    $isEmerg = !empty($res['isEmergency']);
    
    $pass = !$isEmerg;
    if ($pass) $passedNonTrigger++;

    $status = $pass ? "[PASS - NOT EMERGENCY, HYPERBOLIC FILTERED]" : "[FAIL - FALSE POSITIVE EMERGENCY]";
    echo sprintf("%-35s | %-45s\n", "'$text'", $status);
    echo "   Classifier Result: " . ($isEmerg ? "EMERGENCY" : "Normal Consultation") . "\n";
    echo "   Reply snippet: " . substr(str_replace("\n", " ", $facRes['reply']), 0, 90) . "...\n\n";
}

echo "====================================================\n";
echo "TEST SUITE SUMMARY\n";
echo "====================================================\n";
echo "Must Trigger Cases Pass Rate: $passedTrigger / " . count($mustTrigger) . "\n";
echo "Must Not Trigger Cases Pass Rate: $passedNonTrigger / " . count($mustNotTrigger) . "\n";
