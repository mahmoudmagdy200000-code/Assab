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
        'savings_amount',
        'savings_pct',
        'expected_delivery_date',
    ];

    protected $casts = [
        'sent_at' => 'datetime',
        'savings_amount' => 'decimal:2',
        'savings_pct' => 'decimal:2',
        'expected_delivery_date' => 'date',
    ];

    /** Derived tracking-status → Arabic label (PRC-2.3). */
    public const STATUS_LABELS = [
        'sent' => 'أُرسل للمورد',
        'confirmed' => 'مؤكد',
        'preparing' => 'قيد التحضير',
        'on_the_way' => 'في الطريق',
        'delivered' => 'تم التسليم',
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
     *
     * PRC-2.3: a freshly-sent batch whose orders are all still manager-`confirmed`
     * reads as `sent` («أُرسل للمورد») — awaiting the supplier — NOT `confirmed`.
     * It only advances to preparing/on_the_way/delivered once the supplier acts.
     * (`confirmed` is reserved for a future explicit supplier-acceptance signal;
     * the supplier portal is feature-flagged off today.)
     */
    public function deriveStatus(): string
    {
        $statuses = $this->orders->pluck('status');

        if ($statuses->isEmpty()) {
            return 'sent';
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

        return 'sent';
    }
}
