<?php

namespace Modules\Admin\Models;

use App\Support\PublicUrl;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

class Attachment extends Model
{
    use HasUuids;

    protected $table = 'asab_attachments';

    protected $fillable = [
        'owner_type', 'owner_id', 'filename', 'mime_type', 'size', 'storage_key',
        'public_url', 'label', 'verified_at', 'verified_by_id', 'uploaded_by_id', 'uploaded_at',
    ];

    protected $casts = [
        'size' => 'integer',
        'verified_at' => 'datetime',
        'uploaded_at' => 'datetime',
    ];

    /**
     * Always derived from `storage_key`, never read back verbatim from the row.
     *
     * The column pins whatever host + prefix was configured the day the file
     * was uploaded. Rows written while `APP_URL` carried a trailing slash hold
     * `https://host//storage/…`, which 404s; rows written before the document
     * root was understood hold a `/storage/…` prefix on a host that serves the
     * same file at `/public/storage/…`. Deriving on read means fixing the
     * config fixes every historical row too — no data backfill (2026-08-15).
     *
     * A row with no storage key (an external/CDN link stored directly) keeps
     * its value, normalised.
     */
    protected function publicUrl(): Attribute
    {
        return Attribute::make(
            get: fn (?string $value) => trim((string) $this->storage_key) !== ''
                ? PublicUrl::for($this->storage_key)
                : PublicUrl::normalize($value),
        );
    }
}
