<?php

namespace Modules\Purchase\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\MorphMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Str;
use Modules\Branch\Models\Branch;
use Modules\BranchManagers\Models\BranchManager;
use Modules\Purchase\Enums\ReturnRequiredAction;
use Modules\Purchase\Enums\ReturnStatus;

class ReturnOrder extends Model
{
    use HasFactory, HasUuids, SoftDeletes;

    protected $fillable = [
        'return_number',
        'purchase_order_id',
        'supplier_id',
        'branch_id',
        'created_by',
        'return_date',
        'status',
        'required_action',
        'total_return_amount',
        'refund_amount',
        'refund_method',
        'additional_notes',
        'responded_by',
        'response_notes',
        'response_files',
        'responded_at',
        'rejection_reason',
        'rejected_at',
        'is_escalated',
        'escalation_reason',
        'escalated_to',
        'escalated_at',
        'resolution_type',
        'resolution_notes',
        'resolved_by',
        'resolved_at',
        'submitted_at',
        'approved_at',
        'closed_at',
    ];

    protected $casts = [
        'status' => ReturnStatus::class,
        'required_action' => ReturnRequiredAction::class,
        'return_date' => 'date',
        'total_return_amount' => 'decimal:2',
        'refund_amount' => 'decimal:2',
        'response_files' => 'array',
        'is_escalated' => 'boolean',
        'responded_at' => 'datetime',
        'rejected_at' => 'datetime',
        'escalated_at' => 'datetime',
        'resolved_at' => 'datetime',
        'submitted_at' => 'datetime',
        'approved_at' => 'datetime',
        'closed_at' => 'datetime',
    ];

    protected $appends = [
        'status_label',
        'status_color',
        'required_action_label',
        'is_draft',
        'is_pending',
        'is_completed',
    ];

    protected static function boot()
    {
        parent::boot();

        static::creating(function ($order) {
            if (empty($order->return_number)) {
                $order->return_number = static::generateReturnNumber();
            }
            if (empty($order->return_date)) {
                $order->return_date = now();
            }
        });
    }

    // Relationships
    public function purchaseOrder(): BelongsTo
    {
        return $this->belongsTo(PurchaseOrder::class);
    }

    public function supplier(): BelongsTo
    {
        return $this->belongsTo(\Modules\Supplier\Models\Supplier::class, 'supplier_id');
    }

    public function branch(): BelongsTo
    {
        return $this->belongsTo(Branch::class);
    }

    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(BranchManager::class, 'created_by');
    }

    public function items(): HasMany
    {
        return $this->hasMany(ReturnOrderItem::class);
    }

    public function timelines(): MorphMany
    {
        return $this->morphMany(OrderTimeline::class, 'timelineable')->orderBy('occurred_at', 'desc');
    }

    public function documents(): MorphMany
    {
        return $this->morphMany(OrderDocument::class, 'documentable');
    }

    // Accessors
    public function getStatusLabelAttribute(): string
    {
        return $this->status?->label() ?? 'Unknown';
    }

    public function getStatusColorAttribute(): string
    {
        return $this->status?->color() ?? '#6B7280';
    }

    public function getRequiredActionLabelAttribute(): ?string
    {
        return $this->required_action?->label();
    }

    public function getIsDraftAttribute(): bool
    {
        return $this->status === ReturnStatus::DRAFT;
    }

    public function getIsPendingAttribute(): bool
    {
        return $this->status?->isInProgress() ?? false;
    }

    public function getIsCompletedAttribute(): bool
    {
        return $this->status?->isCompleted() ?? false;
    }

    // Scopes
    public function scopeByOrder($query, string $orderId)
    {
        return $query->where('purchase_order_id', $orderId);
    }

    public function scopeBySupplier($query, string $supplierId)
    {
        return $query->where('supplier_id', $supplierId);
    }

    public function scopeByBranch($query, string $branchId)
    {
        return $query->where('branch_id', $branchId);
    }

    public function scopeByCreatedBy($query, string $userId)
    {
        return $query->where('created_by', $userId);
    }

    public function scopeDraft($query)
    {
        return $query->where('status', ReturnStatus::DRAFT);
    }

    public function scopeInProgress($query)
    {
        return $query->whereIn('status', [
            ReturnStatus::PENDING,
            ReturnStatus::APPROVED,
            ReturnStatus::REJECTED,
            ReturnStatus::ESCALATED,
        ]);
    }

    public function scopeCompleted($query)
    {
        return $query->whereIn('status', [
            ReturnStatus::CLOSED,
            ReturnStatus::RESOLVED,
        ]);
    }

    public function scopeEscalated($query)
    {
        return $query->where('is_escalated', true);
    }

    // Methods
    public static function generateReturnNumber(): string
    {
        $date = now()->format('Ymd');
        $random = strtoupper(Str::random(4));
        return "RO-{$date}-{$random}";
    }

    public function calculateTotalReturnAmount(): void
    {
        $total = $this->items()->sum('return_amount');
        $this->update(['total_return_amount' => $total]);
    }

    public function submit(): bool
    {
        if ($this->status !== ReturnStatus::DRAFT) {
            return false;
        }
        
        $this->update([
            'status' => ReturnStatus::PENDING,
            'submitted_at' => now(),
        ]);
        
        return true;
    }

    public function approve(string $respondedBy, ?float $refundAmount = null, ?string $refundMethod = null, ?array $files = null, ?string $notes = null): void
    {
        $this->update([
            'status' => ReturnStatus::APPROVED,
            'responded_by' => $respondedBy,
            'refund_amount' => $refundAmount,
            'refund_method' => $refundMethod,
            'response_files' => $files,
            'response_notes' => $notes,
            'responded_at' => now(),
            'approved_at' => now(),
        ]);
    }

    public function reject(string $respondedBy, string $reason): void
    {
        $this->update([
            'status' => ReturnStatus::REJECTED,
            'responded_by' => $respondedBy,
            'rejection_reason' => $reason,
            'responded_at' => now(),
            'rejected_at' => now(),
        ]);
    }

    public function acceptRejection(): void
    {
        $this->update([
            'status' => ReturnStatus::CLOSED,
            'closed_at' => now(),
        ]);
    }

    public function escalate(string $reason, string $escalatedTo): void
    {
        $this->update([
            'is_escalated' => true,
            'escalation_reason' => $reason,
            'escalated_to' => $escalatedTo,
            'escalated_at' => now(),
            'status' => ReturnStatus::ESCALATED,
        ]);
    }

    public function resolve(string $resolvedBy, string $resolutionType, ?string $notes = null): void
    {
        $this->update([
            'status' => ReturnStatus::RESOLVED,
            'resolution_type' => $resolutionType,
            'resolution_notes' => $notes,
            'resolved_by' => $resolvedBy,
            'resolved_at' => now(),
        ]);
    }

    public function close(): void
    {
        $this->update([
            'status' => ReturnStatus::CLOSED,
            'closed_at' => now(),
        ]);
    }
}

