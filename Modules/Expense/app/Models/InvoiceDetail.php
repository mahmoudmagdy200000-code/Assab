<?php

namespace Modules\Expense\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Factories\HasFactory;

/**
 * Invoice Detail Model
 */
class InvoiceDetail extends Model
{
    use HasFactory;

    protected $fillable = [
        'expense_id',
        'grouped_invoice_id',
        'supplier_id',
        'invoice_number',
        'issue_date',
        'is_tax_invoice',
        'tax_id',
        'payment_type',
        'paid_amount',
        'due_date',
    ];

    protected $casts = [
        'issue_date' => 'date',
        'is_tax_invoice' => 'boolean',
        'paid_amount' => 'decimal:2',
        'due_date' => 'date',
    ];

    public function expense()
    {
        return $this->belongsTo(Expense::class);
    }

    public function groupedInvoice()
    {
        return $this->belongsTo(GroupedInvoice::class);
    }

    public function supplier()
    {
        return $this->belongsTo(Supplier::class);
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
}
