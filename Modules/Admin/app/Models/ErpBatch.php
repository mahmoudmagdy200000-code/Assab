<?php

namespace Modules\Admin\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Modules\Admin\Models\Concerns\BelongsToTenant;

class ErpBatch extends Model
{
    use BelongsToTenant, HasUuids;

    protected $table = 'asab_erp_batches';

    /** SRS §14.3 ERP-1 status enum → Arabic label. `success`/`queued` are legacy. */
    public const STATUS_LABELS = [
        'ready' => 'جاهز للتصدير',
        'exported' => 'تم التصدير لـ ERP',
        'failed' => 'فشل التصدير',
        'success' => 'تم التصدير لـ ERP', // legacy alias
        'queued' => 'في الانتظار',
    ];

    protected $fillable = [
        'batch_id', 'company_id', 'module_key', 'batch_date', 'initiated_by_id', 'approved_by_id',
        'operation_count', 'total_amount', 'status', 'filters', 'branch_count', 'erp_response',
        'started_at', 'ready_at', 'completed_at',
    ];

    protected $casts = [
        'filters' => 'array',
        'erp_response' => 'array',
        'operation_count' => 'integer',
        'total_amount' => 'integer',
        'branch_count' => 'integer',
        'batch_date' => 'date',
        'started_at' => 'datetime',
        'ready_at' => 'datetime',
        'completed_at' => 'datetime',
    ];

    public static function statusLabel(?string $status): string
    {
        return self::STATUS_LABELS[$status] ?? 'غير معروف';
    }
}
