<?php

namespace Modules\Expense\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Factories\HasFactory;

/**
 * Quick Cash Item Model
 */
class QuickCashItem extends Model
{
    use HasFactory;

    protected $fillable = [
        'quick_cash_expense_id',
        'title',
        'amount',
    ];

    protected $casts = [
        'amount' => 'decimal:2',
    ];

    public function quickCashExpense()
    {
        return $this->belongsTo(QuickCashExpense::class);
    }
}
