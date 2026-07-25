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
            if (str_contains($lower, 'patient') || str_contains($lower, 'today') || str_contains($lower, 'schedule')) {
                $assigned = $this->tools->executeToolCall('getAssignedPatients', [], $userId, $role, $convId);
                $executedTools = ['getAssignedPatients'];
                $count = $assigned['patient_count'] ?? 0;
                $reply = "You have $count patient consultations scheduled for today. Would you like me to pull up a specific patient's medical records or consultation history?";
            } else {
                $reply = "Hello Doctor! I am your Clinical Workflow Assistant. I am ready to help manage your consultation schedule, review assigned patient files, or check upcoming appointments.";
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
            } elseif (str_contains($lower, 'doctor') || str_contains($lower, 'consultation') || str_contains($lower, 'book') || str_contains($lower, 'available') || str_contains($lower, 'tomorrow')) {
                $docs = $this->tools->executeToolCall('getAvailableDoctors', [], $userId, $role, $convId);
                $executedTools = ['getAvailableDoctors'];
                $listStr = [];
                foreach ($docs['doctors'] ?? [] as $d) {
                    $listStr[] = "• " . $d['doctor_name'] . " (" . $d['specialization'] . ")";
                }
                $docList = !empty($listStr) ? implode("\n", $listStr) : "• Dr. Santos (General Medicine)\n• Dr. Cruz (Pediatrics)";

                $reply = "Of course! Here are our available doctors:\n\n{$docList}\n\nWhich doctor would you like to see, or would you like me to check available time slots for tomorrow?";
            } elseif (str_contains($lower, 'queue') || str_contains($lower, 'ticket') || str_contains($lower, 'wait')) {
                $q = $this->tools->executeToolCall('getQueueStatus', [], $userId, $role, $convId);
                $executedTools = ['getQueueStatus'];
                if (!empty($q['has_queue_today'])) {
                    $reply = "Your queue ticket for today is #" . $q['queue_number'] . " with " . $q['doctor_name'] . ". There are " . $q['patients_ahead'] . " patients ahead of you (est. wait: " . $q['est_wait_minutes'] . " minutes).";
                } else {
                    $reply = "You currently have no active queue ticket for today. Would you like me to help you book an appointment?";
                }
            } else {
                $reply = "Hello! I am your Personal Clinic Assistant. How can I help you today? I can help you check doctor availability, book or manage appointments, or track your queue position.";
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
