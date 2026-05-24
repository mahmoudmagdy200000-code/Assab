<?php

namespace Modules\FixedAssets\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Modules\Branch\Models\Branch;
use Modules\FixedAssets\Enums\RequestStatus;

class MajorDiscrepancyRequest extends Model
{
    use HasUuids, SoftDeletes;

    protected $table = 'fixed_asset_major_discrepancy_requests';

    protected $fillable = [
        'handover_id',
        'handover_item_id',
        'asset_id',
        'branch_id',
        'status',
        'employee_responsible',
        'warning_note',
        'salary_deduction_amount',
        'salary_deduction_reason',
        'salary_deduction_note',
        'rejection_reason',
        'cancellation',
        'bo_decided_by_id',
        'bo_decided_at',
    ];

    protected $casts = [
        'status' => RequestStatus::class,
        'salary_deduction_amount' => 'decimal:2',
        'cancellation' => 'array',
        'bo_decided_at' => 'datetime',
    ];

    public function handover(): BelongsTo
    {
        return $this->belongsTo(Handover::class, 'handover_id');
    }

    public function handoverItem(): BelongsTo
    {
        return $this->belongsTo(HandoverItem::class, 'handover_item_id');
    }

    public function asset(): BelongsTo
    {
        return $this->belongsTo(FixedAsset::class, 'asset_id');
    }

    public function branch(): BelongsTo
    {
        return $this->belongsTo(Branch::class, 'branch_id');
    }

    public function timelines(): MorphMany
    {
        return $this->morphMany(Timeline::class, 'timelineable');
    }
}
