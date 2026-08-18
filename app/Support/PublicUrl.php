<?php

namespace App\Support;

use Illuminate\Support\Facades\Storage;

/**
 * The one place a stored file becomes a browsable URL.
 *
 * Two production bugs live here, and both are invisible in local dev:
 *
 *  1. **The double slash.** The `public` disk's URL is `APP_URL.'/storage'`, so
 *     an `APP_URL` with a trailing slash yields `https://host//storage/…`. Some
 *     web servers 404 that path outright (Hostinger does).
 *
 *  2. **The doc-root prefix.** This project's `public` disk writes straight into
 *     `public/storage` (see config/filesystems.php — there is no symlink to
 *     `storage/app/public`, which is why `storage:link --force` DESTROYS the
 *     uploaded receipts rather than fixing anything). On a host whose document
 *     root is the project root rather than `public/`, the browsable path is
 *     `/public/storage/…`. `FILESYSTEM_PUBLIC_URL` is the knob for that — set
 *     it instead of bending `APP_URL`, which every other generated link
 *     (password resets, signed routes) also depends on.
 *
 * Normalisation is applied on READ as well as on write, so rows already stored
 * with a doubled slash render without a data backfill.
 */
final class PublicUrl
{
    /**
     * Absolute URL for a path on the `public` disk. Returns null for a blank
     * path; passes an already-absolute URL through the normaliser unchanged
     * otherwise (stored CDN/S3 links, and rows written before this existed).
     */
    public static function for(?string $path): ?string
    {
        $path = trim((string) $path);

        if ($path === '') {
            return null;
        }

        return self::normalize(
            self::isAbsolute($path) ? $path : Storage::disk('public')->url($path),
        );
    }

    /**
     * Collapse duplicate slashes in the PATH portion of a URL, never in the
     * `https://` scheme separator.
     */
    public static function normalize(?string $url): ?string
    {
        if ($url === null || $url === '') {
            return $url;
        }

        return preg_replace('#(?<!:)//+#', '/', $url);
    }

    private static function isAbsolute(string $path): bool
    {
        return (bool) preg_match('#^(https?:)?//#i', $path);
    }
}
