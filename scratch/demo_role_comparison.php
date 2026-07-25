<?php
/**
 * demo_role_comparison.php — Interactive Role-Based Assistant Comparison Demo
 * Open in browser (http://localhost/CLINICK/scratch/demo_role_comparison.php) or run via PHP CLI.
 */

require_once __DIR__ . '/../db.php';
require_once __DIR__ . '/../classes/ai/SecurityGuard.php';
require_once __DIR__ . '/../classes/ai/Tools/ToolRegistry.php';
require_once __DIR__ . '/../classes/ai/Personas/PatientSecretary.php';
require_once __DIR__ . '/../classes/ai/Personas/AdminSecretary.php';
require_once __DIR__ . '/../classes/ai/Personas/DoctorSecretary.php';

$db = get_db_connection();
$security = new SecurityGuard($db);
$tools = new ToolRegistry($db);

$roles = ['Patient', 'Admin', 'Doctor'];

$isHtml = (php_sapi_name() !== 'cli');

if ($isHtml) {
    header('Content-Type: text/html; charset=utf-8');
    echo "<!DOCTYPE html><html><head><title>CLINICK AI Role Comparison</title>";
    echo "<style>
        body { font-family: 'Segoe UI', system-ui, sans-serif; background: #0f172a; color: #f8fafc; padding: 20px; }
        h1 { color: #38bdf8; text-align: center; }
        .grid { display: grid; grid-template-columns: repeat(auto-fit, minmax(320px, 1fr)); gap: 20px; margin-top: 20px; }
        .card { background: #1e293b; border-radius: 12px; padding: 20px; border: 1px solid #334155; }
        .badge { display: inline-block; padding: 4px 10px; border-radius: 20px; font-weight: bold; font-size: 0.8rem; margin-bottom: 10px; }
        .badge-patient { background: #0284c7; color: white; }
        .badge-admin { background: #7c3aed; color: white; }
        .badge-doctor { background: #059669; color: white; }
        ul { padding-left: 20px; color: #cbd5e1; }
        pre { background: #0f172a; padding: 10px; border-radius: 6px; font-size: 0.82rem; color: #38bdf8; overflow-x: auto; }
        .sample { background: #334155; padding: 10px; border-radius: 6px; font-style: italic; color: #f1f5f9; margin-top: 10px; }
    </style></head><body>";
    echo "<h1>🤖 CLINICK Role-Based AI Assistant System Comparison</h1>";
    echo "<p style='text-align:center; color:#94a3b8;'>Each user role gets a dedicated Personal Secretary with scoped permissions, unique tools, specialized system prompts, and strict confidentiality boundaries.</p>";
    echo "<div class='grid'>";
}

foreach ($roles as $role) {
    $badgeClass = 'badge-' . strtolower($role);
    $declarations = $tools->getDeclarationsForRole($role);
    $toolNames = array_map(fn($t) => $t['name'], $declarations);

    $promptText = match ($role) {
        'Patient' => (new PatientSecretary($db))->buildSystemPrompt(1, ['selected_date' => date('Y-m-d')], 'Patient requested appointment slots.'),
        'Admin'   => (new AdminSecretary($db))->buildSystemPrompt(1, ['date' => date('Y-m-d')], 'Admin reviewed daily dashboard.'),
        'Doctor'  => (new DoctorSecretary($db))->buildSystemPrompt(1, ['date' => date('Y-m-d')], 'Doctor checked morning consultation schedule.'),
    };

    $sampleQ = match ($role) {
        'Patient' => "User: \"Can I book a consultation tomorrow?\"\nAI: \"Of course! Dr. Santos and Dr. Cruz are available tomorrow. Would you prefer a morning or afternoon slot?\"",
        'Admin'   => "Admin: \"What should I focus on today?\"\nAI: \"Today's operational summary:\n• 24 appointments scheduled\n• 3 pending approvals\n• 2 high-risk patients require review\n\nRecommended actions:\n1. Review pending approvals\n2. Contact high-risk patients\"",
        'Doctor'  => "Doctor: \"Show my assigned patients for today.\"\nAI: \"Dr. Santos, you have 5 consultations scheduled today:\n1. Juan Dela Cruz (09:00 AM - General Checkup)\n2. Maria Santos (10:00 AM - Follow-up)\"",
    };

    if ($isHtml) {
        echo "<div class='card'>";
        echo "<span class='badge {$badgeClass}'>" . strtoupper($role) . " ASSISTANT</span>";
        echo "<h3>" . ($role === 'Patient' ? 'Personal Clinic Assistant' : ($role === 'Admin' ? 'AI Operations Secretary' : 'Clinical Workflow Assistant')) . "</h3>";
        echo "<h4>Authorized Deterministic Tools (" . count($toolNames) . "):</h4>";
        echo "<ul>";
        foreach ($toolNames as $tn) {
            echo "<li><code>$tn()</code></li>";
        }
        echo "</ul>";
        echo "<h4>Example Conversation:</h4>";
        echo "<div class='sample'><pre>" . htmlspecialchars($sampleQ) . "</pre></div>";
        echo "</div>";
    } else {
        echo "\n========================================\n";
        echo "ROLE: " . strtoupper($role) . "\n";
        echo "Authorized Tools: " . implode(', ', $toolNames) . "\n";
        echo "----------------------------------------\n";
        echo $sampleQ . "\n";
    }
}

if ($isHtml) {
    echo "</div></body></html>";
}
