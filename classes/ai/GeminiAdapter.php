<?php
/**
 * GeminiAdapter.php — Gemini 2.5 API Communication & Function Calling Loop Handler
 */

class GeminiAdapter
{
    private string $apiKey;
    private const API_BASE = 'https://generativelanguage.googleapis.com/v1beta/models/';
    private const MODELS   = [
        'gemini-2.0-flash',
        'gemini-2.0-flash-lite',
        'gemini-1.5-flash',
    ];

    public function __construct(?string $apiKey = null)
    {
        $this->apiKey = $apiKey ?: $this->loadApiKey();
    }

    private function loadApiKey(): string
    {
        if (isset($_ENV['GOOGLE_API_KEY']) && !empty($_ENV['GOOGLE_API_KEY'])) {
            return $_ENV['GOOGLE_API_KEY'];
        }

        $envFile = dirname(__DIR__, 2) . '/.env';
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

    public function isConfigured(): bool
    {
        return !empty($this->apiKey) && $this->apiKey !== 'your_google_api_key_here';
    }

    /**
     * Sends payload to Gemini API with automatic model fallback logic.
     */
    public function generateContent(array $payload, string $preferredModel = 'gemini-2.5-flash'): array
    {
        $modelsToTry = array_unique(array_merge([$preferredModel], self::MODELS));

        foreach ($modelsToTry as $model) {
            $url = self::API_BASE . $model . ':generateContent?key=' . urlencode($this->apiKey);
            $response = $this->httpPost($url, $payload);

            if (isset($response['candidates'][0])) {
                $response['_model_used'] = $model;
                return $response;
            }

            // If quota error or rate limit, fallback to next model
            if (isset($response['error']['code']) && in_array($response['error']['code'], [429, 403, 503])) {
                continue;
            }
        }

        return $response ?? ['error' => ['message' => 'All Gemini models failed to respond.']];
    }

    private function httpPost(string $url, array $payload): array
    {
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_POST           => true,
            CURLOPT_HTTPHEADER     => ['Content-Type: application/json'],
            CURLOPT_POSTFIELDS     => json_encode($payload, JSON_UNESCAPED_UNICODE),
            CURLOPT_TIMEOUT        => 15,
            CURLOPT_SSL_VERIFYPEER => false,
        ]);

        $raw = curl_exec($ch);
        $err = curl_error($ch);
        curl_close($ch);

        if ($err) {
            return ['error' => ['message' => "cURL error: $err"]];
        }

        return json_decode($raw, true) ?: ['error' => ['message' => 'Failed to parse Gemini response JSON.']];
    }
}
