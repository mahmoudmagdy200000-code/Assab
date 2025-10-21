<?php

namespace Modules\Expense\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Factories\HasFactory;

/**
 * Pre-Approval Request Model
 */
class PreApprovalRequest extends Model
{
    use HasFactory;

    protected $fillable = [
        'expense_id',
        'purpose',
        'estimated_amount',
        'priority',
    ];

    protected $casts = [
        'estimated_amount' => 'decimal:2',
    ];

    public function expense()
    {
        return $this->belongsTo(Expense::class);
    }
}
