<?php

namespace Modules\Admin\Models;

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
}
