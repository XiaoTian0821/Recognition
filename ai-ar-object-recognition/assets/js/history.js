/* ═══════════════════════════════════════════════════════════════
   History Module
   Handles history page: list, search, pagination, detail modal.
   ═══════════════════════════════════════════════════════════════ */

'use strict';

const HistoryPage = (() => {
    let currentPage  = 1;
    const perPage    = 20;
    let currentSearch = '';

    // ── Init ──────────────────────────────────────────────────

    function init() {
        // Search input
        const searchInput = document.querySelector('#history-search');
        if (searchInput) {
            let debounceTimer = null;
            searchInput.addEventListener('input', () => {
                clearTimeout(debounceTimer);
                debounceTimer = setTimeout(() => {
                    currentSearch = searchInput.value.trim();
                    currentPage = 1;
                    loadHistory();
                }, 400);
            });
            searchInput.addEventListener('keydown', (e) => {
                if (e.key === 'Enter') {
                    e.preventDefault();
                    clearTimeout(debounceTimer);
                    currentSearch = searchInput.value.trim();
                    currentPage = 1;
                    loadHistory();
                }
            });
        }

        // Clear all button
        const clearBtn = document.querySelector('#clear-history-btn');
        if (clearBtn) {
            clearBtn.addEventListener('click', confirmClear);
        }

        // Load initial data
        loadHistory();
    }

    // ── Load History ──────────────────────────────────────────

    async function loadHistory() {
        const tableBody = document.querySelector('#history-table-body');
        const paginationEl = document.querySelector('#history-pagination');
        const emptyState = document.querySelector('#history-empty');

        if (!tableBody) return;

        // Show loading state
        tableBody.innerHTML = '<tr><td colspan="6" style="text-align:center;padding:20px;color:var(--color-text-muted);">Loading…</td></tr>';

        try {
            let url = `api/history.php?action=list&page=${currentPage}&per_page=${perPage}`;
            if (currentSearch) {
                url = `api/history.php?action=search&q=${encodeURIComponent(currentSearch)}&page=${currentPage}&per_page=${perPage}`;
            }

            const resp = await fetch(url);
            const json = await resp.json();

            if (!json.ok) {
                throw new Error(json.error || 'Failed to load history.');
            }

            const { records, total, page, perPage: pp } = json.data;

            // Clear table
            tableBody.innerHTML = '';

            if (records.length === 0) {
                if (emptyState) emptyState.style.display = '';
                if (paginationEl) paginationEl.style.display = 'none';
                return;
            }

            if (emptyState) emptyState.style.display = 'none';

            // Populate table
            records.forEach(record => {
                const tr = document.createElement('tr');
                const confidencePct = Math.round((record.confidence || 0));
                const dateStr = formatDate(record.created_at);

                tr.innerHTML = `
                    <td class="product-cell">
                        <div class="product-name">${escapeHtml(record.product_name)}</div>
                        <div class="object-label">${escapeHtml(record.object_label)}</div>
                    </td>
                    <td><span class="provider-tag">${escapeHtml(record.provider || 'unknown')}</span></td>
                    <td class="confidence-cell">
                        <div class="confidence-bar-bg">
                            <div class="confidence-bar-fill" style="width:${confidencePct}%"></div>
                        </div>
                        ${confidencePct}%
                    </td>
                    <td class="date-cell">${dateStr}</td>
                    <td>
                        <button class="view-detail-btn" data-id="${record.id}" aria-label="View details for ${escapeHtml(record.product_name)}">
                            Details
                        </button>
                    </td>
                `;
                tableBody.appendChild(tr);
            });

            // Pagination
            if (paginationEl) {
                const totalPages = Math.max(1, Math.ceil(total / pp));
                paginationEl.innerHTML = buildPagination(page, totalPages);
                paginationEl.style.display = 'flex';

                // Wire up pagination buttons
                paginationEl.querySelectorAll('button[data-page]').forEach(btn => {
                    btn.addEventListener('click', () => {
                        currentPage = parseInt(btn.dataset.page, 10);
                        loadHistory();
                    });
                });
            }

            // Wire up detail buttons
            tableBody.querySelectorAll('.view-detail-btn').forEach(btn => {
                btn.addEventListener('click', () => showDetail(parseInt(btn.dataset.id, 10)));
            });

        } catch (err) {
            tableBody.innerHTML = `<tr><td colspan="6" style="text-align:center;padding:20px;color:var(--color-danger);">${escapeHtml(err.message)}</td></tr>`;
            if (paginationEl) paginationEl.style.display = 'none';
        }
    }

    // ── Detail Modal ──────────────────────────────────────────

    async function showDetail(id) {
        const modal = document.querySelector('#detail-modal');
        if (!modal) return;

        const modalBody = modal.querySelector('#detail-modal-body');
        if (!modalBody) return;

        modalBody.innerHTML = '<p style="text-align:center;color:var(--color-text-muted);">Loading…</p>';
        modal.classList.add('visible');
        modal.setAttribute('aria-hidden', 'false');

        try {
            const resp = await fetch(`api/history.php?action=detail&id=${id}`);
            const json = await resp.json();

            if (!json.ok || !json.data) {
                throw new Error('Record not found.');
            }

            const r = json.data;
            const confidencePct = Math.round((r.confidence || 0));

            modalBody.innerHTML = `
                <div style="margin-bottom:16px;">
                    <h2 style="font-size:1.2rem;font-weight:700;color:#fff;margin-bottom:4px;">${escapeHtml(r.product_name)}</h2>
                    <span class="label-tag">${escapeHtml(r.object_label)}</span>
                </div>
                <div class="detail-row">
                    <span class="detail-label">Manufacturer</span>
                    <span class="detail-value">${escapeHtml(r.manufacturer || '—')}</span>
                </div>
                <div class="detail-row">
                    <span class="detail-label">Specification</span>
                    <span class="detail-value">${escapeHtml(r.specification || '—')}</span>
                </div>
                <div class="detail-row">
                    <span class="detail-label">Description</span>
                    <span class="detail-value">${escapeHtml(r.description || '—')}</span>
                </div>
                <div class="detail-row">
                    <span class="detail-label">Confidence</span>
                    <span class="detail-value">${confidencePct}%</span>
                </div>
                <div class="detail-row">
                    <span class="detail-label">Provider</span>
                    <span class="detail-value"><span class="provider-tag">${escapeHtml(r.provider || 'unknown')}</span></span>
                </div>
                <div class="detail-row">
                    <span class="detail-label">Date</span>
                    <span class="detail-value">${formatDate(r.created_at)}</span>
                </div>
                <button class="scan-again-btn" style="margin-top:16px;background:var(--color-danger);border-color:var(--color-danger);"
                        data-delete-id="${r.id}" aria-label="Delete this record">
                    Delete Record
                </button>
            `;

            // Delete button
            const deleteBtn = modalBody.querySelector('[data-delete-id]');
            if (deleteBtn) {
                deleteBtn.addEventListener('click', async () => {
                    await deleteRecord(r.id);
                    modal.classList.remove('visible');
                    modal.setAttribute('aria-hidden', 'true');
                });
            }

        } catch (err) {
            modalBody.innerHTML = `<p style="color:var(--color-danger);text-align:center;">${escapeHtml(err.message)}</p>`;
        }
    }

    async function deleteRecord(id) {
        try {
            const resp = await fetch(`api/history.php?action=delete`, {
                method: 'DELETE',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify({ id }),
            });
            const json = await resp.json();
            if (json.ok) {
                // Close modal and reload
                const modal = document.querySelector('#detail-modal');
                if (modal) {
                    modal.classList.remove('visible');
                    modal.setAttribute('aria-hidden', 'true');
                }
                loadHistory();
            }
        } catch (err) {
            console.error('Delete failed:', err);
        }
    }

    async function confirmClear() {
        if (!confirm('Are you sure you want to clear all scan history? This cannot be undone.')) {
            return;
        }

        try {
            const resp = await fetch('api/history.php?action=clear', { method: 'DELETE' });
            const json = await resp.json();
            if (json.ok) {
                loadHistory();
            }
        } catch (err) {
            alert('Failed to clear history: ' + err.message);
        }
    }

    // ── Pagination Builder ────────────────────────────────────

    function buildPagination(page, totalPages) {
        if (totalPages <= 1) return '';

        let html = '';

        // Previous
        html += `<button ${page <= 1 ? 'disabled' : `data-page="${page - 1}"`} aria-label="Previous page">‹</button>`;

        // Page numbers
        const start = Math.max(1, page - 2);
        const end   = Math.min(totalPages, page + 2);

        if (start > 1) {
            html += `<button data-page="1" aria-label="Go to page 1">1</button>`;
            if (start > 2) html += `<span class="page-info">…</span>`;
        }

        for (let i = start; i <= end; i++) {
            html += `<button ${i === page ? 'disabled aria-current="page"' : `data-page="${i}"`} aria-label="Go to page ${i}">${i}</button>`;
        }

        if (end < totalPages) {
            if (end < totalPages - 1) html += `<span class="page-info">…</span>`;
            html += `<button data-page="${totalPages}" aria-label="Go to page ${totalPages}">${totalPages}</button>`;
        }

        // Next
        html += `<button ${page >= totalPages ? 'disabled' : `data-page="${page + 1}"`} aria-label="Next page">›</button>`;

        html += `<span class="page-info">${page} / ${totalPages}</span>`;

        return html;
    }

    // ── Helpers ───────────────────────────────────────────────

    function formatDate(dateStr) {
        if (!dateStr) return '—';
        try {
            const d = new Date(dateStr);
            return d.toLocaleDateString(undefined, {
                year: 'numeric', month: 'short', day: 'numeric',
                hour: '2-digit', minute: '2-digit',
            });
        } catch {
            return dateStr;
        }
    }

    function escapeHtml(str) {
        if (!str) return '';
        const div = document.createElement('div');
        div.appendChild(document.createTextNode(str));
        return div.innerHTML;
    }

    return { init };
})();
