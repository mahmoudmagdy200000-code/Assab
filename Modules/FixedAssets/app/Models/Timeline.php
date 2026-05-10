<?php

namespace Modules\FixedAssets\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\MorphTo;
use Modules\FixedAssets\Enums\TimelineEventType;

class Timeline extends Model
{
    use HasUuids;

    protected $table = 'fixed_asset_timelines';

    protected $fillable = [
        'timelineable_type',
        'timelineable_id',
        'event_type',
        'name',
        'actor_image_path',
        'actor_id',
        'occurred_at',
    ];

    protected $casts = [
        'event_type' => TimelineEventType::class,
        'occurred_at' => 'datetime',
    ];

    public function timelineable(): MorphTo
    {
        return $this->morphTo('timelineable');
    }
}
