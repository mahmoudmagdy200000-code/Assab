<?php

namespace Modules\Purchase\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Database\Eloquent\Relations\MorphMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Modules\Purchase\Enums\InspectionQuality;
use Modules\Purchase\Enums\QualityLevel;
use Modules\Purchase\Enums\VarianceAction;
use Modules\Purchase\Enums\VarianceType;

class PurchaseVariance extends Model
{
    use HasFactory, HasUuids, SoftDeletes;

    protected $fillable = [
        'goods_receipt_id',
        'goods_receipt_item_id',
        'purchase_order_id',
        'item_name',
        'item_logo',
        'variance_type',
        'quantity_ordered',
        'quantity_received',
        'quantity_variance',
        'quality_ordered',
        'quality_received',
        'unit_price',
        'variance_amount',
        'action',
        'status',
        'responded_by',
        'supplier_response',
        'responded_at',
        'is_escalated',
        'escalation_reason',
        'escalated_to',
        'escalated_at',
        'resolution_notes',
        'resolved_by',
        'resolved_at',
        'photo_evidence',
        'additional_notes',
        'amount_to_deduct',
        'deduction_reason',
    ];

    protected $casts = [
        'variance_type' => VarianceType::class,
        'quality_ordered' => QualityLevel::class,
        'quality_received' => InspectionQuality::class,
        'action' => VarianceAction::class,
        'quantity_ordered' => 'decimal:3',
        'quantity_received' => 'decimal:3',
        'quantity_variance' => 'decimal:3',
        'unit_price' => 'decimal:2',
        'variance_amount' => 'decimal:2',
        'amount_to_deduct' => 'decimal:2',
        'photo_evidence' => 'array',
        'is_escalated' => 'boolean',
        'responded_at' => 'datetime',
        'escalated_at' => 'datetime',
        'resolved_at' => 'datetime',
    ];

    protected $appends = [
        'item_logo_url',
        'is_pending',
        'is_resolved',
        'action_label',
    ];

    // Relationships
    public function goodsReceipt(): BelongsTo
    {
        return $this->belongsTo(GoodsReceipt::class);
    }

    public function goodsReceiptItem(): BelongsTo
    {
        return $this->belongsTo(GoodsReceiptItem::class);
    }

    public function purchaseOrder(): BelongsTo
    {
        return $this->belongsTo(PurchaseOrder::class);
    }

    public function compensatoryOrder(): HasOne
    {
        return $this->hasOne(CompensatoryOrder::class, 'variance_id');
    }

    public function timelines(): MorphMany
    {
        return $this->morphMany(OrderTimeline::class, 'timelineable')->orderBy('occurred_at', 'asc');
    }

    // Accessors
    public function getItemLogoUrlAttribute(): ?string
    {
        if (!$this->item_logo) {
            return null;
        }
        
        return str_starts_with($this->item_logo, 'http') 
            ? $this->item_logo 
            : asset('storage/' . $this->item_logo);
    }

    public function getIsPendingAttribute(): bool
    {
        return in_array($this->status, ['pending', 'reported']);
    }

    public function getIsResolvedAttribute(): bool
    {
        return in_array($this->status, ['resolved', 'closed']);
    }

    public function getActionLabelAttribute(): ?string
    {
        return $this->action?->label();
    }

    // Scopes
    public function scopeByOrder($query, string $orderId)
    {
        return $query->where('purchase_order_id', $orderId);
    }

    public function scopeByReceipt($query, string $receiptId)
    {
        return $query->where('goods_receipt_id', $receiptId);
    }

    public function scopePending($query)
    {
        return $query->whereIn('status', ['pending', 'reported']);
    }

    public function scopeEscalated($query)
    {
        return $query->where('is_escalated', true);
    }

    public function scopeResolved($query)
    {
        return $query->whereIn('status', ['resolved', 'closed']);
    }

    public function scopeByType($query, VarianceType $type)
    {
        return $query->where('variance_type', $type);
    }

    // Methods
    public function acceptAsIs(): void
    {
        $this->update([
            'action' => VarianceAction::ACCEPT,
            'status' => 'closed',
            'resolved_at' => now(),
        ]);
    }

    public function createCompensatoryOrder(array $data): CompensatoryOrder
    {
        $this->update([
            'action' => VarianceAction::COMPENSATORY_ORDER,
            'status' => 'pending',
        ]);
        
        // Store items list in additional_notes or create a separate field
        // For now, we'll store items as JSON in additional_notes along with notes
        $notesData = [
            'notes' => $data['notes'] ?? null,
            'items' => $data['items'] ?? [],
        ];
        
        return CompensatoryOrder::create([
            'variance_id' => $this->id,
            'original_order_id' => $this->purchase_order_id,
            'item_name' => $this->item_name,
            'item_logo' => $this->item_logo,
            'quantity' => $this->quantity_variance,
            'quality' => $this->quality_ordered,
            'reorder_supplier_id' => null, // Will be set later when creating the order
            'reorder_source' => null, // Will be set later
            'delivery_urgency_deadline' => now()->addDays(7), // Default deadline, can be updated later
            'photo_evidence' => $data['photos'] ?? null,
            'additional_notes' => json_encode($notesData), // Store items and notes as JSON
            'created_by' => \Illuminate\Support\Facades\Auth::id(),
            'status' => 'pending',
        ]);
    }

    public function deductFromInvoice(float $amount, string $reason, ?string $notes = null): void
    {
        $this->update([
            'action' => VarianceAction::DEDUCT_FROM_INVOICE,
            'amount_to_deduct' => $amount,
            'deduction_reason' => $reason,
            'additional_notes' => $notes,
            'status' => 'reported',
        ]);
    }

    public function supplierApprove(string $respondedBy, ?string $response = null): void
    {
        $this->update([
            'status' => 'supplier_approved',
            'responded_by' => $respondedBy,
            'supplier_response' => $response,
            'responded_at' => now(),
        ]);
    }

    public function supplierReject(string $respondedBy, string $reason): void
    {
        $this->update([
            'status' => 'supplier_rejected',
            'responded_by' => $respondedBy,
            'supplier_response' => $reason,
            'responded_at' => now(),
        ]);
    }

    public function escalate(string $reason, string $escalatedTo): void
    {
        $this->update([
            'is_escalated' => true,
            'escalation_reason' => $reason,
            'escalated_to' => $escalatedTo,
            'escalated_at' => now(),
            'status' => 'escalated',
        ]);
    }

    public function resolve(string $resolvedBy, ?string $notes = null): void
    {
        $this->update([
            'status' => 'resolved',
            'resolved_by' => $resolvedBy,
            'resolution_notes' => $notes,
            'resolved_at' => now(),
        ]);
    }
}

