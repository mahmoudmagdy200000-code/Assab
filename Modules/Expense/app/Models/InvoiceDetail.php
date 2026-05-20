<?php

namespace Modules\Expense\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

/**
 * Invoice Detail Model
 */
class InvoiceDetail extends Model
{
    use HasFactory , HasUuids;

    protected static function newFactory()
    {
        return \Modules\Expense\Database\Factories\InvoiceDetailFactory::new();
    }

    protected $fillable = [
        'expense_id',
        'grouped_invoice_id',
        'supplier_id',
        'invoice_number',
        'issue_date',
        'is_tax_invoice',
        'tax_id',

        // 🧾 تفاصيل الفاتورة الضريبية
        'tax_supplier_name',
        'tax_net_amount',
        'tax_vat_amount',
        'tax_total_amount',

        'payment_type',
        'paid_amount',
        'due_date',
        'payment_supplier_id',
    ];

    protected $casts = [
        'issue_date' => 'date',
        'is_tax_invoice' => 'boolean',
        'paid_amount' => 'decimal:2',
        'tax_net_amount' => 'decimal:2',
        'tax_vat_amount' => 'decimal:2',
        'tax_total_amount' => 'decimal:2',
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

    public function paymentSupplier()
    {
        return $this->belongsTo(Supplier::class, 'payment_supplier_id');
    }
}
