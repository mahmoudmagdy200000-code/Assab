<?php

namespace Modules\Cashier\Repositories;

use Modules\Cashier\Models\Cashier;
use Illuminate\Support\Collection;

interface CashierRepositoryInterface
{
    public function findById(int $id): ?Cashier;

    public function findByEmail(string $email): ?Cashier;

    public function findByPhone(string $phone): ?Cashier;

    public function getByBranch(int $branchId): Collection;

    public function getActive(int $branchId): Collection;

    public function getPending(int $branchId): Collection;

    public function create(array $data): Cashier;

    public function update(Cashier $cashier, array $data): bool;

    public function delete(Cashier $cashier): bool;

    public function search(string $query, int $branchId): Collection;

    public function getWithShifts(int $cashierId): ?Cashier;

    public function getByStatus(string $status, int $branchId): Collection;

    public function countByBranch(int $branchId): int;

    public function countByStatus(string $status, int $branchId): int;
}
