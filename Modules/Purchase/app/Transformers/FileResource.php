<?php

namespace Modules\Purchase\Transformers;

use Illuminate\Http\Resources\Json\JsonResource;
use Modules\Purchase\Models\OrderDocument;
use Modules\Purchase\Models\PurchaseInvoice;

/**
 * Unified File Resource for consistent file representation across the API.
 *
 * Supports:
 * - OrderDocument models
 * - PurchaseInvoice models (with file attributes)
 * - Arrays with file data
 * - String file paths
 * - Null values
 */
class FileResource extends JsonResource
{
    private const DATE_FORMAT = 'Y-m-d H:i:s';

    /**
     * Transform the resource into an array.
     *
     * @param \Illuminate\Http\Request $request
     * @return array<string, mixed>
     */
    public function toArray($request): array
    {
        // Handle OrderDocument model
        if ($this->resource instanceof OrderDocument) {
            return $this->formatOrderDocument();
        }

        // Handle PurchaseInvoice model
        if ($this->resource instanceof PurchaseInvoice) {
            return $this->formatPurchaseInvoice();
        }

        // Handle array data
        if (is_array($this->resource)) {
            return $this->formatArray();
        }

        // Handle string (file path)
        if (is_string($this->resource)) {
            return $this->formatString();
        }

        // Handle null or other types
        return $this->formatEmpty();
    }

    /**
     * Format OrderDocument model.
     *
     * @return array<string, mixed>
     */
    private function formatOrderDocument(): array
    {
        return [
            'id' => (string) $this->id,
            'file_name' => $this->original_name ?? null,
            'file_type' => $this->mime_type ?? null,
            'file_size' => $this->file_size ? (int) $this->file_size : null,
            'url' => $this->file_url ?? $this->getUrlFromPath($this->file_path),
            'uploaded_at' => $this->created_at?->format(self::DATE_FORMAT),
        ];
    }

    /**
     * Format PurchaseInvoice model.
     *
     * @return array<string, mixed>
     */
    private function formatPurchaseInvoice(): array
    {
        return [
            'id' => (string) $this->id,
            'file_name' => $this->file_name ?? null,
            'file_type' => $this->file_type ?? null,
            'file_size' => $this->file_size ? (int) $this->file_size : null,
            'url' => $this->file_url ?? $this->getUrlFromPath($this->file_path ?? null),
            'uploaded_at' => $this->created_at?->format(self::DATE_FORMAT),
        ];
    }

    /**
     * Format array data.
     *
     * @return array<string, mixed>
     */
    private function formatArray(): array
    {
        $uploadedAt = $this->resource['uploaded_at']
            ?? ($this->resource['created_at'] instanceof \DateTimeInterface
                ? $this->resource['created_at']->format(self::DATE_FORMAT)
                : null);

        return [
            'id' => isset($this->resource['id']) ? (string) $this->resource['id'] : null,
            'file_name' => $this->resource['file_name'] ?? $this->resource['original_name'] ?? null,
            'file_type' => $this->resource['file_type'] ?? $this->resource['mime_type'] ?? null,
            'file_size' => isset($this->resource['file_size']) ? (int) $this->resource['file_size'] : null,
            'url' => $this->resource['url']
                ?? $this->resource['file_url']
                ?? $this->getUrlFromPath($this->resource['file_path'] ?? null),
            'uploaded_at' => $uploadedAt,
        ];
    }

    /**
     * Format string file path.
     *
     * @return array<string, mixed>
     */
    private function formatString(): array
    {
        return [
            'id' => null,
            'file_name' => basename($this->resource),
            'file_type' => null,
            'file_size' => null,
            'url' => $this->getUrlFromPath($this->resource),
            'uploaded_at' => null,
        ];
    }

    /**
     * Format empty/null value.
     *
     * @return array<string, mixed>
     */
    private function formatEmpty(): array
    {
        return [
            'id' => null,
            'file_name' => null,
            'file_type' => null,
            'file_size' => null,
            'url' => null,
            'uploaded_at' => null,
        ];
    }

    /**
     * Convert file path to URL.
     *
     * @param string|null $path
     * @return string|null
     */
    private function getUrlFromPath(?string $path): ?string
    {
        if (!$path) {
            return null;
        }

        // If already a full URL, return as is
        if (str_starts_with($path, 'http://') || str_starts_with($path, 'https://')) {
            return $path;
        }

        // Convert storage path to URL
        return asset('storage/' . ltrim($path, '/'));
    }

    /**
     * Create a FileResource from various input types, returning null if file is null.
     * Use this method when you need to handle null values gracefully.
     *
     * @param mixed $file
     * @return static|null
     */
    public static function makeOrNull($file): ?self
    {
        if ($file === null) {
            return null;
        }

        return new static($file);
    }
}
