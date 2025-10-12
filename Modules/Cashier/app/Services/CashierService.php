<?php

namespace Modules\Cashier\Services;

use Modules\Cashier\Models\Cashier;
use Modules\Cashier\Repositories\CashierRepositoryInterface;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Modules\Shift\Models\CashierShift;
use Modules\Shift\Models\Shift;
use Illuminate\Pagination\LengthAwarePaginator;

class CashierService
{
    public function __construct(
        private CashierRepositoryInterface $cashierRepository,
        private CashierActivationService $activationService
    ) {}

    /**
     * Get cashiers with filters
     */
    public function getCashiers(int $branchId, array $filters = []): LengthAwarePaginator
    {
        $query = Cashier::with(['branch', 'creator'])
            ->where('branch_id', $branchId);

        if (!empty($filters['status'])) {
            $query->where('status', $filters['status']);
        }

        if (!empty($filters['search'])) {
            $query->search($filters['search']);
        }

        if (!empty($filters['date_from'])) {
            $query->whereDate('created_at', '>=', $filters['date_from']);
        }

        if (!empty($filters['date_to'])) {
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
            $defaultPassword = Str::random(12);

            $cashier = Cashier::create([
                'name' => $data['name'],
                'email' => $data['email'],
                'phone' => $data['phone'] ?? null,
                'password' => Hash::make($defaultPassword),
                'branch_id' => $data['branch_id'],
                'status' => 'pending',
                'created_by' => $data['created_by'],
            ]);

            // Assign shifts (أول مرة فقط)
            if (!empty($data['shift_ids'])) {
                $this->assignShiftsToCashier($cashier->id, $data['shift_ids'], $forNext30Days = false);
            }

            $this->activationService->sendActivationLink($cashier, $defaultPassword);

            DB::commit();
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
    public function getCashierDetails(int $cashierId): Cashier
    {
        return Cashier::with([
            'branch',
            'creator',
            'shifts' => function ($query) {
                $query->whereDate('shift_date', '>=', now()->subDays(30))
                    ->orderBy('shift_date', 'desc');
            }
        ])->findOrFail($cashierId);
    }

    /**
     * Search cashiers
     */
    public function searchCashiers(string $search, int $branchId): Collection
    {
        return Cashier::where('branch_id', $branchId)
            ->search($search)
            ->limit(10)
            ->get(['id', 'name', 'email', 'phone', 'image', 'status']);
    }

    /**
     * Get cashier statistics
     */
    public function getCashierStatistics(int $branchId): array
    {
        $cashiers = Cashier::where('branch_id', $branchId);

        return [
            'total' => $cashiers->count(),
            'active' => $cashiers->clone()->where('status', 'active')->count(),
            'pending' => $cashiers->clone()->where('status', 'pending')->count(),
            'deactivated' => $cashiers->clone()->where('status', 'deactivated')->count(),
            'with_active_shifts' => Cashier::where('branch_id', $branchId)
                ->whereHas('shifts', function ($q) {
                    $q->whereDate('shift_date', today())
                        ->whereIn('status', ['not_started', 'in_progress']);
                })->count(),
        ];
    }

    /**
     * Get available cashiers for shift
     */
    public function getAvailableCashiersForShift(int $shiftId, string $shiftDate, int $branchId): Collection
    {
        return Cashier::where('branch_id', $branchId)
            ->where('status', 'active')
            ->whereDoesntHave('shifts', function ($query) use ($shiftDate) {
                $query->whereDate('shift_date', $shiftDate)
                    ->whereIn('status', ['not_started', 'in_progress']);
            })
            ->get(['id', 'name', 'email', 'image']);
    }

    /**
     * Assign shifts to cashier
     */
    public function assignShiftsToCashier(int $cashierId, array $shiftIds, bool $forNext30Days = false): array
    {
        $cashier = Cashier::findOrFail($cashierId);
        $assignedShifts = [];

        foreach ($shiftIds as $shiftId) {
            $shift = Shift::findOrFail($shiftId);

            if ($forNext30Days) {
                for ($i = 0; $i < 30; $i++) {
                    $shiftDate = now()->addDays($i);
                    $exists = CashierShift::where('cashier_id', $cashierId)
                        ->where('shift_id', $shiftId)
                        ->whereDate('shift_date', $shiftDate)
                        ->exists();

                    if (!$exists) {
                        $assignedShifts[] = CashierShift::create([
                            'cashier_id' => $cashierId,
                            'shift_id' => $shiftId,
                            'shift_date' => $shiftDate,
                            'status' => 'not_started',
                            'opening_balance' => 0,
                        ]);
                    }
                }
            } else {

                $assignedShifts[] = CashierShift::create([
                    'cashier_id' => $cashierId,
                    'shift_id' => $shiftId,
                    'shift_date' => now(),
                    'status' => 'not_started',
                    'opening_balance' => 0,
                ]);
            }
        }

        return [
            'cashier_id' => $cashierId,
            'shifts_assigned' => count($assignedShifts),
            'shifts' => $assignedShifts,
        ];
    }


    /**
     * Update cashier shifts
     */
    public function updateCashierShifts(int $cashierId, array $shiftIds): array
    {
        DB::beginTransaction();
        try {
            $cashier = Cashier::findOrFail($cashierId);

            // Remove pending shifts not in the new list
            CashierShift::where('cashier_id', $cashierId)
                ->where('status', 'not_started')
                ->whereDate('shift_date', '>=', today())
                ->whereNotIn('shift_id', $shiftIds)
                ->delete();

            // Assign new shifts
            $result = $this->assignShiftsToCashier($cashierId, $shiftIds);

            DB::commit();
            return $result;
        } catch (\Exception $e) {
            DB::rollBack();
            throw $e;
        }
    }

    /**
     * Resend activation link
     */
    public function resendActivationLink(int $cashierId): Cashier
    {
        $cashier = Cashier::findOrFail($cashierId);

        if ($cashier->isActive()) {
            throw new \Exception('Cashier account is already activated');
        }

        // Generate new password
        $newPassword = Str::random(12);
        $cashier->update([
            'password' => Hash::make($newPassword),
        ]);

        // Send activation link
        $this->activationService->sendActivationLink($cashier, $newPassword);

        return $cashier;
    }
}
