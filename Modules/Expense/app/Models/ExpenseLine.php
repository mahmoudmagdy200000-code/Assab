<?php

namespace Modules\Expense\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Factories\HasFactory;

/**
 * Expense Line Model (Other Expenses)
 */
class ExpenseLine extends Model
{
    use HasFactory;

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
