<?php

namespace Modules\Purchase\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Str;

class PurchaseInvoice extends Model
{
    use HasFactory, HasUuids, SoftDeletes;

    protected $fillable = [
        'invoice_number',
        'goods_receipt_id',
        'purchase_order_id',
        'supplier_id',
        'invoice_date',
        'due_date',
        'payment_terms',
        'amount_before_tax',
        'tax_rate',
        'tax_amount',
        'total_amount',
        'deduction_amount',
        'final_amount',
        'file_path',
        'file_name',
        'file_type',
        'file_size',
        'status',
        'deduction_reason',
        'deduction_details',
    ];

    protected $casts = [
        'invoice_date' => 'date',
        'due_date' => 'date',
        'amount_before_tax' => 'decimal:2',
        'tax_rate' => 'decimal:2',
        'tax_amount' => 'decimal:2',
        'total_amount' => 'decimal:2',
        'deduction_amount' => 'decimal:2',
        'final_amount' => 'decimal:2',
        'file_size' => 'integer',
        'deduction_details' => 'array',
    ];

    protected $appends = [
        'file_url',
        'is_overdue',
        'days_until_due',
    ];

    protected static function boot()
    {
        parent::boot();

        static::creating(function ($invoice) {
            if (empty($invoice->invoice_number)) {
                $invoice->invoice_number = static::generateInvoiceNumber();
            }
            
            // Calculate due date if not provided
            if (empty($invoice->due_date) && $invoice->invoice_date) {
                $invoice->due_date = $invoice->invoice_date->addDays(30);
            }
        });
    }

    // Relationships
    public function goodsReceipt(): BelongsTo
    {
        return $this->belongsTo(GoodsReceipt::class);
    }

    public function purchaseOrder(): BelongsTo
    {
        return $this->belongsTo(PurchaseOrder::class);
    }

    public function supplier(): BelongsTo
    {
        return $this->belongsTo(\Modules\Supplier\Models\Supplier::class, 'supplier_id');
    }

    public function documents(): MorphMany
    {
        return $this->morphMany(OrderDocument::class, 'documentable');
    }

    public function timelines(): MorphMany
    {
        return $this->morphMany(OrderTimeline::class, 'timelineable');
    }

    // Accessors
    public function getFileUrlAttribute(): ?string
    {
        if (!$this->file_path) {
            return null;
        }
        
        return str_starts_with($this->file_path, 'http') 
            ? $this->file_path 
            : asset('storage/' . $this->file_path);
    }

    public function getIsOverdueAttribute(): bool
    {
        return $this->due_date && 
               $this->due_date->isPast() && 
               !in_array($this->status, ['paid', 'disputed']);
    }

    public function getDaysUntilDueAttribute(): ?int
    {
        if (!$this->due_date) {
            return null;
        }
        
        return now()->diffInDays($this->due_date, false);
    }

    // Scopes
    public function scopeBySupplier($query, string $supplierId)
    {
        return $query->where('supplier_id', $supplierId);
    }

    public function scopePending($query)
    {
        return $query->where('status', 'pending');
    }

    public function scopeOverdue($query)
    {
        return $query->where('due_date', '<', now())
                     ->whereNotIn('status', ['paid', 'disputed']);
    }

    public function scopePaid($query)
    {
        return $query->where('status', 'paid');
    }

    // Methods
    public static function generateInvoiceNumber(): string
    {
        $date = now()->format('Ymd');
        $random = strtoupper(Str::random(4));
        return "INV-{$date}-{$random}";
    }

    public function calculateAmounts(): void
    {
        $taxAmount = $this->amount_before_tax * ($this->tax_rate / 100);
        $totalAmount = $this->amount_before_tax + $taxAmount;
        $finalAmount = $totalAmount - ($this->deduction_amount ?? 0);
        
        $this->update([
            'tax_amount' => round($taxAmount, 2),
            'total_amount' => round($totalAmount, 2),
            'final_amount' => round($finalAmount, 2),
        ]);
    }

    public function applyDeduction(float $amount, string $reason, ?array $details = null): void
    {
        $this->update([
            'deduction_amount' => $amount,
            'deduction_reason' => $reason,
            'deduction_details' => $details,
        ]);
        
        $this->calculateAmounts();
    }

    public function markAsPaid(): void
    {
        $this->update(['status' => 'paid']);
    }

    public function dispute(): void
    {
        $this->update(['status' => 'disputed']);
    }

    public function uploadFile(string $path, string $name, string $type, int $size): void
    {
        $this->update([
            'file_path' => $path,
            'file_name' => $name,
            'file_type' => $type,
            'file_size' => $size,
        ]);
    }
}

