<?php
/**
 * settings.php — Configuration and provider settings page.
 */

require_once __DIR__ . '/includes/AppConfig.php';
use App\AppConfig;
AppConfig::boot();
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Settings — AI + AR Scanner</title>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="assets/css/app.css">
</head>
<body>
    <div class="settings-page">

        <!-- ── Header ──────────────────────────────────────── -->
        <div class="settings-header">
            <a href="index.php" class="back-btn" aria-label="Back to scanner">
                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" width="18" height="18" aria-hidden="true">
                    <polyline points="15 18 9 12 15 6"></polyline>
                </svg>
            </a>
            <h1>Settings</h1>
        </div>

        <!-- ── AI Provider Settings ────────────────────────── -->
        <div class="settings-section">
            <h2>AI Provider</h2>

            <div class="form-group">
                <label for="primaryProvider">Primary Provider</label>
                <select id="primaryProvider" name="primary_provider">
                    <option value="auto">Auto (try all)</option>
                    <option value="gemini">Gemini only</option>
                    <option value="agnes">Agnes only</option>
                </select>
                <p class="field-hint">Auto will try Gemini first, then Agnes if Gemini fails.</p>
            </div>
        </div>

        <!-- ── Gemini Configuration ────────────────────────── -->
        <div class="settings-section">
            <h2>Google Gemini</h2>

            <div class="form-group">
                <label for="geminiApiKey">API Key</label>
                <div class="password-wrap">
                    <input type="password" id="geminiApiKey" name="gemini_api_key"
                           placeholder="Enter your Gemini API key" autocomplete="off">
                    <button type="button" onclick="togglePassword('geminiApiKey', this)" aria-label="Toggle API key visibility">Show</button>
                </div>
                <span id="gemini-key-status" class="key-status missing">○ Not configured</span>
                <p class="field-hint">Get a free key at <a href="https://aistudio.google.com/app/apikey" target="_blank" rel="noopener">aistudio.google.com</a></p>
            </div>

            <div class="form-group">
                <label for="geminiModel">Model</label>
                <input type="text" id="geminiModel" name="gemini_model"
                       placeholder="gemini-2.0-flash" value="gemini-2.0-flash">
            </div>

            <div class="form-group">
                <label for="geminiFallback">Fallback Models</label>
                <textarea id="geminiFallback" name="gemini_fallback"
                          placeholder="gemini-1.5-flash, gemini-1.5-pro"
                          rows="2">gemini-1.5-flash, gemini-1.5-pro</textarea>
                <p class="field-hint">Comma-separated list of models to try if the primary fails.</p>
            </div>

            <div class="form-group">
                <label for="geminiTimeout">Timeout (seconds)</label>
                <input type="number" id="geminiTimeout" name="gemini_timeout"
                       min="5" max="120" value="30">
            </div>

            <div class="form-group">
                <button type="button" id="test-gemini-btn" class="test-btn">
                    Test Connection
                </button>
                <div id="gemini-test-result" class="test-result" aria-live="polite"></div>
            </div>
        </div>

        <!-- ── Agnes Configuration ─────────────────────────── -->
        <div class="settings-section">
            <h2>Agnes Vision</h2>

            <div class="form-group">
                <label for="agnesApiKey">API Key</label>
                <div class="password-wrap">
                    <input type="password" id="agnesApiKey" name="agnes_api_key"
                           placeholder="Enter your Agnes API key" autocomplete="off">
                    <button type="button" onclick="togglePassword('agnesApiKey', this)" aria-label="Toggle API key visibility">Show</button>
                </div>
                <span id="agnes-key-status" class="key-status missing">○ Not configured</span>
            </div>

            <div class="form-group">
                <label for="agnesModel">Model</label>
                <input type="text" id="agnesModel" name="agnes_model"
                       placeholder="agnes-vision-v1" value="agnes-vision-v1">
            </div>

            <div class="form-group">
                <label for="agnesFallback">Fallback Models</label>
                <textarea id="agnesFallback" name="agnes_fallback"
                          placeholder="agnes-vision-v1-lite"
                          rows="2">agnes-vision-v1-lite</textarea>
                <p class="field-hint">Comma-separated list of models to try if the primary fails.</p>
            </div>

            <div class="form-group">
                <label for="agnesTimeout">Timeout (seconds)</label>
                <input type="number" id="agnesTimeout" name="agnes_timeout"
                       min="5" max="120" value="30">
            </div>

            <div class="form-group">
                <button type="button" id="test-agnes-btn" class="test-btn">
                    Test Connection
                </button>
                <div id="agnes-test-result" class="test-result" aria-live="polite"></div>
            </div>
        </div>

        <!-- ── Save Button ─────────────────────────────────── -->
        <div class="settings-section">
            <button type="button" id="save-settings-btn" class="save-btn">
                Save Settings
            </button>
            <div id="save-feedback" class="save-feedback" aria-live="polite"></div>
        </div>

        <!-- ── QR Code ─────────────────────────────────────── -->
        <div class="settings-section qr-section">
            <h2>Open on Phone</h2>
            <div class="qr-code-wrap">
                <img id="qr-code-img" src="" alt="QR code to open scanner on phone" loading="lazy">
            </div>
            <p>Scan this QR code with your phone camera to open the scanner.</p>
        </div>

    </div><!-- /settings-page -->

    <script>
        // API key visibility toggle
        function togglePassword(inputId, btn) {
            const input = document.getElementById(inputId);
            if (!input) return;
            if (input.type === 'password') {
                input.type = 'text';
                btn.textContent = 'Hide';
            } else {
                input.type = 'password';
                btn.textContent = 'Show';
            }
        }

        // Wire up settings module
        document.addEventListener('DOMContentLoaded', () => {
            Settings.init({
                primaryProvider: '#primaryProvider',
                geminiApiKey:    '#geminiApiKey',
                geminiModel:     '#geminiModel',
                geminiFallback:  '#geminiFallback',
                geminiTimeout:   '#geminiTimeout',
                agnesApiKey:     '#agnesApiKey',
                agnesModel:      '#agnesModel',
                agnesFallback:   '#agnesFallback',
                agnesTimeout:    '#agnesTimeout',
            });
        });
    </script>
    <script src="assets/js/settings.js"></script>
</body>
</html>
