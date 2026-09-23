/* ═══════════════════════════════════════════════════════════════
   Settings Module
   Handles settings page: config save, provider tests, QR code.
   ═══════════════════════════════════════════════════════════════ */

'use strict';

const Settings = (() => {
    // Form fields
    const fields = {
        primaryProvider: null,
        geminiApiKey:    null,
        geminiModel:     null,
        geminiFallback:  null,
        geminiTimeout:   null,
        agnesApiKey:     null,
        agnesModel:      null,
        agnesFallback:   null,
        agnesTimeout:    null,
    };

    // ── Init ──────────────────────────────────────────────────

    function init(elementSelectors) {
        Object.keys(fields).forEach(key => {
            fields[key] = document.querySelector(elementSelectors[key] || `#${key}`);
        });

        // Load current config on page load
        loadConfig();

        // Wire up event handlers
        if (fields.primaryProvider) {
            fields.primaryProvider.addEventListener('change', onProviderChange);
        }

        // Save button
        const saveBtn = document.querySelector('#save-settings-btn');
        if (saveBtn) {
            saveBtn.addEventListener('click', saveSettings);
        }

        // Test buttons
        const testGemini = document.querySelector('#test-gemini-btn');
        const testAgnes  = document.querySelector('#test-agnes-btn');

        if (testGemini) testGemini.addEventListener('click', () => testProvider('gemini', testGemini));
        if (testAgnes)  testAgnes.addEventListener('click', () => testProvider('agnes', testAgnes));

        // Generate QR code
        generateQRCode();

        // Show/hide key fields based on provider selection
        onProviderChange();
    }

    // ── Load Config ───────────────────────────────────────────

    async function loadConfig() {
        try {
            const resp = await fetch('api/settings.php?action=get');
            if (!resp.ok) return;

            const json = await resp.json();
            if (!json.ok || !json.data) return;

            const cfg = json.data;

            if (fields.primaryProvider) {
                fields.primaryProvider.value = cfg.primary_provider || 'auto';
            }
            if (fields.geminiModel) {
                fields.geminiModel.value = cfg.gemini?.model || 'gemini-3.6-flash';
            }
            if (fields.geminiTimeout) {
                fields.geminiTimeout.value = cfg.gemini?.timeout_seconds || 30;
            }
            if (fields.geminiFallback) {
                fields.geminiFallback.value = (cfg.gemini?.fallback_models || []).join(', ');
            }
            if (fields.agnesModel) {
                fields.agnesModel.value = cfg.agnes?.model || 'agnes-vision-v1';
            }
            if (fields.agnesTimeout) {
                fields.agnesTimeout.value = cfg.agnes?.timeout_seconds || 30;
            }
            if (fields.agnesFallback) {
                fields.agnesFallback.value = (cfg.agnes?.fallback_models || []).join(', ');
            }

            // Update key status indicators
            updateKeyStatus('gemini-key-status', cfg.gemini?.api_key_set);
            updateKeyStatus('agnes-key-status', cfg.agnes?.api_key_set);

        } catch (err) {
            console.error('Failed to load settings:', err);
        }
    }

    // ── Save Settings ─────────────────────────────────────────

    async function saveSettings() {
        const saveBtn = document.querySelector('#save-settings-btn');
        const feedback = document.querySelector('#save-feedback');

        saveBtn.disabled = true;
        saveBtn.textContent = 'Saving…';

        const payload = {
            primary_provider:  fields.primaryProvider?.value || 'auto',
            gemini: {
                model:            fields.geminiModel?.value || 'gemini-3.6-flash',
                timeout_seconds:  parseInt(fields.geminiTimeout?.value || '30', 10),
                fallback_models:  parseCommaList(fields.geminiFallback?.value || ''),
            },
            agnes: {
                model:            fields.agnesModel?.value || 'agnes-vision-v1',
                timeout_seconds:  parseInt(fields.agnesTimeout?.value || '30', 10),
                fallback_models:  parseCommaList(fields.agnesFallback?.value || ''),
            },
        };

        // Handle API key saves
        const geminiKey = fields.geminiApiKey?.value?.trim() || null;
        const agnesKey  = fields.agnesApiKey?.value?.trim()  || null;
        if (geminiKey) payload.gemini_api_key = geminiKey;
        if (agnesKey)  payload.agnes_api_key  = agnesKey;

        try {
            const resp = await fetch('api/settings.php?action=save', {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify(payload),
            });

            const json = await resp.json();

            if (json.ok) {
                if (feedback) feedback.textContent = '✓ Settings saved successfully.';
                updateKeyStatus('gemini-key-status', json.data?.gemini_key_set);
                updateKeyStatus('agnes-key-status', json.data?.agnes_key_set);
            } else {
                throw new Error(json.error || 'Save failed.');
            }
        } catch (err) {
            if (feedback) feedback.textContent = `✗ ${err.message}`;
            feedback.style.color = 'var(--color-danger)';
            setTimeout(() => {
                feedback.style.color = '';
            }, 3000);
        } finally {
            saveBtn.disabled = false;
            saveBtn.textContent = 'Save Settings';
        }
    }

    // ── Test Provider ─────────────────────────────────────────

    async function testProvider(provider, btn) {
        const resultEl = document.querySelector(`#${provider}-test-result`);
        if (!resultEl) return;

        btn.disabled = true;
        btn.textContent = 'Testing…';
        resultEl.innerHTML = '<span style="color:var(--color-text-muted)">Testing connection…</span>';

        try {
            const resp = await fetch(`api/settings.php?action=test&provider=${provider}`, {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify({
                    api_key: fields[`${provider}ApiKey`]?.value?.trim() || '',
                }),
            });
            const json = await resp.json();

            if (json.ok && json.data?.ok) {
                resultEl.innerHTML = `
                    <span class="dot ok"></span>
                    <span style="color:var(--color-accent)">${escapeHtml(json.data.message || 'Connected.')}</span>
                `;
            } else {
                resultEl.innerHTML = `
                    <span class="dot fail"></span>
                    <span style="color:var(--color-danger)">${escapeHtml(json.data?.message || json.error || 'Connection failed.')}</span>
                `;
            }
        } catch (err) {
            resultEl.innerHTML = `
                <span class="dot fail"></span>
                <span style="color:var(--color-danger)">Network error.</span>
            `;
        } finally {
            btn.disabled = false;
            btn.textContent = 'Test Connection';
        }
    }

    // ── QR Code ───────────────────────────────────────────────

    function generateQRCode() {
        const qrImg = document.querySelector('#qr-code-img');
        if (!qrImg) return;

        const scannerUrl = new URL('index.php', window.location.href);
        scannerUrl.hash = '';
        scannerUrl.search = '';

        // Encode the scanner page, not the settings page.
        const currentUrl = scannerUrl.href;
        // Use a public QR code API (no key needed)
        qrImg.src = `https://api.qrserver.com/v1/create-qr-code/?size=160x160&data=${encodeURIComponent(currentUrl)}&bgcolor=ffffff&color=0a0a0f`;
        qrImg.alt = `QR code linking to ${currentUrl}`;
    }

    // ── Helpers ───────────────────────────────────────────────

    function onProviderChange() {
        // Could add UI logic here for provider-specific fields
    }

    function updateKeyStatus(elementId, isSet) {
        const el = document.querySelector(`#${elementId}`);
        if (!el) return;

        el.className = `key-status ${isSet ? 'saved' : 'missing'}`;
        el.textContent = isSet ? '● Key saved' : '○ Not configured';
    }

    function parseCommaList(str) {
        if (!str || !str.trim()) return [];
        return str.split(',').map(s => s.trim()).filter(Boolean);
    }

    function escapeHtml(str) {
        const div = document.createElement('div');
        div.appendChild(document.createTextNode(str));
        return div.innerHTML;
    }

    return { init };
})();
