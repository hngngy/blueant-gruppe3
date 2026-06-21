<?php

class GeminiClient implements AiJsonClient
{
    private string $baseUrl;
    private string $apiKey;
    private string $model;
    private float $temperature;
    private int $timeoutSeconds;

    public function __construct(
        string $baseUrl,
        string $apiKey,
        string $model,
        float $temperature,
        int $timeoutSeconds
    ) {
        if (trim($baseUrl) === '') {
            throw new InvalidArgumentException('GEMINI_BASE_URL ist nicht konfiguriert.');
        }

        if (trim($apiKey) === '') {
            throw new InvalidArgumentException('GEMINI_API_KEY ist nicht konfiguriert.');
        }

        if (trim($model) === '') {
            throw new InvalidArgumentException('GEMINI_MODEL ist nicht konfiguriert.');
        }

        $this->baseUrl = rtrim($baseUrl, '/');
        $this->apiKey = $apiKey;
        $this->model = $model;
        $this->temperature = $temperature;
        $this->timeoutSeconds = $timeoutSeconds;
    }

    public function generateJson(string $systemPrompt, array $inputData): array
    {
        if (!function_exists('curl_init')) {
            throw new RuntimeException('Die PHP-curl-Erweiterung ist nicht aktiviert.');
        }

        $prompt = $systemPrompt . "\n\nEingabedaten als JSON:\n" . json_encode(
            $inputData,
            JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES
        );

        $payload = [
            'contents' => [
                [
                    'role' => 'user',
                    'parts' => [
                        ['text' => $prompt],
                    ],
                ],
            ],
            'generationConfig' => [
                'temperature' => $this->temperature,
                'responseMimeType' => 'application/json',
            ],
        ];

        $url = $this->baseUrl . '/models/' . rawurlencode($this->model) . ':generateContent?key=' . rawurlencode($this->apiKey);
        $ch = curl_init($url);

        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_POST => true,
            CURLOPT_HTTPHEADER => [
                'Content-Type: application/json',
                'Accept: application/json',
                'User-Agent: BlueAnt-Portfolio-Dashboard/1.0',
            ],
            CURLOPT_POSTFIELDS => json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
            CURLOPT_TIMEOUT => $this->timeoutSeconds,
        ]);

        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $error = curl_error($ch);

        curl_close($ch);

        if ($response === false || $httpCode < 200 || $httpCode >= 300) {
            throw new RuntimeException("Gemini API Fehler: HTTP $httpCode $error " . $this->extractErrorMessage($response));
        }

        $decodedResponse = json_decode((string)$response, true);

        if (!is_array($decodedResponse)) {
            throw new RuntimeException('Gemini API Antwort konnte nicht gelesen werden.');
        }

        $generatedText = trim((string)($decodedResponse['candidates'][0]['content']['parts'][0]['text'] ?? ''));

        if ($generatedText === '') {
            throw new RuntimeException('Gemini API hat keinen Auswertungstext geliefert.');
        }

        $decodedJson = json_decode($generatedText, true);

        if (is_array($decodedJson)) {
            return $decodedJson;
        }

        if (preg_match('/\{.*\}/s', $generatedText, $matches) === 1) {
            $decodedJson = json_decode($matches[0], true);

            if (is_array($decodedJson)) {
                return $decodedJson;
            }
        }

        throw new RuntimeException('KI-Antwort war kein valides JSON.');
    }

    private function extractErrorMessage($response): string
    {
        if (!is_string($response) || $response === '') {
            return '';
        }

        $decodedResponse = json_decode($response, true);

        if (!is_array($decodedResponse)) {
            return substr($response, 0, 500);
        }

        $message = $decodedResponse['error']['message'] ?? $decodedResponse['message'] ?? '';

        if (is_array($message)) {
            return json_encode($message, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        }

        return (string)$message;
    }
}
