<?php

namespace Modules\Shift\Services;

use Illuminate\Database\Eloquent\Model;
use Modules\Admin\Models\AsabUser;
use Modules\Admin\Services\TenantBranchResolver;
use Modules\Admin\Services\TenantCompanyResolver;
use Modules\Admin\Support\TenantContext;
use Modules\BranchManagers\Models\BranchManager;
use Modules\BranchManagers\Services\BranchManagerService;
use Modules\Cashier\Models\Cashier;
use Modules\Shift\Models\CashierShift;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;

/**
 * Validates whether an actor is authorized to perform report corrections.
 * Allowed actors:
 * 1. Report owner cashier.
 * 2. Assigned active branch manager of the branch.
 * 3. Active accountant whose company/branch assignment covers the shift's branch.
 */
class ShiftReportCorrectionActorPolicy
{
    public function __construct(
        private BranchManagerService $branchManagers,
        private TenantCompanyResolver $companyResolver,
        private TenantBranchResolver $branchResolver,
    ) {}

    public function assertAllowed(CashierShift $source, Model $actor): string
    {
        $branchId = $source->shift()->value('branch_id') ?? $source->branch_id;

        if ($actor instanceof Cashier) {
            if ((string) $actor->id === (string) $source->cashier_id
                && (string) $actor->branch_id === (string) $branchId) {
                return 'cashier';
            }
            throw new AccessDeniedHttpException('ONLY_REPORT_OWNER_OR_ASSIGNED_BRANCH_MANAGER');
        }

        if ($actor instanceof BranchManager) {
            if ((string) $actor->branch_id === (string) $branchId) {
                $this->branchManagers->assertAssignedActiveManager($branchId, (string) $actor->id);

                return 'branch_manager';
            }
            throw new AccessDeniedHttpException('ONLY_REPORT_OWNER_OR_ASSIGNED_BRANCH_MANAGER');
        }

        if ($actor instanceof AsabUser) {
            if ($actor->status !== 'active') {
                throw new AccessDeniedHttpException('ACCOUNTANT_INACTIVE');
            }

            $assignments = $actor->relationLoaded('roleAssignments')
                ? $actor->roleAssignments->where('role_key', 'accountant')
                : $actor->roleAssignments()->where('role_key', 'accountant')->get();

            if ($assignments->isEmpty()) {
                throw new AccessDeniedHttpException('NOT_AN_ACCOUNTANT');
            }

            foreach ($assignments as $assignment) {
                $ctx = new TenantContext;
                $ctx->companyId = $actor->company_id;
                $ctx->roleKey = 'accountant';
                $ctx->scope = $assignment->scope ?? 'all';
                $ctx->brandIds = $assignment->brand_ids ?? [];
                $ctx->restaurantIds = $assignment->restaurant_ids ?? [];
                $ctx->branchIds = $assignment->branch_ids ?? [];
                $ctx->companyIds = $this->companyResolver
                    ->resolve($ctx->companyId, $ctx->brandIds, $ctx->restaurantIds);

                $allowedBranches = $this->branchResolver->legacyBranchIds($ctx);

                if ($allowedBranches !== null && in_array((string) $branchId, array_map('strval', $allowedBranches), true)) {
                    return 'accountant';
                }
            }

            throw new AccessDeniedHttpException('ACCOUNTANT_UNAUTHORIZED_FOR_BRANCH');
        }

        throw new AccessDeniedHttpException('UNAUTHORIZED_ACTOR');
    }

    public function authorize(Model $actor, CashierShift $source): string
    {
        return $this->assertAllowed($source, $actor);
    }
}
