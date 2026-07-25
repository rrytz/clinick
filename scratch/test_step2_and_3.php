<?php
/**
 * Test script to verify check_symptoms_naive_bayes tool, existing flow, and offline fallback.
 */

require_once __DIR__ . '/../db.php';
require_once __DIR__ . '/../classes/ai/AssistantFactory.php';
require_once __DIR__ . '/../ChatbotService.php';

$db = get_db_connection();
$factory = new AssistantFactory($db);

echo "=== TEST 1: Symptom Assessment Message (check_symptoms_naive_bayes) ===\n";
$msg1 = "I have a fever, cough, and sore throat. What could it be?";
$res1 = $factory->handleMessage($msg1, 1, 'Patient');
echo json_encode($res1, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE) . "\n\n";

echo "=== TEST 2: Existing Flow (getAvailableDoctors / createAppointment) ===\n";
$msg2 = "Show available doctors for tomorrow";
$res2 = $factory->handleMessage($msg2, 1, 'Patient');
echo json_encode($res2, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE) . "\n\n";

echo "=== TEST 3: Emergency Symptom Escalation ===\n";
$msg3 = "I have severe chest pain and difficulty breathing";
$res3 = $factory->handleMessage($msg3, 1, 'Patient');
echo json_encode($res3, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE) . "\n\n";

echo "=== TEST 4: Offline Fallback Simulation (ChatbotService) ===\n";
$chatbot = new ChatbotService();
$fallbackRes = $chatbot->respond("I have a fever");
$fallbackRes['degraded'] = true;
$fallbackRes['assistant_name'] = 'Personal Clinic Assistant (Offline)';
echo json_encode($fallbackRes, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE) . "\n\n";
