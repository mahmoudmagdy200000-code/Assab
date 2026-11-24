<?php

namespace Modules\Inventory\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

class ConsumptionImportBatch extends Model
{
    use HasUuids;

    protected $table = 'consumption_import_batches';

    protected $fillable = [
        'company_id',
        'source_system',
        'file_name',
        'file_hash',
        'status',
        'validation_errors',
        'preview_summary',
        'parsed_payload',
        'uploaded_by_id',
        'confirmed_by_id',
        'confirmed_at',
    ];

    protected $casts = [
        'validation_errors' => 'array',
        'preview_summary' => 'array',
        'parsed_payload' => 'array',
        'confirmed_at' => 'datetime',
    ];
}
