<?php

namespace Modules\Inventory\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphTo;
use Modules\Inventory\Enums\WasteDamageReportTimelineEventType;

class WasteDamageReportTimeline extends Model
{
    use HasUuids;

    protected $table = 'waste_damage_report_timelines';

    protected $fillable = [
        'waste_damage_report_id',
        'event_type',
        'old_status',
        'new_status',
        'actor_id',
        'actor_type',
        'actor_name',
        'actor_image',
        'actor_role',
        'title',
        'description',
        'metadata',
        'occurred_at',
    ];

    protected $casts = [
        'event_type' => WasteDamageReportTimelineEventType::class,
        'metadata' => 'array',
        'occurred_at' => 'datetime',
    ];

    public function wasteDamageReport(): BelongsTo
    {
        return $this->belongsTo(WasteDamageReport::class, 'waste_damage_report_id');
    }

    public function actor(): MorphTo
    {
        return $this->morphTo('actor', 'actor_type', 'actor_id');
    }

    public static function log(
        WasteDamageReport $report,
        WasteDamageReportTimelineEventType $eventType,
        string $title,
        ?string $description = null,
        ?string $oldStatus = null,
        ?string $newStatus = null,
        ?array $metadata = null
    ): self {
        $actor = auth()->user();

        return static::create([
            'waste_damage_report_id' => $report->id,
            'event_type' => $eventType,
            'old_status' => $oldStatus,
            'new_status' => $newStatus,
            'actor_id' => $actor?->getKey(),
            'actor_type' => $actor ? $actor->getMorphClass() : null,
            'actor_name' => $actor?->name ?? null,
            'actor_image' => $actor?->image ?? null,
            'actor_role' => $actor ? class_basename($actor) : null,
            'title' => $title,
            'description' => $description,
            'metadata' => $metadata,
            'occurred_at' => now(),
        ]);
    }
}
