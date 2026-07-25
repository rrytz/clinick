<?php
require_once __DIR__ . '/NaiveBayesClassifier.php';

/**
 * Chatbot pipeline:
 *  1. Keyword pre-match  - instant, high-certainty catch for common single words and chip phrases
 *  2. Language detection - Naive Bayes language classifier
 *  3. Intent classifier  - per-language Naive Bayes intent classifier
 */
class ChatbotService
{
    private const CONFIDENCE_THRESHOLD = 0.28;

    private const KEYWORD_INTENTS = [
        'en' => [
            // greetings
            'hello'                 => 'greeting',
            'hi'                    => 'greeting',
            'hey'                   => 'greeting',
            'help'                  => 'greeting',
            'start'                 => 'greeting',
            // booking & chips
            'book'                  => 'book_appointment',
            'appointment'           => 'book_appointment',
            'see available doctors' => 'book_appointment',
            'check my appointments' => 'book_appointment',
            'my appointments'       => 'book_appointment',
            'how to book'           => 'book_appointment',
            'book appointment'      => 'book_appointment',
            // reschedule
            'reschedule'            => 'reschedule_appointment',
            'rescheduled'           => 'reschedule_appointment',
            // cancel
            'cancel'                => 'cancel_appointment',
            'cancelled'             => 'cancel_appointment',
            // hours
            'hours'                 => 'clinic_hours',
            'clinic hours'          => 'clinic_hours',
            'open'                  => 'clinic_hours',
            // services
            'services'              => 'services_offered',
            'services offered'      => 'services_offered',
            'departments'           => 'services_offered',
            'specialties'           => 'services_offered',
            // symptoms
            'symptoms'              => 'check_symptoms',
            'sick'                  => 'check_symptoms',
            'fever'                 => 'check_symptoms',
            'pain'                  => 'check_symptoms',
            // staff
            'human'                 => 'talk_to_staff',
            'staff'                 => 'talk_to_staff',
            'talk to staff'         => 'talk_to_staff',
            'reception'             => 'talk_to_staff',
            'person'                => 'talk_to_staff',
            // farewell
            'bye'                   => 'farewell',
            'goodbye'               => 'farewell',
            'thanks'                => 'farewell',
            'thank'                 => 'farewell',
            'done'                  => 'farewell',
        ],
        'fil' => [
            'kumusta'                 => 'greeting',
            'salamat'                 => 'farewell',
            'paalam'                  => 'farewell',
            'kanselahin'              => 'cancel_appointment',
            'sintomas'                => 'check_symptoms',
            'serbisyo'                => 'services_offered',
            'reschedule'              => 'reschedule_appointment',
            'ire-schedule'            => 'reschedule_appointment',
            're-schedule'             => 'reschedule_appointment',
        ],
        'ceb' => [
            'kumusta'                 => 'greeting',
            'salamat'                 => 'farewell',
            'paalam'                  => 'farewell',
            'tabang'                  => 'greeting',
            'kanselahon'              => 'cancel_appointment',
            'sintomas'                => 'check_symptoms',
            'konsulta'                => 'book_appointment',
            'unsaon pagpa-konsulta'   => 'book_appointment',
            'reschedule'              => 'reschedule_appointment',
            'i-reschedule'            => 'reschedule_appointment',
        ],
    ];

    private NaiveBayesClassifier $languageDetector;
    private array $intentClassifiers = [];
    private array $data;

    public function __construct()
    {
        $this->data = require __DIR__ . '/chatbot-data.php';

        $this->languageDetector = new NaiveBayesClassifier();
        $langRows = [];
        foreach ($this->data['training_data'] as $lang => $examples) {
            foreach ($examples as $ex) {
                $langRows[] = ['text' => $ex['text'], 'label' => $lang];
            }
        }
        $this->languageDetector->train($langRows);

        foreach ($this->data['training_data'] as $lang => $examples) {
            $classifier = new NaiveBayesClassifier();
            $rows = array_map(
                fn($ex) => ['text' => $ex['text'], 'label' => $ex['intent']],
                $examples
            );
            $classifier->train($rows);
            $this->intentClassifiers[$lang] = $classifier;
        }
    }

    public function detectLanguage(string $message): string
    {
        $result = $this->languageDetector->classify($message);
        return $result['label'] ?: 'en';
    }

    public function getRandomResponse(string $language, string $intent): string
    {
        $set = $this->data['responses'][$language][$intent]
            ?? $this->data['responses'][$language]['fallback'];
        return $set[array_rand($set)];
    }

    private function keywordMatch(string $message, string $language): ?string
    {
        $lower = mb_strtolower(trim($message));
        $clean = preg_replace('/[^a-z0-9\s\-]/u', '', $lower);
        $words = preg_split('/\s+/', trim($clean));
        
        $map = self::KEYWORD_INTENTS[$language] ?? [];
        if (isset($map[$clean])) {
            return $map[$clean];
        }
        foreach ($words as $word) {
            if (isset($map[$word])) {
                return $map[$word];
            }
        }

        foreach (self::KEYWORD_INTENTS as $langMap) {
            if (isset($langMap[$clean])) {
                return $langMap[$clean];
            }
            foreach ($words as $word) {
                if (isset($langMap[$word])) {
                    return $langMap[$word];
                }
            }
        }

        return null;
    }

    public function respond(string $message): array
    {
        $language = $this->detectLanguage($message);

        $keywordIntent = $this->keywordMatch($message, $language);
        if ($keywordIntent !== null) {
            return [
                'intent'        => $keywordIntent,
                'language'      => $language,
                'confidence'    => 1.0,
                'reply'         => $this->getRandomResponse($language, $keywordIntent),
                'lowConfidence' => false,
            ];
        }

        $intentResult   = $this->intentClassifiers[$language]->classify($message);
        $confidence     = round($intentResult['confidence'], 3);

        if ($confidence < 0.20) {
            $intent        = 'fallback';
            $lowConfidence = true;
            $reply         = $this->getRandomResponse($language, 'fallback');
        } elseif ($confidence <= 0.35) {
            $intent        = $intentResult['label'];
            $lowConfidence = true;
            $friendlyName  = str_replace('_', ' ', $intent);
            $reply         = match ($language) {
                'fil'   => "Gusto mo ba mag-{$friendlyName}? Maaari ka ring magtanong tungkol sa clinic hours o mga serbisyo.",
                'ceb'   => "Gusto ba nimo mag-{$friendlyName}? Mahimo sab ka mangutana bahin sa clinic hours o mga serbisyo.",
                default => "Did you mean you'd like to ask about {$friendlyName}? You can also ask about clinic hours or services.",
            };
        } else {
            $intent        = $intentResult['label'];
            $lowConfidence = false;
            $reply         = $this->getRandomResponse($language, $intent);
        }

        return [
            'intent'        => $intent,
            'language'      => $language,
            'confidence'    => $confidence,
            'reply'         => $reply,
            'lowConfidence' => $lowConfidence,
        ];
    }
}
