<?php
declare(strict_types=1);

require_once __DIR__ . '/AIProvider.php';

class AgnesVision extends AIProvider {
    public function recognize(string $base64Image, string $mimeType): array {
        $apiKey = $this->config['agnes_api_key'] ?? '';
        if (empty($apiKey)) {
            throw new Exception("Agnes Vision API Key is not configured.");
        }

        // Agnes Vision API standard endpoint interface
        $url = "https://api.agnes.ai/v1/vision/analyze";
        $payload = [
            'api_key' => $apiKey,
            'model' => $this->config['agnes_model'] ?? 'agnes-v1-vision',
            'prompt' => $this->getSystemPrompt(),
            'image' => [
                'mime' => $mimeType,
                'base64' => $base64Image
            ]
        ];

        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_POST => true,
            CURLOPT_HTTPHEADER => ['Content-Type: application/json'],
            CURLOPT_POSTFIELDS => json_encode($payload),
            CURLOPT_TIMEOUT => (int)($this->config['request_timeout'] ?? 30)
        ]);

        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $error = curl_error($ch);
        curl_close($ch);

        if ($error) {
            throw new Exception("Agnes Vision Request Failed: " . $error);
        }

        if ($httpCode !== 200) {
            throw new Exception("Agnes Vision HTTP Error {$httpCode}: " . $response);
        }

        $parsed = json_decode($response, true);
        if (!is_array($parsed) || !isset($parsed['result'])) {
            throw new Exception("Invalid response format from Agnes Vision.");
        }

        return $parsed['result'];
    }

    public function testConnection(): bool {
        return !empty($this->config['agnes_api_key']);
    }
}