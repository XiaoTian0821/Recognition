<?php
/**
 * index.php — Main scanner page
 * Provides the camera interface and AR overlay.
 */

require_once __DIR__ . '/includes/AppConfig.php';
use App\AppConfig;
AppConfig::boot();
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, viewport-fit=cover">
    <meta name="description" content="AI-powered augmented reality object scanner">
    <meta name="theme-color" content="#0a0a0f">
    <title>AI + AR Object Scanner</title>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="assets/css/app.css">
</head>
<body>
    <div class="app-container" role="application" aria-label="AI Object Scanner">

        <!-- ── Header ──────────────────────────────────────── -->
        <header class="app-header">
            <h1 class="app-title">
                <span>AI</span> + AR Scanner
            </h1>
            <nav aria-label="Page navigation">
                <a href="settings.php" class="header-btn" aria-label="Open settings" title="Settings">
                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
                        <circle cx="12" cy="12" r="3"></circle>
                        <path d="M19.4 15a1.65 1.65 0 0 0 .33 1.82l.06.06a2 2 0 0 1-2.83 2.83l-.06-.06a1.65 1.65 0 0 0-1.82-.33 1.65 1.65 0 0 0-1 1.51V21a2 2 0 0 1-4 0v-.09A1.65 1.65 0 0 0 9 19.4a1.65 1.65 0 0 0-1.82.33l-.06.06a2 2 0 0 1-2.83-2.83l.06-.06A1.65 1.65 0 0 0 4.68 15a1.65 1.65 0 0 0-1.51-1H3a2 2 0 0 1 0-4h.09A1.65 1.65 0 0 0 4.6 9a1.65 1.65 0 0 0-.33-1.82l-.06-.06a2 2 0 0 1 2.83-2.83l.06.06A1.65 1.65 0 0 0 9 4.68a1.65 1.65 0 0 0 1-1.51V3a2 2 0 0 1 4 0v.09a1.65 1.65 0 0 1-1 1.51 1.65 1.65 0 0 1 1.82-.33l.06-.06a2 2 0 0 1 2.83 2.83l-.06.06A1.65 1.65 0 0 1 19.4 9a1.65 1.65 0 0 1 1.51 1H21a2 2 0 0 1 0 4h-.09a1.65 1.65 0 0 1-1.51 1z"></path>
                    </svg>
                </a>
                <a href="history.php" class="header-btn" aria-label="View scan history" title="History" style="margin-left:6px;">
                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
                        <circle cx="12" cy="12" r="10"></circle>
                        <polyline points="12 6 12 12 16 14"></polyline>
                    </svg>
                </a>
            </nav>
        </header>

        <!-- ── Camera Viewport ─────────────────────────────── -->
        <main class="camera-viewport" id="camera-viewport">
            <video
                id="camera-video"
                autoplay
                playsinline
                muted
                preload="none"
                aria-label="Live camera feed"
                role="img"
            ></video>

            <!-- Scanning frame -->
            <div class="scan-frame" id="scan-frame" aria-hidden="true">
                <div class="scan-line"></div>
            </div>

            <!-- AR overlay (bounding box + result card) -->
            <div class="ar-overlay" id="ar-overlay" role="region" aria-label="Recognition result overlay"></div>

            <!-- Status message -->
            <div class="status-bar" id="status-bar" aria-live="polite">
                Point camera at an object
            </div>
        </main>

        <!-- ── Controls ────────────────────────────────────── -->
        <div class="controls-area">
            <div class="scan-btn-wrap">
                <button
                    id="scan-btn"
                    class="scan-btn"
                    aria-label="Scan object"
                    title="Take a photo and identify the object"
                >
                    <span style="font-size:0.65rem;letter-spacing:0.08em;">SCAN</span>
                </button>
            </div>

            <!-- Progress indicator -->
            <div class="progress-section" id="progress-section" aria-live="polite">
                <div class="progress-label" id="progress-label">Identifying object…</div>
                <div class="progress-bar-track">
                    <div class="progress-bar-fill" id="progress-fill"></div>
                </div>
                <div class="progress-steps" id="progress-steps">
                    <span class="step" data-step="0">○ Capture image</span>
                    <span class="step" data-step="1">○ AI identification</span>
                    <span class="step" data-step="2">○ Display result</span>
                </div>
            </div>

            <p class="hint-text" id="hint-text">Point the camera at any object to identify it</p>
        </div>

    </div><!-- /app-container -->

    <!-- Hidden canvas for image capture (offscreen) -->
    <canvas id="capture-canvas" aria-hidden="true" style="display:none;"></canvas>

    <script src="assets/js/camera.js"></script>
    <script src="assets/js/ar-overlay.js"></script>
    <script src="assets/js/scanner.js"></script>
    <script>
        (function() {
            'use strict';

            const video        = document.getElementById('camera-video');
            const canvas       = document.getElementById('capture-canvas');
            const scanBtn      = document.getElementById('scan-btn');
            const statusEl     = document.getElementById('status-bar');
            const progressEl   = document.getElementById('progress-section');
            const progressFill = document.getElementById('progress-fill');
            const progressSteps= document.getElementById('progress-steps');
            const overlay      = document.getElementById('ar-overlay');
            const scanFrame    = document.getElementById('scan-frame');

            Scanner.init({
                video, canvas, scanBtn, statusEl,
                progress: progressEl,
                progressFill,
                progressSteps,
                overlay,
                scanFrame,
            });
        })();
    </script>
</body>
</html>
