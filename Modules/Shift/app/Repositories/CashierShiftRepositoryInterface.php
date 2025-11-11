<?php

namespace Modules\Shift\Repositories;

use Modules\Shift\Models\CashierShift;
use Illuminate\Support\Collection;
use Carbon\Carbon;

interface CashierShiftRepositoryInterface
{
    public function findById(string $id): ?CashierShift;

    public function getPendingShifts(?string $cashierId = null, ?string $branchId = null): Collection;

    public function getInProgressShifts(?string $cashierId = null, ?string $branchId = null): Collection;

    public function getCompletedShifts(
        ?string $cashierId = null,
        ?string $branchId = null,
        ?Carbon $dateFrom = null,
        ?Carbon $dateTo = null
    ): Collection;

    public function getReassignedShifts(?string $cashierId = null, ?string $branchId = null): Collection;

    public function create(array $data): CashierShift;

    public function update(CashierShift $shift, array $data): bool;

    public function delete(CashierShift $shift): bool;

    public function getShiftsByCashierAndDate(string $cashierId, Carbon $date): Collection;

    public function getNextShift(string $cashierId, Carbon $afterDate): ?CashierShift;

    public function hasOverlappingShift(string $cashierId, string $shiftId, Carbon $date): bool;
}
