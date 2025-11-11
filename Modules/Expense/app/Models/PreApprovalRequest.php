<?php

namespace Modules\Expense\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Factories\HasFactory;

/**
 * Pre-Approval Request Model
 */
class PreApprovalRequest extends Model
{
    use HasFactory , HasUuids;

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
