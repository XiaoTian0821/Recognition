<?php
declare(strict_types=1);

/**
 * Agnes vision provider (OpenAI-compatible chat completions API).
 *
 * Default base URL: https://api.agnes.space/v1  (configurable).
 * The key is sent as a Bearer token in the Authorization header.
 */
final class AgnesVision extends AIProvider
{
    private const API_KEY_ENV = 'AGNES_API_KEY';

    /** @var string[] model ids to try in order */
    private array $modelOrder;

    public function __construct()
    {
        parent::__construct('agnes');
        $config = AppConfig::load();
        $models = $config->agnesModels();
        $this->modelOrder = $models !== [] ? $models : ['agnes-2.5-flash'];
    }

    /** @return string base URL without a trailing slash */
    private static function baseUrl(): string
    {
        $base = trim((string) AppConfig::load()->agnes_api_base);
        if ($base === '') {
            $base = 'https://api.agnes.space/v1';
        }
        return rtrim($base, '/');
    }

    /**
     * The key is read from config/config.php, then the AGNES_API_KEY
     * environment variable as a fallback. Empty when nothing is set.
     */
    protected function configKey(): string
    {
        $key = trim((string) AppConfig::load()->agnes_api_key);
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
        $url = self::baseUrl() . '/chat/completions';
        $headers = [
            'Authorization: Bearer ' . $key,
            'Content-Type: application/json',
        ];
        $timeout = AppConfig::load()->timeout();
        $lastError = 'no model was attempted';

        foreach ($this->modelOrder as $model) {
            $payload = [
                'model' => $model,
                'messages' => [
                    [
                        'role' => 'user',
                        'content' => [
                            ['type' => 'text', 'text' => self::buildPrompt()],
                            [
                                'type' => 'image_url',
                                'image_url' => [
                                    'url' => 'data:' . $mime . ';base64,' . $base64Data,
                                ],
                            ],
                        ],
                    ],
                ],
                'temperature' => 0.1,
                'response_format' => ['type' => 'json_object'],
            ];

            $response = HttpHelper::postJson($url, $payload, $headers, $timeout);
            if ($response['error'] !== null) {
                // A 404 on chat completions can mean the model name is wrong;
                // the next model in the chain might be valid.
                $lastError = "{$model}: " . $response['error'];
                if ($response['status'] === 404 || $response['status'] >= 500) {
                    continue;
                }
                throw new ProviderException("Agnes: {$lastError}");
            }

            $text = $this->extractText($response['body'], $model);
            $data = self::extractJson($text);
            if ($data === null) {
                $lastError = "{$model}: the provider did not return a readable JSON answer.";
                continue;
            }
            return $data;
        }

        throw new ProviderException("Agnes: {$lastError}");
    }

    /**
     * Extract the assistant text from an OpenAI-compatible chat response.
     *
     * @param string $body raw HTTP body
     * @param string $model used for error messages only
     * @throws ProviderException
     */
    private function extractText(string $body, string $model): string
    {
        $decoded = json_decode($body, true);
        if (!is_array($decoded)) {
            throw new ProviderException("Agnes ({$model}): response was not valid JSON.");
        }
        $choices = $decoded['choices'] ?? [];
        if (!is_array($choices) || count($choices) === 0) {
            throw new ProviderException("Agnes ({$model}): the response contained no choices.");
        }
        $message = $choices[0]['message'] ?? null;
        if (is_array($message) && isset($message['content']) && is_string($message['content'])) {
            return $message['content'];
        }
        throw new ProviderException("Agnes ({$model}): the response contained no assistant text.");
    }

    /**
     * Connectivity probe for the "Test Connection" feature: asks the API for
     * a one-token completion. Never returns the key itself.
     *
     * @return array{ok: bool, message: string}
     */
    public static function testConnection(): array
    {
        try {
            $provider = new self();
            $provider->requireConfig();
        } catch (ProviderNotConfiguredException $e) {
            return ['ok' => false, 'message' => 'Not configured: no Agnes API key is available.'];
        }

        $key = $provider->configKey();
        $model = $provider->modelOrder[0] ?? 'agnes-2.5-flash';
        $url = self::baseUrl() . '/chat/completions';
        $payload = [
            'model' => $model,
            'messages' => [['role' => 'user', 'content' => 'Reply with the single word: ready']],
            'max_tokens' => 6,
        ];
        $result = HttpHelper::postJson(
            $url,
            $payload,
            ['Authorization: Bearer ' . $key, 'Content-Type: application/json'],
            20
        );

        if ($result['status'] >= 200 && $result['status'] < 300) {
            return ['ok' => true, 'message' => "Connected (model: {$model})."];
        }
        if ($result['error'] !== null && (stripos($result['error'], 'key') !== false || stripos($result['error'], '401') !== false)) {
            return ['ok' => false, 'message' => 'The Agnes API rejected the key. Check that it is valid.'];
        }
        return ['ok' => false, 'message' => 'Connection failed: ' . ($result['error'] ?? 'no response from the provider.')];
    }
}
