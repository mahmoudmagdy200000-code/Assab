<?php

namespace Modules\FixedAssets\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphTo;
use Modules\FixedAssets\Enums\HandoverSignatureRole;

class HandoverSignature extends Model
{
    use HasUuids;

    protected $table = 'fixed_asset_handover_signatures';

    protected $fillable = [
        'handover_id',
        'role',
        'signed_by_type',
        'signed_by_id',
        'signed_by_name_snapshot',
        'signature_image_path',
        'signed_at',
    ];

    protected $casts = [
        'role' => HandoverSignatureRole::class,
        'signed_at' => 'datetime',
    ];

    public function handover(): BelongsTo
    {
        return $this->belongsTo(Handover::class, 'handover_id');
    }

    public function signedBy(): MorphTo
    {
        return $this->morphTo('signed_by');
    }
}
