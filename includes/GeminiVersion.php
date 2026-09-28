<?php
declare(strict_types=1);

require_once __DIR__ . '/AIProvider.php';

class GeminiVision extends AIProvider {
    public function recognize(string $base64Image, string $mimeType): array {
        $apiKey = $this->config['gemini_api_key'] ?? '';
        if (empty($apiKey)) {
            throw new Exception("Gemini API key is not configured.");
        }

        $model = $this->config['gemini_model'] ?? 'gemini-3.8-flash';
        $url = "https://generativelanguage.googleapis.com/v1beta/models/{$model}:generateContent?key={$apiKey}";

        $payload = [
            'contents' => [[
                'parts' => [
                    ['text' => $this->getSystemPrompt()],
                    [
                        'inline_data' => [
                            'mime_type' => $mimeType,
                            'data' => $base64Image
                        ]
                    ]
                ]
            ]],
            'generationConfig' => [
                'response_mime_type' => 'application/json',
                'temperature' => 0.2
            ]
        ];

        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_POST => true,
            CURLOPT_HTTPHEADER => ['Content-Type: application/json'],
            CURLOPT_POSTFIELDS => json_encode($payload),
            CURLOPT_TIMEOUT => (int)($this->config['request_timeout'] ?? 30),
            CURLOPT_SSL_VERIFYPEER => true
        ]);

        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $error = curl_error($ch);
        curl_close($ch);

        if ($error) {
            throw new Exception("Gemini Request Failed: " . $error);
        }

        if ($httpCode !== 200) {
            throw new Exception("Gemini API HTTP Error {$httpCode}: " . $response);
        }

        $json = json_decode($response, true);
        $text = $json['candidates'][0]['content']['parts'][0]['text'] ?? '';
        
        // Sanitize Markdown code block if present
        $text = preg_replace('/^```json\s*|\s*```$/i', '', trim($text));
        $parsed = json_decode($text, true);

        if (!is_array($parsed)) {
            throw new Exception("Invalid JSON structure returned from Gemini AI.");
        }

        return $parsed;
    }

    public function testConnection(): bool {
        $apiKey = $this->config['gemini_api_key'] ?? '';
        if (empty($apiKey)) return false;
        
        $url = "https://generativelanguage.googleapis.com/v1beta/models?key={$apiKey}";
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => 10
        ]);
        $res = curl_exec($ch);
        $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        return $code === 200;
    }
}