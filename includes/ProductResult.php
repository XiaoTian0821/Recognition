<?php
declare(strict_types=1);

/**
 * A normalized, validated recognition result.
 *
 * Created from raw (untrusted) provider data via ::fromProviderData().
 * Invalid or missing values are normalized to safe fallbacks, and any
 * text that survives is treated as untrusted and escaped at output time.
 */
final class ProductResult
{
    public string $objectLabel;
    public string $productName;
    public string $manufacturer;
    public string $specification;
    public string $description;
    /** @var array{ymin: int, xmin: int, ymax: int, xmax: int}|null */
    public ?array $boundingBox;
    public float $confidence;
    public string $provider;
    public string $model;

    /** @param array{ymin: int, xmin: int, ymax: int, xmax: int}|null $boundingBox */
    private function __construct(
        string $objectLabel,
        string $productName,
        string $manufacturer,
        string $specification,
        string $description,
        ?array $boundingBox,
        float $confidence,
        string $provider,
        string $model
    ) {
        $this->objectLabel = $objectLabel;
        $this->productName = $productName;
        $this->manufacturer = $manufacturer;
        $this->specification = $specification;
        $this->description = $description;
        $this->boundingBox = $boundingBox;
        $this->confidence = $confidence;
        $this->provider = $provider;
        $this->model = $model;
    }

    /**
     * Build a safe result from raw provider data.
     *
     * @param array<string, mixed> $data     raw JSON data returned by the AI
     * @param array<string, mixed> $provider ['provider' => string, 'model' => string]
     * @throws InvalidArgumentException when the result is unusable
     *                                  (no object label AND no product name).
     */
    public static function fromProviderData(array $data, array $provider): self
    {
        $objectLabel = self::text($data['objectLabel'] ?? $data['object_label'] ?? null, 60);
        $productName = self::text($data['productName'] ?? $data['product_name'] ?? null, 120);

        if ($objectLabel === '' && $productName === '') {
            throw new InvalidArgumentException('The AI returned no object information.');
        }
        // Fall back to the object label when the product name is missing.
        $productName = $productName !== '' ? $productName : $objectLabel;
        $objectLabel = $objectLabel !== '' ? $objectLabel : strtolower(preg_replace('/[^a-z0-9]+/i', ' ', $productName)) ?: 'object';

        $manufacturer = self::text($data['manufacturer'] ?? $data['brand'] ?? null, 80);
        $specification = self::text($data['specification'] ?? $data['spec'] ?? null, 300);
        $description = self::text($data['description'] ?? $data['summary'] ?? null, 500);

        $confidence = self::clamp01(
            $data['confidence'] ?? $data['confidence_score'] ?? 0.0
        );
        $box = self::normalizeBox($data['boundingBox'] ?? $data['bounding_box'] ?? null);

        return new self(
            $objectLabel,
            $productName,
            $manufacturer,
            $specification,
            $description,
            $box,
            $confidence,
            strtolower(self::text($provider['provider'] ?? 'ai', 30) ?: 'ai'),
            self::text($provider['model'] ?? null, 80)
        );
    }

    /** Optional refinement from the web lookup step (already validated upstream). */
    public function applyLookup(?string $refinedProductName): void
    {
        $refined = self::text($refinedProductName, 120);
        if ($refined !== '') {
            $this->productName = $refined;
        }
    }

    /** API payload for api/recognize.php (keys safe to send to the browser). */
    public function toApiArray(): array
    {
        return [
            'objectLabel' => $this->objectLabel,
            'productName' => $this->productName,
            'manufacturer' => $this->manufacturer,
            'specification' => $this->specification,
            'description' => $this->description,
            'boundingBox' => $this->boundingBox,
            'confidence' => round($this->confidence, 2),
            'provider' => $this->provider,
            'model' => $this->model,
        ];
    }

    /** Scan-history row (all values already trimmed/limited, still must be
     *  stored via PDO prepared statements). */
    public function toHistoryRow(): array
    {
        return [
            'object_label' => $this->objectLabel,
            'product_name' => $this->productName,
            'manufacturer' => $this->manufacturer,
            'specification' => $this->specification,
            'description' => $this->description,
            'confidence' => round($this->confidence, 2),
            'provider' => $this->provider,
        ];
    }

    /**
     * Normalize a bounding box into 0-1000 integer coordinates, or null.
     * @param mixed $raw
     * @return array{ymin: int, xmin: int, ymax: int, xmax: int}|null
     */
    private static function normalizeBox($raw): ?array
    {
        if (!is_array($raw)) {
            return null;
        }
        $get = static function ($key) use ($raw) {
            $v = $raw[$key] ?? null;
            return is_numeric($v) ? (float) $v : null;
        };
        $ymin = $get('ymin');
        $xmin = $get('xmin');
        $ymax = $get('ymax');
        $xmax = $get('xmax');
        if ($ymin === null || $xmin === null || $ymax === null || $xmax === null) {
            return null;
        }
        $clamp = static function (float $v): int {
            $v = max(0, min(1000, $v));
            $lo = (int) floor($v);
            return $v >= 999.5 ? 1000 : $lo;
        };
        $ymin = $clamp($ymin);
        $xmin = $clamp($xmin);
        $ymax = $clamp($ymax);
        $xmax = $clamp($xmax);
        if ($ymin > $ymax) {
            [$ymin, $ymax] = [$ymax, $ymin];
        }
        if ($xmin > $xmax) {
            [$xmin, $xmax] = [$xmax, $xmin];
        }
        // Discard boxes that are too small to be meaningful (< 2% of width/height).
        if ($xmax - $xmin < 20 || $ymax - $ymin < 20) {
            return null;
        }
        return ['ymin' => $ymin, 'xmin' => $xmin, 'ymax' => $ymax, 'xmax' => $xmax];
    }

    /** @param mixed $value */
    private static function text($value, int $max): string
    {
        if (!is_string($value)) {
            return '';
        }
        // Normalize whitespace and strip stray control characters.
        $value = preg_replace('/[\r\n\t]+/', ' ', $value) ?? $value;
        $clean = @preg_replace('/\p{C}+/u', '', $value);
        if (is_string($clean)) {
            $value = $clean;
        }
        $value = trim($value);
        if (strlen($value) > $max) {
            $value = substr($value, 0, $max) . '…';
        }
        return $value;
    }

    /** @param mixed $value */
    private static function clamp01($value): float
    {
        if (!is_numeric($value)) {
            return 0.5;
        }
        $v = (float) $value;
        // A value like 92 is obviously a percentage, not a 0-1 score.
        if ($v > 1.5) {
            $v = $v / 100.0;
        }
        return max(0.0, min(1.0, $v));
    }
}
