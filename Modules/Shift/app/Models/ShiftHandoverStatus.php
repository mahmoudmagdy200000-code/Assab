<?php

namespace Modules\Shift\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Modules\Cashier\Models\Cashier;

class ShiftHandoverStatus extends Model
{
    use HasFactory;

    protected $table = 'shift_handover_status';

    protected $fillable = [
        'cashier_shift_id',
        'status',
        'reviewed_by',
        'rejection_reason',
        'rejection_files',
        'manager_comment',
        'reviewed_at',
    ];

    protected $casts = [
        'rejection_files' => 'array',
        'reviewed_at' => 'datetime',
    ];

    public function cashierShift()
    {
        return $this->belongsTo(CashierShift::class);
    }

    public function reviewer()
    {
        return $this->belongsTo(Cashier::class, 'reviewed_by');
    }

    public function isPending()
    {
        return $this->status === 'pending';
    }

    public function isAccepted()
    {
        return $this->status === 'accepted';
    }

    public function isRejected()
    {
        return $this->status === 'rejected';
    }

    public function scopePending($query)
    {
        return $query->where('status', 'pending');
    }

    public function scopeAccepted($query)
    {
        return $query->where('status', 'accepted');
    }

    public function scopeRejected($query)
    {
        return $query->where('status', 'rejected');
    }
}
