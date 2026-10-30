<?php

namespace Modules\Shift\Services;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;
use Modules\Branch\Models\Branch;
use Modules\BranchManagers\Models\BranchManager;
use Modules\BranchManagers\Services\BranchManagerService;
use Modules\Shift\Models\BranchManagerShift;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;
use Symfony\Component\HttpKernel\Exception\ConflictHttpException;

/** Read-only selection; financial commands must name the workday they displayed. */
class ManagerWorkdayResolver
{
    public function resolve(Request $request, BranchManager $actor, bool $command = false): BranchManagerShift
    {
        Validator::make($request->all(), [
            'manager_workday_id' => [$command ? 'required' : 'sometimes', 'uuid'],
            // Preserve the historical GET alias, never use it as a command fallback.
            'shift_id' => ['sometimes', 'uuid'],
            'workday' => ['sometimes', 'in:today,yesterday'],
        ])->validate();

        $id = $request->input('manager_workday_id');
        $legacyId = $request->input('shift_id');
        if (($id && $legacyId && $id !== $legacyId) || ($request->has('workday') && ($id || $legacyId))) {
            throw ValidationException::withMessages(['manager_workday_id' => 'Conflicting workday selectors.']);
        }

        $query = BranchManagerShift::query()
            ->where('branch_manager_id', $actor->id)->where('branch_id', $actor->branch_id);
        $day = $id || $legacyId
            ? $query->whereKey($id ?? $legacyId)->firstOrFail()
            : $query->whereDate('shift_date', $request->input('workday') === 'yesterday' ? today()->subDay() : today())->firstOrFail();
        $this->assertAuthorized($day, $actor);

        return $day;
    }

    /** Recheck the actor/branch from current rows after the existing financial owner locks. */
    public function assertAuthorized(BranchManagerShift $day, BranchManager $actor, bool $lock = false): void
    {
        $manager = BranchManager::query()->whereKey($actor->id)->when($lock, fn ($q) => $q->lockForUpdate())->first();
        $branch = Branch::query()->whereKey($day->branch_id)->when($lock, fn ($q) => $q->lockForUpdate())->first();
        if (! $manager || ! $branch || (string) $day->branch_manager_id !== (string) $manager->id
            || (string) $manager->branch_id !== (string) $day->branch_id) {
            throw new AccessDeniedHttpException('MANAGER_WORKDAY_SCOPE_MISMATCH');
        }
        app(BranchManagerService::class)->assertAssignedActiveManager((string) $branch->id, (string) $manager->id);
        // Company authority comes from this exact assigned branch, never request tenant IDs.
        if ($day->relationLoaded('branch') && $day->branch
            && (string) $day->branch->asab_company_id !== (string) $branch->asab_company_id) {
            throw new ConflictHttpException('MANAGER_WORKDAY_COMPANY_CHANGED');
        }
        $day->setRelation('branch', $branch);
    }

    public function metadata(BranchManagerShift $day): array
    {
        $clock = now();

        return [
            'manager_workday_id' => (string) $day->id,
            'business_date' => $day->shift_date->toDateString(),
            'server_time' => $clock->toIso8601String(),
            'timezone' => config('app.timezone'),
            'workday_dates' => ['today' => $clock->toDateString(), 'yesterday' => $clock->copy()->subDay()->toDateString()],
        ];
    }
}
