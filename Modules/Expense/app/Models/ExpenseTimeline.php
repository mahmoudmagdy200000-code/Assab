<?php

namespace Modules\Expense\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Factories\HasFactory;

/**
 * Expense Timeline Model
 */
class ExpenseTimeline extends Model
{
    use HasFactory;

    protected $fillable = [
        'expense_id',
        'action',
        'performed_by',
        'performed_by_type',
        'status',
        'notes',
    ];

    public $timestamps = false;

    protected $casts = [
        'created_at' => 'datetime',
    ];

    public function expense()
    {
        return $this->belongsTo(Expense::class);
    }

    public function performer()
    {
        return $this->morphTo(__FUNCTION__, 'performed_by_type', 'performed_by');
    }
}
