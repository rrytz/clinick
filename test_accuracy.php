<?php
/**
 * Classification Accuracy Test Suite for MediBot (Naive Bayes + Keyword Pre-Matcher)
 *
 * Tests 30 standard user intent prompts across English, Filipino, and Cebuano,
 * evaluates predicted intent vs expected intent, and prints accuracy metrics.
 */

require_once __DIR__ . '/db.php';
require_once __DIR__ . '/NaiveBayesClassifier.php';
require_once __DIR__ . '/ChatbotService.php';

$bot = new ChatbotService();

$testCases = [
    // ── English Test Cases (10) ────────────────────────────────────────
    ['text' => 'hello',                     'expected' => 'greeting',               'lang' => 'en'],
    ['text' => 'hi there',                  'expected' => 'greeting',               'lang' => 'en'],
    ['text' => 'I want to book an appointment', 'expected' => 'book_appointment',   'lang' => 'en'],
    ['text' => 'schedule a doctor visit',   'expected' => 'book_appointment',       'lang' => 'en'],
    ['text' => 'cancel my appointment',     'expected' => 'cancel_appointment',     'lang' => 'en'],
    ['text' => 'reschedule for next week',  'expected' => 'reschedule_appointment', 'lang' => 'en'],
    ['text' => 'what are your clinic hours','expected' => 'clinic_hours',          'lang' => 'en'],
    ['text' => 'what services do you offer','expected' => 'services_offered',       'lang' => 'en'],
    ['text' => 'I have a fever and cough',  'expected' => 'check_symptoms',         'lang' => 'en'],
    ['text' => 'talk to a real person',     'expected' => 'talk_to_staff',          'lang' => 'en'],

    // ── Filipino Test Cases (10) ───────────────────────────────────────
    ['text' => 'kumusta po',                'expected' => 'greeting',               'lang' => 'fil'],
    ['text' => 'magandang araw po',         'expected' => 'greeting',               'lang' => 'fil'],
    ['text' => 'gusto ko pong mag-book ng appointment', 'expected' => 'book_appointment', 'lang' => 'fil'],
    ['text' => 'paano magpa-kunsulta sa doktor', 'expected' => 'book_appointment',   'lang' => 'fil'],
    ['text' => 'gusto ko ire-schedule ang appointment', 'expected' => 'reschedule_appointment', 'lang' => 'fil'],
    ['text' => 'kanselahin ang aking appointment', 'expected' => 'cancel_appointment', 'lang' => 'fil'],
    ['text' => 'ano ang oras ng klinika',   'expected' => 'clinic_hours',          'lang' => 'fil'],
    ['text' => 'ano ang mga serbisyo ninyo','expected' => 'services_offered',       'lang' => 'fil'],
    ['text' => 'masakit ang ulo ko at may lagnat', 'expected' => 'check_symptoms',  'lang' => 'fil'],
    ['text' => 'salamat po sa tulong',      'expected' => 'farewell',               'lang' => 'fil'],

    // ── Cebuano Test Cases (10) ────────────────────────────────────────
    ['text' => 'kumusta',                   'expected' => 'greeting',               'lang' => 'ceb'],
    ['text' => 'maayong buntag',            'expected' => 'greeting',               'lang' => 'ceb'],
    ['text' => 'gusto ko mag-book og appointment', 'expected' => 'book_appointment', 'lang' => 'ceb'],
    ['text' => 'unsaon pagpa-konsulta',     'expected' => 'book_appointment',       'lang' => 'ceb'],
    ['text' => 'kanselahon nako ang appointment', 'expected' => 'cancel_appointment', 'lang' => 'ceb'],
    ['text' => 'i-reschedule ang nako visit', 'expected' => 'reschedule_appointment', 'lang' => 'ceb'],
    ['text' => 'unsa nga oras moabli ang klinika', 'expected' => 'clinic_hours',   'lang' => 'ceb'],
    ['text' => 'unsa nga mga serbisyo ang naa ninyo', 'expected' => 'services_offered', 'lang' => 'ceb'],
    ['text' => 'sakit akong ulo ug mag-lagnat', 'expected' => 'check_symptoms',    'lang' => 'ceb'],
    ['text' => 'daghang salamat sa tabang', 'expected' => 'farewell',               'lang' => 'ceb'],
];

$passed = 0;
$total  = count($testCases);
$resultsByLang = ['en' => ['pass' => 0, 'total' => 0], 'fil' => ['pass' => 0, 'total' => 0], 'ceb' => ['pass' => 0, 'total' => 0]];

echo "========================================================================================\n";
echo "                   MEDIBOT CLASSIFICATION ACCURACY TEST SUITE                           \n";
echo "========================================================================================\n";
printf("%-35s | %-6s | %-22s | %-22s | %-6s\n", "Input Text", "Lang", "Expected Intent", "Predicted Intent", "Status");
echo "----------------------------------------------------------------------------------------\n";

foreach ($testCases as $tc) {
    $res = $bot->respond($tc['text']);
    $predicted = $res['intent'];
    $isMatch = ($predicted === $tc['expected']);
    
    if ($isMatch) {
        $passed++;
        $resultsByLang[$tc['lang']]['pass']++;
    }
    $resultsByLang[$tc['lang']]['total']++;

    $statusStr = $isMatch ? "✅ PASS" : "❌ FAIL";
    printf("%-35s | %-6s | %-22s | %-22s | %-6s\n", 
        mb_strimwidth($tc['text'], 0, 34, '…'),
        strtoupper($tc['lang']),
        $tc['expected'],
        $predicted,
        $statusStr
    );
}

$overallAccuracy = round(($passed / $total) * 100, 2);

echo "========================================================================================\n";
echo "                                  ACCURACY SUMMARY REPORT                               \n";
echo "========================================================================================\n";
foreach ($resultsByLang as $lang => $stats) {
    $acc = round(($stats['pass'] / $stats['total']) * 100, 2);
    printf("  • %-10s Accuracy: %d / %d (%s%%)\n", strtoupper($lang), $stats['pass'], $stats['total'], $acc);
}
echo "----------------------------------------------------------------------------------------\n";
printf("  ★ OVERALL SYSTEM ACCURACY: %d / %d (%s%%)\n", $passed, $total, $overallAccuracy);
echo "========================================================================================\n";
