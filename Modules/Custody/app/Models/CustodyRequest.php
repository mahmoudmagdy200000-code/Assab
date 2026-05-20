<?php

namespace Modules\Custody\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Modules\Branch\Models\Branch;
use Modules\BranchManagers\Models\BranchManager;

class CustodyRequest extends Model
{
    use HasUuids, SoftDeletes;

    protected $fillable = [
        'branch_manager_id',
        'branch_id',
        'created_by_brand_owner_id',
        'recipient_employee_id',
        'requested_amount',
        'purpose',
        'preferred_receipt_method',
        'additional_notes',
        'handover_date',
        'transfer_date',
        'status',
        'approved_by',
        'approved_by_type',
        'approved_at',
        'rejected_by',
        'rejected_by_type',
        'rejected_at',
        'rejection_reason',
        'viewed_at',
    ];

    protected $casts = [
        'requested_amount' => 'decimal:2',
        'approved_at' => 'datetime',
        'rejected_at' => 'datetime',
        'viewed_at' => 'datetime',
        'handover_date' => 'datetime',
        'transfer_date' => 'datetime',
    ];

    // Relationships
    public function branchManager(): BelongsTo
    {
        return $this->belongsTo(BranchManager::class);
    }

    public function branch(): BelongsTo
    {
        return $this->belongsTo(Branch::class);
    }

    public function approvedBy(): BelongsTo
    {
        return $this->belongsTo(BranchManager::class, 'approved_by');
    }

    public function rejectedBy(): BelongsTo
    {
        return $this->belongsTo(BranchManager::class, 'rejected_by');
    }

    public function attachments(): HasMany
    {
        return $this->hasMany(CustodyRequestAttachment::class);
    }

    public function timeline(): HasMany
    {
        return $this->hasMany(CustodyRequestTimeline::class, 'custody_request_id')->orderBy('action_date', 'asc');
    }
}
