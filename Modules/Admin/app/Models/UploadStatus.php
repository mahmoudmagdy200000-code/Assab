<?php

namespace Modules\Admin\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

class UploadStatus extends Model
{
    use HasUuids;

    protected $table = 'asab_upload_status';

    protected $fillable = [
        'owner_type', 'owner_id', 'upload_type', 'uploaded_count', 'uploaded_at', 'uploaded_by_id',
    ];

    protected $casts = ['uploaded_count' => 'integer', 'uploaded_at' => 'datetime'];
}
