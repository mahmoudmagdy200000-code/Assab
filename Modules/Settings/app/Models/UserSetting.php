<?php

namespace Modules\Settings\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Factories\HasFactory;

/**
 * User Settings Model
 * Stores personalized settings for each user (BranchManager or Cashier)
 */
class UserSetting extends Model
{
    use HasFactory , HasUuids;

    protected $fillable = [
        'userable_id',
        'userable_type',
        'language',
        'theme',
        'notification_shift_variance',
        'notification_daily_inventory',
        'notification_approved_aggregators',
        'notification_asset_transfers',
        'notification_split_shift_handover',
    ];

    protected $casts = [
        'notification_shift_variance' => 'boolean',
        'notification_daily_inventory' => 'boolean',
        'notification_approved_aggregators' => 'boolean',
        'notification_asset_transfers' => 'boolean',
        'notification_split_shift_handover' => 'boolean',
    ];

    /**
     * Polymorphic relationship to BranchManager or Cashier
     */
    public function userable()
    {
        return $this->morphTo();
    }

    /**
     * Scope: filter settings for a specific Branch Manager
     */
    public function scopeForBranchManager($query, string $userId)
    {
        return $query->where('userable_type', \Modules\BranchManagers\Models\BranchManager::class)
                     ->where('userable_id', $userId);
    }

    /**
     * Scope: filter settings for a specific Cashier
     */
    public function scopeForCashier($query, string $userId)
    {
        return $query->where('userable_type', \Modules\Cashier\Models\Cashier::class)
                     ->where('userable_id', $userId);
    }
}
