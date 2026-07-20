<?php

namespace Modules\Admin\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use Modules\Admin\Models\Concerns\BelongsToTenant;

/**
 * The shared approval-pipeline envelope over any module's record
 * (BACKEND_API_SPEC.md §5). Links to a legacy module record via
 * source_module + source_id; carries spec-shaped data in `payload`.
 */
class Operation extends Model
{
    use BelongsToTenant, HasUuids, SoftDeletes;

    /**
     * A supplier and a procurement manager receive purchase orders from EVERY
     * company, so the tenant scope must step aside for them. Their controllers
     * carry the authorization the scope used to: the supplier portal narrows
     * every read to `payload->supplierId ∈ own supplier ids`
     * (Supplier\SupplierController::ownOrders), and the platform procurement
     * surface is `module_key = purchases` only. Any NEW endpoint reachable by
     * those roles must state its own filter — it will not inherit one.
     */
    protected static function platformVisible(): bool
    {
        return true;
    }

    protected $table = 'asab_operations';

    public const STATUS_PENDING = 'pending';

    public const STATUS_APPROVED = 'approved';

    public const STATUS_REJECTED = 'rejected';

    public const STATUS_FINAL = 'final-approved';

    protected $fillable = [
        'public_id', 'company_id', 'branch_id', 'module_key', 'source_module', 'source_id',
        'payload', 'amount', 'match', 'diff_note', 'origin', 'channel', 'attachment_count', 'status',
        'reject_reason', 'submitted_by_id', 'submitted_at', 'reviewed_by_id', 'reviewed_at',
        'approved_by_id', 'approved_at', 'final_approved_by_id', 'final_approved_at',
        'rejected_by_id', 'rejected_at', 'is_conditional', 'conditional_note', 'is_correction',
        'corrective_ref_id', 'erp_posted', 'erp_batch_id', 'erp_posted_at', 'operation_date',
    ];

    protected $casts = [
        'payload' => 'array',
        'amount' => 'integer',
        'attachment_count' => 'integer',
        'is_conditional' => 'boolean',
        'is_correction' => 'boolean',
        'erp_posted' => 'boolean',
        'submitted_at' => 'datetime',
        'reviewed_at' => 'datetime',
        'approved_at' => 'datetime',
        'final_approved_at' => 'datetime',
        'rejected_at' => 'datetime',
        'erp_posted_at' => 'datetime',
        'operation_date' => 'datetime',
    ];

    public function steps()
    {
        return $this->hasMany(ApprovalStep::class, 'operation_id')->orderBy('occurred_at');
    }

    public function scopeStatus($q, string $status)
    {
        return $q->where('status', $status);
    }

    public function scopeModule($q, string $moduleKey)
    {
        return $q->where('module_key', $moduleKey);
    }

    public function scopePending($q)
    {
        return $q->where('status', self::STATUS_PENDING);
    }
}
