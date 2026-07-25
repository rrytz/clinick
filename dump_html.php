<?php
// Start session and mock admin login
session_start();
$_SESSION['user_id'] = 10; // Super Admin ID
$_SESSION['user_name'] = 'Super Admin';
$_SESSION['user_role'] = 'Admin';

// Capture output of admin_dashboard.php
$_GET['tab'] = 'users';
ob_start();
include __DIR__ . '/admin_dashboard.php';
$html = ob_get_clean();

// Find the table and output it
if (preg_match('/<table class="data-table">.*?<\/table>/is', $html, $matches)) {
    echo "FOUND TABLE:\n";
    echo htmlspecialchars($matches[0]);
} else {
    echo "TABLE NOT FOUND. FULL HTML LENGTH: " . strlen($html);
}
?>
