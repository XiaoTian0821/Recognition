<?php
declare(strict_types=1);

// 开启错误日志记录
ini_set('display_errors', '0');
error_reporting(E_ALL);

header('Content-Type: application/json; charset=utf-8');

try {
    require_once __DIR__ . '/../includes/AppConfig.php';
    $config = AppConfig::load();
} catch (\Throwable $e) {
    http_response_code(500);
    echo json_encode(['ok' => false, 'error' => 'AppConfig Load Error: ' . $e->getMessage()]);
    exit;
}

$action = $_GET['action'] ?? '';

// ==========================================
// 1. 处理测试连接 GET 请求 (?action=test)
// ==========================================
if ($_SERVER['REQUEST_METHOD'] === 'GET' && $action === 'test') {
    $geminiKey = $config->get('gemini_api_key', '');
    $agnesKey = $config->get('agnes_api_key', '');

    $geminiOk = false;
    $agnesOk = false;

    // 测试 Gemini API 连通性
    if (!empty($geminiKey)) {
        $ch = curl_init("https://generativelanguage.googleapis.com/v1beta/models?key=" . urlencode((string)$geminiKey));
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => 5,
            CURLOPT_SSL_VERIFYPEER => false,
            CURLOPT_SSL_VERIFYHOST => false
        ]);
        $res = curl_exec($ch);
        $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);
        if ($code === 200) {
            $geminiOk = true;
        }
    }

    if (!empty($agnesKey)) {
        $agnesOk = true;
    }

    echo json_encode([
        'ok' => true,
        'gemini' => $geminiOk,
        'agnes' => $agnesOk
    ]);
    exit;
}

// ==========================================
// 2. 处理保存配置 POST 请求
// ==========================================
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    try {
        $rawInput = file_get_contents('php://input');
        $data = json_decode($rawInput, true);

        if (!is_array($data)) {
            $data = $_POST;
        }

        // 读取当前已有配置
        $currentValues = $config->currentValues();

        // 密钥处理：若提交为空则保持原有 Key
        $geminiApiKey = !empty($data['gemini_api_key']) ? trim((string)$data['gemini_api_key']) : ($currentValues['gemini_api_key'] ?? '');
        $agnesApiKey  = !empty($data['agnes_api_key'])  ? trim((string)$data['agnes_api_key'])  : ($currentValues['agnes_api_key'] ?? '');

        // 构造新配置数据
        $newSettings = $currentValues;
        $newSettings['provider_mode']   = strtolower((string)($data['provider_mode'] ?? 'auto'));
        $newSettings['gemini_api_key']  = $geminiApiKey;
        $newSettings['gemini_model']    = trim((string)($data['gemini_model'] ?? 'gemini-3.8-flash'));
        $newSettings['agnes_api_key']   = $agnesApiKey;
        $newSettings['agnes_model']     = trim((string)($data['agnes_model'] ?? ''));
        $newSettings['request_timeout'] = max(10, min(120, (int)($data['request_timeout'] ?? 30)));

        // 写入文件
        AppConfig::write($newSettings);

        echo json_encode(['ok' => true, 'message' => 'Settings updated successfully.']);
    } catch (\Throwable $e) {
        http_response_code(500);
        echo json_encode([
            'ok' => false, 
            'error' => 'Failed to save config: ' . $e->getMessage()
        ]);
    }
    exit;
}

// 非法请求
http_response_code(400);
echo json_encode(['ok' => false, 'error' => 'Invalid Request']);