<?php

namespace Modules\Admin\Http\Controllers\Shared;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Modules\Admin\Http\Controllers\AsabController;
use Modules\Admin\Models\AsabBrand;
use Modules\Admin\Models\AsabUser;
use Modules\Admin\Models\Operation;

/**
 * Global search across entities (BACKEND_API_SPEC.md §7.6).
 */
class SearchController extends AsabController
{
    public function index(Request $request): JsonResponse
    {
        return $this->run(function () use ($request) {
            $q = trim((string) $request->query('q', ''));
            $limit = min((int) $request->query('limit', 10), 50);
            $types = $request->query('types') ? explode(',', $request->query('types')) : ['branches', 'operations', 'users', 'suppliers'];

            if ($q === '') {
                return $this->ok($this->emptyResult());
            }

            $result = $this->emptyResult();

            if (in_array('operations', $types, true)) {
                $result['operations'] = Operation::where('public_id', 'like', "%{$q}%")->limit($limit)->get()
                    ->map(fn ($o) => ['id' => $o->id, 'publicId' => $o->public_id, 'moduleKey' => $o->module_key, 'status' => $o->status])->all();
            }
            if (in_array('users', $types, true)) {
                $result['users'] = AsabUser::where('name', 'like', "%{$q}%")->orWhere('email', 'like', "%{$q}%")->limit($limit)->get()
                    ->map(fn ($u) => ['id' => $u->id, 'name' => $u->name, 'email' => $u->email])->all();
            }
            if (in_array('branches', $types, true)) {
                $result['branches'] = $this->legacy(\Modules\Branch\Models\Branch::class, $q, $limit, fn ($b) => ['id' => $b->id, 'name' => $b->name]);
            }
            if (in_array('suppliers', $types, true)) {
                $result['suppliers'] = $this->legacy(\Modules\Supplier\Models\Supplier::class, $q, $limit, fn ($s) => ['id' => $s->id, 'name' => $s->name ?? null]);
            }
            if (in_array('brands', $types, true)) {
                $result['brands'] = AsabBrand::where('name', 'like', "%{$q}%")->limit($limit)->get()
                    ->map(fn ($b) => ['id' => $b->id, 'name' => $b->name])->all();
            }

            $result['total'] = array_sum(array_map(fn ($v) => is_array($v) ? count($v) : 0, $result));

            return $this->ok($result);
        });
    }

    private function emptyResult(): array
    {
        return ['branches' => [], 'operations' => [], 'users' => [], 'suppliers' => [], 'assets' => [], 'total' => 0];
    }

    private function legacy(string $class, string $q, int $limit, callable $map): array
    {
        try {
            if (! class_exists($class)) {
                return [];
            }

            return $class::where('name', 'like', "%{$q}%")->limit($limit)->get()->map($map)->all();
        } catch (\Throwable $e) {
            return [];
        }
    }
}
