<?php

namespace Modules\Admin\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use Modules\Admin\Models\Concerns\BelongsToTenant;

/**
 * A branch manager's «طلب مورد جديد» (BRM-3.3). Persisted so procurement can
 * list/approve it and the branch can see «قيد المراجعة»/«معتمد» chips.
 */
class SupplierRequest extends Model
{
    use BelongsToTenant, HasUuids, SoftDeletes;

    protected $table = 'asab_supplier_requests';

    public const STATUS_PENDING = 'pending_review';

    public const STATUS_APPROVED = 'approved';

    public const STATUS_REJECTED = 'rejected';

    /** Chip labels (BRM-3.3). */
    public const STATUS_LABELS = [
        self::STATUS_PENDING => 'قيد المراجعة',
        self::STATUS_APPROVED => 'معتمد',
        self::STATUS_REJECTED => 'مرفوض',
    ];

    protected $fillable = [
        'company_id', 'branch_id', 'name', 'category', 'contact_phone',
        'reason', 'status', 'requested_by_id', 'supplier_id',
    ];
}
