<?php

namespace Modules\Expense\Models;


use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Support\Facades\Storage;
use Modules\Branch\Models\Branch;
use Modules\BranchManagers\Models\BranchManager;

class Expense extends Model
{
    use SoftDeletes;

    protected $fillable = [
        'branch_id', 'branch_manager_id', 'expense_name', 'expense_type',
        'total_amount', 'net_amount', 'vat_amount', 'has_vat', 'expense_date',
        'status', 'payment_method', 'payment_type', 'supplier_id', 'amount_paid',
        'credit_due_date', 'invoice_number', 'tax_id', 'issue_date',
        'is_tax_invoice', 'rejection_reason', 'rejected_by', 'rejected_at',
        'approved_by', 'approved_at', 'viewed_by', 'viewed_at'
    ];

    protected $casts = [
        'total_amount' => 'decimal:2',
        'net_amount' => 'decimal:2',
        'vat_amount' => 'decimal:2',
        'amount_paid' => 'decimal:2',
        'has_vat' => 'boolean',
        'is_tax_invoice' => 'boolean',
        'expense_date' => 'date',
        'issue_date' => 'date',
        'credit_due_date' => 'date',
        'rejected_at' => 'datetime',
        'approved_at' => 'datetime',
        'viewed_at' => 'datetime',
    ];

    // Relationships
    public function branch(): BelongsTo
    {
        return $this->belongsTo(Branch::class);
    }

    public function branchManager(): BelongsTo
    {
        return $this->belongsTo(BranchManager::class, 'branch_manager_id');
    }

    public function supplier(): BelongsTo
    {
        return $this->belongsTo(Supplier::class);
    }

    public function rejectedBy(): BelongsTo
    {
        return $this->belongsTo(BranchManager::class, 'rejected_by');
    }

    public function approvedBy(): BelongsTo
    {
        return $this->belongsTo(BranchManager::class, 'approved_by');
    }

    public function viewedBy(): BelongsTo
    {
        return $this->belongsTo(BranchManager::class, 'viewed_by');
    }

    public function quickCashExpense(): HasOne
    {
        return $this->hasOne(QuickCashExpense::class);
    }

    public function preApprovalRequest(): HasOne
    {
        return $this->hasOne(PreApprovalRequest::class);
    }

    public function groupedInvoice(): HasOne
    {
        return $this->hasOne(GroupedInvoice::class);
    }

    public function invoiceDetails(): HasMany
    {
        return $this->hasMany(InvoiceDetail::class);
    }

    public function items(): HasMany
    {
        return $this->hasMany(ExpenseItem::class);
    }

    public function expenseLines(): HasMany
    {
        return $this->hasMany(ExpenseLine::class);
    }

    public function quickCashItems(): HasMany
    {
        return $this->hasMany(QuickCashItem::class);
    }

    public function attachments(): HasMany
    {
        return $this->hasMany(ExpenseAttachment::class);
    }

    public function timelines(): HasMany
    {
        return $this->hasMany(ExpenseTimeline::class);
    }

    // Scopes
    public function scopeByType($query, $type)
    {
        return $query->where('expense_type', $type);
    }

    public function scopeByStatus($query, $status)
    {
        return $query->where('status', $status);
    }

    public function scopeByMonth($query, $year, $month)
    {
        return $query->whereYear('expense_date', $year)
                    ->whereMonth('expense_date', $month);
    }

    public function scopeRecentExpenses($query, $limit = 10)
    {
        return $query->orderBy('created_at', 'desc')->limit($limit);
    }

    public function scopeForBranch($query, $branchId)
    {
        return $query->where('branch_id', $branchId);
    }

    // Accessors
    public function getIsQuickCashAttribute(): bool
    {
        return $this->expense_type === 'quick_cash';
    }

    public function getIsSingleInvoiceAttribute(): bool
    {
        return $this->expense_type === 'single_invoice';
    }

    public function getIsGroupedInvoicesAttribute(): bool
    {
        return $this->expense_type === 'grouped_invoices';
    }

    public function getIsPreApprovalAttribute(): bool
    {
        return $this->expense_type === 'pre_approval';
    }

    public function getIsApprovedAttribute(): bool
    {
        return $this->status === 'approved';
    }

    public function getIsRejectedAttribute(): bool
    {
        return $this->status === 'rejected';
    }

    public function getIsPendingAttribute(): bool
    {
        return $this->status === 'pending';
    }

    public function getIsDraftAttribute(): bool
    {
        return $this->status === 'draft';
    }

    // Methods
    public function calculateVat(): void
    {
        if ($this->has_vat) {
            $this->vat_amount = $this->total_amount * 0.15;
            $this->net_amount = $this->total_amount;
            $this->total_amount = $this->net_amount + $this->vat_amount;
        }
    }

    public function submit(): void
    {
        $this->status = 'pending';
        $this->save();

        $this->timelines()->create([
            'branch_manager_id' => $this->branch_manager_id,
            'action' => 'submit',
            'status' => 'submitted'
        ]);
    }

    public function approve($userId): void
    {
        $this->status = 'approved';
        $this->approved_by = $userId;
        $this->approved_at = now();
        $this->save();

        $this->timelines()->create([
            'branch_manager_id' => $userId,
            'action' => 'approve',
            'status' => 'approved'
        ]);
    }

    public function reject($userId, $reason): void
    {
        $this->status = 'rejected';
        $this->rejected_by = $userId;
        $this->rejected_at = now();
        $this->rejection_reason = $reason;
        $this->save();

        $this->timelines()->create([
            'branch_manager_id' => $userId,
            'action' => 'reject',
            'status' => 'rejected',
            'note' => $reason
        ]);
    }

    public function markAsViewed($userId): void
    {
        if (!$this->viewed_by) {
            $this->viewed_by = $userId;
            $this->viewed_at = now();
            $this->save();

            $this->timelines()->create([
                'branch_manager_id' => $userId,
                'action' => 'view',
                'status' => 'viewed'
            ]);
        }
    }

    public function resubmit(): void
    {
        $this->status = 'pending';
        $this->rejection_reason = null;
        $this->rejected_by = null;
        $this->rejected_at = null;
        $this->save();

        $this->timelines()->create([
            'branch_manager_id' => $this->branch_manager_id,
            'action' => 'resubmit',
            'status' => 'resubmitted'
        ]);
    }
}

class QuickCashExpense extends Model
{
    protected $fillable = ['expense_id', 'custody_balance'];

    protected $casts = [
        'custody_balance' => 'decimal:2'
    ];

    public function expense(): BelongsTo
    {
        return $this->belongsTo(Expense::class);
    }
}

class PreApprovalRequest extends Model
{
    protected $fillable = ['expense_id', 'purpose', 'estimated_amount', 'priority'];

    protected $casts = [
        'estimated_amount' => 'decimal:2'
    ];

    public function expense(): BelongsTo
    {
        return $this->belongsTo(Expense::class);
    }
}

class GroupedInvoice extends Model
{
    protected $fillable = ['expense_id', 'number_of_suppliers', 'total_invoices'];

    public function expense(): BelongsTo
    {
        return $this->belongsTo(Expense::class);
    }
}

class InvoiceDetail extends Model
{
    protected $fillable = [
        'expense_id', 'grouped_invoice_id', 'supplier_id', 'invoice_number',
        'issue_date', 'tax_id', 'is_tax_invoice', 'net_amount', 'vat_amount',
        'total_amount'
    ];

    protected $casts = [
        'net_amount' => 'decimal:2',
        'vat_amount' => 'decimal:2',
        'total_amount' => 'decimal:2',
        'is_tax_invoice' => 'boolean',
        'issue_date' => 'date'
    ];

    public function expense(): BelongsTo
    {
        return $this->belongsTo(Expense::class);
    }

    public function groupedInvoice(): BelongsTo
    {
        return $this->belongsTo(GroupedInvoice::class);
    }

    public function supplier(): BelongsTo
    {
        return $this->belongsTo(Supplier::class);
    }

    public function items(): HasMany
    {
        return $this->hasMany(ExpenseItem::class);
    }

    public function expenseLines(): HasMany
    {
        return $this->hasMany(ExpenseLine::class);
    }

    public function attachments(): HasMany
    {
        return $this->hasMany(ExpenseAttachment::class);
    }
}

class ExpenseItem extends Model
{
    protected $fillable = [
        'expense_id', 'invoice_detail_id', 'item_name', 'category_id',
        'subcategory_id', 'quantity', 'unit_price', 'total_amount'
    ];

    protected $casts = [
        'unit_price' => 'decimal:2',
        'total_amount' => 'decimal:2',
        'quantity' => 'integer'
    ];

    public function expense(): BelongsTo
    {
        return $this->belongsTo(Expense::class);
    }

    public function invoiceDetail(): BelongsTo
    {
        return $this->belongsTo(InvoiceDetail::class);
    }

    public function category(): BelongsTo
    {
        return $this->belongsTo(Category::class);
    }

    public function subcategory(): BelongsTo
    {
        return $this->belongsTo(Category::class, 'subcategory_id');
    }
}

class ExpenseLine extends Model
{
    protected $fillable = [
        'expense_id', 'invoice_detail_id', 'expense_name', 'category_id',
        'subcategory_id', 'price'
    ];

    protected $casts = [
        'price' => 'decimal:2'
    ];

    public function expense(): BelongsTo
    {
        return $this->belongsTo(Expense::class);
    }

    public function invoiceDetail(): BelongsTo
    {
        return $this->belongsTo(InvoiceDetail::class);
    }

    public function category(): BelongsTo
    {
        return $this->belongsTo(Category::class);
    }

    public function subcategory(): BelongsTo
    {
        return $this->belongsTo(Category::class, 'subcategory_id');
    }
}

class QuickCashItem extends Model
{
    protected $fillable = ['expense_id', 'item_title', 'item_amount'];

    protected $casts = [
        'item_amount' => 'decimal:2'
    ];

    public function expense(): BelongsTo
    {
        return $this->belongsTo(Expense::class);
    }
}

class ExpenseAttachment extends Model
{
    protected $fillable = [
        'expense_id', 'invoice_detail_id', 'file_name', 'file_path',
        'file_type', 'file_size', 'attachment_type'
    ];

    public function expense(): BelongsTo
    {
        return $this->belongsTo(Expense::class);
    }

    public function invoiceDetail(): BelongsTo
    {
        return $this->belongsTo(InvoiceDetail::class);
    }

    public function getUrlAttribute(): string
    {
        return Storage::url($this->file_path);
    }
}

class ExpenseTimeline extends Model
{
    protected $fillable = [
        'expense_id', 'branch_manager_id', 'action', 'status', 'edited_fields', 'note'
    ];

    protected $casts = [
        'edited_fields' => 'array'
    ];

    public function expense(): BelongsTo
    {
        return $this->belongsTo(Expense::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(BranchManager::class);
    }
}

class Category extends Model
{
    protected $fillable = ['name', 'parent_id', 'type', 'is_active'];

    protected $casts = [
        'is_active' => 'boolean'
    ];

    public function parent(): BelongsTo
    {
        return $this->belongsTo(Category::class, 'parent_id');
    }

    public function children(): HasMany
    {
        return $this->hasMany(Category::class, 'parent_id');
    }

    public function scopeMainCategories($query)
    {
        return $query->whereNull('parent_id');
    }

    public function scopeActive($query)
    {
        return $query->where('is_active', true);
    }

    public function scopeByType($query, $type)
    {
        return $query->where('type', $type);
    }
}

class Supplier extends Model
{
    use SoftDeletes;

    protected $fillable = [
        'name', 'tax_id', 'phone', 'email', 'address', 'is_active'
    ];

    protected $casts = [
        'is_active' => 'boolean'
    ];

    public function expenses(): HasMany
    {
        return $this->hasMany(Expense::class);
    }

    public function invoiceDetails(): HasMany
    {
        return $this->hasMany(InvoiceDetail::class);
    }

    public function scopeActive($query)
    {
        return $query->where('is_active', true);
    }
}
