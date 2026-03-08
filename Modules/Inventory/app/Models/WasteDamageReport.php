<?php

namespace Modules\Inventory\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Modules\Branch\Models\Branch;
use Modules\BranchManagers\Models\BranchManager;
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
        'assigned_to_type',
        'assigned_to_id',
        'status',
        'submitted_at',
    ];

    protected $casts = [
        'status' => WasteDamageReportStatus::class,
        'submitted_at' => 'datetime',
    ];

    public function branch(): BelongsTo
    {
        return $this->belongsTo(Branch::class);
    }

    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(BranchManager::class, 'created_by');
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
