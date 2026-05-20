<?php

namespace Modules\RecurringOrder\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\MorphTo;
use Illuminate\Database\Eloquent\SoftDeletes;
use Modules\Branch\Models\Branch;
use Modules\BranchManagers\Models\BranchManager;
use Modules\RecurringOrder\Enums\OrderSourceType;
use Modules\RecurringOrder\Enums\RecurringOrderStatus;
use Modules\RecurringOrder\Enums\RepeatFrequency;

class RecurringOrder extends Model
{
    use HasUuids, SoftDeletes;

    protected $table = 'recurring_orders';

    protected $fillable = [
        'branch_id',
        'created_by',
        'order_name',
        'order_source_type',
        'status',
        'sourceable_type',
        'sourceable_id',
        'message',
        'notification_channels',
        'repeat_frequency',
        'repeat_config',
        'scheduling_time_am',
        'scheduling_time_pm',
        'notification_options',
        'smart_settings',
        'start_date',
        'end_date',
        'end_type',
        'next_run_at',
        'paused_at',
    ];

    protected $casts = [
        'order_source_type' => OrderSourceType::class,
        'status' => RecurringOrderStatus::class,
        'repeat_frequency' => RepeatFrequency::class,
        'repeat_config' => 'array',
        'notification_channels' => 'array',
        'notification_options' => 'array',
        'smart_settings' => 'array',
        'start_date' => 'date',
        'end_date' => 'date',
        'next_run_at' => 'datetime',
        'paused_at' => 'datetime',
    ];

    public function branch(): BelongsTo
    {
        return $this->belongsTo(Branch::class);
    }

    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(BranchManager::class, 'created_by');
    }

    public function sourceable(): MorphTo
    {
        return $this->morphTo();
    }

    public function items(): HasMany
    {
        return $this->hasMany(RecurringOrderItem::class);
    }

    public function purchaseOrders(): HasMany
    {
        return $this->hasMany(\Modules\Purchase\Models\PurchaseOrder::class, 'recurring_order_id');
    }

    public function scopeByBranch($query, string $branchId)
    {
        return $query->where('branch_id', $branchId);
    }

    public function scopeByCreatedBy($query, string $branchManagerId)
    {
        return $query->where('created_by', $branchManagerId);
    }

    public function scopeInProgressList($query)
    {
        return $query->whereIn('status', [
            RecurringOrderStatus::GENERATED,
            RecurringOrderStatus::IN_PROGRESS,
        ]);
    }

    public function scopeNextSchedulingList($query)
    {
        return $query->where('status', RecurringOrderStatus::PENDING);
    }

    public function scopePausedList($query)
    {
        return $query->where('status', RecurringOrderStatus::PAUSED);
    }

    public function scopeSearch($query, ?string $search)
    {
        if (empty($search)) {
            return $query;
        }

        return $query->where('order_name', 'like', "%{$search}%");
    }
}
