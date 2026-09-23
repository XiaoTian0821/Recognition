<?php

declare(strict_types=1);

/**
 * AI Object Recognition API Endpoint
 * Receives a Base64-encoded image and returns structured recognition data.
 */

header('Content-Type: application/json; charset=utf-8');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit;
}

require_once __DIR__ . '/../includes/AppConfig.php';
require_once __DIR__ . '/../includes/ProductResult.php';
require_once __DIR__ . '/../includes/AIProvider.php';
require_once __DIR__ . '/../includes/ImageValidator.php';
require_once __DIR__ . '/../includes/GeminiVision.php';
require_once __DIR__ . '/../includes/AgnesVision.php';
require_once __DIR__ . '/../includes/ObjectRecognizer.php';
require_once __DIR__ . '/../includes/History.php';

use App\AppConfig;
use App\ImageValidator;
use App\ObjectRecognizer;
use App\History;

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
// 1. Validate request method
// ─────────────────────────────────────────────────────────────────

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    jsonResponseError('Invalid request method.', 405);
}

// ─────────────────────────────────────────────────────────────────
// 2. Parse request body
// ─────────────────────────────────────────────────────────────────

$input = json_decode(file_get_contents('php://input'), true);

if (!is_array($input)) {
    jsonResponseError('Invalid JSON payload.');
}

// ─────────────────────────────────────────────────────────────────
// 3. Extract and validate image data
// ─────────────────────────────────────────────────────────────────

$base64Image = $input['image'] ?? '';

if (empty($base64Image)) {
    jsonResponseError('No image data provided.');
}

// ─────────────────────────────────────────────────────────────────
// 4. Validate image
// ─────────────────────────────────────────────────────────────────

$allowedMimeTypes = AppConfig::get('allowed_mime_types', [
    'image/jpeg', 'image/png', 'image/webp', 'image/gif',
]);
$maxFileSize    = AppConfig::get('max_file_size_bytes', 1572864);
$maxDimension   = AppConfig::get('max_image_dimension', 1280);
$jpegQuality    = AppConfig::get('jpeg_quality', 70);

$validation = ImageValidator::validateBase64($base64Image, $allowedMimeTypes, $maxFileSize);

if (!$validation['ok']) {
    jsonResponseError($validation['error'] ?? 'Invalid image data.');
}

$mimeType = $validation['mimeType'] ?? 'image/jpeg';

// ─────────────────────────────────────────────────────────────────
// 5. Resize and compress image
// ─────────────────────────────────────────────────────────────────

$resizeResult = ImageValidator::resizeAndCompress(
    $base64Image,
    $mimeType,
    $maxDimension,
    $jpegQuality
);

if (!$resizeResult['ok']) {
    jsonResponseError($resizeResult['error'] ?? 'Image processing failed.');
}

$finalBase64 = $resizeResult['base64'] ?? '';
$finalMimeType = $resizeResult['mimeType'] ?? 'image/jpeg';

// ─────────────────────────────────────────────────────────────────
// 6. Run AI recognition
// ─────────────────────────────────────────────────────────────────

$recognizer = new ObjectRecognizer();
$recognizeResult = $recognizer->recognize($finalBase64, $finalMimeType);

if (!$recognizeResult['ok']) {
    jsonResponseError($recognizeResult['error'] ?? 'Recognition failed.', 200);
}

$data = $recognizeResult['data'];

// ─────────────────────────────────────────────────────────────────
// 7. Save to history (optional)
// ─────────────────────────────────────────────────────────────────

$historyEnabled = AppConfig::get('enable_history', true);
if ($historyEnabled) {
    $history = new History();
    $history->save($data);
}

// ─────────────────────────────────────────────────────────────────
// 8. Return result
// ─────────────────────────────────────────────────────────────────

jsonResponseSuccess($data);
