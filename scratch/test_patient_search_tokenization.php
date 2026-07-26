<?php
require_once __DIR__ . '/../db.php';
require_once __DIR__ . '/../classes/ai/Tools/StaffTools.php';
require_once __DIR__ . '/../classes/ai/Tools/DoctorTools.php';

$db = get_db_connection();
$staffTools = new StaffTools($db);
$doctorTools = new DoctorTools($db);

$queries = ["christian rivera", "rivera christian", "harold rivera", "rivera"];

echo "======================================================================\n";
echo "  PATIENT SEARCH TOKENIZATION AUDIT\n";
echo "======================================================================\n\n";

foreach ($queries as $q) {
    $resStaff = $staffTools->searchPatientByName(['query' => $q], 1);
    $cntStaff = $resStaff['match_count'] ?? count($resStaff['patients'] ?? []);
    echo "Query: '{$q}' -> Matches: {$cntStaff}\n";
    foreach ($resStaff['patients'] ?? [] as $p) {
        echo "  • " . $p['name'] . " (" . $p['email'] . ")\n";
    }
}
