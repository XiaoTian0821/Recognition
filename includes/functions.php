<?php
declare(strict_types=1);

/**
 * Small global helper functions shared by pages and API endpoints.
 */

/** Escape a value for safe HTML output. */
function app_e(string $value): string
{
    return htmlspecialchars($value, ENT_QUOTES, 'UTF-8');
}

/**
 * URL path prefix of the application.
 * Returns '' when the app lives at the domain root, or '/Recognition'
 * (for example) when it is installed in a subdirectory.
 */
function app_base_path(): string
{
    $script = str_replace('\\', '/', (string) ($_SERVER['SCRIPT_NAME'] ?? ''));
    // Requests arrive either from a page at the app root
    // (/{base}/index.php) or from the api/ folder (/{base}/api/recognize.php).
    if (preg_match('#^(.*)/api/[^/]+$#', $script, $m)) {
        $base = $m[1];
    } else {
        $base = dirname($script);
    }
    if ($base === '/' || $base === '\\' || $base === '' || $base === '.') {
        return '';
    }
    return $base;
}

/** Send a JSON API response and stop execution. */
function api_respond(int $status, array $payload): void
{
    http_response_code($status);
    header('Content-Type: application/json; charset=utf-8');
    header('Cache-Control: no-store');
    echo json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
}

/**
 * Read and decode the JSON request body (max 15 MB).
 * Returns [] when the body is empty or not valid JSON.
 */
function api_read_json_body(): array
{
    $raw = (string) file_get_contents('php://input');
    if (strlen($raw) > 15 * 1024 * 1024) {
        api_respond(413, ['ok' => false, 'error' => 'Request body is too large.']);
    }
    $decoded = json_decode($raw, true);
    return is_array($decoded) ? $decoded : [];
}
