<?php

namespace Modules\FixedAssets\Services;

use Illuminate\Database\Eloquent\Model;
use Modules\BranchManagers\Models\BranchManager;
use Modules\Cashier\Models\Cashier;

class HandoverRecipientResolver
{
    public function resolve(string $employeeId, string $branchId): ?Model
    {
        $manager = BranchManager::query()
            ->where('id', $employeeId)
            ->where('branch_id', $branchId)
            ->first();

        if ($manager) {
            return $manager;
        }

        return Cashier::query()
            ->where('id', $employeeId)
            ->where('branch_id', $branchId)
            ->first();
    }

    public function displayName(?Model $recipient): string
    {
        return (string) ($recipient?->name ?? '');
    }

    public function imagePath(?Model $recipient): ?string
    {
        return $recipient?->image;
    }

    public function phone(?Model $recipient): string
    {
        return (string) ($recipient?->phone ?? '');
    }
}
