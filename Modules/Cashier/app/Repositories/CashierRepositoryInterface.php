<?php

namespace Modules\Cashier\Repositories;

use Illuminate\Support\Collection;
use Modules\Cashier\Models\Cashier;

interface CashierRepositoryInterface
{
    public function findById(string $id): ?Cashier;

    public function findByEmail(string $email): ?Cashier;

    public function findByPhone(string $phone): ?Cashier;

    public function getByBranch(string $branchId): Collection;

    public function getActive(string $branchId): Collection;

    public function getPending(string $branchId): Collection;

    public function create(array $data): Cashier;

    public function update(Cashier $cashier, array $data): bool;

    public function delete(Cashier $cashier): bool;

    public function search(string $query, string $branchId): Collection;

    public function getWithShifts(string $cashierId): ?Cashier;

    public function getByStatus(string $status, string $branchId): Collection;

    public function countByBranch(string $branchId): string;

    public function countByStatus(string $status, string $branchId): string;
}
