<?php

namespace Modules\Cashier\Services;

use App\Support\TemporaryPassword;
use Carbon\Carbon;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Modules\Cashier\Events\CashierCreatedEvent;
use Modules\Cashier\Models\Cashier;
use Modules\Cashier\Repositories\CashierRepositoryInterface;
use Modules\Shift\Enums\ShiftStatus;
use Modules\Shift\Models\CashierShift;
use Modules\Shift\Models\Shift;

class CashierService
{
    public function __construct(
        private CashierRepositoryInterface $cashierRepository,
        private CashierActivationService $activationService
    ) {}

    /**
     * Get cashiers with filters
     */
    public function getCashiers(string $branchId, array $filters = []): LengthAwarePaginator
    {
        $query = Cashier::with(['branch', 'creator'])
            ->where('branch_id', $branchId);

        if (! empty($filters['status'])) {
            $query->where('status', $filters['status']);
        }

        if (! empty($filters['search'])) {
            $query->search($filters['search']);
        }

        if (! empty($filters['date_from'])) {
            $query->whereDate('created_at', '>=', $filters['date_from']);
        }

        if (! empty($filters['date_to'])) {
            $query->whereDate('created_at', '<=', $filters['date_to']);
        }

        $sortBy = $filters['sort_by'] ?? 'created_at';
        $sortOrder = $filters['sort_order'] ?? 'desc';
        $query->orderBy($sortBy, $sortOrder);

        $perPage = $filters['per_page'] ?? 15;

        return $query->paginate($perPage);
    }

    /**
     * Create new cashier
     */
    public function createCashier(array $data): Cashier
    {
        DB::beginTransaction();
        try {
            // Random one-time password per cashier; delivered via the activation link and changed on first login.
            $defaultPassword = TemporaryPassword::generate();

            $cashier = Cashier::create([
                'name' => $data['name'],
                'email' => $data['email'],
                'phone' => $data['phone'] ?? null,
                'password' => Hash::make($defaultPassword),
                'branch_id' => $data['branch_id'],
                'status' => 'pending',
                'created_by' => $data['created_by'],
            ]);

            if (! empty($data['shift_ids'])) {
                $this->assignShiftsToCashier(
                    cashierId: $cashier->id,
                    shiftIds: $data['shift_ids'],
                    shiftDate: Carbon::today()->toDateString(),
                    forFullWeek: true
                );
            }

            DB::commit();

            // After commit, never inside it: the listeners send the activation
            // link, raise the account notification and mirror the cashier into
            // the dashboard (asab_employees) — none of which may outlive a
            // rolled-back creation.
            CashierCreatedEvent::dispatch($cashier, $defaultPassword);

            return $cashier->fresh(['branch', 'creator']);
        } catch (\Exception $e) {
            DB::rollBack();
            throw $e;
        }
    }

    /**
     * Update cashier
     */
    public function updateCashier(Cashier $cashier, array $data): Cashier
    {
        DB::beginTransaction();
        try {
            $cashier->update([
                'name' => $data['name'] ?? $cashier->name,
                'email' => $data['email'] ?? $cashier->email,
                'phone' => $data['phone'] ?? $cashier->phone,
            ]);

            // Update shifts if provided
            if (isset($data['shift_ids'])) {
                $this->updateCashierShifts($cashier->id, $data['shift_ids']);
            }

            DB::commit();

            return $cashier->fresh(['branch', 'creator']);
        } catch (\Exception $e) {
            DB::rollBack();
            throw $e;
        }
    }

    /**
     * Delete cashier
     */
    public function deleteCashier(Cashier $cashier): bool
    {
        DB::beginTransaction();
        try {
            // Delete pending shifts
            $cashier->shifts()
                ->where('status', 'not_started')
                ->delete();

            // Soft delete cashier
            $cashier->delete();

            DB::commit();

            return true;
        } catch (\Exception $e) {
            DB::rollBack();
            throw $e;
        }
    }

    /**
     * Activate cashier
     */
    public function activateCashier(Cashier $cashier): void
    {
        $cashier->activate();
    }

    /**
     * Deactivate cashier
     */
    public function deactivateCashier(Cashier $cashier): void
    {
        $cashier->deactivate();
    }

    /**
     * Get cashier details
     */
    public function getCashierDetails(string $cashierId): Cashier
    {
        return Cashier::with([
            'branch',
            'creator',
            'shifts' => function ($query) {
                $query->whereDate('shift_date', '>=', now()->subDays(30))
                    ->orderBy('shift_date', 'desc');
            },
        ])->findOrFail($cashierId);
    }

    /**
     * Search cashiers
     */
    public function searchCashiers(string $search, string $branchId): Collection
    {
        return Cashier::where('branch_id', $branchId)
            ->search($search)
            ->limit(10)
            ->get(['id', 'name', 'email', 'phone', 'image', 'status']);
    }

    /**
     * Get cashier statistics
     */
    public function getCashierStatistics(string $branchId): array
    {
        // Single query for the status breakdown instead of four separate counts.
        $counts = Cashier::where('branch_id', $branchId)
            ->selectRaw('COUNT(*) as total')
            ->selectRaw("COUNT(CASE WHEN status = 'active' THEN 1 END) as active")
            ->selectRaw("COUNT(CASE WHEN status = 'pending' THEN 1 END) as pending")
            ->selectRaw("COUNT(CASE WHEN status = 'deactivated' THEN 1 END) as deactivated")
            ->first();

        $withActiveShifts = Cashier::where('branch_id', $branchId)
            ->whereHas('shifts', function ($q) {
                $q->whereDate('shift_date', today())
                    ->whereIn('status', ['not_started', 'in_progress']);
            })->count();

        return [
            'total' => (int) $counts->total,
            'active' => (int) $counts->active,
            'pending' => (int) $counts->pending,
            'deactivated' => (int) $counts->deactivated,
            'with_active_shifts' => $withActiveShifts,
        ];
    }

    /**
     * Get available cashiers for shift
     */
    public function getAvailableCashiersForShift(string $shiftId, string $shiftDate, string $branchId): Collection
    {
        return Cashier::where('branch_id', $branchId)
            ->where('status', 'active')
            ->whereDoesntHave('shifts', function ($query) use ($shiftDate) {
                $query->whereDate('shift_date', $shiftDate)
                    ->whereIn('status', ['not_started', 'in_progress']);
            })
            ->with(['branch:id,name', 'creator:id,name'])
            ->withCount('shifts')
            ->get([
                'id',
                'name',
                'email',
                'phone',
                'image',
                'branch_id',
                'status',
                'created_by',
                'activated_at',
                'created_at',
                'updated_at',
            ]);
    }

    /**
     * Assign shifts to cashier.
     * When forFullWeek=true, creates CashierShifts for each work day (Sun–Thu) excluding holidays.
     */
    public function assignShiftsToCashier(
        string $cashierId,
        array $shiftIds,
        string $shiftDate,
        bool $forFullWeek = false
    ): array {
        return DB::transaction(function () use ($cashierId, $shiftIds, $shiftDate, $forFullWeek) {
            $cashier = Cashier::query()->whereKey($cashierId)->lockForUpdate()->firstOrFail();
            $shiftIds = array_values(array_unique($shiftIds));
            $this->validateAndLockAssignmentSelection($cashier, $shiftIds);
            $refDate = Carbon::parse($shiftDate);
            $dates = $forFullWeek
                ? array_values(array_filter(\Modules\Shift\Helpers\ShiftHelper::workWeekDatesExcludingHolidays($refDate),
                    fn (Carbon $date) => $date->greaterThanOrEqualTo($refDate->copy()->startOfDay())))
                : [$refDate];
            // Serialize competing roster writers on the selected template rows.
            if (CashierShift::query()->whereIn('shift_id', $shiftIds)
                ->where('cashier_id', '!=', $cashierId)->where('status', '!=', ShiftStatus::COMPLETED)
                ->whereDate('shift_date', '>=', $refDate->toDateString())->exists()) {
                throw \Illuminate\Validation\ValidationException::withMessages([
                    'shift_ids' => 'A selected shift is already assigned to another cashier.',
                ]);
            }
            $assignedBy = auth('branch_manager')->id() ?? auth()->id();
            $assignedShifts = [];
            $created = [];
            foreach ($dates as $date) {
                foreach ($shiftIds as $id) {
                    // Existing assignments (including completed/canceled ones) are immutable here.
                    $row = CashierShift::query()->where('cashier_id', $cashierId)->where('shift_id', $id)
                        ->whereDate('shift_date', $date->toDateString())->firstOrCreate([], [
                            'cashier_id' => $cashierId, 'shift_id' => $id, 'shift_date' => $date->toDateString(),
                            'status' => ShiftStatus::NOT_STARTED->value, 'opening_balance' => 0, 'assigned_by' => $assignedBy,
                        ]);
                    $assignedShifts[] = $row;
                    if ($row->wasRecentlyCreated) {
                        $created[] = $row;
                    }
                }
            }
            $this->setNextCashierIdsForCreatedShifts($created);

            return ['cashier_id' => $cashierId, 'shifts_assigned' => count($assignedShifts), 'shifts' => $assignedShifts];
        });
    }

    private function validateAndLockAssignmentSelection(Cashier $cashier, array $shiftIds): void
    {
        $manager = auth('branch_manager')->user() ?? auth()->user();
        if ($manager && isset($manager->branch_id) && $manager->branch_id !== $cashier->branch_id) {
            throw new \Illuminate\Auth\Access\AuthorizationException('Cannot assign shifts to a cashier of another branch');
        }
        // Validate the complete selection before creating even the first row.
        $templates = Shift::query()->whereIn('id', $shiftIds)->orderBy('id')->lockForUpdate()->get()->keyBy('id');
        foreach ($shiftIds as $id) {
            $template = $templates->get($id);
            if (! $template || $template->branch_id !== $cashier->branch_id || ! $template->is_active) {
                throw \Illuminate\Validation\ValidationException::withMessages([
                    'shift_ids' => 'Select active shift templates from the cashier branch only.',
                ]);
            }
        }
    }

    /**
     * Set next_cashier_id on created CashierShifts from the chronologically next shift (same day, same branch).
     *
     * @param  array<int, CashierShift>  $created
     */
    private function setNextCashierIdsForCreatedShifts(array $created): void
    {
        $shiftService = app(\Modules\Shift\Services\ShiftService::class);

        foreach ($created as $cs) {
            $next = $shiftService->getNextShiftCashier($cs);
            if ($next) {
                $cs->update(['next_cashier_id' => $next->id]);
            }
        }
    }

    /**
     * Update cashier shifts: remove pending shifts not in new list (target work week),
     * then assign for full work week. Uses next work week when current week has already ended.
     */
    public function updateCashierShifts(string $cashierId, array $shiftIds): array
    {
        DB::beginTransaction();
        try {
            $cashier = Cashier::query()->whereKey($cashierId)->lockForUpdate()->firstOrFail();
            $start = Carbon::today();
            $end = $start->copy()->addDays(6);

            $this->validateAndLockAssignmentSelection($cashier, $shiftIds);

            $pending = CashierShift::where('cashier_id', $cashierId)
                ->where('status', ShiftStatus::NOT_STARTED)
                ->whereDate('shift_date', '>=', $start)
                ->whereDate('shift_date', '<=', $end)
                ->whereNotIn('shift_id', $shiftIds)
                ->orderBy('id')->lockForUpdate()->get();

            foreach ($pending as $row) {
                $this->assertAssignmentHasNoActivity($row);
                $row->delete();
            }

            $result = $this->assignShiftsToCashier(
                cashierId: $cashierId,
                shiftIds: $shiftIds,
                shiftDate: $start->toDateString(),
                forFullWeek: true
            );

            DB::commit();

            return $result;
        } catch (\Exception $e) {
            DB::rollBack();
            throw $e;
        }
    }

    /** Roster edits must never erase cash/report/reassignment evidence. */
    private function assertAssignmentHasNoActivity(CashierShift $shift): void
    {
        foreach (['actual_start_time', 'actual_end_time', 'operational_chain_id', 'operational_ended_at',
            'handed_over_at', 'pos_receipt', 'original_cashier_id', 'reassigned_at', 'reassigned_by',
            'reassignment_reason', 'review_status', 'reviewed_at', 'review_reason', 'handover_notes'] as $field) {
            if ($shift->getRawOriginal($field) !== null) {
                throw \Illuminate\Validation\ValidationException::withMessages(['shift_ids' => 'A shift with activity cannot be removed from the roster.']);
            }
        }
        foreach (['opening_balance', 'closing_balance', 'expected_balance', 'variance', 'total_sales', 'net_sales', 'vat_amount', 'cash_collected', 'card_payments'] as $field) {
            $value = $shift->getRawOriginal($field);
            if ($value !== null && ! preg_match('/\A[+-]?0+(?:\.0+)?\z/', (string) $value)) {
                throw \Illuminate\Validation\ValidationException::withMessages(['shift_ids' => 'A shift with financial evidence cannot be removed.']);
            }
        }
        if (DB::table('shift_report_aggregates')->where('source_type', 'cashier_shift')->where('source_id', $shift->id)->exists()
            || DB::table('asab_operations')->where('module_key', 'shifts')->where('source_id', $shift->id)->exists()
            || DB::table('cashier_shift_handovers as incoming')
                ->join('cashier_shifts as source', 'source.id', '=', 'incoming.cashier_shift_id')
                ->join('shifts as slot', 'slot.id', '=', 'source.shift_id')
                ->where('incoming.handover_to_type', 'cashier')->where('incoming.handover_to_id', $shift->cashier_id)
                ->where('slot.branch_id', $shift->shift()->value('branch_id'))
                ->whereDate('source.shift_date', $shift->shift_date)->exists()) {
            throw \Illuminate\Validation\ValidationException::withMessages(['shift_ids' => 'A shift with report evidence cannot be removed.']);
        }
        foreach ([
            ['shift_sales_breakdown', 'cashier_shift_id'], ['shift_handover_status', 'cashier_shift_id'],
            ['cashier_shift_handovers', 'cashier_shift_id'], ['branch_manager_cash_transfers', 'destination_cashier_shift_id'],
            ['cashier_shift_handover_receipts', 'receiving_cashier_shift_id'], ['shift_transfer_attempts', 'receiving_cashier_shift_id'],
            ['shift_report_cash_counts', 'cashier_shift_id'], ['shift_variance_details', 'cashier_shift_id'],
            ['shift_transfer_rejection_evidence', 'receiving_cashier_shift_id'],
            ['shift_variance_alerts', 'cashier_shift_id'], ['shift_liability_postings', 'cashier_shift_id'],
            ['cashier_shift_history', 'cashier_shift_id'], ['shift_liability_allocations', 'cashier_shift_id'],
            ['shift_liability_daily_locks', 'cashier_shift_id'], ['cashier_custody_transactions', 'related_shift_id'],
            ['personal_ledger_transactions', 'related_shift_id'], ['asab_shifts', 'legacy_shift_id'],
        ] as [$table, $column]) {
            if (DB::table($table)->where($column, $shift->id)->exists()) {
                throw \Illuminate\Validation\ValidationException::withMessages(['shift_ids' => 'A shift with financial evidence cannot be removed.']);
            }
        }
    }

    /**
     * Resend activation link
     */
    public function resendActivationLink(string $cashierId): Cashier
    {
        $cashier = Cashier::findOrFail($cashierId);

        if ($cashier->isActive()) {
            throw new \Exception('Cashier account is already activated');
        }

        // Generate new password
        $newPassword = TemporaryPassword::generate();
        $cashier->update([
            'password' => Hash::make($newPassword),
        ]);

        // Send activation link
        $this->activationService->sendActivationLink($cashier, $newPassword);

        return $cashier;
    }
}
