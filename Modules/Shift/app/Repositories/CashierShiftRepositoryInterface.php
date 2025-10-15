<?php

namespace Modules\Shift\Repositories;

use Modules\Shift\Models\CashierShift;
use Illuminate\Support\Collection;
use Carbon\Carbon;

interface CashierShiftRepositoryInterface
{
    public function findById(int $id): ?CashierShift;

    public function getPendingShifts(?int $cashierId = null, ?int $branchId = null): Collection;

    public function getInProgressShifts(?int $cashierId = null, ?int $branchId = null): Collection;

    public function getCompletedShifts(
        ?int $cashierId = null,
        ?int $branchId = null,
        ?Carbon $dateFrom = null,
        ?Carbon $dateTo = null
    ): Collection;

    public function getReassignedShifts(?int $cashierId = null, ?int $branchId = null): Collection;

    public function create(array $data): CashierShift;

    public function update(CashierShift $shift, array $data): bool;

    public function delete(CashierShift $shift): bool;

    public function getShiftsByCashierAndDate(int $cashierId, Carbon $date): Collection;

    public function getNextShift(int $cashierId, Carbon $afterDate): ?CashierShift;

    public function hasOverlappingShift(int $cashierId, int $shiftId, Carbon $date): bool;
}
