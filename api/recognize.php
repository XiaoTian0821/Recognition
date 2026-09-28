<?php
declare(strict_types=1);

// 开启错误捕捉，防止 PHP 致命错误直接导致 500 空白响应
ini_set('display_errors', '0');
error_reporting(E_ALL);

header('Content-Type: application/json; charset=utf-8');

try {
    require_once __DIR__ . '/../includes/AppConfig.php';
    $config = AppConfig::load();
} catch (\Throwable $e) {
    http_response_code(500);
    echo json_encode(['ok' => false, 'error' => 'AppConfig 加载失败: ' . $e->getMessage()]);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['ok' => false, 'error' => '仅支持 POST 请求']);
    exit;
}

// 获取请求数据
$rawInput = file_get_contents('php://input');
$data = json_decode($rawInput, true) ?? $_POST;
$imageData = $data['image'] ?? '';

if (empty($imageData)) {
    http_response_code(400);
    echo json_encode(['ok' => false, 'error' => '未接收到有效图片']);
    exit;
}

$apiKey = $config->get('gemini_api_key', '');
if (empty($apiKey)) {
    http_response_code(400);
    echo json_encode(['ok' => false, 'error' => '请先在 Settings 页面配置 Gemini API Key']);
    exit;
}

// 提取 base64 图片
$mimeType = 'image/jpeg';
if (preg_match('/^data:(image\/\w+);base64,/', $imageData, $matches)) {
    $mimeType = $matches[1];
    $imageContent = substr($imageData, strpos($imageData, ',') + 1);
} else {
    $imageContent = $imageData;
}

// 识别提示词
$promptText = "Identify the main object in this photo. "
    . "Return ONLY a valid JSON object without markdown tags: "
    . '{"name": "Object Name", "description": "Brief description", "confidence": "0.95"}';

// Try the configured model list in order. Keep model selection centralized in AppConfig.
$modelsToTry = $config->geminiModels();
if ($modelsToTry === []) {
    $modelsToTry = ['gemini-3.8-flash'];
}

$lastError = '';
$recognizedResult = null;

foreach ($modelsToTry as $model) {
    $url = "https://generativelanguage.googleapis.com/v1beta/models/{$model}:generateContent?key=" . urlencode((string)$apiKey);

    $payload = [
        'contents' => [
            [
                'parts' => [
                    ['text' => $promptText],
                    [
                        'inline_data' => [
                            'mime_type' => $mimeType,
                            'data' => $imageContent
                        ]
                    ]
                ]
            ]
        ]
    ];

    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_POST => true,
        CURLOPT_HTTPHEADER => ['Content-Type: application/json'],
        CURLOPT_POSTFIELDS => json_encode($payload),
        CURLOPT_TIMEOUT => 30,
        CURLOPT_SSL_VERIFYPEER => false,
        CURLOPT_SSL_VERIFYHOST => false
    ]);

    $response = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $curlError = curl_error($ch);
    curl_close($ch);

    if ($response === false) {
        $lastError = "cURL 错误: " . $curlError;
        continue;
    }

    if ($httpCode !== 200) {
        $lastError = "Gemini API HTTP {$httpCode}: " . $response;
        continue;
    }

    $resultData = json_decode($response, true);
    $textOutput = $resultData['candidates'][0]['content']['parts'][0]['text'] ?? '';

    if (!empty($textOutput)) {
        $cleanedText = preg_replace('/```(?:json)?\s*(.*?)\s*```/s', '$1', trim($textOutput));
        $parsedJson = json_decode($cleanedText, true);

        if (is_array($parsedJson)) {
            $recognizedResult = $parsedJson;
        } else {
            $recognizedResult = [
                'name' => '识别对象',
                'description' => $cleanedText,
                'confidence' => '1.0'
            ];
        }
        break;
    }
}

if ($recognizedResult !== null) {
    echo json_encode([
        'ok' => true,
        'provider' => 'gemini',
        'result' => $recognizedResult
    ]);
} else {
    http_response_code(500);
    echo json_encode([
        'ok' => false,
        'error' => '识别失败，详细原因: ' . $lastError
    ]);
}