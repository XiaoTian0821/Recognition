<?php
require_once __DIR__ . '/includes/AppConfig.php';

// 加载配置对象
$config = AppConfig::load();

// 获取配置值的辅助函数，兼容对象读取与默认值
$providerMode =$config->providerMode(); // 'auto', 'gemini', 'agnes'
$geminiModel =$config->get('gemini_model', 'gemini-3.8-flash');
$geminiApiKey =$config->get('gemini_api_key', '');
$agnesModel =$config->get('agnes_model', '');
$agnesApiKey =$config->get('agnes_api_key', '');
$timeout =$config->timeout();
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Settings - AI+AR Object Recognition</title>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css">
    <link rel="stylesheet" href="assets/css/settings.css">
    <style>
        body { 
            overflow-y: auto; 
            padding: 20px; 
            background-color: var(--bg-dark, #0a0e17);
            color: var(--text-main, #f0f4f8);
        }
        .settings-card { 
            background: var(--panel-bg, rgba(18, 26, 43, 0.75)); 
            border: 1px solid var(--border-line, rgba(0, 242, 254, 0.25)); 
            border-radius: 16px; 
            padding: 24px; 
            margin-bottom: 20px; 
            backdrop-filter: blur(10px);
        }
    </style>
</head>
<body>
    <div class="container" style="max-width: 600px;">
        <div class="d-flex justify-content-between align-items-center mb-4">
            <h2 class="text-info"><i class="fa-solid fa-gear"></i> Settings</h2>
            <a href="index.php" class="btn btn-outline-info rounded-circle"><i class="fa-solid fa-xmark"></i></a>
        </div>

        <form id="settings-form" class="settings-card">
            <!-- 1. AI Provider Mode -->
            <div class="mb-3">
                <label class="form-label text-light">AI Provider Mode</label>
                <select name="provider_mode" class="form-select bg-dark text-light border-secondary">
                    <option value="auto" <?= $providerMode === 'auto' ? 'selected' : '' ?>>Auto (Gemini -> Fallback Agnes)</option>
                    <option value="gemini" <?= $providerMode === 'gemini' ? 'selected' : '' ?>>Gemini Only</option>
                    <option value="agnes" <?= $providerMode === 'agnes' ? 'selected' : '' ?>>Agnes Only</option>
                </select>
            </div>

            <!-- 2. Gemini Configuration -->
            <div class="mb-3">
                <label class="form-label text-light">Gemini API Key</label>
                <input type="password" name="gemini_api_key" class="form-control bg-dark text-light border-secondary" placeholder="Leave empty to keep existing key">
                <small class="text-muted d-block mt-1">
                    <?= !empty($geminiApiKey) ? '✅ Status: Key Saved' : '❌ Status: Not configured' ?>
                </small>
            </div>

            <div class="mb-3">
                <label class="form-label text-light">Gemini Model</label>
                <input type="text" name="gemini_model" class="form-control bg-dark text-light border-secondary" value="<?= htmlspecialchars((string)$geminiModel) ?>">
            </div>

            <!-- 3. Agnes Configuration -->
            <div class="mb-3">
                <label class="form-label text-light">Agnes API Key</label>
                <input type="password" name="agnes_api_key" class="form-control bg-dark text-light border-secondary" placeholder="Leave empty to keep existing key">
                <small class="text-muted d-block mt-1">
                    <?= !empty($agnesApiKey) ? '✅ Status: Key Saved' : '❌ Status: Not configured' ?>
                </small>
            </div>

            <div class="mb-3">
                <label class="form-label text-light">Agnes Model</label>
                <input type="text" name="agnes_model" class="form-control bg-dark text-light border-secondary" value="<?= htmlspecialchars((string)$agnesModel) ?>" placeholder="Optional (e.g. agnes-v1-vision)">
            </div>

            <!-- 4. Timeout -->
            <div class="mb-4">
                <label class="form-label text-light">Request Timeout (seconds)</label>
                <input type="number" name="request_timeout" class="form-control bg-dark text-light border-secondary" value="<?= $timeout ?>" min="10" max="120">
            </div>

            <!-- Buttons -->
            <div class="d-flex gap-2">
                <button type="submit" class="btn btn-info flex-grow-1">Save Configuration</button>
                <button type="button" id="test-btn" class="btn btn-outline-light">Test Connection</button>
            </div>

        </form>

        <section class="settings-section qrcode-container" aria-labelledby="qr-title">
            <h2 id="qr-title">手机扫码访问</h2>
            <p class="qrcode-desc">使用手机摄像头扫描下方二维码，快速打开应用</p>
            <div id="qrcode-box" class="qrcode-box" aria-label="打开应用二维码"></div>
            <a href="https://liaiting.kolejsynergy.com/" target="_blank" rel="noopener noreferrer" class="qrcode-link">
                liaiting.kolejsynergy.com
            </a>
        </section>
    </div>

    <script src="https://cdnjs.cloudflare.com/ajax/libs/qrcodejs/1.0.0/qrcode.min.js"></script>
    <script>
        const qrUrl = "https://liaiting.kolejsynergy.com/";

        function renderQRCode() {
            const qrContainer = document.getElementById("qrcode-box");
            if (!qrContainer) return;

            if (typeof QRCode === "function") {
                new QRCode(qrContainer, {
                    text: qrUrl,
                    width: 192,
                    height: 192,
                    colorDark: "#000000",
                    colorLight: "#ffffff",
                    correctLevel: QRCode.CorrectLevel.H
                });
                return;
            }

            const fallbackImage = document.createElement("img");
            fallbackImage.src = `https://api.qrserver.com/v1/create-qr-code/?size=192x192&data=${encodeURIComponent(qrUrl)}`;
            fallbackImage.alt = "Scan to open the application";
            fallbackImage.width = 192;
            fallbackImage.height = 192;
            qrContainer.appendChild(fallbackImage);
        }

        document.getElementById('settings-form').addEventListener('submit', async (e) => {
            e.preventDefault();
            const data = Object.fromEntries(new FormData(e.target));
            
            try {
                const res = await fetch('api/settings.php', {
                    method: 'POST',
                    headers: {'Content-Type': 'application/json'},
                    body: JSON.stringify(data)
                });
                const result = await res.json();
                
                if (result.ok) {
                    alert("Settings saved successfully!");
                    window.location.reload();
                } else {
                    alert("Error: " + (result.error || "Failed to save settings."));
                }
            } catch (err) {
                alert("Network or server error: " + err.message);
            }
        });

        document.getElementById('test-btn').addEventListener('click', async () => {
            try {
                const res = await fetch('api/settings.php?action=test');
                const result = await res.json();
                alert(`Gemini Connection: ${result.gemini ? 'Connected ✓' : 'Failed ✗'}\nAgnes Connection: ${result.agnes ? 'Connected ✓' : 'Failed ✗'}`);
            } catch (err) {
                alert("Test failed: " + err.message);
            }
        });

        document.addEventListener("DOMContentLoaded", renderQRCode);

    </script>
</body>
</html>