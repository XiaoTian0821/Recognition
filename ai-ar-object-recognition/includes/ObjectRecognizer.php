<?php

declare(strict_types=1);

/**
 * Orchestrates object recognition using configured AI providers.
 */

namespace App;

class ObjectRecognizer
{
    /**
     * @var array<string, AIProvider>
     */
    private array $providers = [];

    /**
     * Register providers.
     */
    public function __construct()
    {
        AppConfig::boot();

        $geminiConfig = AppConfig::get('gemini', []);
        $agnesConfig  = AppConfig::get('agnes', []);

        $this->providers['gemini'] = new GeminiVision($geminiConfig);
        $this->providers['agnes']  = new AgnesVision($agnesConfig);
    }

    /**
     * Recognize an object from Base64 image data.
     *
     * @param string $base64Image Base64 without data URI prefix.
     * @param string $mimeType    MIME type.
     * @return array{ok: bool, data?: array<string, mixed>, error?: string}
     */
    public function recognize(string $base64Image, string $mimeType): array
    {
        $primary = AppConfig::get('primary_provider', 'auto');
        $timeout = (int) AppConfig::get('request_timeout', 60);
        $configuredProvider = false;
        $providerErrors = [];

        // Build ordered list of providers to try
        $providerOrder = $this->buildProviderOrder($primary);

        foreach ($providerOrder as $providerName) {
            if (!isset($this->providers[$providerName])) {
                continue;
            }

            $provider = $this->providers[$providerName];

            // Check if provider has an API key
            $configKey = $providerName;
            $apiKey = AppConfig::get("{$configKey}.api_key", '');
            if (empty($apiKey) || $this->isInvalidApiKeyPlaceholder($apiKey)) {
                continue;
            }
            $configuredProvider = true;

            // Run recognition with timeout
            try {
                $result = $this->runWithTimeout(
                    static fn() => $provider->recognize($base64Image, $mimeType),
                    $timeout
                );

                if ($result !== null && $result->isValid()) {
                    return ['ok' => true, 'data' => $result->toArray()];
                }

                if ($provider instanceof GeminiVision && $provider->lastError() !== null) {
                    $providerErrors[] = $providerName . ': ' . $provider->lastError();
                }
            } catch (\Throwable $e) {
                // Log and continue to next provider
                $message = $this->sanitiseError($e->getMessage());
                error_log('[ObjectRecognizer] ' . $providerName . ' error: ' . $message);
                $providerErrors[] = $providerName . ': ' . $message;
                continue;
            }
        }

        return [
            'ok'    => false,
            'error' => $configuredProvider
                ? (!empty($providerErrors)
                    ? implode(' ', $providerErrors)
                    : 'Unable to identify the object. Please try again with a clearer image.')
                : 'No AI provider is configured. Add a Gemini or Agnes API key in Settings.',
        ];
    }

    /**
     * Test all configured providers.
     *
     * @return array<string, array{ok: bool, message: string}>
     */
    public function testAllProviders(): array
    {
        $results = [];

        foreach ($this->providers as $name => $provider) {
            try {
                $results[$name] = $this->runWithTimeout(
                    static fn() => $provider->testConnection(),
                    10
                );
            } catch (\Throwable $e) {
                $results[$name] = ['ok' => false, 'message' => 'Error: '
                    . $this->sanitiseError($e->getMessage())];
            }
        }

        return $results;
    }

    /**
     * Get a provider by name.
     */
    public function getProvider(string $name): ?AIProvider
    {
        return $this->providers[$name] ?? null;
    }

    /**
     * Build ordered list of providers based on primary setting.
     *
     * @return list<string>
     */
    private function buildProviderOrder(string $primary): array
    {
        switch ($primary) {
            case 'gemini':
                return ['gemini'];
            case 'agnes':
                return ['agnes'];
            case 'auto':
            default:
                // Try Gemini first, then Agnes
                return ['gemini', 'agnes'];
        }
    }

    /**
     * Run a callable with a timeout.
     *
     * @template T
     * @param callable(): T $callable
     * @param int $seconds Timeout in seconds.
     * @return T|null
     */
    private function runWithTimeout(callable $callable, int $seconds): mixed
    {
        // Use pcntl if available (Unix), otherwise run synchronously
        if (function_exists('pcntl_signal') && function_exists('pcntl_alarm')) {
            $result = null;
            $finished = false;

            pcntl_signal(SIGALRM, fn() => null);
            pcntl_alarm($seconds);

            $result = $callable();
            $finished = true;

            pcntl_alarm(0);

            if ($finished) {
                return $result;
            }
        }

        // Fallback: run without timeout
        return $callable();
    }

    /**
     * Sanitise error messages.
     */
    private function sanitiseError(string $message): string
    {
        return preg_replace('/[a-zA-Z0-9]{30,}/', '***', $message) ?? $message;
    }

    private function isInvalidApiKeyPlaceholder(string $apiKey): bool
    {
        return in_array(strtolower(trim($apiKey)), [
            'connection timeout or failure.',
            'network error.',
            'connection failed.',
        ], true);
    }
}
