<?php

namespace Modules\Purchase\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Database\Eloquent\Relations\MorphMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Str;
use Modules\Branch\Models\Branch;
use Modules\BranchManagers\Models\BranchManager;
use Modules\Purchase\Enums\DocumentType;

class GoodsReceipt extends Model
{
    use HasFactory, HasUuids, SoftDeletes;

    protected $fillable = [
        'receipt_number',
        'purchase_order_id',
        'branch_id',
        'received_by',
        'status',
        'driver_name',
        'driver_contact',
        'driver_image',
        'vehicle_number',
        'arrival_time',
        'delivery_address',
        'delivery_notes',
        'total_items_expected',
        'total_items_received',
        'quantity_variances',
        'quality_variances',
        'expected_amount',
        'received_amount',
        'variance_amount',
        'document_type',
        'inspection_started_at',
        'inspection_completed_at',
    ];

    protected $casts = [
        'document_type' => DocumentType::class,
        'arrival_time' => 'datetime',
        'total_items_expected' => 'integer',
        'total_items_received' => 'integer',
        'quantity_variances' => 'integer',
        'quality_variances' => 'integer',
        'expected_amount' => 'decimal:2',
        'received_amount' => 'decimal:2',
        'variance_amount' => 'decimal:2',
        'inspection_started_at' => 'datetime',
        'inspection_completed_at' => 'datetime',
    ];

    protected $appends = [
        'has_variances',
        'is_draft',
        'is_completed',
    ];

    protected static function boot()
    {
        parent::boot();

        static::creating(function ($receipt) {
            if (empty($receipt->receipt_number)) {
                $receipt->receipt_number = static::generateReceiptNumber();
            }
        });
    }

    // Relationships
    public function purchaseOrder(): BelongsTo
    {
        return $this->belongsTo(PurchaseOrder::class);
    }

    public function branch(): BelongsTo
    {
        return $this->belongsTo(Branch::class);
    }

    public function receivedBy(): BelongsTo
    {
        return $this->belongsTo(BranchManager::class, 'received_by');
    }

    public function items(): HasMany
    {
        return $this->hasMany(GoodsReceiptItem::class);
    }

    public function invoice(): HasOne
    {
        return $this->hasOne(PurchaseInvoice::class);
    }

    public function variances(): HasMany
    {
        return $this->hasMany(PurchaseVariance::class);
    }

    public function timelines(): MorphMany
    {
        return $this->morphMany(OrderTimeline::class, 'timelineable')->orderBy('occurred_at', 'asc');
    }

    public function documents(): MorphMany
    {
        return $this->morphMany(OrderDocument::class, 'documentable');
    }

    // Accessors
    public function getHasVariancesAttribute(): bool
    {
        // Check if there are actual variance records, not just calculated variances
        // This ensures variances are only considered if they were explicitly reported
        return $this->variances()->exists();
    }

    public function getIsDraftAttribute(): bool
    {
        return $this->status === 'draft';
    }

    public function getIsCompletedAttribute(): bool
    {
        return $this->status === 'completed';
    }

    // Scopes
    public function scopeByOrder($query, string $orderId)
    {
        return $query->where('purchase_order_id', $orderId);
    }

    public function scopeByBranch($query, string $branchId)
    {
        return $query->where('branch_id', $branchId);
    }

    public function scopeDraft($query)
    {
        return $query->where('status', 'draft');
    }

    public function scopeInProgress($query)
    {
        return $query->where('status', 'in_progress');
    }

    public function scopeCompleted($query)
    {
        return $query->where('status', 'completed');
    }

    public function scopeWithVariances($query)
    {
        return $query->where('status', 'has_variance');
    }

    // Methods
    public static function generateReceiptNumber(): string
    {
        $date = now()->format('Ymd');
        $random = strtoupper(Str::random(4));

        return "GR-{$date}-{$random}";
    }

    public function startInspection(): void
    {
        $this->update([
            'status' => 'in_progress',
            'inspection_started_at' => now(),
        ]);
    }

    public function completeInspection(): void
    {
        $this->calculateSummary();

        $this->update([
            'status' => $this->hasVariances ? 'has_variance' : 'completed',
            'inspection_completed_at' => now(),
        ]);
    }

    public function calculateSummary(): void
    {
        $items = $this->items;

        $quantityVariances = $items->filter(fn ($item) => $item->quantity_variance != 0)->count();
        $qualityVariances = $items->filter(fn ($item) => $item->has_quality_variance)->count();

        $expectedAmount = $items->sum('expected_total');
        $receivedAmount = $items->sum('received_total');
        $varianceAmount = $expectedAmount - $receivedAmount;

        $this->update([
            'total_items_expected' => $items->count(),
            'total_items_received' => $items->whereNotNull('quantity_received')->count(),
            'quantity_variances' => $quantityVariances,
            'quality_variances' => $qualityVariances,
            'expected_amount' => $expectedAmount,
            'received_amount' => $receivedAmount,
            'variance_amount' => abs($varianceAmount),
        ]);
    }

    public function setDocumentType(DocumentType $type): void
    {
        $this->update(['document_type' => $type]);
    }

    public function saveDraft(): void
    {
        $this->update(['status' => 'draft']);
    }
}
