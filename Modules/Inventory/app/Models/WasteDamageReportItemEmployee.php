<?php

namespace Modules\Inventory\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Modules\BranchManagers\Models\BranchManager;
use Modules\Cashier\Models\Cashier;

class WasteDamageReportItemEmployee extends Model
{
    use HasFactory, HasUuids;

    protected $table = 'waste_damage_report_item_employees';

    protected $fillable = [
        'waste_damage_report_item_id',
        'cashier_id',
        'branch_manager_id',
        'quantity_accountable',
    ];

    protected $casts = [
        'quantity_accountable' => 'decimal:3',
    ];

    public function wasteDamageReportItem(): BelongsTo
    {
        return $this->belongsTo(WasteDamageReportItem::class, 'waste_damage_report_item_id');
    }

    public function cashier(): BelongsTo
    {
        return $this->belongsTo(Cashier::class);
    }

    public function branchManager(): BelongsTo
    {
        return $this->belongsTo(BranchManager::class);
    }

    public function isBranchManager(): bool
    {
        return $this->branch_manager_id !== null;
    }
}
