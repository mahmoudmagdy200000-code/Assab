<?php

namespace Modules\BrandOwner\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * Tracks every PDF/Excel report a brand owner has exported.
 * Powers the "export_history" list on the reports & analytics screen.
 */
class BrandOwnerReportExport extends Model
{
    use HasUuids, SoftDeletes;

    protected $table = 'brand_owner_report_exports';

    protected $fillable = [
        'brand_owner_id',
        'report_kind',
        'format',
        'title',
        'file_path',
        'params',
    ];

    protected $casts = [
        'params' => 'array',
    ];

    public function brandOwner(): BelongsTo
    {
        return $this->belongsTo(BrandOwner::class, 'brand_owner_id');
    }

    public function getDownloadUrlAttribute(): ?string
    {
        return $this->file_path ? asset('storage/'.$this->file_path) : null;
    }
}
