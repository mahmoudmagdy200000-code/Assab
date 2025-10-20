<?php

namespace Modules\Shift\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Modules\Branch\Models\Branch;

class Shift extends Model
{
    use HasFactory;

    protected $table = 'shifts';

    protected $fillable = [
        'name',
        'start_time',
        'end_time',
        'branch_id',
        'is_active',
    ];

    protected $casts = [
        'is_active' => 'boolean',
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


}
