<?php

declare(strict_types=1);

/**
 * Gemini Vision API integration.
 * Sends images to Google Gemini and parses the structured response.
 */

namespace App;

class GeminiVision implements AIProvider
{
    private const SYSTEM_INSTRUCTION = <<<'PROMPT'
You are an expert product identification assistant. When given an image:

1. Identify the main object in the image.
2. Read any visible text, brand names, logos, or model numbers.
3. If you can determine the specific manufacturer and product name, do so.
4. If the exact model cannot be confidently determined from the image alone, return a generic product name (e.g., "Laptop" not "Dell Latitude 5440" unless the model is clearly visible).
5. Provide specifications and a brief description based on visual evidence only.
6. Return the bounding box of the main object using normalized coordinates (0-1000 for each value).
7. Estimate your confidence level as a decimal between 0 and 1.

IMPORTANT: Never invent or guess specific model numbers unless they are clearly visible in the image. When uncertain, use generic names like "Smartphone", "Wireless Headphones", "Water Bottle", etc.
PROMPT;

    /**
     * @var array<string, mixed>
     */
    private array $config;
    private ?string $lastError = null;

    /**
     * @param array<string, mixed> $config
     */
    public function __construct(array $config)
    {
        $this->config = $config;
    }

    /**
     * Return the provider name.
     */
    public function name(): string
    {
        return 'gemini';
    }

    /**
     * Recognize an object from a Base64-encoded image.
     *
     * @param string $base64Image Base64 without data URI prefix.
     * @param string $mimeType    MIME type of the original image.
     * @return ProductResult|null
     */
    public function recognize(string $base64Image, string $mimeType): ?ProductResult
    {
        $this->lastError = null;
        $apiKey    = $this->config['api_key'] ?? '';
        $modelName = $this->config['model'] ?? 'gemini-3.6-flash';
        $url       = $this->config['api_url'] ?? '';

        if (empty($apiKey)) {
            return null;
        }

        $response = $this->callApi($apiKey, $modelName, $base64Image, $mimeType);

        if ($response === null) {
            $this->lastError = 'Gemini request timed out or failed.';
            return null;
        }

        if (isset($response['error'])) {
            if ((int) ($response['error']['code'] ?? 0) === 429) {
                $this->lastError = 'Gemini quota exceeded. Please wait for the quota to reset or use a billing-enabled API key.';
                return null;
            }

            $message = $response['error']['message'] ?? 'Gemini API request failed.';
            $details = is_string($message) ? json_decode($message, true) : null;
            if (is_array($details)) {
                $message = $details['error']['message'] ?? $message;
            }
            $this->lastError = $this->sanitiseError((string) $message);
            return null;
        }

        $result = $this->parseResponse($response);
        if ($result === null) {
            $this->lastError = 'Gemini returned an unrecognised response format.';
        }

        return $result;
    }

    public function lastError(): ?string
    {
        return $this->lastError;
    }

    /**
     * Test connectivity with a lightweight request.
     */
    public function testConnection(): array
    {
        $apiKey = $this->config['api_key'] ?? '';

        if (empty($apiKey)) {
            return ['ok' => false, 'message' => 'API key not configured.'];
        }

        $modelName = $this->config['model'] ?? 'gemini-3.6-flash';

        // Send a minimal text-only request to test connectivity
        $payload = [
            'contents' => [
                [
                    'parts' => [
                        ['text' => 'Respond with exactly: OK'],
                    ],
                ],
            ],
        ];

        $url = str_replace(
            ['{model}', '{api_key}'],
            [$modelName, $apiKey],
            $this->config['api_url']
        );

        try {
            $response = $this->httpPost($url, $payload, max(10, (int) ($this->config['timeout_seconds'] ?? 30)));

            if ($response === null) {
                return ['ok' => false, 'message' => 'No response from Gemini. Check network access and try again.'];
            }

            if (isset($response['error'])) {
                $message = $response['error']['message'] ?? 'API request failed.';
                $details = is_string($message) ? json_decode($message, true) : null;
                if (is_array($details)) {
                    $message = $details['error']['message'] ?? $message;
                }
                return ['ok' => false, 'message' => $this->sanitiseError((string) $message)];
            }

            $text = $this->extractText($response);
            if (strtolower(trim($text)) === 'ok') {
                return ['ok' => true, 'message' => 'Connected.'];
            }

            return ['ok' => false, 'message' => 'Unexpected response from API.'];
        } catch (\Exception $e) {
            return ['ok' => false, 'message' => $this->sanitiseError($e->getMessage())];
        }
    }

    /**
     * @param array<string, mixed> $payload
     * @return array<string, mixed>|null
     */
    private function callApi(string $apiKey, string $modelName, string $base64Image, string $mimeType): ?array
    {
        $url = str_replace(
            ['{model}', '{api_key}'],
            [$modelName, $apiKey],
            $this->config['api_url']
        );

        $payload = [
            'contents' => [
                [
                    'parts' => [
                        ['text' => self::SYSTEM_INSTRUCTION],
                        [
                            'inline_data' => [
                                'mime_type' => $mimeType,
                                'data'      => $base64Image,
                            ],
                        ],
                    ],
                ],
            ],
            'generationConfig' => [
                'response_mime_type' => 'application/json',
                'temperature'        => 0.1,
            ],
        ];

        $response = $this->httpPost($url, $payload, $this->config['timeout_seconds'] ?? 60);

        if ($response === null) {
            return null;
        }

        // Handle 429 (rate limit) → try fallback models
        if (isset($response['error']) && $response['error']['code'] == 429) {
            $fallbacks = $this->config['fallback_models'] ?? [];
            foreach ($fallbacks as $fallbackModel) {
                $fallbackResponse = $this->callApiWithModel($apiKey, $fallbackModel, $base64Image, $mimeType);
                if ($fallbackResponse !== null) {
                    return $fallbackResponse;
                }
            }
        }

        return $response;
    }

    /**
     * @param array<string, mixed> $payload
     * @return array<string, mixed>|null
     */
    private function callApiWithModel(string $apiKey, string $modelName, string $base64Image, string $mimeType): ?array
    {
        $url = str_replace(
            ['{model}', '{api_key}'],
            [$modelName, $apiKey],
            $this->config['api_url']
        );

        $payload = [
            'contents' => [
                [
                    'parts' => [
                        ['text' => self::SYSTEM_INSTRUCTION],
                        [
                            'inline_data' => [
                                'mime_type' => $mimeType,
                                'data'      => $base64Image,
                            ],
                        ],
                    ],
                ],
            ],
            'generationConfig' => [
                'response_mime_type' => 'application/json',
                'temperature'        => 0.1,
            ],
        ];

        return $this->httpPost($url, $payload, $this->config['timeout_seconds'] ?? 60);
    }

    /**
     * @param array<string, mixed> $payload
     * @return array<string, mixed>|null
     */
    private function httpPost(string $url, array $payload, int $timeout): ?array
    {
        $ch = curl_init($url);

        curl_setopt_array($ch, [
            CURLOPT_POST            => true,
            CURLOPT_POSTFIELDS      => json_encode($payload),
            CURLOPT_HTTPHEADER      => ['Content-Type: application/json'],
            CURLOPT_TIMEOUT         => $timeout,
            CURLOPT_CONNECTTIMEOUT  => 5,
            CURLOPT_RETURNTRANSFER  => true,
            CURLOPT_SSL_VERIFYPEER  => true,
        ]);

        $body   = curl_exec($ch);
        $httpCode = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $error  = curl_error($ch);
        curl_close($ch);

        if ($error) {
            return ['error' => ['code' => 0, 'message' => $error]];
        }

        if ($httpCode >= 400) {
            return ['error' => ['code' => $httpCode, 'message' => $body ?? '']];
        }

        $decoded = json_decode($body ?? '', true);
        return is_array($decoded) ? $decoded : null;
    }

    /**
     * Parse the Gemini API response into a ProductResult.
     *
     * @param array<string, mixed> $response
     */
    private function parseResponse(array $response): ?ProductResult
    {
        // Try to extract JSON from the response text
        $candidate = $this->extractCandidateText($response);

        if (empty($candidate)) {
            return null;
        }

        $candidate = trim($candidate);
        $candidate = preg_replace('/^```(?:json)?\s*|\s*```$/i', '', $candidate) ?? $candidate;
        $data = json_decode($candidate, true);

        if (!is_array($data) && preg_match('/\{[\s\S]*\}/', $candidate, $matches)) {
            $data = json_decode($matches[0], true);
        }

        if (!is_array($data)) {
            return null;
        }

        return $this->buildProductResult($data);
    }

    /**
     * Extract the text candidate from a Gemini API response.
     *
     * @param array<string, mixed> $response
     */
    private function extractCandidateText(array $response): ?string
    {
        // Standard Gemini response format
        if (isset($response['candidates'][0]['content']['parts'])) {
            foreach ($response['candidates'][0]['content']['parts'] as $part) {
                if (isset($part['text']) && is_string($part['text'])) {
                    return $part['text'];
                }
            }
        }

        // Fallback: search for JSON block
        $text = $this->extractText($response);
        if (preg_match('/\{[\s\S]*\}/', $text, $matches)) {
            return $matches[0];
        }

        return null;
    }

    /**
     * Extract plain text from a Gemini response.
     *
     * @param array<string, mixed> $response
     */
    private function extractText(array $response): string
    {
        if (isset($response['candidates'][0]['content']['parts'])) {
            foreach ($response['candidates'][0]['content']['parts'] as $part) {
                if (isset($part['text']) && is_string($part['text'])) {
                    return $part['text'];
                }
            }
        }
        if (isset($response['text']) && is_string($response['text'])) {
            return $response['text'];
        }
        return '';
    }

    /**
     * Build a validated ProductResult from parsed JSON data.
     *
     * @param array<string, mixed> $data
     */
    private function buildProductResult(array $data): ?ProductResult
    {
        // ── objectLabel ──────────────────────────────────────────────
        $objectLabel = $this->strOrNull($data['objectLabel']
            ?? $data['object_label']
            ?? $data['label']
            ?? $data['name']
            ?? null);

        if (empty($objectLabel)) {
            return null;
        }

        // ── productName ──────────────────────────────────────────────
        $productName = $this->strOrNull($data['productName']
            ?? $data['product_name']
            ?? $data['product']
            ?? $objectLabel);

        // ── manufacturer ─────────────────────────────────────────────
        $manufacturer = $this->strOrNull($data['manufacturer']
            ?? $data['brand']
            ?? $data['company']
            ?? null);

        // ── specification ────────────────────────────────────────────
        $specification = $this->strOrNull($data['specification']
            ?? $data['specs']
            ?? $data['specifications']
            ?? null);

        // ── description ──────────────────────────────────────────────
        $description = $this->strOrNull($data['description']
            ?? $data['summary']
            ?? null);

        // ── boundingBox ──────────────────────────────────────────────
        $bbox = $this->parseBoundingBox($data['boundingBox']
            ?? $data['bounding_box']
            ?? $data['bbox']
            ?? []);

        // ── confidence ───────────────────────────────────────────────
        $confidence = $this->parseConfidence($data['confidence']
            ?? $data['confidence_score']
            ?? 0.5);

        return new ProductResult(
            objectLabel: $objectLabel,
            productName: $productName,
            manufacturer: $manufacturer,
            specification: $specification,
            description: $description,
            boundingBox: $bbox,
            confidence: $confidence,
            provider: $this->name(),
        );
    }

    /**
     * Parse bounding box from various formats into a ProductResult bbox array.
     *
     * @param array<string, mixed>|mixed $raw
     * @return array{ymin: float, xmin: float, ymax: float, xmax: float}|null
     */
    private function parseBoundingBox($raw): ?array
    {
        if (!is_array($raw)) {
            return null;
        }

        $ymin = $this->floatInRange($raw['ymin'] ?? $raw['y_min'] ?? $raw['top'] ?? 0, 0, 1000);
        $xmin = $this->floatInRange($raw['xmin'] ?? $raw['x_min'] ?? $raw['left'] ?? 0, 0, 1000);
        $ymax = $this->floatInRange($raw['ymax'] ?? $raw['y_max'] ?? $raw['bottom'] ?? 1000, 0, 1000);
        $xmax = $this->floatInRange($raw['xmax'] ?? $raw['x_max'] ?? $raw['right'] ?? 1000, 0, 1000);

        // Ensure ymin < ymax and xmin < xmax
        if ($ymin >= $ymax || $xmin >= $xmax) {
            // Try to fix inverted coordinates
            $ymin = min($ymin, $ymax);
            $ymax = max($ymin, $ymax);
            $xmin = min($xmin, $xmax);
            $xmax = max($xmin, $xmax);
        }

        return ['ymin' => $ymin, 'xmin' => $xmin, 'ymax' => $ymax, 'xmax' => $xmax];
    }

    /**
     * Parse confidence value into a 0-1 float.
     */
    private function parseConfidence(mixed $value): float
    {
        if (!is_numeric($value)) {
            return 0.5;
        }
        $num = (float) $value;
        // If value is > 1, treat as percentage
        if ($num > 1.0) {
            $num = $num / 100.0;
        }
        return max(0.0, min(1.0, $num));
    }

    /**
     * Sanitise an error message to not leak internal details.
     */
    private function sanitiseError(string $message): string
    {
        // Strip anything that looks like an API key or URL path
        return preg_replace('/[a-zA-Z0-9]{30,}/', '***', $message) ?? $message;
    }

    /**
     * Ensure a value is a non-empty string or null.
     */
    private function strOrNull(mixed $value): ?string
    {
        if (is_array($value)) {
            $value = json_encode($value, JSON_UNESCAPED_UNICODE) ?: null;
        } elseif ($value !== null && !is_string($value)) {
            $value = (string) $value;
        }

        if ($value === null || trim($value) === '') {
            return null;
        }
        return trim($value);
    }

    /**
     * Clamp a float to a range.
     */
    private function floatInRange(float $value, float $min, float $max): float
    {
        return max($min, min($max, $value));
    }
}
