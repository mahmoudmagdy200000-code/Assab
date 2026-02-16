<?php

namespace Modules\Inventory\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphTo;
use Modules\Inventory\Enums\DailyInventoryTimelineEventType;

class InventorySessionTimeline extends Model
{
    use HasUuids;

    protected $table = 'inventory_session_timelines';

    protected $fillable = [
        'inventory_session_id',
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
        'event_type' => DailyInventoryTimelineEventType::class,
        'metadata' => 'array',
        'occurred_at' => 'datetime',
    ];

    protected $appends = [
        'event_label',
        'event_icon',
    ];

    public function inventorySession(): BelongsTo
    {
        return $this->belongsTo(InventorySession::class);
    }

    public function actor(): MorphTo
    {
        return $this->morphTo('actor', 'actor_type', 'actor_id');
    }

    public function getEventLabelAttribute(): ?string
    {
        return $this->event_type?->label();
    }

    public function getEventIconAttribute(): ?string
    {
        return $this->event_type?->icon();
    }

    public static function log(
        InventorySession $session,
        DailyInventoryTimelineEventType $eventType,
        string $title,
        ?string $description = null,
        ?string $oldStatus = null,
        ?string $newStatus = null,
        ?array $metadata = null
    ): self {
        $actor = auth()->user();

        return static::create([
            'inventory_session_id' => $session->id,
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
