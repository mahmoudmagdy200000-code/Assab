<?php

namespace Modules\Expense\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Factories\HasFactory;

/**
 * Grouped Invoice Model
 */
class GroupedInvoice extends Model
{
    use HasFactory;

    protected $fillable = [
        'expense_id',
        'payment_type',
        'paid_amount',
        'due_date',
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
}
