<?php

namespace Modules\Expense\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Factories\HasFactory;

/**
 * Grouped Invoice Model
 */
class GroupedInvoice extends Model
{
    use HasFactory , HasUuids;

    protected $fillable = [
        'expense_id',
        'payment_type',
        'paid_amount',
        'due_date',
        'payment_supplier_id',
        'default_supplier_id',
    ];

    protected $casts = [
        'paid_amount' => 'decimal:2',
        'due_date' => 'date',
    ];

    public function expense()
    {
        return $this->belongsTo(Expense::class);
    }

    public function invoiceDetails()
    {
        return $this->hasMany(InvoiceDetail::class);
    }

    public function paymentSupplier()
    {
        return $this->belongsTo(Supplier::class, 'payment_supplier_id');
    }
    public function defaultSupplier()
    {
        return $this->belongsTo(Supplier::class, 'default_supplier_id');
    }
}
