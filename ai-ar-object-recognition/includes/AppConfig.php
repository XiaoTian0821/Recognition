<?php

declare(strict_types=1);

/**
 * Loads and provides access to the application configuration.
 */

namespace App;

class AppConfig
{
    /** @var array<string, mixed> */
    private static array $config = [];

    /**
     * Load configuration from config/config.php.
     */
    public static function boot(): void
    {
        $configPath = __DIR__ . '/../config/config.php';

        if (!file_exists($configPath)) {
            self::$config = self::getDefaultConfig();
            return;
        }

        $config = require $configPath;
        self::$config = is_array($config) ? $config : self::getDefaultConfig();
    }

    /**
     * Get a configuration value by key path.
     *
     * @param mixed $default
     * @return mixed
     */
    public static function get(string $key, $default = null)
    {
        $keys = explode('.', $key);
        $value = self::$config;

        foreach ($keys as $k) {
            if (!is_array($value) || !array_key_exists($k, $value)) {
                return $default;
            }
            $value = $value[$k];
        }

        return $value;
    }

    /**
     * Get the entire configuration array.
     *
     * @return array<string, mixed>
     */
    public static function all(): array
    {
        return self::$config;
    }

    /**
     * Get the primary AI provider name.
     */
    public static function provider(): string
    {
        return strtolower(self::get('primary_provider', 'auto'));
    }

    /**
     * Get default configuration when config.php is missing.
     *
     * @return array<string, mixed>
     */
    private static function getDefaultConfig(): array
    {
        return [
            'primary_provider'     => 'gemini',
            'gemini'               => [
                'api_key'          => '',
                'model'            => 'gemini-3.6-flash',
                'fallback_models'  => [],
                'timeout_seconds'  => 30,
                'api_url'          => 'https://generativelanguage.googleapis.com/v1beta/models/{model}:generateContent?key={api_key}',
            ],
            'agnes'                => [
                'api_key'          => '',
                'model'            => 'agnes-vision-v1',
                'fallback_models'  => ['agnes-vision-v1-lite'],
                'timeout_seconds'  => 30,
                'api_url'          => 'https://api.agnes.ai/v1/vision/recognize',
            ],
            'enable_web_lookup'    => false,
            'database'             => [
                'host'     => '127.0.0.1',
                'port'     => '3306',
                'dbname'   => 'ai_ar_recognition',
                'charset'  => 'utf8mb4',
                'username' => 'root',
                'password' => '',
                'options'  => [],
            ],
            'max_image_dimension'  => 1280,
            'jpeg_quality'         => 70,
            'max_file_size_bytes'  => 1572864,
            'allowed_mime_types'   => ['image/jpeg', 'image/png', 'image/webp', 'image/gif'],
            'request_timeout'      => 60,
            'enable_history'       => true,
            'max_history_records'  => 500,
        ];
    }
}
