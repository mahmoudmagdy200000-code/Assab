<?php

namespace Modules\Shift\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class ShiftLiabilityAllocation extends Model
{
    use HasUuids;

    protected $guarded = ['id'];

    protected $attributes = ['manager_approval_status' => 'pending'];

    protected $casts = [
        'version' => 'integer',
        'variance_halalas' => 'integer',
        'cashier_confirmed_at' => 'datetime',
        'manager_approved_at' => 'datetime',
        'superseded_at' => 'datetime',
    ];

    public function shares(): HasMany
    {
        return $this->hasMany(ShiftLiabilityShare::class, 'allocation_id');
    }
}
