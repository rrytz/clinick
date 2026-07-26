<?php
require_once __DIR__ . '/../db.php';
require_once __DIR__ . '/../classes/ai/AssistantFactory.php';

$db = get_db_connection();
$factory = new AssistantFactory($db);

// Find Rivera (patient ID)
$pRow = $db->querySingle("SELECT id, name FROM users WHERE role = 'Patient' AND (name LIKE '%Rivera%' OR email LIKE '%rivera%') LIMIT 1", true);
$pId = $pRow['id'] ?? 1;

echo "Testing Patient Assistant 'Check my appointments' for User #{$pId} (" . ($pRow['name'] ?? 'Patient') . ")...\n\n";

$res = $factory->handleMessage("Check my appointments", $pId, 'Patient', null);

echo "Reply:\n" . $res['reply'] . "\n\n";
echo "Tools executed: " . implode(', ', $res['tool_calls_executed'] ?? []) . "\n";
