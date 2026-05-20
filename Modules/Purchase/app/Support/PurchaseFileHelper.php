<?php

namespace Modules\Purchase\Support;

use Modules\Purchase\Models\OrderDocument;

/**
 * Unified file shape for Purchase API: id, file_name, file_type, file_size, url, uploaded_at.
 */
final class PurchaseFileHelper
{
    public static function toApiShape(array|string|OrderDocument $file): array
    {
        if ($file instanceof OrderDocument) {
            return [
                'id' => $file->id,
                'file_name' => $file->original_name,
                'file_type' => $file->mime_type,
                'file_size' => (int) $file->file_size,
                'url' => $file->file_url ?? self::pathToUrl($file->file_path),
                'uploaded_at' => $file->created_at?->format('Y-m-d H:i:s'),
            ];
        }

        if (is_string($file)) {
            return [
                'id' => null,
                'file_name' => null,
                'file_type' => null,
                'file_size' => null,
                'url' => self::pathToUrl($file),
                'uploaded_at' => null,
            ];
        }

        $path = $file['file_path'] ?? $file['url'] ?? null;

        return [
            'id' => $file['id'] ?? null,
            'file_name' => $file['file_name'] ?? null,
            'file_type' => $file['file_type'] ?? null,
            'file_size' => isset($file['file_size']) ? (int) $file['file_size'] : null,
            'url' => $path ? self::pathToUrl($path) : null,
            'uploaded_at' => $file['uploaded_at'] ?? null,
        ];
    }

    /**
     * Build storable file entry from OrderDocument (for return item files JSON).
     */
    public static function toStorable(OrderDocument $doc): array
    {
        return [
            'id' => $doc->id,
            'file_name' => $doc->original_name,
            'file_type' => $doc->mime_type,
            'file_size' => (int) $doc->file_size,
            'file_path' => $doc->file_path,
            'uploaded_at' => $doc->created_at?->format('Y-m-d H:i:s'),
        ];
    }

    private static function pathToUrl(?string $path): ?string
    {
        if (! $path) {
            return null;
        }

        return str_starts_with($path, 'http') ? $path : asset('storage/'.$path);
    }
}
