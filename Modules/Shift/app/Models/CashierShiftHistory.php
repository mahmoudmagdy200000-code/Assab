<?php

namespace Modules\Shift\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Factories\HasFactory;

/**
 * Updated CashierShiftHistory Model
 */
class CashierShiftHistory extends Model
{
    use HasFactory;

    protected $table = 'cashier_shift_history';

    protected $fillable = [
        'cashier_shift_id',
        'action',
        'performed_by',
        'performed_by_type',
        'old_value',
        'new_value',
        'notes',
    ];

    protected $casts = [
        'old_value' => 'array',
        'new_value' => 'array',
        'created_at' => 'datetime',
    ];

    public $timestamps = false;

    // Relationships
    public function cashierShift()
    {
        return $this->belongsTo(CashierShift::class);
    }

    public function performer()
    {
        return $this->morphTo(__FUNCTION__, 'performed_by_type', 'performed_by');
    }

    // Scopes
    public function scopeByAction($query, string $action)
    {
        return $query->where('action', $action);
    }

    public function scopeByPerformer($query, string $type)
    {
        return $query->where('performed_by_type', $type);
    }

    public function scopeRecent($query, int $limit = 10)
    {
        return $query->orderBy('created_at', 'desc')->limit($limit);
    }

    // Helper Methods
    public function getPerformerName(): string
    {
        return match($this->performed_by_type) {
            'branch_manager' => \Modules\BranchManagers\Models\BranchManager::find($this->performed_by)?->name ?? 'Unknown',
            'cashier' => \Modules\Cashier\Models\Cashier::find($this->performed_by)?->name ?? 'Unknown',
            'system' => 'System',
            default => 'Unknown',
        };
    }

    public function getActionLabel(): string
    {
        return match($this->action) {
            'assigned' => 'Assigned',
            'started' => 'Started',
            'completed' => 'Completed',
            'reassigned' => 'Reassigned',
            'handed_over' => 'Handed Over',
            'ended_without_handover' => 'Ended Without Handover',
            default => ucfirst($this->action),
        };
    }
}

