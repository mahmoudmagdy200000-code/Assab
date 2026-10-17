<?php

namespace Tests\Support\Harness;

use Modules\Admin\Models\AsabCompany;
use Modules\Admin\Models\AsabUser;
use Modules\Branch\Models\Branch;
use Modules\BranchManagers\Models\BranchManager;
use Modules\Cashier\Models\Cashier;
use Modules\Shift\Models\Shift;

final class AssabHarnessFixture
{
    /** @param array<string, mixed> $manifest */
    public function __construct(
        public readonly array $manifest,
        public readonly string $runtimePassword,
    ) {}

    public function company(): AsabCompany
    {
        return AsabCompany::findOrFail($this->manifest['companyId']);
    }

    public function user(string $key): AsabUser
    {
        return AsabUser::findOrFail($this->manifest['accounts'][$key]['id']);
    }

    public function branch(): Branch
    {
        return Branch::findOrFail($this->manifest['branchId']);
    }

    public function branchManager(): BranchManager
    {
        return BranchManager::findOrFail($this->manifest['legacyDependencies']['branchManagerId']);
    }

    public function cashier(): Cashier
    {
        return Cashier::findOrFail($this->manifest['cashier']['id']);
    }

    public function shiftTemplate(): Shift
    {
        return Shift::findOrFail($this->manifest['shiftTemplateId']);
    }
}
