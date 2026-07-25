<?php
require_once __DIR__ . '/../db.php';
require_once __DIR__ . '/../ChatbotService_AI.php';

$db = get_db_connection();
$ai = new ChatbotService_AI($db);

echo "=== Patient Assistant Test ===\n";
print_r($ai->respond("Can I book a consultation tomorrow?", 1, 'Patient'));

echo "\n=== Admin Operations Secretary Test ===\n";
print_r($ai->respond("What should I focus on today?", 1, 'Admin'));

echo "\n=== Doctor Clinical Assistant Test ===\n";
print_r($ai->respond("Show my assigned patients for today.", 1, 'Doctor'));
