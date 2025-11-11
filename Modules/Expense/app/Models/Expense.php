<?php

namespace Modules\Expense\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\SoftDeletes;
use Modules\BranchManagers\Models\BranchManager;

/**
 * Main Expense Model
 */
class Expense extends Model
{
    use HasFactory, SoftDeletes , HasUuids;

    protected $fillable = [
        'branch_manager_id',
        'expense_type',
        'status',
        'total_amount',
        'net_amount',
        'vat_amount',
        'payment_method',
        'supplier_id',
        'submitted_at',
        'approved_by',
        'approved_at',
        'rejected_by',
        'rejected_at',
        'rejection_reason',
    ];

    protected $casts = [
        'total_amount' => 'decimal:2',
        'net_amount' => 'decimal:2',
        'vat_amount' => 'decimal:2',
        'submitted_at' => 'datetime',
        'approved_at' => 'datetime',
        'rejected_at' => 'datetime',
    ];

    // Relationships
    public function branchManager()
    {
        return $this->belongsTo(BranchManager::class, 'branch_manager_id');
    }

    public function supplier()
    {
        return $this->belongsTo(Supplier::class);
    }

    public function quickCashExpense()
    {
        return $this->hasOne(QuickCashExpense::class);
    }

    public function invoiceDetails()
    {
        return $this->hasMany(InvoiceDetail::class);
    }

    public function groupedInvoice()
    {
        return $this->hasOne(GroupedInvoice::class);
    }

    public function preApprovalRequest()
    {
        return $this->hasOne(PreApprovalRequest::class);
    }

    public function items()
    {
        return $this->hasMany(ExpenseItem::class);
    }

    public function expenseLines()
    {
        return $this->hasMany(ExpenseLine::class);
    }

    public function attachments()
    {
        return $this->hasMany(ExpenseAttachment::class);
    }

    public function timelines()
    {
        return $this->hasMany(ExpenseTimeline::class);
    }

    // Scopes
    public function scopeDraft($query)
    {
        return $query->where('status', 'draft');
    }

    public function scopePending($query)
    {
        return $query->where('status', 'pending');
    }

    public function scopeApproved($query)
    {
        return $query->where('status', 'approved');
    }

    public function scopeRejected($query)
    {
        return $query->where('status', 'rejected');
    }

    public function scopeQuickCash($query)
    {
        return $query->where('expense_type', 'quick_cash');
    }

    public function scopeSingleInvoice($query)
    {
        return $query->where('expense_type', 'single_invoice');
    }

    public function scopeGroupedInvoice($query)
    {
        return $query->where('expense_type', 'grouped_invoice');
    }

    public function scopePreApproval($query)
    {
        return $query->where('expense_type', 'pre_approval');
    }

    // Helper Methods
    public function isDraft(): bool
    {
        return $this->status === 'draft';
    }

    public function isPending(): bool
    {
        return $this->status === 'pending';
    }

    public function isApproved(): bool
    {
        return $this->status === 'approved';
    }

    public function isRejected(): bool
    {
        return $this->status === 'rejected';
    }
}
