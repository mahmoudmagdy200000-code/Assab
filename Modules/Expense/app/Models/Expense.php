<?php

namespace Modules\Expense\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use Modules\BranchManagers\Models\BranchManager;

/**
 * Main Expense Model
 */
class Expense extends Model
{
    use HasFactory, HasUuids , SoftDeletes;

    protected static function newFactory()
    {
        return \Modules\Expense\Database\Factories\ExpenseFactory::new();
    }

    /**
     * FU-02: stamp the immutable originating branch/brand/company exactly once,
     * when the expense row is first created — the single authoritative capture
     * point for every writer (QuickCash / SingleInvoice / GroupedInvoice /
     * PreApproval / drafts / resubmits / factories / imports).
     *
     * It only fires on `creating`, so submitting a draft or resubmitting a
     * rejected expense (updates to the same row) never restamps ownership; and
     * a fresh row created by a resubmit flow correctly gets its own origin. If
     * origin is already set (explicit, server-side only) or no manager is
     * present it is left as-is. origin_* are NOT fillable, so a client/request
     * can never mass-assign them — the value is always server-derived here.
     */
    protected static function booted(): void
    {
        static::creating(function (self $expense): void {
            if ($expense->origin_branch_id !== null || $expense->branch_manager_id === null) {
                return;
            }

            // Single source of truth for the resolution (shared with ExpenseOriginGuard,
            // which also pre-checks it at the operational submission paths). Any
            // element stays NULL when the branch is not fully linked — readers fail
            // closed and the F-04 guard blocks such a row from becoming pending.
            $triplet = \Modules\Expense\Services\ExpenseOriginGuard::resolveForManager((string) $expense->branch_manager_id);

            if ($triplet['branch_id'] === null) {
                return;
            }

            $expense->origin_branch_id = $triplet['branch_id'];
            $expense->origin_asab_brand_id = $triplet['brand_id'];
            $expense->origin_asab_company_id = $triplet['company_id'];
        });
    }

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
        'approval_stage',
        'decided_by_name',
        'decided_by_role',
        'decided_at',
    ];

    protected $casts = [
        'total_amount' => 'decimal:2',
        'net_amount' => 'decimal:2',
        'vat_amount' => 'decimal:2',
        'submitted_at' => 'datetime',
        'approved_at' => 'datetime',
        'rejected_at' => 'datetime',
        'decided_at' => 'datetime',
        'approval_stage' => \Modules\Expense\Enums\ExpenseApprovalStage::class,
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

    /**
     * Whether the dashboard (accountant / head of accounts) has taken the
     * record. Once it has, the brand owner is a spectator: the two approval
     * cycles are exclusive, and letting both write would leave the mobile row
     * and the ASAB operation disagreeing about who decided (meeting 2026-08-14).
     */
    public function isOwnedByAccounting(): bool
    {
        return $this->approval_stage?->isAccountingCycle() ?? false;
    }

    /** No actor — brand owner, accountant or head — may act any further. */
    public function isDecisionLocked(): bool
    {
        return $this->approval_stage?->isTerminal() ?? false;
    }
}
