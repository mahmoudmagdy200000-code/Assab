<?php

namespace Modules\Purchase\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\MorphTo;
use Modules\Purchase\Enums\TimelineEventType;

class OrderTimeline extends Model
{
    use HasFactory, HasUuids;

    protected $fillable = [
        'timelineable_type',
        'timelineable_id',
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
        'attachments',
        'ip_address',
        'user_agent',
        'occurred_at',
    ];

    protected $casts = [
        'event_type' => TimelineEventType::class,
        'metadata' => 'array',
        'attachments' => 'array',
        'occurred_at' => 'datetime',
    ];

    protected $appends = [
        'event_label',
        'event_icon',
        'actor_image_url',
    ];

    // Relationships
    public function timelineable(): MorphTo
    {
        return $this->morphTo();
    }

    public function actor(): MorphTo
    {
        return $this->morphTo('actor');
    }

    // Accessors
    public function getEventLabelAttribute(): ?string
    {
        return $this->event_type?->label();
    }

    public function getEventIconAttribute(): ?string
    {
        return $this->event_type?->icon();
    }

    public function getActorImageUrlAttribute(): ?string
    {
        if (! $this->actor_image) {
            return null;
        }

        return str_starts_with($this->actor_image, 'http')
            ? $this->actor_image
            : asset('storage/'.$this->actor_image);
    }

    // Scopes
    public function scopeByType($query, TimelineEventType $type)
    {
        return $query->where('event_type', $type);
    }

    public function scopeByActor($query, string $actorId)
    {
        return $query->where('actor_id', $actorId);
    }

    public function scopeRecent($query, int $days = 7)
    {
        return $query->where('occurred_at', '>=', now()->subDays($days));
    }

    // Static Methods
    public static function log(
        Model $model,
        TimelineEventType $eventType,
        string $title,
        ?string $description = null,
        ?string $oldStatus = null,
        ?string $newStatus = null,
        ?array $metadata = null,
        ?array $attachments = null
    ): self {
        $actor = auth()->user();

        return static::create([
            'timelineable_type' => get_class($model),
            'timelineable_id' => $model->id,
            'event_type' => $eventType,
            'old_status' => $oldStatus,
            'new_status' => $newStatus,
            'actor_id' => $actor?->id,
            'actor_type' => $actor ? get_class($actor) : null,
            'actor_name' => $actor?->name,
            'actor_image' => $actor?->image ?? $actor?->profile_image,
            'actor_role' => class_basename($actor),
            'title' => $title,
            'description' => $description,
            'metadata' => $metadata,
            'attachments' => $attachments,
            'ip_address' => request()->ip(),
            'user_agent' => request()->userAgent(),
            'occurred_at' => now(),
        ]);
    }
}
