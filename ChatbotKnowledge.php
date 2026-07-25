<?php
/**
 * ChatbotKnowledge.php — Role-Aware RAG Knowledge Base for MediBot
 *
 * Manages the kb_chunks table: seeds role-scoped clinic knowledge (Patient, Doctor, Staff, Admin),
 * runs BM25-style keyword search to retrieve relevant context filtered by user role before calling AI.
 */
class ChatbotKnowledge
{
    private SQLite3 $db;

    public function __construct(SQLite3 $db)
    {
        $this->db = $db;
        $this->ensureSeeded();
    }

    /**
     * Seed the KB on first run or re-seed if role_scope chunks are missing.
     */
    private function ensureSeeded(): void
    {
        $count = $this->db->querySingle("SELECT COUNT(*) FROM kb_chunks");
        if ($count > 0) return;

        $chunks = [
            // ── GENERAL CHUNKS (All Roles) ──────────────────────────────
            [
                'category'   => 'hours',
                'role_scope' => 'all',
                'title'      => 'Clinic Operating Hours',
                'content'    => 'CLINICK is open Monday through Saturday, 8:00 AM to 6:00 PM. The clinic is closed on Sundays and public holidays. Emergency services and on-call support are available 24/7.',
                'keywords'   => 'hours,open,close,schedule,time,operating,monday,saturday,sunday,holiday,emergency,24/7',
            ],
            [
                'category'   => 'about',
                'role_scope' => 'all',
                'title'      => 'About CLINICK System',
                'content'    => 'CLINICK is an enterprise clinic management platform connecting patients, doctors, staff, and administrators for appointments, electronic health records, prescriptions, and queue tracking.',
                'keywords'   => 'about,clinick,system,portal,platform,overview',
            ],

            // ── PATIENT ASSISTANT CHUNKS (Patient Only) ──────────────────
            [
                'category'   => 'appointments',
                'role_scope' => 'patient',
                'title'      => 'How Patients Book an Appointment',
                'content'    => 'To book an appointment: (1) Go to the Appointments tab in your Patient Dashboard, (2) Select your preferred doctor and available time slot, (3) Confirm booking. You will be assigned a Queue Number (Q-#).',
                'keywords'   => 'book,booking,appointment,schedule,slot,doctor,queue,how to,patient',
            ],
            [
                'category'   => 'appointments',
                'role_scope' => 'patient',
                'title'      => 'How Patients Cancel or Reschedule',
                'content'    => 'To cancel or reschedule your visit: Go to Patient Dashboard > Appointments, locate your appointment, and click Cancel or Reschedule. Cancellations must be made at least 2 hours before the visit.',
                'keywords'   => 'cancel,cancellation,reschedule,rebook,move,change,appointment',
            ],
            [
                'category'   => 'queue',
                'role_scope' => 'patient',
                'title'      => 'Patient Queue Tracking',
                'content'    => 'Track your position in real-time under the Patient Dashboard Overview tab. Your card displays your Queue Number, number of patients ahead, and estimated wait time.',
                'keywords'   => 'queue,number,wait,waiting,position,ahead,estimated,live tracker',
            ],
            [
                'category'   => 'records',
                'role_scope' => 'patient',
                'title'      => 'Viewing Patient Prescriptions & Medical Records',
                'content'    => 'Patients can view past consultation records, diagnosis notes, and written prescriptions by navigating to the Medical Records tab in the Patient Dashboard.',
                'keywords'   => 'records,prescription,rx,diagnosis,history,past visits,my records',
            ],

            // ── DOCTOR ASSISTANT CHUNKS (Doctor Only) ───────────────────
            [
                'category'   => 'clinical',
                'role_scope' => 'doctor',
                'title'      => 'Doctor Consultation & Prescription Workflow',
                'content'    => 'To prescribe medication: (1) Open Doctor Dashboard, (2) Go to Patient Schedule or Prescribe Meds tab, (3) Select the patient, (4) Enter diagnosis, medication name, dosage, frequency, and duration, (5) Click Issue Prescription. The prescription will immediately save to SQLite and update the patient\'s record.',
                'keywords'   => 'prescribe,medication,rx,doctor,write rx,dosage,frequency,diagnosis,issue',
            ],
            [
                'category'   => 'clinical',
                'role_scope' => 'doctor',
                'title'      => 'Managing Work Availability & Shifts',
                'content'    => 'Doctors can set their monthly availability in Doctor Dashboard > Work Availability. Click any calendar date to toggle status (Available/Unavailable) or set shift hours. Use "Generate Default Shifts" for bulk Mon-Fri scheduling.',
                'keywords'   => 'availability,shifts,work availability,schedule,calendar,hours,duty,doctor shift',
            ],
            [
                'category'   => 'clinical',
                'role_scope' => 'doctor',
                'title'      => 'Viewing Doctor Daily Consultations',
                'content'    => 'Doctors can review today\'s patient list under Doctor Dashboard Overview or Patient Schedule. Click "Complete" when a consultation finishes to update queue status.',
                'keywords'   => 'daily consultations,today appointments,patient list,complete consultation,queue update',
            ],

            // ── STAFF ASSISTANT CHUNKS (Staff Only) ────────────────────
            [
                'category'   => 'reception',
                'role_scope' => 'staff',
                'title'      => 'Staff Front Desk Patient Check-In',
                'content'    => 'Reception staff can check in arriving patients via Staff Dashboard > Queue Management. Locate the patient\'s appointment and mark status as "Checked-In" to alert the doctor.',
                'keywords'   => 'check-in,checkin,reception,front desk,arrival,queue management,staff',
            ],
            [
                'category'   => 'reception',
                'role_scope' => 'staff',
                'title'      => 'Walk-in Patient Registration',
                'content'    => 'For walk-in patients: Go to Registered Patients tab, click "Register New Patient", enter demographics, then assign a walk-in queue slot under Appointments.',
                'keywords'   => 'walk-in,walkin,register patient,new patient,front desk,receptionist',
            ],
            [
                'category'   => 'reception',
                'role_scope' => 'staff',
                'title'      => 'Verifying Patient HMO & PhilHealth Status',
                'content'    => 'Staff must verify patient HMO card validity or PhilHealth MDR form upon check-in. Note the approval code in the patient record notes.',
                'keywords'   => 'hmo,philhealth,insurance,verification,card,approval,staff check',
            ],

            // ── ADMIN ASSISTANT CHUNKS (Admin Only) ────────────────────
            [
                'category'   => 'admin',
                'role_scope' => 'admin',
                'title'      => 'Admin User Management & Account Approvals',
                'content'    => 'System administrators approve new doctor/staff registrations in Admin Dashboard > Account Approvals. Admins can assign user roles (Admin, Doctor, Staff, Patient) and activate or suspend accounts.',
                'keywords'   => 'approvals,pending approvals,user management,roles,suspend,activate,admin',
            ],
            [
                'category'   => 'admin',
                'role_scope' => 'admin',
                'title'      => 'System Audit Logs & Security',
                'content'    => 'Admin Dashboard > Audit Logs records every critical system event including logins, patient data access, prescription issuances, and account role changes.',
                'keywords'   => 'audit logs,security,system logs,events,user activity,track,admin log',
            ],
            [
                'category'   => 'admin',
                'role_scope' => 'admin',
                'title'      => 'Clinic Analytics & Performance Reports',
                'content'    => 'Admins can view clinic revenue, total consultations completed, doctor utilization rates, and ISO 25010 system metrics under Admin Dashboard Analytics.',
                'keywords'   => 'analytics,reports,performance,revenue,consultations count,metrics,iso 25010',
            ],
        ];

        $stmt = $this->db->prepare(
            "INSERT INTO kb_chunks (category, role_scope, title, content, keywords) VALUES (:cat, :role, :title, :content, :kw)"
        );

        foreach ($chunks as $chunk) {
            $stmt->bindValue(':cat',     $chunk['category'],   SQLITE3_TEXT);
            $stmt->bindValue(':role',    $chunk['role_scope'], SQLITE3_TEXT);
            $stmt->bindValue(':title',   $chunk['title'],      SQLITE3_TEXT);
            $stmt->bindValue(':content', $chunk['content'],    SQLITE3_TEXT);
            $stmt->bindValue(':kw',      $chunk['keywords'],   SQLITE3_TEXT);
            $stmt->execute();
            $stmt->reset();
        }
    }

    /**
     * BM25-style keyword search filtered by user role.
     */
    public function search(string $query, int $limit = 3, string $userRole = 'patient'): array
    {
        $tokens = $this->tokenize($query);
        if (empty($tokens)) return [];

        $roleNorm = strtolower(trim($userRole));
        if (!in_array($roleNorm, ['patient', 'doctor', 'staff', 'admin'])) {
            $roleNorm = 'patient';
        }

        // Fetch chunks accessible to this role (matching role or 'all')
        $stmt = $this->db->prepare(
            "SELECT id, category, role_scope, title, content, keywords FROM kb_chunks WHERE role_scope = :role OR role_scope = 'all'"
        );
        $stmt->bindValue(':role', $roleNorm, SQLITE3_TEXT);
        $result = $stmt->execute();

        $scored = [];
        while ($row = $result->fetchArray(SQLITE3_ASSOC)) {
            $score = $this->score($tokens, $row);
            if ($score > 0) {
                $scored[] = ['score' => $score, 'chunk' => $row];
            }
        }

        usort($scored, fn($a, $b) => $b['score'] <=> $a['score']);
        $top = array_slice($scored, 0, $limit);

        return array_map(fn($s) => $s['chunk'], $top);
    }

    /**
     * Format retrieved chunks into a compact context block for Gemini system prompt.
     */
    public function formatContext(array $chunks): string
    {
        if (empty($chunks)) return '';

        $lines = ["[ROLE-SCOPED CLINIC CONTEXT — use this to answer the user's question]"];
        foreach ($chunks as $chunk) {
            $lines[] = "## {$chunk['title']} (Category: {$chunk['category']})";
            $lines[] = $chunk['content'];
            $lines[] = '';
        }

        return implode("\n", $lines);
    }

    // ── Private Helpers ───────────────────────────────────────────────

    private function tokenize(string $text): array
    {
        $text   = mb_strtolower($text);
        $text   = preg_replace('/[^a-z0-9\s\-]/u', ' ', $text);
        $tokens = preg_split('/\s+/', trim($text));
        return array_values(array_filter($tokens, fn($t) => strlen($t) > 1));
    }

    private function score(array $tokens, array $chunk): float
    {
        $score     = 0.0;
        $kw        = mb_strtolower($chunk['keywords'] ?? '');
        $content   = mb_strtolower($chunk['content']);
        $title     = mb_strtolower($chunk['title']);
        $kwTokens  = preg_split('/[,\s]+/', $kw);

        foreach ($tokens as $token) {
            if (in_array($token, $kwTokens, true)) {
                $score += 3.0;
            }
            if (str_contains($title, $token)) {
                $score += 2.0;
            }
            $count = substr_count($content, $token);
            $score += min($count, 3) * 0.5;
        }

        return $score;
    }
}
