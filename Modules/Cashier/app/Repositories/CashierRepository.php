<?php

namespace Modules\Cashier\Repositories;

use Illuminate\Support\Collection;
use Modules\Cashier\Models\Cashier;

class CashierRepository implements CashierRepositoryInterface
{
    public function findById(string $id): ?Cashier
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

    public function getByBranch(string $branchId): Collection
    {
        return Cashier::where('branch_id', $branchId)
            ->with(['branch', 'creator'])
            ->get();
    }

    public function getActive(string $branchId): Collection
    {
        return Cashier::where('branch_id', $branchId)
            ->where('status', 'active')
            ->get();
    }

    public function getPending(string $branchId): Collection
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

    public function search(string $query, string $branchId): Collection
    {
        return Cashier::where('branch_id', $branchId)
            ->search($query)
            ->get();
    }

    public function getWithShifts(string $cashierId): ?Cashier
    {
        return Cashier::with(['shifts', 'branch', 'creator'])
            ->find($cashierId);
    }

    public function getByStatus(string $status, string $branchId): Collection
    {
        return Cashier::where('branch_id', $branchId)
            ->where('status', $status)
            ->with(['branch', 'creator'])
            ->get();
    }

    public function countByBranch(string $branchId): string
    {
        return Cashier::where('branch_id', $branchId)->count();
    }

    public function countByStatus(string $status, string $branchId): string
    {
        return Cashier::where('branch_id', $branchId)
            ->where('status', $status)
            ->count();
    }
}
