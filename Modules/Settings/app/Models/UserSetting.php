<?php

namespace Modules\Settings\App\Models;


use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Factories\HasFactory;

/**
 * User Settings Model
 * Stores personalized settings for each user
 */
class UserSetting extends Model
{
    use HasFactory;

    protected $fillable = [
        'user_id',
        'user_type',
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

    // Polymorphic relationship to user
    public function user()
    {
        return $this->morphTo(__FUNCTION__, 'user_type', 'user_id');
    }

    // Scopes
    public function scopeForBranchManager($query, int $userId)
    {
        return $query->where('user_type', 'branch_manager')
            ->where('user_id', $userId);
    }

    public function scopeForCashier($query, int $userId)
    {
        return $query->where('user_type', 'cashier')
            ->where('user_id', $userId);
    }
}
