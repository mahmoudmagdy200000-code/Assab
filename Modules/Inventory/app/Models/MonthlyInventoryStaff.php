<?php

namespace Modules\Inventory\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphTo;

class MonthlyInventoryStaff extends Model
{
    use HasUuids;

    protected $table = 'monthly_inventory_staff';

    protected $fillable = [
        'monthly_inventory_id',
        'user_id',
        'user_type',
        'role',
    ];

    public const ROLE_TEAM_LEADER = 'team_leader';

    public const ROLE_STAFF = 'staff';

    public function monthlyInventory(): BelongsTo
    {
        return $this->belongsTo(MonthlyInventory::class);
    }

    public function user(): MorphTo
    {
        return $this->morphTo(__FUNCTION__, 'user_type', 'user_id');
    }
}
