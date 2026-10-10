<?php

namespace Modules\Shift\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

class ShiftLiabilityShare extends Model
{
    use HasUuids;

    protected $guarded = ['id'];

    protected $attributes = ['employee_response_status' => 'pending'];

    protected $casts = [
        'amount_halalas' => 'integer',
        'employee_responded_at' => 'datetime',
    ];
}
