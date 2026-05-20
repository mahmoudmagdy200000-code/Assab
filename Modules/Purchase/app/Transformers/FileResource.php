<?php

namespace Modules\Purchase\Transformers;

use Carbon\Carbon;
use Illuminate\Http\Resources\Json\JsonResource;
use Illuminate\Support\Facades\Storage;
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
     * Generate a stable, deterministic id from a file path when no DB id exists.
     */
    private static function idFromPath(string $path): string
    {
        return substr(hash('sha256', $path), 0, 32);
    }

    /**
     * Get uploaded_at from file's last modified time on storage when no DB value exists.
     */
    private static function uploadedAtFromPath(?string $path): ?string
    {
        if (! $path || str_starts_with($path, 'http')) {
            return null;
        }
        try {
            if (Storage::disk('public')->exists($path)) {
                $timestamp = Storage::disk('public')->lastModified($path);

                return Carbon::createFromTimestamp($timestamp)->format(self::DATE_FORMAT);
            }
        } catch (\Throwable) {
            // Keep null on error
        }

        return null;
    }

    /**
     * Transform the resource into an array.
     *
     * @param  \Illuminate\Http\Request  $request
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
        $fileSize = $this->file_size ? (int) $this->file_size : null;
        if ($fileSize === null && $this->file_path) {
            try {
                if (Storage::disk('public')->exists($this->file_path)) {
                    $fileSize = Storage::disk('public')->size($this->file_path);
                }
            } catch (\Throwable) {
                // Keep null on error
            }
        }

        $uploadedAt = $this->created_at?->format(self::DATE_FORMAT)
            ?? self::uploadedAtFromPath($this->file_path);

        return [
            'id' => (string) $this->id,
            'file_name' => $this->original_name ?? null,
            'file_type' => $this->mime_type ?? null,
            'file_size' => $fileSize,
            'url' => $this->file_url ?? $this->getUrlFromPath($this->file_path),
            'uploaded_at' => $uploadedAt ?? Carbon::now()->format(self::DATE_FORMAT),
        ];
    }

    /**
     * Format PurchaseInvoice model.
     *
     * @return array<string, mixed>
     */
    private function formatPurchaseInvoice(): array
    {
        $uploadedAt = $this->created_at?->format(self::DATE_FORMAT)
            ?? self::uploadedAtFromPath($this->file_path ?? null);

        return [
            'id' => (string) $this->id,
            'file_name' => $this->file_name ?? null,
            'file_type' => $this->file_type ?? null,
            'file_size' => $this->file_size ? (int) $this->file_size : null,
            'url' => $this->file_url ?? $this->getUrlFromPath($this->file_path ?? null),
            'uploaded_at' => $uploadedAt ?? Carbon::now()->format(self::DATE_FORMAT),
        ];
    }

    /**
     * Format array data.
     * Resolves file_type and file_size from file_path when missing (e.g. from storage path).
     *
     * @return array<string, mixed>
     */
    private function formatArray(): array
    {
        $uploadedAt = null;
        if (isset($this->resource['uploaded_at'])) {
            $uploadedAt = $this->resource['uploaded_at'];
        } elseif (isset($this->resource['created_at'])) {
            $createdAt = $this->resource['created_at'];
            if ($createdAt instanceof \DateTimeInterface) {
                $uploadedAt = $createdAt->format(self::DATE_FORMAT);
            } elseif (is_string($createdAt)) {
                $uploadedAt = $createdAt;
            }
        }

        $fileType = $this->resource['file_type'] ?? $this->resource['mime_type'] ?? null;
        $fileSize = isset($this->resource['file_size']) ? (int) $this->resource['file_size'] : null;
        $filePath = $this->resource['file_path'] ?? null;

        // Resolve file_type and file_size from path/storage when missing
        if ($filePath && is_string($filePath) && ! str_starts_with($filePath, 'http')) {
            if (! $fileType) {
                $ext = pathinfo($filePath, PATHINFO_EXTENSION);
                $fileType = $ext !== '' ? strtolower($ext) : null;
            }
            if ($fileSize === null) {
                try {
                    if (Storage::disk('public')->exists($filePath)) {
                        $fileSize = (int) Storage::disk('public')->size($filePath);
                    }
                } catch (\Throwable) {
                    // Keep null on error
                }
            }
        }

        $id = isset($this->resource['id']) ? (string) $this->resource['id'] : null;
        if ($id === null && $filePath && is_string($filePath)) {
            $id = self::idFromPath($filePath);
        }

        if ($uploadedAt === null && $filePath && is_string($filePath)) {
            $uploadedAt = self::uploadedAtFromPath($filePath);
        }

        return [
            'id' => $id,
            'file_name' => $this->resource['file_name'] ?? $this->resource['original_name'] ?? basename($filePath ?? ''),
            'file_type' => $fileType,
            'file_size' => $fileSize,
            'url' => $this->resource['url']
                ?? $this->resource['file_url']
                ?? $this->getUrlFromPath($filePath),
            'uploaded_at' => $uploadedAt ?? Carbon::now()->format(self::DATE_FORMAT),
        ];
    }

    /**
     * Format string file path.
     * Resolves file_type from path extension and file_size from storage when path is a storage path.
     *
     * @return array<string, mixed>
     */
    private function formatString(): array
    {
        $path = $this->resource;
        $fileType = null;
        $fileSize = null;

        // Resolve file_type from path (works for both storage path and URL path)
        $pathForExtension = str_starts_with($path, 'http') ? parse_url($path, PHP_URL_PATH) : $path;
        if ($pathForExtension !== null && $pathForExtension !== '') {
            $ext = pathinfo($pathForExtension, PATHINFO_EXTENSION);
            if ($ext !== '') {
                $fileType = strtolower($ext);
            }
        }

        // Resolve file_size and uploaded_at from storage only for relative storage paths
        $uploadedAt = null;
        if (! str_starts_with($path, 'http')) {
            try {
                if (Storage::disk('public')->exists($path)) {
                    $fileSize = (int) Storage::disk('public')->size($path);
                    $uploadedAt = self::uploadedAtFromPath($path);
                }
            } catch (\Throwable) {
                // Keep null on error
            }
        }

        return [
            'id' => self::idFromPath($path),
            'file_name' => basename($pathForExtension ?? $path),
            'file_type' => $fileType,
            'file_size' => $fileSize,
            'url' => $this->getUrlFromPath($path),
            'uploaded_at' => $uploadedAt ?? Carbon::now()->format(self::DATE_FORMAT),
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
            'uploaded_at' => Carbon::now()->format(self::DATE_FORMAT),
        ];
    }

    /**
     * Convert file path to URL.
     */
    private function getUrlFromPath(?string $path): ?string
    {
        if (! $path) {
            return null;
        }

        // If already a full URL, return as is
        if (str_starts_with($path, 'http://') || str_starts_with($path, 'https://')) {
            return $path;
        }

        // Convert storage path to URL
        return asset('storage/'.ltrim($path, '/'));
    }

    /**
     * Create a FileResource from various input types, returning null if file is null.
     * Use this method when you need to handle null values gracefully.
     *
     * @param  mixed  $file
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
