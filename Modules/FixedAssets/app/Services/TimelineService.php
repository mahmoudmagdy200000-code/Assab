<?php

namespace Modules\FixedAssets\Services;

use Illuminate\Database\Eloquent\Model;
use Modules\BranchManagers\Models\BranchManager;
use Modules\FixedAssets\Enums\TimelineEventType;
use Modules\FixedAssets\Models\Timeline;

class TimelineService
{
    public function log(
        Model $subject,
        TimelineEventType $eventType,
        string $name,
        ?BranchManager $actor = null,
    ): Timeline {
        return Timeline::create([
            'timelineable_type' => $subject->getMorphClass(),
            'timelineable_id' => $subject->getKey(),
            'event_type' => $eventType->value,
            'name' => $name,
            'actor_image_path' => $actor?->image,
            'actor_id' => $actor?->id,
            'occurred_at' => now(),
        ]);
    }
}
