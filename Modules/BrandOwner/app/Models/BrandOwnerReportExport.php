<?php

namespace Modules\BrandOwner\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * Tracks every PDF/Excel report exported from the reports & analytics screen.
 * Powers the "export_history" list on that screen.
 *
 * Note: `brand_owner_id` holds the id of whoever created the export — a brand
 * owner or a branch manager. Export history is filtered by that id, so each
 * user only ever sees their own exports.
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
