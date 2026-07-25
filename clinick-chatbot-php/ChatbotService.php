<?php
require_once __DIR__ . '/NaiveBayesClassifier.php';

/**
 * Chatbot pipeline: detects language (en/fil/ceb), then classifies intent
 * using a classifier trained only on that language's phrases.
 *
 * Usage:
 *   $service = new ChatbotService();
 *   $reply = $service->respond("gusto ko pong mag-book ng appointment");
 *   // ['intent' => 'book_appointment', 'language' => 'fil', 'confidence' => 0.83, 'reply' => '...', 'lowConfidence' => false]
 */
class ChatbotService
{
    private const CONFIDENCE_THRESHOLD = 0.35;

    private NaiveBayesClassifier $languageDetector;
    private array $intentClassifiers = []; // [lang => NaiveBayesClassifier]
    private array $data;

    public function __construct()
    {
        $this->data = require __DIR__ . '/chatbot-data.php';

        // Language detector: one classifier where the "class" is the language itself
        $this->languageDetector = new NaiveBayesClassifier();
        $langRows = [];
        foreach ($this->data['training_data'] as $lang => $examples) {
            foreach ($examples as $ex) {
                $langRows[] = ['text' => $ex['text'], 'label' => $lang];
            }
        }
        $this->languageDetector->train($langRows);

        // One intent classifier per language
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

    /**
     * @return array{intent: string, language: string, confidence: float, reply: string, lowConfidence: bool}
     */
    public function respond(string $message): array
    {
        $language = $this->detectLanguage($message);
        $intentResult = $this->intentClassifiers[$language]->classify($message);
        $lowConfidence = $intentResult['confidence'] < self::CONFIDENCE_THRESHOLD;

        $intent = $lowConfidence ? 'fallback' : $intentResult['label'];
        $reply = $this->getRandomResponse($language, $intent);

        return [
            'intent' => $intent,
            'language' => $language,
            'confidence' => round($intentResult['confidence'], 3),
            'reply' => $reply,
            'lowConfidence' => $lowConfidence,
        ];
    }
}
