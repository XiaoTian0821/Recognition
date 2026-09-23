/* ═══════════════════════════════════════════════════════════════
   Scanner Module
   Orchestrates camera capture, API calls, and UI state management.
   ═══════════════════════════════════════════════════════════════ */

'use strict';

const Scanner = (() => {
    // DOM elements (set during init)
    let videoEl        = null;
    let canvasEl       = null;
    let scanBtn        = null;
    let statusEl       = null;
    let progressEl     = null;
    let progressFill   = null;
    let progressSteps  = null;
    let overlayEl      = null;
    let scanFrameEl    = null;

    // State
    let isProcessing = false;

    // ── Init ─────────────────────────────────────────────────

    function init(elements) {
        videoEl        = elements.video;
        canvasEl       = elements.canvas;
        scanBtn        = elements.scanBtn;
        statusEl       = elements.status;
        progressEl     = elements.progress;
        progressFill   = elements.progressFill;
        progressSteps  = elements.progressSteps;
        overlayEl      = elements.overlay;
        scanFrameEl    = elements.scanFrame;
            console.info('[Scanner] initialized');

        // Initialize AR overlay
        AROverlay.init(overlayEl, videoEl, scanFrameEl);

        // Initialize camera
        initCamera();

        // Scan button handler — if overlay is visible, dismiss it first then scan
        scanBtn.addEventListener('click', () => {
            console.info('[Scanner] scan button clicked');
            if (overlayEl && overlayEl.classList.contains('visible')) {
                AROverlay.reset();
                setScanButtonState('ready');
                updateStatus('Point camera at an object', '');
            }
            handleScan();
        });

        // Handle visibility change
        document.addEventListener('visibilitychange', handleVisibilityChange);
    }

    // ── Camera ────────────────────────────────────────────────

    async function initCamera() {
        updateStatus('Initializing camera…', 'processing');

        const result = await Camera.init(videoEl, canvasEl);

        if (!result.ok) {
            updateStatus(result.error, '');
            showCameraError(result.error);
            return;
        }

        updateStatus('Point camera at an object', '');
    }

    // ── Scan ──────────────────────────────────────────────────

    async function handleScan() {
        if (isProcessing) {
            console.info('[Scanner] scan ignored while processing');
            return;
        }

        isProcessing = true;
        setScanButtonState('scanning');
        updateStatus('Capturing image…', 'processing');
        hideProgress();

        try {
            // Step 1: Capture
            setProgressStep(0, 'done');
            updateProgress(25);

            const dataUrl = Camera.capture();
            if (!dataUrl) {
                throw new Error('Failed to capture image from camera.');
            }
            console.info('[Scanner] image captured');

            // Extract Base64 (strip data:image/...;base64, prefix)
            const base64Data = dataUrl.replace(/^data:image\/[^;]+;base64,/, '');

            // Step 2: Send to API
            setProgressStep(1, 'active');
            updateStatus('AI identifying object…', 'processing');
            updateProgress(50);

            const apiResult = await callRecognitionApi(base64Data);
            console.info('[Scanner] API response', apiResult);

            if (!apiResult.ok) {
                throw new Error(apiResult.error || 'Recognition failed.');
            }

            // Step 3: Display result
            setProgressStep(2, 'done');
            updateProgress(100);
            updateStatus('Object identified', '');

            // Show AR overlay
            AROverlay.show(apiResult.data);

        } catch (err) {
            console.error('[Scanner] scan failed', err);
            updateStatus(err.message || 'An error occurred.', '');
            setProgressStep(1, 'fail');
        } finally {
            setScanButtonState('done');
            isProcessing = false;
            setTimeout(hideProgress, 2500);
        }
    }

    // ── API Call ──────────────────────────────────────────────

    async function callRecognitionApi(base64Data) {
        const controller = new AbortController();
        const timeout = setTimeout(() => controller.abort(), 60000);

        try {
            console.info('[Scanner] sending recognition request');
            const response = await fetch('api/recognize.php', {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify({ image: base64Data }),
                signal: controller.signal,
            });

            clearTimeout(timeout);

            const json = await response.json().catch(() => null);

            if (!response.ok) {
                return {
                    ok: false,
                    error: json?.error || `Server error: ${response.status}`,
                };
            }

            return json || { ok: false, error: 'Invalid response from recognition server.' };
        } catch (err) {
            clearTimeout(timeout);

            if (err.name === 'AbortError') {
                return { ok: false, error: 'Request timed out. Please try again.' };
            }

            return { ok: false, error: 'Network error. Check your connection and try again.' };
        }
    }

    // ── UI Helpers ────────────────────────────────────────────

    function setScanButtonState(state) {
        if (!scanBtn) return;

        switch (state) {
            case 'ready':
                scanBtn.disabled = false;
                scanBtn.innerHTML = '<span style="font-size:0.65rem;letter-spacing:0.08em;">SCAN</span>';
                break;
            case 'scanning':
                scanBtn.disabled = true;
                scanBtn.innerHTML = '<span style="font-size:0.7rem;">⏳</span>';
                break;
            case 'done':
                scanBtn.disabled = false;
                scanBtn.innerHTML = '<span style="font-size:0.65rem;letter-spacing:0.08em;">↺ SCAN AGAIN</span>';
                break;
        }
    }

    function updateStatus(text, className) {
        if (!statusEl) return;
        statusEl.textContent = text;
        statusEl.className = 'status-bar' + (className ? ` ${className}` : '');
        statusEl.setAttribute('aria-live', 'polite');
    }

    function hideProgress() {
        if (progressEl) progressEl.classList.remove('visible');
        if (progressFill) progressFill.style.width = '0%';
        if (progressSteps) {
            progressSteps.querySelectorAll('.step').forEach(s => {
                s.classList.remove('done', 'active', 'fail');
                s.textContent = s.textContent.replace(/^[✓✗●]\s*/, '○ ');
            });
        }
    }

    function updateProgress(percent) {
        if (progressFill) progressFill.style.width = `${percent}%`;
    }

    function setProgressStep(index, state) {
        if (!progressSteps) return;
        const steps = progressSteps.querySelectorAll('.step');
        if (steps[index]) {
            steps[index].className = 'step ' + state;
            const labels = ['Capture image', 'AI identification', 'Display result'];
            const icon = state === 'done' ? '✓' : state === 'fail' ? '✗' : '●';
            steps[index].textContent = `${icon} ${labels[index] || ''}`;
        }
    }

    function showCameraError(message) {
        if (!statusEl) return;
        statusEl.innerHTML = `
            <div style="color:var(--color-danger);font-weight:600;margin-bottom:4px;">
                ⚠ Camera Error
            </div>
            <div style="font-size:0.8rem;">${escapeHtml(message)}</div>
        `;
    }

    function handleVisibilityChange() {
        // Camera should resume automatically when page becomes visible
    }

    // ── Reset ─────────────────────────────────────────────────

    function reset() {
        isProcessing = false;
        setScanButtonState('ready');
        updateStatus('Point camera at an object', '');
    }

    // ── Helpers ───────────────────────────────────────────────

    function escapeHtml(str) {
        const div = document.createElement('div');
        div.appendChild(document.createTextNode(str));
        return div.innerHTML;
    }

    return { init, reset };
    function reset() {
        isProcessing = false;
        setScanButtonState('ready');
        updateStatus('Point camera at an object', '');
        hideProgress();
    }

    return { init, reset };
})();
