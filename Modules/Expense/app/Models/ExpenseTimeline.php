<?php

namespace Modules\Expense\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

/**
 * Expense Timeline Model
 */
class ExpenseTimeline extends Model
{
    use HasFactory , HasUuids;

    protected static function newFactory()
    {
        return \Modules\Expense\Database\Factories\ExpenseTimelineFactory::new();
    }

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
