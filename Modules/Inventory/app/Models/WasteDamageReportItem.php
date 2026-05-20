<?php

namespace Modules\Inventory\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Modules\Branch\Models\Branch;
use Modules\Inventory\Enums\CauseOfDamage;
use Modules\Inventory\Enums\ProblemType;
use Modules\Inventory\Enums\WasteDamageReason;
use Modules\Purchase\Models\Item;
use Modules\Purchase\Models\PurchaseOrderItem;

class WasteDamageReportItem extends Model
{
    use HasFactory, HasUuids, SoftDeletes;

    protected $table = 'waste_damage_report_items';

    protected $fillable = [
        'waste_damage_report_id',
        'branch_id',
        'item_id',
        'purchase_order_item_id',
        'problem_type',
        'cause_of_damage',
        'quantity',
        'reason',
        'unit',
        'total_value',
        'justification_text',
        'photo_path',
        'price_per_unit',
    ];

    protected $casts = [
        'problem_type' => ProblemType::class,
        'cause_of_damage' => CauseOfDamage::class,
        'reason' => WasteDamageReason::class,
        'quantity' => 'decimal:3',
        'total_value' => 'decimal:2',
        'price_per_unit' => 'decimal:2',
    ];

    public function wasteDamageReport(): BelongsTo
    {
        return $this->belongsTo(WasteDamageReport::class);
    }

    public function branch(): BelongsTo
    {
        return $this->belongsTo(Branch::class);
    }

    public function item(): BelongsTo
    {
        return $this->belongsTo(Item::class);
    }

    public function purchaseOrderItem(): BelongsTo
    {
        return $this->belongsTo(PurchaseOrderItem::class);
    }

    public function responsibleEmployees(): HasMany
    {
        return $this->hasMany(WasteDamageReportItemEmployee::class, 'waste_damage_report_item_id');
    }

    /**
     * Whether an explanatory photo is required (damage and value > 20 SAR).
     */
    public function requiresPhoto(): bool
    {
        return $this->problem_type->isDamage() && abs((float) $this->total_value) > 20;
    }
}
