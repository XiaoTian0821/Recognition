<?php

declare(strict_types=1);

/**
 * Agnes Vision API integration.
 * Sends images to Agnes AI and parses the structured response.
 */

namespace App;

class AgnesVision implements AIProvider
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
        return 'agnes';
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
        $apiKey  = $this->config['api_key'] ?? '';
        $modelName = $this->config['model'] ?? 'agnes-vision-v1';
        $url     = $this->config['api_url'] ?? '';

        if (empty($apiKey)) {
            return null;
        }

        $response = $this->callApi($apiKey, $modelName, $base64Image, $mimeType);

        if ($response === null) {
            return null;
        }

        return $this->parseResponse($response);
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

        $modelName = $this->config['model'] ?? 'agnes-vision-v1';

        $payload = [
            'model'   => $modelName,
            'message' => 'Respond with exactly: OK',
            'stream'  => false,
        ];

        $url = $this->config['api_url'];

        try {
            $response = $this->httpPost($url, $payload, max(10, (int) ($this->config['timeout_seconds'] ?? 30)), $apiKey);

            if ($response === null) {
                return ['ok' => false, 'message' => 'No response from Agnes. Check network access and try again.'];
            }

            if (isset($response['error'])) {
                return ['ok' => false, 'message' => $this->formatApiError($response['error'])];
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
        $url = $this->config['api_url'];

        $payload = [
            'model'    => $modelName,
            'stream'   => false,
            'messages' => [
                [
                    'role'    => 'system',
                    'content' => self::SYSTEM_INSTRUCTION,
                ],
                [
                    'role' => 'user',
                    'content' => [
                        [
                            'type'     => 'text',
                            'text'     => 'Identify the main object in this image and return a JSON with: objectLabel, productName, manufacturer, specification, description, boundingBox (ymin, xmin, ymax, xmax as 0-1000), and confidence (0-1).',
                        ],
                        [
                            'type'     => 'image_url',
                            'image_url' => [
                                'url' => "data:{$mimeType};base64,{$base64Image}",
                            ],
                        ],
                    ],
                ],
            ],
        ];

        $response = $this->httpPost($url, $payload, $this->config['timeout_seconds'] ?? 30, $apiKey);

        if ($response === null) {
            // Try fallback models
            $fallbacks = $this->config['fallback_models'] ?? [];
            foreach ($fallbacks as $fallbackModel) {
                $fallbackResponse = $this->callApiWithModel(
                    $apiKey,
                    $fallbackModel,
                    $base64Image,
                    $mimeType
                );
                if ($fallbackResponse !== null) {
                    return $fallbackResponse;
                }
            }
            return null;
        }

        return $response;
    }

    /**
     * @param array<string, mixed> $payload
     * @return array<string, mixed>|null
     */
    private function callApiWithModel(string $apiKey, string $modelName, string $base64Image, string $mimeType): ?array
    {
        $url = $this->config['api_url'];

        $payload = [
            'model'    => $modelName,
            'stream'   => false,
            'messages' => [
                [
                    'role'    => 'system',
                    'content' => self::SYSTEM_INSTRUCTION,
                ],
                [
                    'role' => 'user',
                    'content' => [
                        [
                            'type'     => 'text',
                            'text'     => 'Identify the main object in this image and return a JSON with: objectLabel, productName, manufacturer, specification, description, boundingBox (ymin, xmin, ymax, xmax as 0-1000), and confidence (0-1).',
                        ],
                        [
                            'type'     => 'image_url',
                            'image_url' => [
                                'url' => "data:{$mimeType};base64,{$base64Image}",
                            ],
                        ],
                    ],
                ],
            ],
        ];

        return $this->httpPost($url, $payload, $this->config['timeout_seconds'] ?? 30, $apiKey);
    }

    /**
     * @param array<string, mixed> $payload
     * @return array<string, mixed>|null
     */
    private function httpPost(string $url, array $payload, int $timeout, string $apiKey): ?array
    {
        $ch = curl_init($url);

        curl_setopt_array($ch, [
            CURLOPT_POST            => true,
            CURLOPT_POSTFIELDS      => json_encode($payload),
            CURLOPT_HTTPHEADER      => [
                'Content-Type: application/json',
                'Authorization: Bearer ' . $apiKey,
            ],
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
     * Format an API error without exposing credentials.
     *
     * @param array<string, mixed> $error
     */
    private function formatApiError(array $error): string
    {
        $message = $error['message'] ?? 'API request failed.';
        return $this->sanitiseError(is_string($message) ? $message : 'API request failed.');
    }

    /**
     * Parse the Agnes API response into a ProductResult.
     *
     * @param array<string, mixed> $response
     */
    private function parseResponse(array $response): ?ProductResult
    {
        // Try to extract text from response
        $candidate = $this->extractCandidateText($response);

        if (empty($candidate)) {
            return null;
        }

        $data = json_decode($candidate, true);

        if (!is_array($data)) {
            return null;
        }

        return $this->buildProductResult($data);
    }

    /**
     * Extract the candidate text from an Agnes API response.
     *
     * @param array<string, mixed> $response
     */
    private function extractCandidateText(array $response): ?string
    {
        // Standard Agnes response format
        if (
            isset($response['choices'][0]['message']['content'])
        ) {
            return $response['choices'][0]['message']['content'];
        }

        // Fallback: search for JSON block in text
        $text = $this->extractText($response);
        if (preg_match('/\{[\s\S]*\}/', $text, $matches)) {
            return $matches[0];
        }

        return null;
    }

    /**
     * Extract plain text from an Agnes response.
     *
     * @param array<string, mixed> $response
     */
    private function extractText(array $response): string
    {
        if (isset($response['choices'][0]['message']['content'])) {
            return $response['choices'][0]['message']['content'];
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
        $objectLabel = $this->strOrNull($data['objectLabel']
            ?? $data['object_label']
            ?? $data['label']
            ?? $data['name']
            ?? null);

        if (empty($objectLabel)) {
            return null;
        }

        $productName = $this->strOrNull($data['productName']
            ?? $data['product_name']
            ?? $data['product']
            ?? $objectLabel);

        $manufacturer = $this->strOrNull($data['manufacturer']
            ?? $data['brand']
            ?? $data['company']
            ?? null);

        $specification = $this->strOrNull($data['specification']
            ?? $data['specs']
            ?? $data['specifications']
            ?? null);

        $description = $this->strOrNull($data['description']
            ?? $data['summary']
            ?? null);

        $bbox = $this->parseBoundingBox($data['boundingBox']
            ?? $data['bounding_box']
            ?? $data['bbox']
            ?? []);

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

        if ($ymin >= $ymax || $xmin >= $xmax) {
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
