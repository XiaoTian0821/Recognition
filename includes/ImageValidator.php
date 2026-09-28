<?php
declare(strict_types=1);

/**
 * Validates Base64 image data coming from the browser before it is sent
 * to an AI provider.
 *
 * Rules:
 *  - non-empty Base64, optional data-URI prefix,
 *  - strict Base64 decoding,
 *  - decoded size capped at 12 MB,
 *  - MIME sniffing from magic bytes (JPEG or PNG only),
 *  - minimum dimension check (64 px) when dimensions are readable.
 */
final class ImageValidator
{
    private const MAX_DECODED_BYTES = 12 * 1024 * 1024;
    private const MIN_DIMENSION = 64;

    /**
     * Validate a Base64 image payload.
     *
     * @return array{mime: string, data: string, width: int, height: int}
     * @throws InvalidArgumentException with a user-safe message.
     */
    public static function validateBase64(string $base64): array
    {
        $base64 = trim($base64);
        if ($base64 === '') {
            throw new InvalidArgumentException('Image data is empty.');
        }

        // Accept a "data:image/jpeg;base64,..." prefix and strip it.
        if (preg_match('/^data:image\/(jpeg|png);base64,(.+)$/s', $base64, $m)) {
            $base64 = $m[2];
        }
        $base64 = str_replace(["\r", "\n"], '', $base64);

        // Rough pre-check of the decoded size to avoid huge base64_decode calls.
        if ((int) floor(strlen($base64) * 3 / 4) > self::MAX_DECODED_BYTES) {
            throw new InvalidArgumentException('Image data is too large.');
        }

        $data = base64_decode($base64, true);
        if ($data === false || strlen($data) < 1024) {
            throw new InvalidArgumentException('Invalid image data.');
        }
        if (strlen($data) > self::MAX_DECODED_BYTES) {
            throw new InvalidArgumentException('Image data is too large.');
        }

        $mime = self::detectMime($data);
        if ($mime === null) {
            throw new InvalidArgumentException('Unsupported or invalid image format.');
        }

        [$width, $height] = self::dimensions($data, $mime);
        if ($width > 0 && ($width < self::MIN_DIMENSION || $height < self::MIN_DIMENSION)) {
            throw new InvalidArgumentException('Image is too small to analyze.');
        }

        return ['mime' => $mime, 'data' => $data, 'width' => $width, 'height' => $height];
    }

    /** @return string|null image/jpeg, image/png or null when unknown */
    private static function detectMime(string $data): ?string
    {
        if (strlen($data) >= 3 && substr($data, 0, 3) === "\xFF\xD8\xFF") {
            return 'image/jpeg';
        }
        if (strlen($data) >= 8 && substr($data, 0, 8) === "\x89PNG\r\n\x1A\n") {
            return 'image/png';
        }
        return null;
    }

    /**
     * Read the pixel dimensions. Prefers GD when available, otherwise a
     * small PNG/JPEG header parser so the app also runs on minimal hosts.
     *
     * @return array{0: int, 1: int} [width, height]; zeros when unknown.
     */
    private static function dimensions(string $data, string $mime): array
    {
        if (function_exists('getimagesize_from_string')) {
            $size = @getimagesize_from_string($data);
            if (is_array($size)) {
                return [(int) $size[0], (int) $size[1]];
            }
        }
        if ($mime === 'image/png' && strlen($data) >= 24) {
            // PNG: width and height are the first two big-endian uint32 values
            // of the IHDR chunk (bytes 16-24).
            [$w, $h] = unpack('N2', substr($data, 16, 8)) ?: [0, 0];
            return [$w, $h];
        }
        if ($mime === 'image/jpeg') {
            return self::jpegDimensions($data);
        }
        return [0, 0];
    }

    /** Walk JPEG markers to find a SOF frame that carries the dimensions. */
    private static function jpegDimensions(string $data): array
    {
        $offset = 2; // skip the SOI marker
        $length = strlen($data);
        while ($offset + 4 < $length) {
            if (ord($data[$offset]) !== 0xFF) {
                break;
            }
            $marker = ord($data[$offset + 1]);
            // Markers without a payload segment.
            if (($marker >= 0xD0 && $marker <= 0xD9) || $marker === 0xFF) {
                $offset += 2;
                continue;
            }
            $size = unpack('n', $data, $offset + 2)[1];
            // SOF0-SOF3 / SOF5-SOF7 / SOF9-SOF11 / SOF13-SOF15 carry dimensions.
            if ($marker >= 0xC0 && $marker <= 0xCF
                && $marker !== 0xC4 && $marker !== 0xC8 && $marker !== 0xCC) {
                $h = unpack('n', $data, $offset + 5)[1];
                $w = unpack('n', $data, $offset + 7)[1];
                return [$w, $h];
            }
            $offset += 2 + $size;
        }
        return [0, 0];
    }
}
