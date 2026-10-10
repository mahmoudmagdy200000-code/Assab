<?php

namespace Modules\Admin\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use Modules\Admin\Models\Concerns\BelongsToTenant;

class Shift extends Model
{
    use BelongsToTenant, HasUuids, SoftDeletes;

    protected $table = 'asab_shifts';

    /** A cashier's till shift — the pipeline (SHF-) subject. */
    public const ROLE_CASHIER = 'cashier';

    /**
     * A branch manager's workday, mirrored from `branch_manager_shifts` so the
     * live board shows who is running the branch. Display-only: it carries no
     * sales of its own (they belong to the cashier rows underneath it), is not
     * judged against a shift window, and never walks the close pipeline.
     */
    public const ROLE_BRANCH_MANAGER = 'branch_manager';

    protected $fillable = [
        'company_id', 'branch_id', 'supervisor_user_id', 'supervisor_name',
        'cashier_employee_id', 'cashier_name', 'role', 'shift_type', 'shift_no',
        'started_at', 'ended_at', 'status', 'orders_count', 'sales_amount',
        'opening_float', 'cash_expected', 'cash_actual', 'variance', 'notes', 'legacy_shift_id',
        'cash_count_state', 'pending_incoming_counted',
    ];

    /**
     * Mirrors the column default so a freshly created row is presented with its
     * role already set — without this the create response says `role: null`
     * while a re-read says `cashier`.
     */
    protected $attributes = [
        'role' => self::ROLE_CASHIER,
    ];

    protected $casts = [
        'started_at' => 'datetime',
        'ended_at' => 'datetime',
        'orders_count' => 'integer',
        'sales_amount' => 'integer',
        'shift_no' => 'integer',
        'opening_float' => 'integer',
        'cash_expected' => 'integer',
        'cash_actual' => 'integer',
        'variance' => 'integer',
        'pending_incoming_counted' => 'integer',
    ];

    /**
     * Till shifts only. Every figure-bearing read (sales sums, the «وردية مفتوحة»
     * guard, lateness, exports, the close pipeline) means THIS set — a manager's
     * mirrored workday would otherwise double-count or block them.
     */
    public function scopeCashierRole($query)
    {
        return $query->where('role', self::ROLE_CASHIER);
    }

    public function isBranchManagerShift(): bool
    {
        return $this->role === self::ROLE_BRANCH_MANAGER;
    }

    /** Operational visibility only; financial close eligibility retains active/late. */
    public function scopeCurrentlyOperational($query)
    {
        return $query->where(function ($visible) {
            $visible->where('asab_shifts.role', self::ROLE_BRANCH_MANAGER)
                ->orWhereNull('asab_shifts.legacy_shift_id')
                ->orWhereNotExists(function ($ended) {
                    $ended->selectRaw('1')->from('cashier_shifts')
                        ->whereColumn('cashier_shifts.id', 'asab_shifts.legacy_shift_id')
                        ->whereNotNull('cashier_shifts.operational_ended_at');
                });
        });
    }
}
