<?php

namespace App\Services;

use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;

/**
 * Streams uploaded files to storage without buffering the entire file in memory.
 * Uses writeStream so bytes flow from the request temp file directly to disk/cloud.
 */
class StreamUploadService
{
    /**
     * Store an uploaded file via stream write. Does not load the entire file into memory.
     *
     * @param  UploadedFile  $file  The uploaded file from the request
     * @param  string  $directory  Storage directory (e.g. profiles/managers)
     * @param  string|null  $name  Filename; falls back to hashName() when null
     * @param  string|null  $disk  Disk name; defaults to config upload_disk or FILESYSTEM_DISK
     * @return string Stored path relative to the disk root
     */
    public function storeFromUpload(
        UploadedFile $file,
        string $directory,
        ?string $name = null,
        ?string $disk = null
    ): string {
        $disk = $disk ?? config('filesystems.upload_disk', config('filesystems.default'));
        $name = $name ?? $file->hashName();
        $path = rtrim($directory, '/').'/'.$name;

        $stream = fopen($file->getRealPath(), 'r');
        try {
            Storage::disk($disk)->writeStream($path, $stream);
        } finally {
            if (is_resource($stream)) {
                fclose($stream);
            }
        }

        return $path;
    }
}
