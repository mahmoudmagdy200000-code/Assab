<?php

namespace Modules\Shift\Liability;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;
use Modules\Admin\Models\Employee;
use Modules\BranchManagers\Models\BranchManager;
use Modules\Cashier\Models\Cashier;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;

final class ResponsibleActorResolver
{
    private const TYPES = [
        'cashier' => Cashier::class,
        'branch_manager' => BranchManager::class,
        'employee' => Employee::class,
    ];

    public function resolve(string $type, string $id, string $companyId, string $branchId): Model
    {
        $actor = $this->branchActors($type, $companyId, $branchId)->whereKey($id)->first();
        if (! $actor) {
            throw new AccessDeniedHttpException('RESPONSIBLE_ACTOR_OUT_OF_SCOPE');
        }

        return $actor;
    }

    /** @return list<array{id: string, name: string, type: string, role: string}> */
    public function candidates(string $companyId, string $branchId): array
    {
        $candidates = [];
        foreach (array_keys(self::TYPES) as $type) {
            foreach ($this->branchActors($type, $companyId, $branchId)->orderBy('name')->orderBy('id')->get(['id', 'name']) as $actor) {
                $candidates[] = [
                    'id' => (string) $actor->getKey(),
                    'name' => (string) $actor->name,
                    'type' => $type,
                    'role' => $type,
                ];
            }
        }

        return $candidates;
    }

    private function branchActors(string $type, string $companyId, string $branchId): Builder
    {
        $class = self::TYPES[$type] ?? null;
        if (! $class || ! DB::table('branches')->where('id', $branchId)->where('asab_company_id', $companyId)->exists()) {
            throw new AccessDeniedHttpException('RESPONSIBLE_ACTOR_OUT_OF_SCOPE');
        }

        $query = $class::query()->where('branch_id', $branchId);
        if ($type === 'employee') {
            $query->where('company_id', $companyId);
        }

        return $query;
    }

    /** The caller supplies a server-authenticated/resolved model, never a client actor ID. */
    public function identity(Model $actor, string $companyId, string $branchId): array
    {
        $type = array_search($actor::class, self::TYPES, true);
        if ($type === false) {
            throw new AccessDeniedHttpException('UNSUPPORTED_LIABILITY_ACTOR');
        }
        $this->resolve($type, (string) $actor->getKey(), $companyId, $branchId);

        return ['type' => $type, 'id' => (string) $actor->getKey()];
    }
}
