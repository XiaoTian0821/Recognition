<?php

declare(strict_types=1);

/**
 * Represents a validated object recognition result.
 */

namespace App;

class ProductResult
{
    /**
     * @param string|null $objectLabel    The identified object category (e.g. "laptop", "smartphone").
     * @param string|null $productName    The product name (generic or specific).
     * @param string|null $manufacturer   The manufacturer/brand.
     * @param string|null $specification  Product specifications.
     * @param string|null $description    Short product description.
     * @param array{ymin: float, xmin: float, ymax: float, xmax: float}|null $boundingBox Bounding box in 0-1000 coordinates.
     * @param float       $confidence     Confidence score between 0 and 1.
     * @param string      $provider       The AI provider that produced this result.
     */
    public function __construct(
        public ?string $objectLabel,
        public ?string $productName,
        public ?string $manufacturer,
        public ?string $specification,
        public ?string $description,
        public ?array  $boundingBox,
        public float   $confidence,
        public string  $provider = 'unknown',
    ) {
    }

    /**
     * Check if the result is valid and complete.
     */
    public function isValid(): bool
    {
        return !empty($this->objectLabel)
            && !empty($this->productName)
            && !empty($this->boundingBox)
            && $this->confidence > 0;
    }

    /**
     * Convert to an array for JSON serialization.
     *
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'objectLabel'  => $this->objectLabel,
            'productName'  => $this->productName,
            'manufacturer' => $this->manufacturer,
            'specification' => $this->specification,
            'description'  => $this->description,
            'boundingBox'  => $this->boundingBox,
            'confidence'   => round($this->confidence * 100, 1),
            'provider'     => $this->provider,
        ];
    }

    /**
     * Convert to a human-readable summary.
     */
    public function summary(): string
    {
        $parts = [];

        if ($this->productName) {
            $parts[] = $this->productName;
        }
        if ($this->manufacturer) {
            $parts[] = $this->manufacturer;
        }

        return implode(' — ', $parts) ?: $this->objectLabel ?? 'Unknown object';
    }
}
