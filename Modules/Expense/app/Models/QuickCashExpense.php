<?php

namespace Modules\Expense\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

/**
 * Quick Cash Expense Model
 */
class QuickCashExpense extends Model
{
    use HasFactory , HasUuids;

    protected static function newFactory()
    {
        return \Modules\Expense\Database\Factories\QuickCashExpenseFactory::new();
    }

    protected $fillable = [
        'expense_id',
        'expense_date',
        'expense_name',
        'has_vat',
        'invoice_number',
        'vat_total_amount',
        'payment_supplier_id',
    ];

    protected $casts = [
        'expense_date' => 'date',
        'has_vat' => 'boolean',
    ];

    public function expense()
    {
        return $this->belongsTo(Expense::class);
    }

    public function items()
    {
        return $this->hasMany(QuickCashItem::class);
    }

    public function paymentSupplier()
    {
        return $this->belongsTo(Supplier::class, 'payment_supplier_id');
    }
}
