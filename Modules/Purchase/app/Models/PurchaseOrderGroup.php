<?php

namespace Modules\Purchase\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Str;
use Modules\Purchase\Enums\OrderStatus;

class PurchaseOrderGroup extends Model
{
    use HasUuids, SoftDeletes;

    protected $fillable = [
        'group_number',
        'supplier_id',
        'created_by_asab_user_id',
        'sent_at',
    ];

    protected $casts = [
        'sent_at' => 'datetime',
    ];

    protected static function boot()
    {
        parent::boot();

        static::creating(function ($group) {
            if (empty($group->group_number)) {
                $group->group_number = 'GRP-'.now()->format('Ymd').'-'.strtoupper(Str::random(4));
            }
        });
    }

    public function orders(): HasMany
    {
        return $this->hasMany(PurchaseOrder::class, 'group_id');
    }

    public function supplier(): BelongsTo
    {
        return $this->belongsTo(\Modules\Supplier\Models\Supplier::class, 'supplier_id');
    }

    /**
     * Group tracking status derived from member orders — no second state
     * machine: the orders remain the single source of truth.
     */
    public function deriveStatus(): string
    {
        $statuses = $this->orders->pluck('status');

        if ($statuses->isEmpty()) {
            return OrderStatus::CONFIRMED->value;
        }
        if ($statuses->every(fn ($s) => in_array($s, [OrderStatus::DELIVERED, OrderStatus::CLOSED], true))) {
            return OrderStatus::DELIVERED->value;
        }
        if ($statuses->contains(OrderStatus::ON_THE_WAY)) {
            return OrderStatus::ON_THE_WAY->value;
        }
        if ($statuses->contains(OrderStatus::PREPARING)) {
            return OrderStatus::PREPARING->value;
        }

        return OrderStatus::CONFIRMED->value;
    }
}
