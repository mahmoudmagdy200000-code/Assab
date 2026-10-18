<?php

namespace Modules\Admin\Services;

use Modules\Admin\Models\Operation;
use Modules\Branch\Models\Branch;
use Modules\Shift\Models\BranchManagerShift;

/** Server provenance, never a client label or absence of review metadata. */
class OperationWorkflowClassifier
{
    public const SAL = 'sal_day';
    public const LEGACY = 'legacy_sales';
    public const UNKNOWN = 'unknown';

    public function classify(Operation $operation): string
    {
        if ($operation->module_key !== 'sales' || ! $this->scopedBranch($operation)) {
            return self::UNKNOWN;
        }
        $payload = $operation->payload ?? [];
        if (! is_array($payload)) {
            return self::UNKNOWN;
        }
        $proofs = $operation->steps()->where('stage_id', 'submit')->get()
            ->filter(fn ($step) => is_array($step->meta) && isset($step->meta['workflowSource']));
        $hasDayLink = array_key_exists('managerShiftId', $payload);
        // A contradictory or incomplete PKG-6 marker must never degrade to Legacy.
        $hasReview = isset($payload['pkg6Review']) || isset($payload['reportReview']);
        if ($hasDayLink || $hasReview) {
            return $proofs->isEmpty() && $this->daySource($operation) !== null ? self::SAL : self::UNKNOWN;
        }
        if ($proofs->count() !== 1) {
            return self::UNKNOWN;
        }
        $step = $proofs->first();
        $proof = $step->meta['workflowSource'];
        return is_array($proof)
            && ($proof['kind'] ?? null) === self::LEGACY
            && ($proof['source'] ?? null) === 'native_branch_upload'
            && ($proof['operationId'] ?? null) === $operation->id
            && ($proof['companyId'] ?? null) === $operation->company_id
            && ($proof['branchId'] ?? null) === $operation->branch_id
            && is_string($proof['actorId'] ?? null) && $proof['actorId'] !== ''
            && $proof['actorId'] === $operation->submitted_by_id
            && $step->actor_user_id === $operation->submitted_by_id
            ? self::LEGACY : self::UNKNOWN;
    }

    /** Validate the actual manager-day link and both tenant/branch coordinates. */
    public function daySource(Operation $operation): ?string
    {
        $payload = $operation->payload;
        $id = is_array($payload) ? ($payload['managerShiftId'] ?? null) : null;
        if ($operation->module_key !== 'sales' || ! is_string($id) || $id === '' || ! $this->scopedBranch($operation)) {
            return null;
        }
        return BranchManagerShift::withoutEagerLoads()->whereKey($id)->where('branch_id', $operation->branch_id)->exists()
            ? $id : null;
    }

    private function scopedBranch(Operation $operation): bool
    {
        return is_string($operation->company_id) && $operation->company_id !== ''
            && is_string($operation->branch_id) && $operation->branch_id !== ''
            && Branch::whereKey($operation->branch_id)->where('asab_company_id', $operation->company_id)->exists();
    }
}
