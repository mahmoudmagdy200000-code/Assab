<?php

namespace Modules\BrandOwner\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;
use Modules\Branch\Models\Branch;

class CashSalesTransferRequest extends Model
{
    use HasUuids, SoftDeletes;

    protected $table = 'cash_sales_transfer_requests';

    protected $fillable = [
        'sender_id',
        'sender_type',
        'branch_id',
        'brand_owner_id',
        'handover_amount',
        'handover_method',
        'handover_date',
        'additional_notes',
        'status',
        'approved_by',
        'approved_at',
        'rejected_by',
        'rejected_at',
        'rejection_reason',
    ];

    protected $casts = [
        'handover_amount' => 'decimal:2',
        'handover_date' => 'datetime',
        'approved_at' => 'datetime',
        'rejected_at' => 'datetime',
    ];

    public function branch(): BelongsTo
    {
        return $this->belongsTo(Branch::class);
    }

    public function brandOwner(): BelongsTo
    {
        return $this->belongsTo(BrandOwner::class, 'brand_owner_id');
    }

    public function isPending(): bool
    {
        return $this->status === 'pending';
    }
}
