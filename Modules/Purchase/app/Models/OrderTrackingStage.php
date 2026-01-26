<?php

namespace Modules\Purchase\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

class OrderTrackingStage extends Model
{
    use HasFactory, HasUuids, SoftDeletes;

    protected $fillable = [
        'purchase_order_id',
        'stage_type',
        'stage_data',
        'started_at',
        'completed_at',
        'created_by',
        'created_by_type',
    ];

    protected $casts = [
        'stage_data' => 'array',
        'started_at' => 'datetime',
        'completed_at' => 'datetime',
    ];

    // Relationships
    public function purchaseOrder(): BelongsTo
    {
        return $this->belongsTo(PurchaseOrder::class);
    }

    // Scopes
    public function scopeByType($query, string $stageType)
    {
        return $query->where('stage_type', $stageType);
    }

    public function scopeByOrder($query, string $orderId)
    {
        return $query->where('purchase_order_id', $orderId);
    }

    public function scopeActive($query)
    {
        return $query->whereNull('completed_at');
    }

    public function scopeCompleted($query)
    {
        return $query->whereNotNull('completed_at');
    }

    public function scopeOrdered($query)
    {
        return $query->orderBy('started_at', 'asc');
    }

    // Methods
    public function markAsCompleted(): bool
    {
        return $this->update(['completed_at' => now()]);
    }

    public function isCompleted(): bool
    {
        return $this->completed_at !== null;
    }

    public function isActive(): bool
    {
        return $this->completed_at === null;
    }
}
