<?php

declare(strict_types=1);

/**
 * Scan History API Endpoint
 */

header('Content-Type: application/json; charset=utf-8');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, POST, DELETE, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit;
}

require_once __DIR__ . '/../includes/AppConfig.php';
require_once __DIR__ . '/../includes/History.php';

use App\AppConfig;
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
// Ensure history is enabled
// ─────────────────────────────────────────────────────────────────

if (!AppConfig::get('enable_history', true)) {
    jsonResponseError('Scan history is disabled.');
}

$history = new History();

// ─────────────────────────────────────────────────────────────────
// GET: List or search history
// ─────────────────────────────────────────────────────────────────

if ($_SERVER['REQUEST_METHOD'] === 'GET') {
    $action = $_GET['action'] ?? 'list';
    $page   = max(1, (int) ($_GET['page'] ?? 1));
    $perPage = min(100, max(1, (int) ($_GET['per_page'] ?? 20)));

    switch ($action) {
        case 'list':
            $result = $history->getHistory($page, $perPage);
            jsonResponseSuccess($result);
            break;

        case 'search':
            $keyword = trim($_GET['q'] ?? '');
            if (empty($keyword)) {
                jsonResponseError('Search keyword is required.');
            }
            $result = $history->search($keyword, $page, $perPage);
            jsonResponseSuccess($result);
            break;

        case 'detail':
            $id = (int) ($_GET['id'] ?? 0);
            if ($id <= 0) {
                jsonResponseError('Invalid record ID.');
            }
            $record = $history->getById($id);
            if ($record === null) {
                jsonResponseError('Record not found.', 404);
            }
            jsonResponseSuccess($record);
            break;

        default:
            jsonResponseError('Invalid action.');
    }
}

// ─────────────────────────────────────────────────────────────────
// DELETE: Clear all or delete single record
// ─────────────────────────────────────────────────────────────────

if ($_SERVER['REQUEST_METHOD'] === 'DELETE') {
    $input = json_decode(file_get_contents('php://input'), true);
    $action = $_GET['action'] ?? 'clear';

    switch ($action) {
        case 'clear':
            $result = $history->clear();
            jsonResponseSuccess(['deleted' => $result]);
            break;

        case 'delete':
            $id = (int) ($input['id'] ?? 0);
            if ($id <= 0) {
                jsonResponseError('Invalid record ID.');
            }
            $result = $history->delete($id);
            if (!$result) {
                jsonResponseError('Record not found or deletion failed.', 404);
            }
            jsonResponseSuccess(['deleted' => true]);
            break;

        default:
            jsonResponseError('Invalid action.');
    }
}

jsonResponseError('Method not allowed.', 405);
