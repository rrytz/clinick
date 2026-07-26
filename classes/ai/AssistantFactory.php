<?php
/**
 * AssistantFactory.php — Orchestration Factory for CLINICK Role-Based AI Assistants
 */

require_once __DIR__ . '/SecurityGuard.php';
require_once __DIR__ . '/MemoryManager.php';
require_once __DIR__ . '/GeminiAdapter.php';
require_once __DIR__ . '/Tools/ToolRegistry.php';
require_once __DIR__ . '/Personas/PatientSecretary.php';
require_once __DIR__ . '/Personas/AdminSecretary.php';
require_once __DIR__ . '/Personas/DoctorSecretary.php';
require_once __DIR__ . '/Personas/StaffSecretary.php';
require_once __DIR__ . '/CrisisDetector.php';

class AssistantFactory
{
    private SQLite3 $db;
    private SecurityGuard $security;
    private MemoryManager $memory;
    private GeminiAdapter $gemini;
    private ToolRegistry $tools;

    public function __construct(SQLite3 $db)
    {
        $this->db       = $db;
        $this->security = new SecurityGuard($db);
        $this->memory   = new MemoryManager($db);
        $this->gemini   = new GeminiAdapter();
        $this->tools    = new ToolRegistry($db);
    }

    /**
     * Processes an incoming conversation message and returns the role-scoped AI Assistant response.
     */
    public function handleMessage(string $userMessage, ?int $userId, string $role = 'Patient', ?int $conversationId = null): array
    {
        // 1. Authenticate & Validate RBAC
        $auth = $this->security->validateUser($userId, $role);
        if (!$auth['valid']) {
            return [
                'success' => false,
                'error'   => $auth['error'],
            ];
        }

        $validUserId = $auth['user_id'];
        $validRole   = $auth['role'];

        // 2. Enforce Rate Limit
        if (!$this->security->checkRateLimit($validUserId)) {
            return [
                'success' => false,
                'error'   => 'Rate limit exceeded. Please wait a minute before sending another message.',
            ];
        }

        // 3. Sanitize User Input
        $cleanMessage = $this->security->sanitizeInput($userMessage);

        // 3.5 Hard Crisis / Self-Harm Interception Point (BEFORE Memory / Gemini execution)
        if (CrisisDetector::isCrisisMessage($cleanMessage)) {
            $convId = $this->memory->getOrCreateConversation($validUserId, $validRole, $conversationId);
            $this->memory->saveMessage($convId, 'user', $cleanMessage);

            $crisisReply = CrisisDetector::getCrisisResponse($cleanMessage);

            $this->memory->saveMessage($convId, 'assistant', $crisisReply);

            $assistantName = match ($validRole) {
                'Admin'  => 'AI Operations Secretary',
                'Staff'  => 'Frontdesk Assistant',
                'Doctor' => 'Clinical Workflow Assistant',
                default  => 'Personal Clinic Assistant',
            };

            return [
                'success'             => true,
                'conversation_id'     => $convId,
                'role'                => $validRole,
                'assistant_name'      => $assistantName,
                'reply'               => $crisisReply,
                'tool_calls_executed' => [],
                'timestamp'           => date('c'),
                'degraded'            => false,
            ];
        }

        // 4. Session & Conversation History Management
        $convId = $this->memory->getOrCreateConversation($validUserId, $validRole, $conversationId);
        $this->memory->saveMessage($convId, 'user', $cleanMessage);

        // 5. Load Memory Context & Summaries
        $contextData   = $this->memory->getContext($convId);
        $summaryMemory = $this->memory->getSummary($convId);

        // 6. Build Role System Prompt
        $systemPrompt = match ($validRole) {
            'Admin'  => (new AdminSecretary($this->db))->buildSystemPrompt($validUserId, $contextData, $summaryMemory),
            'Staff'  => (new StaffSecretary($this->db))->buildSystemPrompt($validUserId, $contextData, $summaryMemory),
            'Doctor' => (new DoctorSecretary($this->db))->buildSystemPrompt($validUserId, $contextData, $summaryMemory),
            default  => (new PatientSecretary($this->db))->buildSystemPrompt($validUserId, $contextData, $summaryMemory),
        };

        // 7. Select Model & Tool Declarations
        $preferredModel = ($validRole === 'Admin') ? 'gemini-2.5-pro' : 'gemini-2.5-flash';
        $declarations   = $this->tools->getDeclarationsForRole($validRole);
        $recentHistory  = $this->memory->getRecentHistory($convId);

        // Build Gemini payload
        $payload = [
            'system_instruction' => ['parts' => [['text' => $systemPrompt]]],
            'contents'           => $recentHistory,
            'tools'              => [['function_declarations' => $declarations]],
            'generationConfig'   => [
                'temperature'     => 0.6,
                'maxOutputTokens' => 800,
                'topP'            => 0.95,
            ],
        ];

        // 8. Execute Multi-Turn Function Calling Loop
        $replyText       = '';
        $executedTools   = [];
        $maxLoops        = 3;
        $loopCount       = 0;

        while ($loopCount < $maxLoops) {
            $loopCount++;
            $response = $this->gemini->generateContent($payload, $preferredModel);

            if (isset($response['error'])) {
                return $this->fallbackRoleResponse($cleanMessage, $validUserId, $validRole, $convId);
            }

            $candidate    = $response['candidates'][0] ?? [];
            $contentParts = $candidate['content']['parts'] ?? [];

            $functionCallFound = false;
            foreach ($contentParts as $part) {
                if (isset($part['text'])) {
                    $replyText .= $part['text'];
                }

                if (isset($part['functionCall'])) {
                    $functionCallFound = true;
                    $fnName = $part['functionCall']['name'];
                    $fnArgs = $part['functionCall']['args'] ?? [];

                    $executedTools[] = $fnName;

                    // Append model's functionCall turn to history & payload
                    $payload['contents'][] = [
                        'role'  => 'model',
                        'parts' => [['functionCall' => ['name' => $fnName, 'args' => $fnArgs]]],
                    ];

                    // Execute Tool Call
                    $toolOutput = $this->tools->executeToolCall($fnName, $fnArgs, $validUserId, $validRole, $convId);

                    // Update Entity Context state if doctor/date selected
                    if (isset($fnArgs['doctor_id'])) {
                        $this->memory->setContext($convId, $validUserId, 'selected_doctor', $fnArgs['doctor_id']);
                    }
                    if (isset($fnArgs['date'])) {
                        $this->memory->setContext($convId, $validUserId, 'selected_date', $fnArgs['date']);
                    }

                    // Append tool response to payload
                    $payload['contents'][] = [
                        'role'  => 'user',
                        'parts' => [[
                            'functionResponse' => [
                                'name'     => $fnName,
                                'response' => ['content' => $toolOutput],
                            ],
                        ]],
                    ];
                }
            }

            // Break loop if no functionCall was requested by model
            if (!$functionCallFound) {
                break;
            }
        }

        if (empty(trim($replyText))) {
            $replyText = "How else may I assist you with your clinic activities today?";
        }

        // 9. Sanitize Output & Save Assistant Message
        $cleanReply = $this->security->sanitizeOutput($replyText);
        $this->memory->saveMessage($convId, 'assistant', $cleanReply);

        $assistantName = match ($validRole) {
            'Admin'  => 'AI Operations Secretary',
            'Staff'  => 'Frontdesk Assistant',
            'Doctor' => 'Clinical Workflow Assistant',
            default  => 'Personal Clinic Assistant',
        };

        return [
            'success'              => true,
            'conversation_id'      => $convId,
            'role'                 => $validRole,
            'assistant_name'       => $assistantName,
            'reply'                => $cleanReply,
            'tool_calls_executed'  => array_values(array_unique($executedTools)),
            'timestamp'            => date('c'),
        ];
    }

    /**
     * Fallback deterministic execution engine when AI API is offline or quota exhausted.
     * Ensures the assistant ALWAYS responds in its true Role Persona with live DB facts.
     */
    private function fallbackRoleResponse(string $message, int $userId, string $role, int $convId): array
    {
        if (CrisisDetector::isCrisisMessage($message)) {
            $crisisReply = CrisisDetector::getCrisisResponse($message);

            $this->memory->saveMessage($convId, 'assistant', $crisisReply);

            $assistantName = match ($role) {
                'Admin'  => 'AI Operations Secretary',
                'Staff'  => 'Frontdesk Assistant',
                'Doctor' => 'Clinical Workflow Assistant',
                default  => 'Personal Clinic Assistant',
            };

            return [
                'success'             => true,
                'conversation_id'     => $convId,
                'role'                => $role,
                'assistant_name'      => $assistantName,
                'reply'               => $crisisReply,
                'tool_calls_executed' => [],
                'timestamp'           => date('c'),
                'degraded'            => false,
            ];
        }

        $lower = strtolower($message);
        $executedTools = [];
        $reply = '';

        if ($role === 'Admin') {
            $assistantName = 'AI Operations Secretary';
            if (str_contains($lower, 'focus') || str_contains($lower, 'today') || str_contains($lower, 'summary') || str_contains($lower, 'stat') || str_contains($lower, 'dashboard')) {
                $daily = $this->tools->executeToolCall('getDailyStats', [], $userId, $role, $convId);
                $pending = $this->tools->executeToolCall('getPendingApprovals', [], $userId, $role, $convId);
                $highRisk = $this->tools->executeToolCall('getHighRiskPatients', [], $userId, $role, $convId);
                $executedTools = ['getDailyStats', 'getPendingApprovals', 'getHighRiskPatients'];

                $bookedDocs = !empty($daily['fully_booked_doctors']) ? implode(', ', $daily['fully_booked_doctors']) : 'None';

                $reply = "Today's operational summary:\n\n" .
                         "• " . ($daily['scheduled_appointments'] ?? 0) . " appointments scheduled\n" .
                         "• " . ($pending['pending_count'] ?? 0) . " pending approvals\n" .
                         "• " . ($highRisk['high_risk_count'] ?? 0) . " high-risk patients require review\n" .
                         "• Fully-booked doctors: {$bookedDocs}\n" .
                         "• No-show rate yesterday: " . ($daily['yesterday_no_show_rate_pct'] ?? 0) . "%\n\n" .
                         "Recommended actions:\n" .
                         "1. Review pending approvals\n" .
                         "2. Contact high-risk patients\n" .
                         "3. Consider opening additional consultation slots";
            } else {
                $reply = "Hello! I am your AI Operations Secretary. How can I assist you with clinic performance summaries, doctor workload, pending approvals, or no-show analytics today?";
            }
        } elseif ($role === 'Doctor') {
            $assistantName = 'Clinical Workflow Assistant';
            if (str_contains($lower, 'prescription') || str_contains($lower, 'prescribe') || str_contains($lower, 'rx') || str_contains($lower, 'medication') || str_contains($lower, 'meds')) {
                $rx = $this->tools->executeToolCall('getPrescriptionLog', [], $userId, $role, $convId);
                $executedTools = ['getPrescriptionLog'];
                $logCount = $rx['log_count'] ?? 0;
                $lines = [];
                foreach ($rx['logs'] ?? [] as $item) {
                    $lines[] = "• **{$item['medication']}** ({$item['dosage']}) - Patient: **{$item['patient_name']}** (" . date('M j', strtotime($item['created_at'])) . ")";
                }
                $logStr = !empty($lines) ? implode("\n", $lines) : "No prescriptions logged recently.";

                $reply = "💊 **Prescription Log & Writing Guide**\n\nYou have **{$logCount}** recent prescription entry(ies):\n\n{$logStr}\n\nTo prescribe new medication, click the **'Prescription'** tab on your dashboard toolbar.";
            } elseif (str_contains($lower, 'availability') || str_contains($lower, 'avail') || str_contains($lower, 'shift') || str_contains($lower, 'off') || str_contains($lower, 'duty') || str_contains($lower, 'calendar')) {
                $avail = $this->tools->executeToolCall('getDoctorAvailability', [], $userId, $role, $convId);
                $executedTools = ['getDoctorAvailability'];
                $aDays = $avail['available_days'] ?? 0;
                $uDays = $avail['unavailable_days'] ?? 0;

                $reply = "📅 **Work Availability & Shifts**\n\nYour schedule overview for " . date('F Y') . ":\n" .
                         "• Available Days: **{$aDays}**\n" .
                         "• Days Off / Unavailable: **{$uDays}**\n\n" .
                         "To update your clinical shifts, click the **'Availability'** tab on your dashboard.";
            } elseif (str_contains($lower, 'next') || str_contains($lower, 'who is next')) {
                $next = $this->tools->executeToolCall('getNextPatient', [], $userId, $role, $convId);
                $executedTools = ['getNextPatient'];
                if (!empty($next['has_next']) && !empty($next['next_patient'])) {
                    $np = $next['next_patient'];
                    $reply = "🩺 **Next Patient in Queue**\n\n• **Patient Name**: " . $np['patient_name'] . "\n• **Queue Ticket**: Q-" . ($np['queue_number'] ?? '1') . "\n• **Time Slot**: " . $np['time_slot'] . "\n• **Reason**: " . ($np['reason'] ?: 'Follow-up / Consultation') . "\n• **Status**: " . $np['status'];
                } else {
                    $reply = "🩺 **Next Patient Queue**\n\nThere are currently no remaining patients waiting in your queue for today.";
                }
            } elseif (str_contains($lower, 'record') || str_contains($lower, 'records') || str_contains($lower, 'history') || str_contains($lower, 'chart') || str_contains($lower, 'search') || str_contains($lower, 'lookup') || str_contains($lower, 'find')) {
                $cleanSearch = trim(preg_replace('/^(show|view|find|search|lookup|patient|records|history|chart|for)+/i', '', $message));
                if (empty($cleanSearch)) { $cleanSearch = $message; }

                $rec = $this->tools->executeToolCall('searchAssignedPatientRecords', ['query' => $cleanSearch], $userId, $role, $convId);
                $executedTools = ['searchAssignedPatientRecords'];
                $matchCount = $rec['match_count'] ?? 0;

                if ($matchCount > 0) {
                    $lines = [];
                    foreach ($rec['patients'] ?? [] as $p) {
                        $lines[] = "• **{$p['name']}** (ID #{$p['id']}) | Email: {$p['email']}";
                    }
                    $pList = implode("\n", $lines);
                    $reply = "📋 **Assigned Patient Records for '{$cleanSearch}'**\n\nFound **{$matchCount}** matching patient record(s):\n\n{$pList}\n\nWould you like me to pull detailed consultation notes or prescription history for a specific patient ID?";
                } else {
                    $reply = "📋 **Assigned Patient Search**\n\nNo assigned patient records found matching **'{$cleanSearch}'**. You can inspect all registered patients under the **'Patients'** tab.";
                }
            } elseif (str_contains($lower, 'workflow') || str_contains($lower, 'guide') || str_contains($lower, 'how to conduct') || str_contains($lower, 'process')) {
                $next = $this->tools->executeToolCall('getNextPatient', [], $userId, $role, $convId);
                $executedTools = ['getNextPatient'];
                $nextStr = (!empty($next['has_next']) && !empty($next['next_patient']))
                    ? "Current next patient: **" . $next['next_patient']['patient_name'] . "** (Q-" . ($next['next_patient']['queue_number'] ?? '1') . ")"
                    : "No patients currently waiting in active queue.";

                $reply = "🩺 **CLINICK Clinical Consultation Workflow**\n\nHere is the standard 4-step doctor consultation process:\n\n" .
                         "1. 📋 **Queue Check**: Review your queue in the Overview table or ask me for your next patient ({$nextStr}).\n" .
                         "2. 🔍 **Record Review**: Inspect patient medical history and past prescription logs under **'Patients'**.\n" .
                         "3. 🩺 **Clinical Assessment**: Conduct the patient examination and record findings.\n" .
                         "4. 💊 **Prescribe & Complete**: Issue prescriptions under **'Prescription'**, then click **'Complete'** on the appointment schedule.\n\n" .
                         "How else can I assist your consultation workflow today?";
            } elseif (str_contains($lower, 'appointment') || str_contains($lower, 'appointments') || str_contains($lower, 'today') || str_contains($lower, 'schedule') || str_contains($lower, 'assigned') || str_contains($lower, 'complete') || str_contains($lower, 'consultation')) {
                $assigned = $this->tools->executeToolCall('getAssignedPatients', [], $userId, $role, $convId);
                $executedTools = ['getAssignedPatients'];
                $count = $assigned['patient_count'] ?? 0;
                $lines = [];
                foreach ($assigned['assigned_list'] ?? [] as $p) {
                    $lines[] = "• **" . $p['time_slot'] . "** — **" . $p['patient_name'] . "** (Q-" . ($p['queue_number'] ?? '-') . ") | " . ($p['reason'] ?: 'General Consultation') . " [" . strtoupper($p['status']) . "]";
                }
                $listStr = !empty($lines) ? implode("\n", $lines) : "No consultations scheduled for today.";

                $reply = "📋 **Today's Patient Schedule ({$count} Consultations)**\n\n{$listStr}\n\nNeed details on a specific patient or consultation history?";
            } else {
                $reply = "👋 **Clinical Workflow Assistant**\n\nI am ready to assist you, Doctor! Here is what I can do:\n\n" .
                         "1. 📅 **Today's Consultation Schedule**: View your queue and patient appointment list.\n" .
                         "2. 🩺 **Next Patient Lookup**: Check who is next in your consultation queue.\n" .
                         "3. 💊 **Prescription Log**: Review recent prescriptions issued.\n" .
                         "4. 📋 **Patient File Lookup**: Search clinical records for assigned patients.\n" .
                         "5. 🗓️ **Work Availability**: Check your active shift schedules.\n\n" .
                         "How can I assist your clinical workflow today?";
            }
        } elseif ($role === 'Staff') {
            $assistantName = 'Frontdesk Assistant';
            if (str_contains($lower, 'function') || str_contains($lower, 'capability') || str_contains($lower, 'capabilities') || str_contains($lower, 'what can you do') || str_contains($lower, 'who are you') || str_contains($lower, 'features') || str_contains($lower, 'help')) {
                $docs = $this->tools->executeToolCall('getAvailableDoctors', [], $userId, $role, $convId);
                $q = $this->tools->executeToolCall('getClinicQueueOverview', [], $userId, $role, $convId);
                $executedTools = ['getAvailableDoctors', 'getClinicQueueOverview'];
                $availCount = $docs['count'] ?? 0;
                $totalQueue = $q['total_in_queue'] ?? 0;

                $reply = "👋 **Frontdesk Assistant Capabilities**\n\nI am your dedicated operational assistant for clinic frontdesk workflows. Here is everything I can do for you:\n\n" .
                         "1. 📋 **Live Queue Overview**: Check live patient counts across all doctors (currently **{$totalQueue}** patient(s) in queue).\n" .
                         "2. 🚶 **Walk-in Registration Guide**: Guide you through registering walk-in/phone appointments (**{$availCount} doctor(s) available today**).\n" .
                         "3. ✅ **Patient Check-in Support**: Step-by-step guidance on checking in scheduled patients.\n" .
                         "4. 🔍 **Patient Demographics Lookup**: Search patient records by name or email (e.g. *'is there someone named Rivera'*).\n" .
                         "5. 🩺 **Doctor Availability**: Check on-duty schedules and consultation slots.\n\n" .
                         "How can I assist you with frontdesk operations right now?";
            } elseif (str_contains($lower, 'queue') || str_contains($lower, 'line') || str_contains($lower, 'waiting')) {
                $q = $this->tools->executeToolCall('getClinicQueueOverview', [], $userId, $role, $convId);
                $executedTools = ['getClinicQueueOverview'];
                $total = $q['total_in_queue'] ?? 0;
                $inRoom = $q['currently_in_room'] ?? 0;
                $reply = "📋 **Frontdesk Queue Status**\n\nThere are currently **{$total}** patient(s) in today's clinic queue (**{$inRoom}** currently in consultation room).\n\nNeed help checking in a patient or registering a walk-in?";
            } elseif (str_contains($lower, 'walk-in') || str_contains($lower, 'walkin') || str_contains($lower, 'guide')) {
                $docs = $this->tools->executeToolCall('getAvailableDoctors', [], $userId, $role, $convId);
                $executedTools = ['getAvailableDoctors'];
                $availCount = $docs['count'] ?? 0;
                $reply = "🚶 **Walk-in Patient Guide**\n\nHere is how to register a walk-in patient:\n\n1. Click the **'Book Walk-in/Phone'** button on your dashboard toolbar.\n2. Select an existing patient or fill in new patient details.\n3. Select an available doctor (**{$availCount} doctor(s) on-duty today**).\n4. Choose a time slot and click **Submit** to place them into the live queue!";
            } elseif (str_contains($lower, 'check-in') || str_contains($lower, 'checkin') || str_contains($lower, 'arrived') || str_contains($lower, 'present')) {
                $q = $this->tools->executeToolCall('getClinicQueueOverview', [], $userId, $role, $convId);
                $executedTools = ['getClinicQueueOverview'];
                $total = $q['total_in_queue'] ?? 0;
                $reply = "✅ **Patient Check-in Guide**\n\nTo check in a scheduled patient:\n\n1. Search for the patient's name in the **Live Schedule List** table.\n2. Verify their appointment time and details.\n3. Click the **'Check-In'** action button.\n\nCurrently **{$total}** patient(s) are in the active queue.";
            } elseif (str_contains($lower, 'doctor') || str_contains($lower, 'dr') || str_contains($lower, 'duty') || str_contains($lower, 'schedule') || str_contains($lower, 'available')) {
                $docs = $this->tools->executeToolCall('getAvailableDoctors', [], $userId, $role, $convId);
                $executedTools = ['getAvailableDoctors'];
                $listStr = [];
                foreach ($docs['doctors'] ?? [] as $d) {
                    $listStr[] = "• **" . $d['doctor_name'] . "** (" . $d['specialization'] . ") - Status: " . $d['status'];
                }
                $docList = !empty($listStr) ? implode("\n", $listStr) : "• No doctors currently listed as available today.";
                $reply = "🩺 **Live On-Duty Doctors**\n\nHere are the doctors on-duty for today:\n\n{$docList}\n\nWould you like me to check a specific doctor's consultation slots?";
            } else {
                // Smart Name/Demographic Search Extractor for queries like "is there someone named rivera", "find patient john", "lookup smith"
                $cleanSearch = trim(preg_replace('/^(is there|someone|named|find|search|lookup|who is|patient|check in|check-in|mark|arrived|present|for|a|the)+/i', '', $message));
                if (empty($cleanSearch)) {
                    $cleanSearch = $message;
                }

                $res = $this->tools->executeToolCall('searchPatientByName', ['query' => $cleanSearch], $userId, $role, $convId);
                $executedTools = ['searchPatientByName'];
                $matchCount = $res['match_count'] ?? 0;

                if ($matchCount > 0) {
                    $patientLines = [];
                    foreach ($res['patients'] ?? [] as $p) {
                        $patientLines[] = "• **{$p['name']}** (ID #{$p['id']}) | Email: {$p['email']} | Reg Date: " . substr($p['created_at'], 0, 10);
                    }
                    $pList = implode("\n", $patientLines);
                    $reply = "🔍 **Patient Search Results for '{$cleanSearch}'**\n\nFound **{$matchCount}** matching patient record(s):\n\n{$pList}\n\nHow else can I assist with this patient's frontdesk records?";
                } else {
                    $reply = "🔍 **Patient Search Results for '{$cleanSearch}'**\n\nNo matching patient records found in the clinic database for **'{$cleanSearch}'**.\n\nWould you like to register a new walk-in patient using the **'Book Walk-in/Phone'** button?";
                }
            }
        } else {
            $assistantName = 'Personal Clinic Assistant';
            $symptomKeywords = [
                'breathing', 'breath', 'difficulty breathing', 'shortness of breath', 'hirap huminga', 'hindi makahinga', 'huminga', 'sipon', 'ubo', 'cough',
                'nausea', 'nauseous', 'vomiting', 'vomit', 'diarrhea', 'pagsusuka', 'pagtatae', 'suka', 'tatae', 'tiyan', 'stomach',
                'dizzy', 'dizziness', 'nahihilo', 'hilo', 'headache', 'ulo',
                'symptom', 'symptoms', 'sick', 'illness', 'pain', 'discomfort', 'masakit', 'sumasakit', 'sakit', 'fever', 'lagnat', 'sinat', 'sore', 'lalamunan', 'feel'
            ];

            $isSymptomQuery = false;
            foreach ($symptomKeywords as $kw) {
                if (str_contains($lower, $kw)) {
                    $isSymptomQuery = true;
                    break;
                }
            }

            if ($isSymptomQuery) {
                $symRes = $this->tools->executeToolCall('check_symptoms_naive_bayes', ['symptom_text' => $message], $userId, $role, $convId);
                $executedTools = ['check_symptoms_naive_bayes'];
                if (!empty($symRes['is_emergency'])) {
                    $reply = "⚠️ EMERGENCY WARNING: " . ($symRes['recommendation'] ?? 'Seek immediate care.') . "\n\n" . ($symRes['disclaimer'] ?? '');
                } else {
                    $tier  = $symRes['confidence_tier'] ?? 'Moderate Confidence';
                    $reply = "Based on our Naive Bayes symptom analysis:\n\n" .
                             "• Possible Condition: " . ($symRes['possible_condition'] ?? 'General Assessment') . " (" . $tier . ")\n" .
                             "• Urgency Level: " . ($symRes['urgency_level'] ?? 'Normal Consultation') . "\n" .
                             "• Recommendation: " . ($symRes['recommendation'] ?? 'Please consult a doctor.') . "\n\n" .
                             "⚠️ Note: " . ($symRes['disclaimer'] ?? 'This is educational guidance only, not a medical diagnosis.');
                }
            } elseif (str_contains($lower, 'service') || str_contains($lower, 'offered') || str_contains($lower, 'capability')) {
                $docs = $this->tools->executeToolCall('getAvailableDoctors', [], $userId, $role, $convId);
                $executedTools = ['getAvailableDoctors'];
                $docCount = $docs['count'] ?? count($docs['doctors'] ?? []);

                $reply = "🏥 **CLINICK Services Offered**\n\n" .
                         "Here are the primary clinical services available at CLINICK:\n\n" .
                         "1. 🩺 **General Medicine & Consultations**: Comprehensive outpatient health check-ups.\n" .
                         "2. 👶 **Pediatric & Family Care**: Specialized consultations for infants, children, and adolescents.\n" .
                         "3. 📅 **Online Appointment Booking**: Reserve specific consultation time slots in advance.\n" .
                         "4. 🚶 **Walk-in & Phone Registration**: Priority queue placement for on-site walk-in patients.\n" .
                         "5. 📊 **Real-Time Queue Tracking**: Track live queue position and estimated wait times.\n" .
                         "6. 🩺 **AI Symptom Assessment**: Symptom analysis powered by Naive Bayes classifier.\n\n" .
                         "Currently **{$docCount} on-duty doctor(s)** are available today. Would you like me to check available doctor schedules?";
            } elseif (str_contains($lower, 'hour') || str_contains($lower, 'hours') || str_contains($lower, 'open')) {
                $reply = "🕒 **CLINICK Operating Hours & Schedule**\n\n" .
                         "• **Regular Consultation Hours**: Monday to Saturday, 8:00 AM – 5:00 PM\n" .
                         "• **Walk-in Registration Desk**: 8:00 AM – 4:00 PM\n" .
                         "• **Sunday Operations**: Closed for routine consultations (Emergency on-call only).\n\n" .
                         "Would you like me to check available doctor schedules for today or tomorrow?";
            } elseif (str_contains($lower, 'my appointment') || str_contains($lower, 'my appointments') || str_contains($lower, 'my booking') || str_contains($lower, 'view booking') || str_contains($lower, 'check my appointments')) {
                $q = $this->tools->executeToolCall('getQueueStatus', [], $userId, $role, $convId);
                $appRes = $this->tools->executeToolCall('getAppointmentStatus', [], $userId, $role, $convId);
                $executedTools = ['getQueueStatus', 'getAppointmentStatus'];

                $todayDate = date('Y-m-d');
                $upcomingList = [];
                foreach ($appRes['appointments'] ?? [] as $ap) {
                    if (in_array($ap['status'], ['Scheduled', 'Approved', 'In Progress'], true) && $ap['appointment_date'] >= $todayDate) {
                        $upcomingList[] = $ap;
                    }
                }

                if (!empty($q['has_queue_today'])) {
                    $reply = "📅 **Active Consultation Today (Q-#{$q['queue_number']})**\n\n" .
                             "• **Physician**: " . $q['doctor_name'] . "\n" .
                             "• **Time Slot**: " . $q['time_slot'] . "\n" .
                             "• **Patients Ahead**: " . $q['patients_ahead'] . "\n" .
                             "• **Est. Wait Time**: " . $q['est_wait_minutes'] . " minute(s)\n\n";

                    if (!empty($upcomingList)) {
                        $lines = [];
                        foreach ($upcomingList as $up) {
                            if ($up['appointment_date'] !== $todayDate) {
                                $formattedDate = date('M j, Y', strtotime($up['appointment_date']));
                                $lines[] = "• **{$formattedDate}** at **{$up['time_slot']}** — **{$up['doctor_name']}** (Q-{$up['queue_number']})";
                            }
                        }
                        if (!empty($lines)) {
                            $reply .= "🗓️ **Future Scheduled Visits**:\n" . implode("\n", $lines) . "\n\n";
                        }
                    }
                    $reply .= "Track your live position on your patient dashboard!";
                } elseif (!empty($upcomingList)) {
                    $lines = [];
                    foreach ($upcomingList as $up) {
                        $formattedDate = date('M j, Y', strtotime($up['appointment_date']));
                        $docName = stripos($up['doctor_name'], 'Dr.') === 0 ? $up['doctor_name'] : 'Dr. ' . $up['doctor_name'];
                        $reason = $up['reason'] ?: 'Routine Consultation';
                        $lines[] = "• **{$formattedDate}** at **{$up['time_slot']}** — **{$docName}** (Q-{$up['queue_number']})\n  *Reason*: {$reason} | *Status*: **" . strtoupper($up['status']) . "**";
                    }
                    $listStr = implode("\n\n", $lines);
                    $reply = "📅 **My Upcoming Appointments**\n\nYou have **" . count($upcomingList) . "** upcoming appointment booking(s):\n\n{$listStr}\n\nWould you like help rescheduling or booking an additional consultation?";
                } else {
                    $reply = "📅 **My Appointments**\n\nYou currently have no active queue ticket for today and no upcoming appointments scheduled.\n\nWould you like me to show you available doctors to book an appointment?";
                }
            } elseif (str_contains($lower, 'book') || str_contains($lower, 'booking')) {
                $docs = $this->tools->executeToolCall('getAvailableDoctors', [], $userId, $role, $convId);
                $executedTools = ['getAvailableDoctors'];
                $docCount = $docs['count'] ?? count($docs['doctors'] ?? []);

                $reply = "📅 **How to Book an Appointment**\n\nHere is how to schedule a consultation:\n\n1. Click the **'New Appointment'** button on your dashboard.\n2. Select your preferred doctor (**{$docCount} doctor(s) on-duty today**).\n3. Pick an available date and time slot.\n4. Enter your visit reason and click **Submit Booking**!\n\nWould you like me to list today's available doctors?";
            } elseif (str_contains($lower, 'doctor') || str_contains($lower, 'dr') || str_contains($lower, 'consultation') || str_contains($lower, 'available') || str_contains($lower, 'tomorrow') || in_array($lower, ['yes', 'yep', 'sure', 'ok', 'okay', 'please', 'check tomorrow'], true)) {
                $targetDate = (str_contains($lower, 'tomorrow') || str_contains($lower, 'yes')) ? date('Y-m-d', strtotime('+1 day')) : date('Y-m-d');
                $dateLabel  = (str_contains($lower, 'tomorrow') || str_contains($lower, 'yes')) ? 'Tomorrow (' . date('M j, Y', strtotime('+1 day')) . ')' : 'Today (' . date('M j, Y') . ')';

                $docs = $this->tools->executeToolCall('getAvailableDoctors', ['date' => $targetDate], $userId, $role, $convId);
                $executedTools = ['getAvailableDoctors'];
                $listStr = [];
                foreach ($docs['doctors'] ?? [] as $d) {
                    $listStr[] = "• **" . $d['doctor_name'] . "** (" . $d['specialization'] . ") - Status: " . $d['status'];
                }
                $docList = !empty($listStr) ? implode("\n", $listStr) : "• No doctors currently listed as available for " . $dateLabel . ".";

                $reply = "🩺 **Available On-Duty Doctors for {$dateLabel}**\n\n{$docList}\n\nTo schedule an appointment with any of these doctors, click the **'New Appointment'** button on your dashboard toolbar!";
            } elseif (str_contains($lower, 'queue') || str_contains($lower, 'ticket') || str_contains($lower, 'wait')) {
                $q = $this->tools->executeToolCall('getQueueStatus', [], $userId, $role, $convId);
                $executedTools = ['getQueueStatus'];
                if (!empty($q['has_queue_today'])) {
                    $reply = "Your queue ticket for today is #" . $q['queue_number'] . " with " . $q['doctor_name'] . ". There are " . $q['patients_ahead'] . " patients ahead of you (est. wait: " . $q['est_wait_minutes'] . " minutes).";
                } else {
                    $reply = "You currently have no active queue ticket for today. Would you like me to help you book an appointment?";
                }
            } else {
                $reply = "Hello! I am your Personal Clinic Assistant. How can I help you today? I can help you check doctor availability, view services offered, book or manage appointments, or track your queue position.";
            }
        }

        $this->memory->saveMessage($convId, 'assistant', $reply);

        return [
            'success'             => true,
            'conversation_id'     => $convId,
            'role'                => $role,
            'assistant_name'      => $assistantName,
            'reply'               => $reply,
            'tool_calls_executed' => $executedTools,
            'timestamp'           => date('c'),
            'degraded'            => true,
        ];
    }
}
