<?php
declare(strict_types=1);

/**
 * Google Gemini vision provider.
 *
 * Sends the captured image to the Gemini REST API (generateContent endpoint)
 * and asks for the shared JSON recognition structure. The API key only ever
 * appears in the Authorization request header - never in URLs, logs or
 * responses.
 */
final class GeminiVision extends AIProvider
{
    private const GENERATE_CONTENT = 'https://generativelanguage.googleapis.com/v1beta/models/%s:generateContent';
    private const MODELS_LIST = 'https://generativelanguage.googleapis.com/v1beta/models?pageSize=1';
    private const API_KEY_ENV = 'GEMINI_API_KEY';

    /** @var string[] model ids to try in order */
    private array $modelOrder;

    public function __construct()
    {
        parent::__construct('gemini');
        $config = AppConfig::load();
        $models = $config->geminiModels();
        $this->modelOrder = $models !== [] ? $models : ['gemini-3.8-flash'];
    }

    /**
     * The key is read from config/config.php, then the GEMINI_API_KEY
     * environment variable as a fallback. Empty when nothing is set.
     */
    protected function configKey(): string
    {
        $key = trim((string) AppConfig::load()->gemini_api_key);
        if ($key === '' && function_exists('getenv')) {
            $key = (string) getenv(self::API_KEY_ENV);
        }
        if ($key === '' && isset($_SERVER[self::API_KEY_ENV]) && is_string($_SERVER[self::API_KEY_ENV])) {
            $key = (string) $_SERVER[self::API_KEY_ENV];
        }
        return $key;
    }

    /**
     * @param string $base64Data image data (no data: prefix)
     * @return array<string, mixed> raw provider data
     * @throws ProviderException
     * @throws ProviderNotConfiguredException
     */
    public function request(string $base64Data, string $mime): array
    {
        $this->requireConfig();
        $key = $this->configKey();
        $headers = ['Authorization: Bearer ' . $key];
        $timeout = AppConfig::load()->timeout();
        $lastError = 'no model was attempted';

        // Try each configured model in order until one succeeds.
        foreach ($this->modelOrder as $model) {
            $url = sprintf(self::GENERATE_CONTENT, rawurlencode($model));
            $payload = [
                'contents' => [
                    [
                        'parts' => [
                            ['text' => self::buildPrompt()],
                            [
                                'inlineData' => [
                                    'mimeType' => $mime,
                                    'data' => $base64Data,
                                ],
                            ],
                        ],
                    ],
                ],
                'generationConfig' => [
                    'temperature' => 0.1,
                    'responseMimeType' => 'application/json',
                ],
            ];

            $response = HttpHelper::postJson($url, $payload, $headers, $timeout);
            if ($response['error'] !== null) {
                $lastError = "{$model}: " . $response['error'];
                continue; // 4xx/5xx or network failure - next model in the chain.
            }

            $text = $this->extractText($response['body'], $model);
            $data = self::extractJson($text);
            if ($data === null) {
                $lastError = "{$model}: the provider did not return a readable JSON answer.";
                continue;
            }
            return $data;
        }

        throw new ProviderException("Gemini: {$lastError}");
    }

    /**
     * Extract the model's text from a generateContent response body.
     *
     * @param string $body raw HTTP body
     * @param string $model used for error messages only
     * @throws ProviderException
     */
    private function extractText(string $body, string $model): string
    {
        $decoded = json_decode($body, true);
        if (!is_array($decoded)) {
            throw new ProviderException("Gemini ({$model}): response was not valid JSON.");
        }
        // Some API versions answer with the structured JSON object directly.
        if (isset($decoded['objectLabel'])) {
            return $body;
        }

        $candidates = $decoded['candidates'] ?? [];
        if (!is_array($candidates) || count($candidates) === 0) {
            $blockReason = $decoded['promptFeedback']['blockReason'] ?? '';
            if (is_string($blockReason) && $blockReason !== '') {
                throw new ProviderException("Gemini ({$model}): request was blocked ({$blockReason}).");
            }
            throw new ProviderException("Gemini ({$model}): the response contained no candidates.");
        }

        foreach ($candidates as $candidate) {
            $content = $candidate['content'] ?? null;
            if (!is_array($content)) {
                continue;
            }
            foreach ((array) ($content['parts'] ?? []) as $part) {
                if (is_array($part) && isset($part['text']) && is_string($part['text'])) {
                    return $part['text'];
                }
            }
        }
        throw new ProviderException("Gemini ({$model}): the response contained no text output.");
    }

    /**
     * Cheap connectivity probe for the "Test Connection" feature.
     * Lists one model with the key - validates the key without spending a
     * full image request. Never returns the key itself.
     *
     * @return array{ok: bool, message: string}
     */
    public static function testConnection(): array
    {
        try {
            $provider = new self();
            $provider->requireConfig();
        } catch (ProviderNotConfiguredException $e) {
            return ['ok' => false, 'message' => 'Not configured: no Gemini API key is available.'];
        }

        $key = $provider->configKey();
        $result = HttpHelper::getJson(self::MODELS_LIST, ['Authorization: Bearer ' . $key], 20);
        if ($result['status'] >= 200 && $result['status'] < 300) {
            return ['ok' => true, 'message' => 'Connected to the Gemini API.'];
        }
        if ($result['error'] !== null && (stripos($result['error'], 'API key') !== false || stripos($result['error'], '401') !== false || stripos($result['error'], '400') !== false)) {
            return ['ok' => false, 'message' => 'The Gemini API rejected the key (' . $result['error'] . '). Check that the key has the correct API enabled.'];
        }
        return ['ok' => false, 'message' => 'Connection failed: ' . ($result['error'] ?? 'no response from the provider.')];
    }
}
