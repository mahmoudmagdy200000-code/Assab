<?php

namespace Modules\Purchase\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Str;
use Modules\Purchase\Enums\QualityLevel;

class CompensatoryOrder extends Model
{
    use HasFactory, HasUuids, SoftDeletes;

    protected $fillable = [
        'order_number',
        'variance_id',
        'original_order_id',
        'new_order_id',
        'item_name',
        'item_logo',
        'quantity',
        'quality',
        'reorder_supplier_id',
        'reorder_source',
        'delivery_urgency_deadline',
        'photo_evidence',
        'additional_notes',
        'status',
        'created_by',
    ];

    protected $casts = [
        'quality' => QualityLevel::class,
        'quantity' => 'decimal:3',
        'photo_evidence' => 'array',
        'delivery_urgency_deadline' => 'date',
    ];

    protected $appends = [
        'item_logo_url',
        'is_pending',
        'is_completed',
    ];

    protected static function boot()
    {
        parent::boot();

        static::creating(function ($order) {
            if (empty($order->order_number)) {
                $order->order_number = static::generateOrderNumber();
            }
        });
    }

    // Relationships
    public function variance(): BelongsTo
    {
        return $this->belongsTo(PurchaseVariance::class, 'variance_id');
    }

    public function originalOrder(): BelongsTo
    {
        return $this->belongsTo(PurchaseOrder::class, 'original_order_id');
    }

    public function newOrder(): BelongsTo
    {
        return $this->belongsTo(PurchaseOrder::class, 'new_order_id');
    }

    public function reorderSupplier(): BelongsTo
    {
        return $this->belongsTo(PurchaseSupplier::class, 'reorder_supplier_id');
    }

    public function timelines(): MorphMany
    {
        return $this->morphMany(OrderTimeline::class, 'timelineable')->orderBy('occurred_at', 'desc');
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
        return $this->status === 'pending';
    }

    public function getIsCompletedAttribute(): bool
    {
        return $this->status === 'completed';
    }

    // Scopes
    public function scopeByVariance($query, string $varianceId)
    {
        return $query->where('variance_id', $varianceId);
    }

    public function scopeByOriginalOrder($query, string $orderId)
    {
        return $query->where('original_order_id', $orderId);
    }

    public function scopePending($query)
    {
        return $query->where('status', 'pending');
    }

    public function scopeCompleted($query)
    {
        return $query->where('status', 'completed');
    }

    // Methods
    public static function generateOrderNumber(): string
    {
        $date = now()->format('Ymd');
        $random = strtoupper(Str::random(4));
        return "CO-{$date}-{$random}";
    }

    public function markAsOrdered(string $newOrderId): void
    {
        $this->update([
            'new_order_id' => $newOrderId,
            'status' => 'ordered',
        ]);
    }

    public function markAsConfirmed(): void
    {
        $this->update(['status' => 'confirmed']);
    }

    public function markAsDelivered(): void
    {
        $this->update(['status' => 'delivered']);
    }

    public function complete(): void
    {
        $this->update(['status' => 'completed']);
        
        // Also resolve the variance
        $this->variance->resolve(auth()->id(), 'Compensatory order completed');
    }

    public function cancel(): void
    {
        $this->update(['status' => 'canceled']);
    }
}

