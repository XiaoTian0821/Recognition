<?php
/**
 * history.php — Scan history viewer.
 */

require_once __DIR__ . '/includes/AppConfig.php';
require_once __DIR__ . '/includes/History.php';

use App\AppConfig;
use App\History;

$historyEnabled = AppConfig::get('enable_history', true);
AppConfig::boot();
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Scan History — AI + AR Scanner</title>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="assets/css/app.css">
</head>
<body>
    <div class="history-page">

        <!-- ── Header ──────────────────────────────────────── -->
        <div class="history-header">
            <a href="index.php" class="back-btn" aria-label="Back to scanner">
                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" width="18" height="18" aria-hidden="true">
                    <polyline points="15 18 9 12 15 6"></polyline>
                </svg>
            </a>
            <h1>Scan History</h1>
            <?php if ($historyEnabled): ?>
            <button type="button" id="clear-history-btn" class="clear-btn" aria-label="Clear all history">
                Clear All
            </button>
            <?php endif; ?>
        </div>

        <!-- ── Search ──────────────────────────────────────── -->
        <div class="search-wrap">
            <svg class="search-icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
                <circle cx="11" cy="11" r="8"></circle>
                <line x1="21" y1="21" x2="16.65" y2="16.65"></line>
            </svg>
            <input type="search" id="history-search"
                   placeholder="Search by product or manufacturer…"
                   aria-label="Search scan history">
        </div>

        <?php if (!$historyEnabled): ?>
            <div class="empty-state">
                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5" aria-hidden="true">
                    <circle cx="12" cy="12" r="10"></circle>
                    <polyline points="12 6 12 12 16 14"></polyline>
                </svg>
                <p>Scan history is disabled in configuration.</p>
            </div>
        <?php else: ?>
            <!-- ── Table ─────────────────────────────────────── -->
            <div class="history-table-wrap">
                <table class="history-table" aria-label="Scan history">
                    <thead>
                        <tr>
                            <th scope="col">Product</th>
                            <th scope="col">Provider</th>
                            <th scope="col">Confidence</th>
                            <th scope="col">Date</th>
                            <th scope="col">Actions</th>
                        </tr>
                    </thead>
                    <tbody id="history-table-body">
                        <tr><td colspan="5" style="text-align:center;padding:20px;color:var(--color-text-muted);">Loading…</td></tr>
                    </tbody>
                </table>
            </div>

            <!-- ── Pagination ────────────────────────────────── -->
            <div id="history-pagination" class="pagination" aria-label="Pagination"></div>

            <!-- ── Empty State ───────────────────────────────── -->
            <div id="history-empty" class="empty-state" style="display:none;">
                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5" aria-hidden="true">
                    <circle cx="12" cy="12" r="10"></circle>
                    <polyline points="12 6 12 12 16 14"></polyline>
                </svg>
                <p>No scan history yet. Point your camera at an object to start.</p>
            </div>
        <?php endif; ?>

    </div><!-- /history-page -->

    <!-- ── Detail Modal ─────────────────────────────────────── -->
    <div id="detail-modal" class="modal-overlay" role="dialog" aria-modal="true" aria-hidden="true" aria-label="Scan detail">
        <div class="modal-content">
            <button class="modal-close" id="modal-close-btn" aria-label="Close dialog">
                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" width="16" height="16" aria-hidden="true">
                    <line x1="18" y1="6" x2="6" y2="18"></line>
                    <line x1="6" y1="6" x2="18" y2="18"></line>
                </svg>
            </button>
            <div id="detail-modal-body"></div>
        </div>
    </div>

    <script>
        // Close modal on overlay click
        document.addEventListener('DOMContentLoaded', () => {
            const modal = document.getElementById('detail-modal');
            const closeBtn = document.getElementById('modal-close-btn');

            if (modal) {
                modal.addEventListener('click', (e) => {
                    if (e.target === modal) closeModal();
                });
            }

            if (closeBtn) {
                closeBtn.addEventListener('click', closeModal);
            }

            document.addEventListener('keydown', (e) => {
                if (e.key === 'Escape' && modal && modal.classList.contains('visible')) {
                    closeModal();
                }
            });
        });

        function closeModal() {
            const modal = document.getElementById('detail-modal');
            if (modal) {
                modal.classList.remove('visible');
                modal.setAttribute('aria-hidden', 'true');
            }
        }
    </script>
    <script src="assets/js/history.js"></script>
    <script>
        document.addEventListener('DOMContentLoaded', () => {
            HistoryPage.init();
        });
    </script>
</body>
</html>
