<?php

namespace Modules\Expense\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Factories\HasFactory;

/**
 * Quick Cash Expense Model
 */
class QuickCashExpense extends Model
{
    use HasFactory;

    protected $fillable = [
        'expense_id',
        'expense_date',
        'expense_name',
        'has_vat',
        'invoice_number',
        'vat_total_amount',
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
}
