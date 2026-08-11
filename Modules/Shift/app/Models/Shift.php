<?php

namespace Modules\Shift\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Modules\Branch\Models\Branch;

class Shift extends Model
{
    use HasFactory, HasUuids;

    protected $table = 'shifts';

    protected $fillable = [
        'name',
        'start_time',
        'end_time',
        'branch_id',
        'is_active',
        // «الرصيد الافتتاحي» of the schedule this template belongs to, in SAR.
        // A cashier shift created against it defaults its opening_balance here.
        'opening_float',
    ];

    protected $casts = [
        'is_active' => 'boolean',
        'opening_float' => 'decimal:2',
        'start_time' => 'datetime:H:i',
        'end_time' => 'datetime:H:i',
    ];

    public function branch()
    {
        return $this->belongsTo(Branch::class);
    }

    public function cashierShifts()
    {
        return $this->hasMany(CashierShift::class);
    }

    /**
     * Create a new factory instance for the model.
     */
    protected static function newFactory()
    {
        return \Modules\Shift\Database\Factories\ShiftFactory::new();
    }
}
