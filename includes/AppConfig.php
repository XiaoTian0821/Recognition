<?php
declare(strict_types=1);

/**
 * Application configuration.
 *
 * Values are read from config/config.php (which is protected from direct
 * HTTP download by .htaccess + index.php in that folder) and merged over
 * the built-in defaults below. Missing files or keys are never fatal:
 * the application always boots with safe defaults and simply reports
 * "AI provider is not configured" when keys are absent.
 */

// 自动定义 APP_ROOT 常量，防止未在入口文件定义时抛出 Undefined constant 错误
if (!defined('APP_ROOT')) {
    define('APP_ROOT', dirname(__DIR__));
}

final class AppConfig
{
    /** @var array<string, mixed> */
    private array $values;

    private static ?self $instance = null;

    /** @param array<string, mixed> $values */
    private function __construct(array $values)
    {
        $this->values = $values;
        $this->values['db'] = (array) ($this->values['db'] ?? []) + self::defaults()['db'];
    }

    /** Load (and cache) the configuration for this request. */
    public static function load(): self
    {
        if (self::$instance === null) {
            $values = self::defaults();
            $path = APP_ROOT . '/config/config.php';
            if (is_file($path)) {
                $loaded = include $path;
                if (is_array($loaded)) {
                    $values = array_merge($values, $loaded);
                    $values['db'] = (array) ($loaded['db'] ?? []) + $values['db'];
                } else {
                    error_log('[config] config/config.php did not return an array; using defaults.');
                }
            }
            self::$instance = new self($values);
        }
        return self::$instance;
    }

    /** Forget the cached instance (used after the settings page writes a new file). */
    public static function reset(): void
    {
        self::$instance = null;
    }

    /**
     * Read a single configuration value with a default fallback.
     */
    public function get(string $name, $default = null)
    {
        return $this->values[$name] ?? $default;
    }

    /**
     * Magic accessor for config keys (AppConfig::load()->gemini_api_key).
     */
    public function __get(string $name)
    {
        return $this->values[$name] ?? null;
    }

    /** Valid provider mode: auto | gemini | agnes */
    public function providerMode(): string
    {
        $mode = strtolower((string) $this->get('provider_mode', 'auto'));
        return in_array($mode, ['auto', 'gemini', 'agnes'], true) ? $mode : 'auto';
    }

    /** @return string[] Gemini models to try in order (primary first). */
    public function geminiModels(): array
    {
        return self::uniqueNonEmpty(
            [(string) $this->get('gemini_model', '')],
            (array) $this->get('gemini_fallback_models', [])
        );
    }

    /** @return string[] Agnes models to try in order (primary first). */
    public function agnesModels(): array
    {
        return self::uniqueNonEmpty(
            [(string) $this->get('agnes_model', '')],
            (array) $this->get('agnes_fallback_models', [])
        );
    }

    /** Request timeout in seconds, clamped to the allowed 10-120 range. */
    public function timeout(): int
    {
        $t = (int) $this->get('request_timeout', 30);
        if ($t <= 0) {
            $t = 30;
        }
        return max(10, min(120, $t));
    }

    /** @return array<string, mixed> the merged raw values (used by the settings API). */
    public function currentValues(): array
    {
        return $this->values;
    }

    /**
     * Write a full settings array to config/config.php and reload.
     * Called only by api/settings.php after validation.
     *
     * @param array<string, mixed> $settings
     * @throws RuntimeException when the file cannot be written.
     */
    public static function write(array $settings): void
    {
        $values = array_merge(self::defaults(), $settings);
        $values['db'] = (array) ($settings['db'] ?? []) + self::defaults()['db'];
        $values['debug'] = (bool) ($settings['debug'] ?? false);

        $dir = APP_ROOT . '/config';
        if (!is_dir($dir)) {
            @mkdir($dir, 0775, true);
        }

        $content = "<?php\n"
            . "// Local configuration for the AI + AR Object Scanner.\n"
            . "// Keep this file private - it may contain API keys.\n"
            . "// Generated on " . date('Y-m-d H:i') . "\n"
            . 'return ' . var_export($values, true) . ";\n";

        $file = $dir . '/config.php';
        if (file_put_contents($file, $content, LOCK_EX) === false) {
            throw new RuntimeException(
                'The configuration file could not be written. Check the permissions of the config folder.'
            );
        }
        self::reset();
    }

    /** @return array<string, mixed> */
    private static function defaults(): array
    {
        return [
            'provider_mode' => 'auto',
            'gemini_api_key' => '',
            'gemini_model' => 'gemini-3.8-flash',
            'gemini_fallback_models' => [],
            'agnes_api_key' => '',
            'agnes_api_base' => 'https://api.agnes.space/v1',
            'agnes_model' => '',
            'agnes_fallback_models' => [],
            'request_timeout' => 30,
            'web_lookup_url' => '',
            'settings_password' => '',
            'debug' => false,
            'db' => [
                'enabled' => false,
                'host' => '127.0.0.1',
                'name' => 'ai_ar_scanner',
                'user' => 'root',
                'pass' => '',
            ],
        ];
    }

    /**
     * Merge a primary model plus fallback lists into an ordered, de-duplicated
     * list of non-empty model ids.
     *
     * @param string[] $primary
     * @param array<int|string, mixed> $fallback
     * @return string[]
     */
    private static function uniqueNonEmpty(array $primary, array $fallback): array
    {
        $list = array_merge($primary, array_map('strval', $fallback));
        $seen = [];
        $out = [];
        foreach ($list as $model) {
            $model = trim((string) $model);
            if ($model === '' || isset($seen[$model])) {
                continue;
            }
            $seen[$model] = true;
            $out[] = $model;
        }
        return $out;
    }
}