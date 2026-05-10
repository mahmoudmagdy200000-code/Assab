<?php

namespace Modules\FixedAssets\Services;

use Illuminate\Support\Collection;
use Modules\Branch\Models\Branch;
use Modules\BranchManagers\Models\BranchManager;
use Modules\Cashier\Models\Cashier;
use Modules\FixedAssets\Models\AssetType;
use Modules\FixedAssets\Models\AssetZone;
use Modules\FixedAssets\Models\FixedAsset;

class ReferenceDataService
{
    public function zones(string $branchId): Collection
    {
        return AssetZone::query()
            ->where('branch_id', $branchId)
            ->where('is_active', true)
            ->orderBy('name')
            ->get(['id', 'name']);
    }

    public function types(): Collection
    {
        return AssetType::query()
            ->where('is_active', true)
            ->orderBy('name')
            ->get(['id', 'name']);
    }

    public function employees(string $branchId): Collection
    {
        $managers = BranchManager::query()
            ->where('branch_id', $branchId)
            ->where('is_active', true)
            ->orderBy('name')
            ->get(['id', 'name', 'image'])
            ->map(fn ($m) => [
                'id' => $m->id,
                'name' => $m->name,
                'image' => $m->image ? asset('storage/'.$m->image) : '',
            ]);

        $cashiers = Cashier::query()
            ->where('branch_id', $branchId)
            ->orderBy('name')
            ->get(['id', 'name', 'image'])
            ->map(fn ($c) => [
                'id' => $c->id,
                'name' => $c->name,
                'image' => $c->image ? asset('storage/'.$c->image) : '',
            ]);

        return $managers->concat($cashiers)->values();
    }

    public function branches(string $excludeBranchId): Collection
    {
        return Branch::query()
            ->where('id', '!=', $excludeBranchId)
            ->orderBy('name')
            ->get(['id', 'name', 'image'])
            ->map(fn ($b) => [
                'id' => $b->id,
                'name' => $b->name,
                'image' => $b->image ? asset('storage/'.$b->image) : '',
            ]);
    }

    public function assets(string $branchId, ?string $search = null): Collection
    {
        $query = FixedAsset::query()
            ->with(['zone', 'assetType', 'assignedTo'])
            ->where('branch_id', $branchId);

        if ($search) {
            $query->where('name', 'like', '%'.$search.'%');
        }

        return $query->orderBy('name')->get();
    }
}
