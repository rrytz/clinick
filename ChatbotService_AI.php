<?php
/**
 * ChatbotService_AI.php — Role-Aware AI Assistant Layer for MediBot
 *
 * Automatically detects user role (Patient, Doctor, Staff, Admin) and routes
 * to dedicated system personas, role-scoped RAG context, and RBAC tools.
 */

require_once __DIR__ . '/ChatbotKnowledge.php';
require_once __DIR__ . '/ChatbotTools.php';
require_once __DIR__ . '/ChatbotSession.php';
require_once __DIR__ . '/classes/ai/AssistantFactory.php';

class ChatbotService_AI
{
    private const EMERGENCY_KEYWORDS = [
        'chest pain', 'heart attack', "can't breathe", 'cannot breathe',
        'difficulty breathing', 'shortness of breath', 'stroke', 'unconscious',
        'fainted', 'severe bleeding', 'suicide', 'suicidal', 'overdose',
        'seizure', 'convulsion', 'poisoning', 'cpr', 'dying',
        'hindi makahinga', 'atake sa puso', 'nagdurugo nang grabe',
        'nag-seizure', 'lason', 'nakatulog na', 'hindi nagigising',
        'dili makaginhawa', 'atake sa kasingkasing', 'grabeng pagdugo',
    ];

    private SQLite3 $db;
    private string $apiKey;
    private AssistantFactory $assistantFactory;

    public function __construct(SQLite3 $db)
    {
        $this->db               = $db;
        $this->apiKey           = $this->loadApiKey();
        $this->assistantFactory = new AssistantFactory($db);
    }

    public function isConfigured(): bool
    {
        return !empty($this->apiKey) && $this->apiKey !== 'your_google_api_key_here';
    }

    public function respond(string $message, ?int $userId, string $userRole = 'Patient'): array
    {
        if ($this->isEmergency($message)) {
            return $this->emergencyResponse($userId);
        }

        // Delegate to new Role-Based AI Assistant Factory
        $result = $this->assistantFactory->handleMessage($message, $userId, $userRole);

        if ($result['success']) {
            return [
                'reply'          => $result['reply'],
                'intent'         => 'ai_assistant',
                'language'       => 'en',
                'confidence'     => 0.95,
                'lowConfidence'  => false,
                'session_id'     => $result['conversation_id'],
                'role'           => $result['role'],
                'assistant_name' => $result['assistant_name'],
                'tool_calls'     => $result['tool_calls_executed'],
            ];
        }

        return [
            'reply'         => $result['error'] ?? 'I am your CLINICK Personal Secretary Assistant. How may I assist you today?',
            'intent'        => 'error',
            'language'      => 'en',
            'confidence'    => 0.0,
            'lowConfidence' => true,
            'session_id'    => session_id(),
        ];
    }

    private function isEmergency(string $message): bool
    {
        $lower = strtolower($message);
        foreach (self::EMERGENCY_KEYWORDS as $kw) {
            if (str_contains($lower, $kw)) return true;
        }
        return false;
    }

    private function emergencyResponse(?int $userId): array
    {
        return [
            'reply' => "⚠️ **EMERGENCY WARNING**\n\nIf you or someone else is experiencing severe symptoms (chest pain, shortness of breath, heavy bleeding, unconsciousness), **please call emergency services immediately (911 or your local emergency number)** or proceed directly to the nearest hospital emergency room.\n\nDo not wait for an online consultation.",
            'intent'        => 'emergency',
            'language'      => 'en',
            'confidence'    => 1.0,
            'lowConfidence' => false,
            'isEmergency'   => true,
            'session_id'    => session_id(),
        ];
    }

    private function loadApiKey(): string
    {
        if (isset($_ENV['GOOGLE_API_KEY']) && !empty($_ENV['GOOGLE_API_KEY'])) {
            return $_ENV['GOOGLE_API_KEY'];
        }

        $envFile = __DIR__ . '/.env';
        if (file_exists($envFile)) {
            $lines = file($envFile, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
            foreach ($lines as $line) {
                if (str_starts_with(trim($line), 'GOOGLE_API_KEY=')) {
                    return trim(explode('=', $line, 2)[1]);
                }
            }
        }

        return getenv('GOOGLE_API_KEY') ?: '';
    }
}
