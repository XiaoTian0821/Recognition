<?php
declare(strict_types=1);

/**
 * Scan-history API (used by history.php).
 *
 * GET  ?page=N&perPage=M&search=...      list rows
 * GET  ?id=...                           one row (detail view)
 * POST {"action":"clear"}                delete all rows
 *
 * Returns {"ok":false,"error":"Database is not configured.","disabled":true}
 * when the database module is off, so the frontend can show a friendly hint.
 */

require dirname(__DIR__) . '/includes/bootstrap.php';

if (!Database::isEnabled()) {
    api_respond(200, [
        'ok' => false,
        'disabled' => true,
        'error' => 'Scan history is not available. Enable the database module in Settings (see database/database.sql).',
    ]);
}

$method = strtoupper((string) ($_SERVER['REQUEST_METHOD'] ?? 'GET'));

// ---- Clear history (POST) ------------------------------------------------
if ($method === 'POST') {
    $body = api_read_json_body();
    if ((string) ($body['action'] ?? '') === 'clear') {
        $cleared = Database::clear();
        api_respond(
            $cleared ? 200 : 500,
            $cleared
                ? ['ok' => true, 'data' => ['message' => 'Scan history cleared.']]
                : ['ok' => false, 'error' => 'Could not clear the history. Check the database settings.']
        );
    }
    api_respond(400, ['ok' => false, 'error' => 'Unknown history action.']);
}

// ---- List / detail (GET) -------------------------------------------------
if ($method !== 'GET') {
    api_respond(405, ['ok' => false, 'error' => 'Method not allowed. Use GET or POST.']);
}

$page = max(1, (int) ($_GET['page'] ?? 1));
$perPage = max(1, min(100, (int) ($_GET['perPage'] ?? 20)));
$search = (string) ($_GET['search'] ?? '');
// Keep searches sane and prevent LIKE-injection surprises.
$search = mb_substr($search, 0, 80);

if (isset($_GET['id'])) {
    $row = Database::find((int) $_GET['id']);
    if ($row === null) {
        api_respond(404, ['ok' => false, 'error' => 'No scan found with that id.']);
    }
    api_respond(200, ['ok' => true, 'data' => ['row' => $row]]);
}

$result = Database::history($page, $perPage, $search);
$totalPages = max(1, (int) ceil($result['total'] / $perPage));

api_respond(200, [
    'ok' => true,
    'data' => [
        'rows' => $result['rows'],
        'total' => $result['total'],
        'page' => $page,
        'perPage' => $perPage,
        'totalPages' => $totalPages,
    ],
]);
