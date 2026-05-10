<?php

namespace Modules\FixedAssets\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\MorphTo;

class Attachment extends Model
{
    use HasUuids;

    protected $table = 'fixed_asset_attachments';

    protected $fillable = [
        'attachable_type',
        'attachable_id',
        'kind',
        'file_name',
        'file_type',
        'file_size',
        'path',
        'uploaded_by_id',
        'uploaded_at',
    ];

    protected $casts = [
        'file_size' => 'integer',
        'uploaded_at' => 'datetime',
    ];

    public function attachable(): MorphTo
    {
        return $this->morphTo('attachable');
    }
}
