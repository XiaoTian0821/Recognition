<?php

declare(strict_types=1);

/**
 * Settings API Endpoint
 * Handles provider connection tests and configuration queries.
 */

header('Content-Type: application/json; charset=utf-8');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit;
}

require_once __DIR__ . '/../includes/AppConfig.php';
require_once __DIR__ . '/../includes/AIProvider.php';
require_once __DIR__ . '/../includes/GeminiVision.php';
require_once __DIR__ . '/../includes/AgnesVision.php';

use App\AppConfig;
use App\GeminiVision;
use App\AgnesVision;

AppConfig::boot();

/**
 * Return a JSON error response.
 */
function jsonResponseError(string $message, int $status = 400): void
{
    http_response_code($status);
    echo json_encode(['ok' => false, 'error' => $message], JSON_UNESCAPED_UNICODE);
    exit;
}

/**
 * Return a JSON success response.
 */
function jsonResponseSuccess(array $data): void
{
    http_response_code(200);
    echo json_encode(['ok' => true, 'data' => $data], JSON_UNESCAPED_UNICODE);
    exit;
}

// ─────────────────────────────────────────────────────────────────
// Test provider connection
// ─────────────────────────────────────────────────────────────────

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_GET['action']) && $_GET['action'] === 'test') {
    $provider = $_GET['provider'] ?? '';
    $testInput = json_decode(file_get_contents('php://input'), true);
    $testInput = is_array($testInput) ? $testInput : [];

    switch ($provider) {
        case 'gemini':
            $config = AppConfig::get('gemini', []);
            if (!empty($testInput['api_key'])) {
                $config['api_key'] = trim((string) $testInput['api_key']);
            }
            $vision = new GeminiVision($config);
            $result = $vision->testConnection();
            jsonResponseSuccess($result);
            break;

        case 'agnes':
            $config = AppConfig::get('agnes', []);
            if (!empty($testInput['api_key'])) {
                $config['api_key'] = trim((string) $testInput['api_key']);
            }
            $vision = new AgnesVision($config);
            $result = $vision->testConnection();
            jsonResponseSuccess($result);
            break;

        default:
            jsonResponseError('Invalid provider. Use "gemini" or "agnes".');
    }
}

// ─────────────────────────────────────────────────────────────────
// Get current configuration (without API keys)
// ─────────────────────────────────────────────────────────────────

if ($_SERVER['REQUEST_METHOD'] === 'GET' && isset($_GET['action']) && $_GET['action'] === 'get') {
    $config = AppConfig::all();

    // Redact API keys
    $sanitised = [
        'primary_provider' => $config['primary_provider'] ?? 'auto',
        'gemini' => [
            'model'           => $config['gemini']['model'] ?? 'gemini-3.6-flash',
            'fallback_models' => $config['gemini']['fallback_models'] ?? [],
            'timeout_seconds' => $config['gemini']['timeout_seconds'] ?? 30,
            'api_key_set'     => !empty($config['gemini']['api_key'] ?? null),
        ],
        'agnes' => [
            'model'           => $config['agnes']['model'] ?? 'agnes-vision-v1',
            'fallback_models' => $config['agnes']['fallback_models'] ?? [],
            'timeout_seconds' => $config['agnes']['timeout_seconds'] ?? 30,
            'api_key_set'     => !empty($config['agnes']['api_key'] ?? null),
        ],
        'enable_history' => $config['enable_history'] ?? true,
        'database_enabled' => !empty($config['database']['dbname'] ?? null),
    ];

    jsonResponseSuccess($sanitised);
}

// ─────────────────────────────────────────────────────────────────
// POST: Save settings
// ─────────────────────────────────────────────────────────────────

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_GET['action']) && $_GET['action'] === 'save') {
    $input = json_decode(file_get_contents('php://input'), true);

    if (!is_array($input)) {
        jsonResponseError('Invalid JSON payload.');
    }

    $configPath = __DIR__ . '/../config/config.php';
    $examplePath = __DIR__ . '/../config/config.example.php';

    if (!file_exists($configPath)) {
        // Copy from example if config.php doesn't exist
        if (!file_exists($examplePath)) {
            jsonResponseError('Configuration file not found. Copy config.example.php to config.php first.');
        }
        copy($examplePath, $configPath);
    }

    // Load current config
    $currentConfig = require $configPath;

    // Update provider
    if (isset($input['primary_provider'])) {
        $currentConfig['primary_provider'] = in_array($input['primary_provider'], ['auto', 'gemini', 'agnes'])
            ? $input['primary_provider'] : 'auto';
    }

    // Update Gemini settings
    if (isset($input['gemini']) && is_array($input['gemini'])) {
        if (isset($input['gemini']['model'])) {
            $currentConfig['gemini']['model'] = trim($input['gemini']['model']);
        }
        if (isset($input['gemini']['timeout_seconds'])) {
            $currentConfig['gemini']['timeout_seconds'] = max(5, min(120, (int) $input['gemini']['timeout_seconds']));
        }
        if (isset($input['gemini']['fallback_models'])) {
            $models = array_filter(array_map('trim', $input['gemini']['fallback_models']));
            $currentConfig['gemini']['fallback_models'] = array_values($models);
        }
    }

    // Update Agnes settings
    if (isset($input['agnes']) && is_array($input['agnes'])) {
        if (isset($input['agnes']['model'])) {
            $currentConfig['agnes']['model'] = trim($input['agnes']['model']);
        }
        if (isset($input['agnes']['timeout_seconds'])) {
            $currentConfig['agnes']['timeout_seconds'] = max(5, min(120, (int) $input['agnes']['timeout_seconds']));
        }
        if (isset($input['agnes']['fallback_models'])) {
            $models = array_filter(array_map('trim', $input['agnes']['fallback_models']));
            $currentConfig['agnes']['fallback_models'] = array_values($models);
        }
    }

    // Update API keys (only if provided)
    $geminiKeySet = false;
    $agnesKeySet  = false;

    $invalidKeyPlaceholders = [
        'connection timeout or failure.',
        'network error.',
        'connection failed.',
    ];

    foreach (['gemini_api_key', 'agnes_api_key'] as $keyField) {
        $submittedKey = trim((string) ($input[$keyField] ?? ''));
        $containsErrorText = preg_match('/(?:timeout|could not resolve host|network error|connection failed)/i', $submittedKey);
        if ($submittedKey !== '' && ($containsErrorText || in_array(strtolower($submittedKey), $invalidKeyPlaceholders, true))) {
            jsonResponseError('Enter a valid API key, not a connection error message.');
        }
    }

    if (isset($input['gemini_api_key']) && !empty($input['gemini_api_key'])
        && !preg_match('/(?:timeout|could not resolve host|network error|connection failed)/i', trim($input['gemini_api_key']))
        && !in_array(strtolower(trim($input['gemini_api_key'])), $invalidKeyPlaceholders, true)) {
        $currentConfig['gemini']['api_key'] = trim($input['gemini_api_key']);
        $geminiKeySet = true;
    }

    if (isset($input['agnes_api_key']) && !empty($input['agnes_api_key'])
        && !preg_match('/(?:timeout|could not resolve host|network error|connection failed)/i', trim($input['agnes_api_key']))
        && !in_array(strtolower(trim($input['agnes_api_key'])), $invalidKeyPlaceholders, true)) {
        $currentConfig['agnes']['api_key'] = trim($input['agnes_api_key']);
        $agnesKeySet = true;
    }

    // Write config
    $configContent = "<?php\n\ndeclare(strict_types=1);\n\n/**\n * Application configuration.\n * Modified by the settings page.\n */\n\nreturn " . var_export($currentConfig, true) . ";\n";

    $writeResult = @file_put_contents($configPath, $configContent);

    if ($writeResult === false) {
        jsonResponseError('Failed to write configuration file. Check file permissions.');
    }

    jsonResponseSuccess([
        'message'     => 'Settings saved successfully.',
        'gemini_key_set' => $geminiKeySet,
        'agnes_key_set'  => $agnesKeySet,
    ]);
}

jsonResponseError('Invalid or missing action parameter.');
