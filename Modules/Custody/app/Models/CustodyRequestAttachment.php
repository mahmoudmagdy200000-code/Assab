<?php

namespace Modules\Custody\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class CustodyRequestAttachment extends Model
{
    use HasUuids;

    protected $fillable = [
        'custody_request_id',
        'file_name',
        'original_name',
        'file_path',
        'file_type',
        'file_size',
        'mime_type',
    ];

    protected $casts = [
        'file_size' => 'integer',
    ];

    // Relationships
    public function custodyRequest(): BelongsTo
    {
        return $this->belongsTo(CustodyRequest::class);
    }

    // Accessors
    public function getUrlAttribute(): string
    {
        return asset('storage/'.$this->file_path);
    }
}
