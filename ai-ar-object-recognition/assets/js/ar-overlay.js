/* ═══════════════════════════════════════════════════════════════
   AR Overlay Module
   Renders bounding box and result card on top of the camera feed.
   ═══════════════════════════════════════════════════════════════ */

'use strict';

const AROverlay = (() => {
    let overlayEl    = null;
    let boxEl        = null;
    let cardEl       = null;
    let videoEl      = null;
    let scanFrameEl  = null;

    /**
     * Initialize overlay references.
     */
    function init(overlayContainer, videoElement, scanFrame) {
        overlayEl    = overlayContainer;
        videoEl      = videoElement;
        scanFrameEl  = scanFrame;
    }

    /**
     * Show the AR overlay with a detected object.
     * boundingBox: { ymin, xmin, ymax, xmax } — values 0-1000
     */
    function show(result) {
        if (!overlayEl) return;

        // Clear previous
        removeBox();

        // Create bounding box element
        const box = document.createElement('div');
        box.className = 'detection-box';
        box.setAttribute('role', 'img');
        box.setAttribute('aria-label',
            `Detected: ${result.productName || result.objectLabel}, confidence ${Math.round((result.confidence || 0) * 100)}%`);

        // Position based on bounding box coordinates (0-1000 scale)
        const top    = (result.boundingBox?.ymin ?? 100)  / 1000 * 100;
        const left   = (result.boundingBox?.xmin ?? 100)  / 1000 * 100;
        const height = ((result.boundingBox?.ymax ?? 900) - (result.boundingBox?.ymin ?? 100)) / 1000 * 100;
        const width  = ((result.boundingBox?.xmax ?? 900) - (result.boundingBox?.xmin ?? 100)) / 1000 * 100;

        box.style.top     = `${top}%`;
        box.style.left    = `${left}%`;
        box.style.height  = `${height}%`;
        box.style.width   = `${width}%`;

        overlayEl.appendChild(box);
        boxEl = box;

        // Build result card
        buildCard(result);

        // Show overlay
        overlayEl.classList.add('visible');
    }

    /**
     * Build the product information card.
     */
    function buildCard(result) {
        if (!overlayEl) return;

        // Remove old card
        const oldCard = overlayEl.querySelector('.result-card');
        if (oldCard) oldCard.remove();

        const card = document.createElement('div');
        card.className = 'result-card';
        card.setAttribute('role', 'region');
        card.setAttribute('aria-label', 'Recognition result');

        const confidencePct = Math.round((result.confidence ?? 0) * 100);

        card.innerHTML = `
            <div class="result-card-header" tabindex="0" role="button"
                 aria-expanded="false" aria-controls="result-details"
                 title="Tap to expand details">
                <div class="result-card-title">
                    <h2>${escapeHtml(result.productName || result.objectLabel || 'Object')}</h2>
                    <span class="label-tag">${escapeHtml(result.objectLabel || 'Object')}</span>
                </div>
                <div class="confidence-badge" style="--confidence: ${confidencePct}">
                    <div class="confidence-badge-inner">${confidencePct}%</div>
                </div>
            </div>
            <div class="result-card-details" id="result-details">
                ${result.manufacturer ? `
                <div class="detail-row">
                    <span class="detail-label">Manufacturer</span>
                    <span class="detail-value">${escapeHtml(result.manufacturer)}</span>
                </div>` : ''}
                ${result.specification ? `
                <div class="detail-row">
                    <span class="detail-label">Specification</span>
                    <span class="detail-value">${escapeHtml(result.specification)}</span>
                </div>` : ''}
                ${result.description ? `
                <div class="detail-row">
                    <span class="detail-label">Description</span>
                    <span class="detail-value">${escapeHtml(result.description)}</span>
                </div>` : ''}
                <div class="detail-row">
                    <span class="detail-label">Confidence</span>
                    <span class="detail-value">${confidencePct}%</span>
                </div>
                ${result.provider ? `
                <div class="detail-row">
                    <span class="detail-label">Provider</span>
                    <span class="detail-value"><span class="provider-tag">${escapeHtml(result.provider)}</span></span>
                </div>` : ''}
            </div>
            <div class="tap-hint" role="button" tabindex="0" aria-label="Expand or collapse details">
                Tap for details  ▾
            </div>
            <button class="scan-again-btn" aria-label="Scan another object">
                Scan Another Object
            </button>
        `;

        overlayEl.appendChild(card);
        cardEl = card;

        // Toggle expand on header tap
        const header = card.querySelector('.result-card-header');
        header.addEventListener('click', () => toggleExpand(card, header));
        header.addEventListener('keydown', (e) => {
            if (e.key === 'Enter' || e.key === ' ') {
                e.preventDefault();
                toggleExpand(card, header);
            }
        });

        // Toggle expand on hint tap
        const hint = card.querySelector('.tap-hint');
        hint.addEventListener('click', () => toggleExpand(card, header));
        hint.addEventListener('keydown', (e) => {
            if (e.key === 'Enter' || e.key === ' ') {
                e.preventDefault();
                toggleExpand(card, header);
            }
        });

        // Scan again button
        const scanAgainBtn = card.querySelector('.scan-again-btn');
        scanAgainBtn.addEventListener('click', () => {
            AROverlay.reset();
            if (typeof Scanner !== 'undefined' && typeof Scanner.reset === 'function') {
                Scanner.reset();
            }
        });
    }

    /**
     * Toggle card expand/collapse.
     */
    function toggleExpand(card, header) {
        const isExpanded = card.classList.toggle('expanded');
        header.setAttribute('aria-expanded', isExpanded);
        const hint = card.querySelector('.tap-hint');
        if (hint) {
            hint.textContent = isExpanded ? 'Tap to collapse  ▴' : 'Tap for details  ▾';
        }
    }

    /**
     * Remove the bounding box element.
     */
    function removeBox() {
        if (boxEl && boxEl.parentNode) {
            boxEl.remove();
        }
        boxEl = null;
    }

    /**
     * Hide the entire overlay and reset state.
     */
    function reset() {
        if (overlayEl) {
            overlayEl.classList.remove('visible');
        }
        removeBox();
        if (cardEl && cardEl.parentNode) {
            cardEl.remove();
        }
        cardEl = null;
    }

    /**
     * Helper: escape HTML special characters.
     */
    function escapeHtml(str) {
        const div = document.createElement('div');
        div.appendChild(document.createTextNode(str));
        return div.innerHTML;
    }

    return { init, show, reset };
})();
