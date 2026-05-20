<?php

namespace Modules\Expense\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

/**
 * Expense Line Model (Other Expenses)
 */
class ExpenseLine extends Model
{
    use HasFactory , HasUuids;

    protected static function newFactory()
    {
        return \Modules\Expense\Database\Factories\ExpenseLineFactory::new();
    }

    protected $fillable = [
        'expense_id',
        'invoice_detail_id',
        'category_id',
        'name',
        'price',
    ];

    protected $casts = [
        'price' => 'decimal:2',
    ];

    public function expense()
    {
        return $this->belongsTo(Expense::class);
    }

    public function invoiceDetail()
    {
        return $this->belongsTo(InvoiceDetail::class);
    }

    public function category()
    {
        return $this->belongsTo(Category::class);
    }
}
