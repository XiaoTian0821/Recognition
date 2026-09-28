<?php
declare(strict_types=1);

/**
 * Base class for AI vision providers (Gemini, Agnes).
 *
 * A provider knows how to send one image to its API and parse the answer
 * into the provider-agnostic JSON structure the rest of the app expects:
 *
 *   {
 *     "objectLabel": "laptop",
 *     "productName": "Dell Latitude laptop",
 *     "manufacturer": "Dell",
 *     "specification": "14-inch business laptop",
 *     "description": "...",
 *     "boundingBox": { "ymin":0, "xmin":0, "ymax":1000, "xmax":1000 },
 *     "confidence": 0.0-1.0
 *   }
 *
 * Subclasses implement request(), which returns that raw array. The shared
 * buildPrompt() and requireConfig() keep the prompt consistent everywhere.
 */
abstract class AIProvider
{
    /** @var string the provider id used in API responses ("gemini" | "agnes") */
    protected string $providerId;

    /** @param string $providerId */
    protected function __construct(string $providerId)
    {
        $this->providerId = $providerId;
    }

    /**
     * Send one image to this provider and return the raw (untrusted) data.
     *
     * @param string $base64Data encoded image data (no data: prefix)
     * @param string $mime       image/jpeg or image/png
     * @return array<string, mixed> raw provider data
     * @throws ProviderException on network / HTTP / parse failures
     */
    abstract public function request(string $base64Data, string $mime): array;

    /**
     * The shared vision prompt. Every provider is asked for exactly the same
     * JSON structure, so downstream code never has to special-case providers.
     */
    protected static function buildPrompt(): string
    {
        return implode("\n", [
            'You are a precise AI vision model used by a product-scanning app.',
            'Look at the image and identify the single MAIN physical object or product in it.',
            '',
            'STRICT RULES:',
            '1. Rely only on visible evidence in the image. Never invent details you cannot see.',
            '2. Read any visible text, brand names, logos and model numbers carefully.',
            '3. If you can read a specific model number with confidence, include it in the product name.',
            '4. If the exact model CANNOT be determined, use a generic product name instead of a specific one.',
            '   - Correct: "Sony headphones", "Apple iPhone", "Canon camera".',
            '   - Incorrect: "Sony WH-1000XM6", "Apple iPhone 15 Pro Max", "Canon EOS R50" when those exact',
            '     models are not clearly visible/readable.',
            '5. Do not add marketing claims or information that is not supported by the image.',
            '',
            'Respond with ONLY a valid JSON object, no markdown, no code fences, no extra text, in exactly',
            'this shape:',
            '{',
            '  "objectLabel": "<short lowercase category, e.g. laptop, camera, headphones, bottle>",',
            '  "productName": "<most likely product name, generic when the exact model is unclear>",',
            '  "manufacturer": "<manufacturer/brand, or an empty string when unknown>",',
            '  "specification": "<short spec summary (size/type/capability) or empty string when unknown>",',
            '  "description": "<one or two plain factual sentences about the object, or empty string>",',
            '  "boundingBox": { "ymin": <int>, "xmin": <int>, "ymax": <int>, "xmax": <int> },',
            '  "confidence": <number between 0 and 1>',
            '}',
            '',
            'About boundingBox: give the tight box around the main object using NORMALIZED',
            'coordinates from 0 to 1000 on BOTH axes. ymin/xmin is the top-left corner, ymax/xmax is',
            'the bottom-right corner, all measured on a 0-1000 scale relative to the image width/height.',
            '',
            'About confidence: your honest certainty (0.0-1.0). Keep it high only when the evidence is strong.',
        ]);
    }

    /**
     * @throws ProviderNotConfiguredException when the provider has no usable key.
     */
    protected function requireConfig(): void
    {
        if ($this->configKey() === '') {
            throw new ProviderNotConfiguredException(
                ucfirst($this->providerId) . ' is not configured: no API key is available.'
            );
        }
    }

    /**
     * The configured API key for this provider. Subclasses override this to
     * read the right value from AppConfig. Centralizing the check here means
     * the "not configured" case is handled consistently for every provider.
     *
     * @return string empty string when no key is configured
     */
    protected function configKey(): string
    {
        return '';
    }

    /**
     * Extract a clean JSON object from a provider reply that may include
     * markdown fences, prose, or a JSON array containing a single object.
     *
     * @return array<string, mixed>|null null when no JSON object can be recovered.
     */
    protected static function extractJson(string $text): ?array
    {
        $text = trim($text);
        if ($text === '') {
            return null;
        }

        // 1) Direct decode (fast path when the model returns pure JSON).
        $direct = json_decode($text, true);
        if (is_array($direct)) {
            return $direct;
        }

        // 2) Strip a "```json ... ```" fence if present.
        if (preg_match('/```(?:json)?\s*(\{.*\})\s*```/s', $text, $m)) {
            $obj = json_decode($m[1], true);
            if (is_array($obj)) {
                return $obj;
            }
        }

        // 3) Find the outermost {...} object anywhere in the text.
        $start = strpos($text, '{');
        if ($start !== false) {
            $depth = 0;
            $inString = false;
            $escaped = false;
            $end = -1;
            $length = strlen($text);
            for ($i = $start; $i < $length; $i++) {
                $ch = $text[$i];
                if ($inString) {
                    if ($escaped) {
                        $escaped = false;
                    } elseif ($ch === '\\') {
                        $escaped = true;
                    } elseif ($ch === '"') {
                        $inString = false;
                    }
                } else {
                    if ($ch === '"') {
                        $inString = true;
                    } elseif ($ch === '{') {
                        $depth++;
                    } elseif ($ch === '}') {
                        $depth--;
                        if ($depth === 0) {
                            $end = $i;
                            break;
                        }
                    }
                }
            }
            if ($end > $start) {
                $obj = json_decode(substr($text, $start, $end - $start + 1), true);
                if (is_array($obj)) {
                    return $obj;
                }
            }
        }

        return null;
    }

    /** @return string */
    public function id(): string
    {
        return $this->providerId;
    }
}
