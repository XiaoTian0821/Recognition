<?php

declare(strict_types=1);

/**
 * Interface for AI vision providers.
 */

namespace App;

interface AIProvider
{
    /**
     * Return the provider name (e.g. "gemini", "agnes").
     */
    public function name(): string;

    /**
     * Recognize an object from an image.
     *
     * @param string $base64Image Base64-encoded image data (without data URI prefix).
     * @param string $mimeType    MIME type of the original image (e.g. "image/jpeg").
     * @return ProductResult|null Null if recognition fails or no object is identified.
     */
    public function recognize(string $base64Image, string $mimeType): ?ProductResult;

    /**
     * Test connectivity to the provider.
     *
     * @return array{ok: bool, message: string}
     */
    public function testConnection(): array;
}
