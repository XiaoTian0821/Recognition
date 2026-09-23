<?php

declare(strict_types=1);

/**
 * Validates uploaded/processed image data.
 */

namespace App;

class ImageValidator
{
    /**
     * Validate Base64 image data.
     *
     * @param string $base64Data Base64 string (may include or omit data URI prefix).
     * @param array<string> $allowedMimeTypes Allowed MIME types.
     * @param int $maxFileSizeBytes Maximum allowed file size in bytes.
     * @return array{ok: bool, error?: string}
     */
    public static function validateBase64(
        string $base64Data,
        array $allowedMimeTypes,
        int $maxFileSizeBytes
    ): array {
        // Strip data URI prefix if present
        $base64Data = preg_replace('/^data:[^;]+;base64,/', '', $base64Data);

        if (empty($base64Data)) {
            return ['ok' => false, 'error' => 'Image data is empty.'];
        }

        // Validate Base64 encoding
        if (!preg_match('/^[a-zA-Z0-9\/+=]+$/', trim($base64Data))) {
            return ['ok' => false, 'error' => 'Invalid Base64 encoding.'];
        }

        // Decode to check MIME type and size
        $rawData = base64_decode($base64Data, true);
        if ($rawData === false) {
            return ['ok' => false, 'error' => 'Failed to decode Base64 data.'];
        }

        // Check file size
        if (strlen($rawData) > $maxFileSizeBytes) {
            return ['ok' => false, 'error' => 'Image is too large. Maximum size: '
                . round($maxFileSizeBytes / 1024 / 1024, 1) . ' MB.'];
        }

        // Detect MIME type from magic bytes
        $finfo = finfo_open(FILEINFO_MIME_TYPE);
        $mimeType = finfo_buffer($finfo, $rawData);
        finfo_close($finfo);

        if (!in_array($mimeType, $allowedMimeTypes, true)) {
            return ['ok' => false, 'error' => 'Unsupported image type: ' . $mimeType
                . '. Allowed: ' . implode(', ', $allowedMimeTypes) . '.'];
        }

        // Check image dimensions
        $dimensions = getimagesizefromstring($rawData);
        if ($dimensions === false) {
            return ['ok' => false, 'error' => 'Could not read image dimensions.'];
        }

        [$width, $height] = $dimensions;

        if ($width < 50 || $height < 50) {
            return ['ok' => false, 'error' => 'Image is too small (' . $width . 'x' . $height . ').'];
        }

        return ['ok' => true, 'mimeType' => $mimeType, 'width' => $width, 'height' => $height];
    }

    /**
     * Resize an image to fit within maxDimension, return as Base64 JPEG.
     *
     * @param string $base64Data Base64 image data.
     * @param string $mimeType   Original MIME type.
     * @param int    $maxDimension Maximum width or height in pixels.
     * @param int    $quality      JPEG quality (1-100).
     * @return array{ok: bool, base64?: string, mimeType?: string, error?: string}
     */
    public static function resizeAndCompress(
        string $base64Data,
        string $mimeType,
        int $maxDimension,
        int $quality
    ): array {
        $rawData = base64_decode($base64Data, true);
        if ($rawData === false) {
            return ['ok' => false, 'error' => 'Failed to decode image data.'];
        }

        // Create image from source
        $image = imagecreatefromstring($rawData);
        if ($image === false) {
            return ['ok' => false, 'error' => 'Failed to create image from data.'];
        }

        $origWidth  = imagesx($image);
        $origHeight = imagesy($image);

        // Calculate new dimensions
        $newWidth  = $origWidth;
        $newHeight = $origHeight;

        if ($origWidth > $maxDimension || $origHeight > $maxDimension) {
            $scale = $maxDimension / max($origWidth, $origHeight);
            $newWidth  = (int) round($origWidth * $scale);
            $newHeight = (int) round($origHeight * $scale);
        }

        // Create resized image
        $resized = imagecreatetruecolor($newWidth, $newHeight);

        // Handle transparency for PNG/WebP
        if ($mimeType === 'image/png' || $mimeType === 'image/webp') {
            imagealphablending($resized, false);
            imagesavealpha($resized, true);
            $transparent = imagecolorallocatealpha($resized, 255, 255, 255, 127);
            imagefilledrectangle($resized, 0, 0, $newWidth, $newHeight, $transparent);
        }

        imagecopyresampled($resized, $image, 0, 0, 0, 0, $newWidth, $newHeight, $origWidth, $origHeight);
        imagedestroy($image);

        // Encode as JPEG
        ob_start();
        imagejpeg($resized, null, $quality);
        $compressedData = ob_get_clean();
        imagedestroy($resized);

        if (empty($compressedData)) {
            return ['ok' => false, 'error' => 'Failed to compress image.'];
        }

        $base64 = base64_encode($compressedData);

        return [
            'ok'      => true,
            'base64'  => $base64,
            'mimeType' => 'image/jpeg',
            'width'   => $newWidth,
            'height'  => $newHeight,
        ];
    }
}
