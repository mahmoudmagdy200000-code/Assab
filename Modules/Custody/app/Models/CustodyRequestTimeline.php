<?php

namespace Modules\Custody\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphTo;

class CustodyRequestTimeline extends Model
{
    use HasUuids;

    protected $table = 'custody_request_timeline';

    protected $fillable = [
        'custody_request_id',
        'stage',
        'status',
        'actor_id',
        'actor_type',
        'actor_name',
        'actor_profile_image',
        'action_date',
        'notes',
    ];

    protected $casts = [
        'action_date' => 'datetime',
    ];

    // Relationships
    public function custodyRequest(): BelongsTo
    {
        return $this->belongsTo(CustodyRequest::class);
    }

    public function actor(): MorphTo
    {
        return $this->morphTo('actor');
    }
}
