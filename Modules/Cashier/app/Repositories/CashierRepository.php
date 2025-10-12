<?php

namespace Modules\Cashier\Repositories;

use Modules\Cashier\Models\Cashier;
use Illuminate\Support\Collection;

class CashierRepository implements CashierRepositoryInterface
{
    public function findById(int $id): ?Cashier
    {
        return Cashier::with(['branch', 'creator'])->find($id);
    }

    public function findByEmail(string $email): ?Cashier
    {
        return Cashier::where('email', $email)->first();
    }

    public function findByPhone(string $phone): ?Cashier
    {
        return Cashier::where('phone', $phone)->first();
    }

    public function getByBranch(int $branchId): Collection
    {
        return Cashier::where('branch_id', $branchId)
            ->with(['branch', 'creator'])
            ->get();
    }

    public function getActive(int $branchId): Collection
    {
        return Cashier::where('branch_id', $branchId)
            ->where('status', 'active')
            ->get();
    }

    public function getPending(int $branchId): Collection
    {
        return Cashier::where('branch_id', $branchId)
            ->where('status', 'pending')
            ->get();
    }

    public function create(array $data): Cashier
    {
        return Cashier::create($data);
    }

    public function update(Cashier $cashier, array $data): bool
    {
        return $cashier->update($data);
    }

    public function delete(Cashier $cashier): bool
    {
        return $cashier->delete();
    }

    public function search(string $query, int $branchId): Collection
    {
        return Cashier::where('branch_id', $branchId)
            ->search($query)
            ->get();
    }

    public function getWithShifts(int $cashierId): ?Cashier
    {
        return Cashier::with(['shifts', 'branch', 'creator'])
            ->find($cashierId);
    }

    public function getByStatus(string $status, int $branchId): Collection
    {
        return Cashier::where('branch_id', $branchId)
            ->where('status', $status)
            ->with(['branch', 'creator'])
            ->get();
    }

    public function countByBranch(int $branchId): int
    {
        return Cashier::where('branch_id', $branchId)->count();
    }

    public function countByStatus(string $status, int $branchId): int
    {
        return Cashier::where('branch_id', $branchId)
            ->where('status', $status)
            ->count();
    }
}
