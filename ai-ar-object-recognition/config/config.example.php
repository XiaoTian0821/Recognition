<?php

declare(strict_types=1);

/**
 * Configuration example file.
 * Copy this to config.php and fill in your actual values.
 * This file is safe to commit to version control.
 */

return [
    // ─── AI Vision Providers ───────────────────────────────────────────

    // Primary AI provider: "auto", "gemini", or "agnes"
    'primary_provider' => 'gemini',

    // Gemini AI configuration
    'gemini' => [
        'api_key' => '',                    // Get key from https://aistudio.google.com
        'model' => 'gemini-3.6-flash',
        'fallback_models' => [],
        'timeout_seconds' => 60,
        'api_url' => 'https://generativelanguage.googleapis.com/v1beta/models/{model}:generateContent?key={api_key}',
    ],

    // Agnes AI configuration
    'agnes' => [
        'api_key' => '',                    // Your Agnes Vision API key
        'model' => 'agnes-vision-v1',
        'fallback_models' => [
            'agnes-vision-v1-lite',
        ],
        'timeout_seconds' => 30,
        'api_url' => 'https://api.agnes.ai/v1/vision/recognize',
    ],

    // ─── Web Lookup ────────────────────────────────────────────────────

    // Enable optional web-based product lookup after AI recognition
    'enable_web_lookup' => false,

    // ─── Database ──────────────────────────────────────────────────────

    'database' => [
        'host' => '127.0.0.1',
        'port' => '3306',
        'dbname' => 'ai_ar_recognition',
        'charset' => 'utf8mb4',
        'username' => 'root',
        'password' => '123456',
        'options' => [
            PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::MYSQL_ATTR_INIT_COMMAND => "SET NAMES utf8mb4",
        ],
    ],

    // ─── Application ───────────────────────────────────────────────────

    // Maximum image dimension for uploads (pixels)
    'max_image_dimension' => 1280,

    // JPEG quality for compressed uploads (0.0 – 1.0)
    'jpeg_quality' => 70,

    // Maximum file size in bytes (~1.5 MB)
    'max_file_size_bytes' => 1572864,

    // Allowed MIME types for upload
    'allowed_mime_types' => [
        'image/jpeg',
        'image/png',
        'image/webp',
        'image/gif',
    ],

    // General request timeout in seconds
    'request_timeout' => 60,

    // Enable scan history logging
    'enable_history' => true,

    // Maximum history records to keep
    'max_history_records' => 500,
];
