<?php
declare(strict_types=1);

/**
 * Optional, conservative web lookup that can refine a generic AI result
 * (for example "Canon camera" -> "Canon EOS R50") when a configured,
 * trusted lookup endpoint says the refinement is safe.
 *
 * Design rules:
 *  - Only runs when `web_lookup_url` is set in config/config.php.
 *  - That URL must be a server you control; it receives the AI's brand and
 *    product name (both already sanitized) and MAY answer with:
 *        {"productName": "<more specific name>"}
 *    or {"refine": false} when it has no better answer.
 *  - The refined name is accepted only when it is plausibly the same product:
 *    plain text, short, and containing a significant word from the original
 *    AI result. Anything else is silently dropped - a web result must never
 *    override the AI result on weak evidence.
 */
final class ProductLookup
{
    private AppConfig $config;

    public function __construct(AppConfig $config)
    {
        $this->config = $config;
    }

    /**
     * Refine a result when a configured lookup endpoint provides a
     * trustworthy, more specific product name.
     *
     * @return string|null the refined name, or null to keep the original.
     */
    public function refine(ProductResult $result): ?string
    {
        $endpoint = trim((string) $this->config->web_lookup_url);
        if ($endpoint === '') {
            return null; // No lookup configured - keep the AI result as-is.
        }

        $payload = [
            'brand' => $result->manufacturer,
            'productName' => $result->productName,
            'objectLabel' => $result->objectLabel,
        ];

        $response = HttpHelper::postJson($endpoint, $payload, ['Content-Type: application/json'], 10);
        if ($response['error'] !== null || $response['status'] < 200 || $response['status'] >= 300) {
            return null; // Lookup failed (network, timeout, HTTP error) - keep the AI result.
        }

        $decoded = json_decode($response['body'], true);
        if (!is_array($decoded)) {
            return null;
        }
        if (isset($decoded['refine']) && $decoded['refine'] === false) {
            return null;
        }
        $refined = $decoded['productName'] ?? null;
        if (!is_string($refined)) {
            return null;
        }
        $refined = trim(preg_replace('/[\r\n\t]+/', ' ', $refined) ?? $refined);

        return $this->isPlausiblySameProduct($result->productName, $refined) ? $refined : null;
    }

    /**
     * Decide whether `refined` is a safe, more specific version of `original`.
     * Both strings come from sanitized (or endpoint-validated) text, but we
     * still refuse anything that looks like it refers to a different product.
     *
     * @param string $original AI product name, e.g. "Canon camera"
     * @param string $refined  candidate, e.g. "Canon EOS R50"
     */
    private function isPlausiblySameProduct(string $original, string $refined): bool
    {
        $refined = mb_substr($refined, 0, 120);
        if (trim($refined) === '') {
            return false;
        }
        // Plain, printable text only.
        if (preg_match('/[^\pL\pN .,\-/()&\'":;+]/u', $refined) === 1) {
            return false;
        }

        // At least one significant word of the original name must appear in
        // the refined name (case-insensitive). Otherwise the refined result
        // may refer to a different product and must not replace the AI's
        // answer. When the original has no significant word, the whole
        // original string must appear in the refinement instead.
        $lower = mb_strtolower($refined);
        $words = $this->significantWords($original);
        if ($words === []) {
            return $original !== '' && mb_strpos($lower, mb_strtolower($original)) !== false;
        }
        foreach ($words as $word) {
            if (mb_strpos($lower, mb_strtolower($word)) !== false) {
                return true;
            }
        }
        return false;
    }

    /** Words of 4+ letters from the original name (lowercase kept as-is). */
    private function significantWords(string $original): array
    {
        $words = preg_split('/[\s,\/\-&]+/', $original) ?: [];
        $out = [];
        foreach ($words as $word) {
            $word = trim($word);
            if (strlen($word) >= 4) {
                $out[] = $word;
            }
        }
        return $out;
    }

    /** Best-effort object class word (the last word of a short original name). */
    private function configObjectClass(string $original): string
    {
        $words = preg_split('/\s+/', trim($original)) ?: [];
        return (string) end($words);
    }
}
