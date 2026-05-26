<?php

namespace Modules\Inventory\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\MorphTo;
use Illuminate\Database\Eloquent\SoftDeletes;
use Modules\Branch\Models\Branch;
use Modules\Cashier\Models\Cashier;
use Modules\Inventory\Enums\WasteDamageReportStatus;

class WasteDamageReport extends Model
{
    use HasFactory, HasUuids, SoftDeletes;

    protected static function newFactory(): \Modules\Inventory\Database\Factories\WasteDamageReportFactory
    {
        return \Modules\Inventory\Database\Factories\WasteDamageReportFactory::new();
    }

    protected $table = 'waste_damage_reports';

    protected $fillable = [
        'branch_id',
        'created_by',
        'created_by_type',
        'assigned_to_type',
        'assigned_to_id',
        'status',
        'submitted_at',
        'approved_at',
        'approved_by',
        'rejected_at',
        'rejected_by',
        'rejection_comment',
        'manager_confirmed_at',
    ];

    protected $casts = [
        'status' => WasteDamageReportStatus::class,
        'submitted_at' => 'datetime',
        'approved_at' => 'datetime',
        'rejected_at' => 'datetime',
        'manager_confirmed_at' => 'datetime',
    ];

    /**
     * Resolve report_type from items: waste | damage | waste_and_damage.
     */
    public function getReportTypeAttribute(): string
    {
        if (! $this->relationLoaded('items') || $this->items->isEmpty()) {
            return 'waste_and_damage';
        }
        $types = $this->items->pluck('problem_type')->unique()->filter()->values();
        if ($types->isEmpty()) {
            return 'waste_and_damage';
        }
        $hasWaste = $types->contains(fn ($t) => $t === \Modules\Inventory\Enums\ProblemType::WASTE);
        $hasDamage = $types->contains(fn ($t) => $t === \Modules\Inventory\Enums\ProblemType::DAMAGE);
        if ($hasWaste && $hasDamage) {
            return 'waste_and_damage';
        }

        return $hasWaste ? 'waste' : 'damage';
    }

    public function branch(): BelongsTo
    {
        return $this->belongsTo(Branch::class);
    }

    public function createdBy(): MorphTo
    {
        return $this->morphTo(__FUNCTION__, 'created_by_type', 'created_by');
    }

    public function assignedTo(): BelongsTo
    {
        return $this->belongsTo(Cashier::class, 'assigned_to_id');
    }

    public function items(): HasMany
    {
        return $this->hasMany(WasteDamageReportItem::class, 'waste_damage_report_id');
    }

    public function timelines(): HasMany
    {
        return $this->hasMany(WasteDamageReportTimeline::class, 'waste_damage_report_id')
            ->orderBy('occurred_at', 'asc');
    }
}
