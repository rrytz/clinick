<?php
require_once __DIR__ . '/../db.php';
require_once __DIR__ . '/../classes/ai/Tools/StaffTools.php';

$db = get_db_connection();
$staffTools = new StaffTools($db);

$queries = [
    "rivera?",
    "christian rivera?",
    "rivera!",
    "rivera...",
    "is there rivera?"
];

echo "======================================================================\n";
echo "  PUNCTUATION & NOISE PATIENT SEARCH TEST\n";
echo "======================================================================\n\n";

foreach ($queries as $q) {
    $res = $staffTools->searchPatientByName(['query' => $q], 1);
    $cnt = $res['match_count'] ?? count($res['patients'] ?? []);
    echo "Query: '{$q}' -> Matches: {$cnt}\n";
    foreach ($res['patients'] ?? [] as $p) {
        echo "  • " . $p['name'] . " (" . $p['email'] . ")\n";
    }
}
