<?php

namespace Modules\FixedAssets\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Modules\Branch\Models\Branch;
use Modules\BranchManagers\Models\BranchManager;

class AssetHistoryReport extends Model
{
    use HasUuids;

    protected $table = 'fixed_asset_history_reports';

    protected $fillable = [
        'asset_id',
        'branch_id',
        'generated_by_id',
        'file_name',
        'path',
        'generated_at',
    ];

    protected $casts = [
        'generated_at' => 'datetime',
    ];

    public function asset(): BelongsTo
    {
        return $this->belongsTo(FixedAsset::class, 'asset_id');
    }

    public function branch(): BelongsTo
    {
        return $this->belongsTo(Branch::class, 'branch_id');
    }

    public function generatedBy(): BelongsTo
    {
        return $this->belongsTo(BranchManager::class, 'generated_by_id');
    }
}
