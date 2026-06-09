<?php

namespace Modules\Admin\Http\Controllers\Admin;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Modules\Admin\Http\Controllers\AsabController;
use Modules\Admin\Models\PermissionMatrixEntry;
use Modules\Admin\Models\PermissionSnapshot;
use Modules\Admin\Services\AuditService;
use Modules\Admin\Services\RealtimeBroadcaster;

class PermissionMatrixController extends AsabController
{
    /** Column order the frontend expects. */
    private const ROLES = ['accountant', 'head', 'branch', 'procurement', 'supplier', 'admin'];

    private const LEGEND = [
        'view' => ['labelAr' => 'عرض', 'labelEn' => 'View'],
        'submit' => ['labelAr' => 'رفع', 'labelEn' => 'Submit'],
        'review' => ['labelAr' => 'مراجعة', 'labelEn' => 'Review'],
        'approve' => ['labelAr' => 'اعتماد', 'labelEn' => 'Approve'],
        'final' => ['labelAr' => 'اعتماد نهائي', 'labelEn' => 'Final'],
        'none' => ['labelAr' => 'لا شيء', 'labelEn' => 'None'],
    ];

    public function index(): JsonResponse
    {
        return $this->run(fn () => $this->ok($this->buildMatrix()));
    }

    /** The full matrix payload (AdminPermissionsMatrix type). */
    private function buildMatrix(): array
    {
        $byModule = PermissionMatrixEntry::whereNull('company_id')->get()->groupBy('module');

        $matrix = $byModule->map(function ($rows, $module) {
            $map = $rows->keyBy('role_key');

            return [
                'module' => $module,
                'perms' => array_map(fn ($role) => $map[$role]->permission ?? 'none', self::ROLES),
            ];
        })->values()->all();

        return [
            'matrix' => $matrix,
            'roles' => self::ROLES,
            'legend' => self::LEGEND,
        ];
    }

    public function updateCell(Request $request): JsonResponse
    {
        return $this->run(function () use ($request) {
            $data = $request->validate([
                'module' => 'required|string|max:64',
                'roleKey' => 'required|in:'.implode(',', self::ROLES),
                'permission' => 'required|in:view,submit,review,approve,final,none',
            ]);

            $entry = PermissionMatrixEntry::updateOrCreate(
                ['company_id' => null, 'role_key' => $data['roleKey'], 'module' => $data['module']],
                ['permission' => $data['permission'], 'updated_by_id' => $request->user()->id],
            );

            return $this->ok([
                'module' => $entry->module,
                'roleKey' => $entry->role_key,
                'permission' => $entry->permission,
            ]);
        });
    }

    public function replace(Request $request): JsonResponse
    {
        return $this->run(function () use ($request) {
            $data = $request->validate([
                'matrix' => 'required|array',
                'matrix.*.module' => 'required|string',
                'matrix.*.perms' => 'required|array',
            ]);

            $changedModules = DB::transaction(function () use ($data, $request) {
                $current = PermissionMatrixEntry::whereNull('company_id')->get()
                    ->groupBy('module')->map(fn ($rows) => $rows->keyBy('role_key'));
                $changed = 0;

                foreach ($data['matrix'] as $row) {
                    $moduleChanged = false;
                    foreach (self::ROLES as $i => $roleKey) {
                        $perm = $row['perms'][$i] ?? 'none';
                        $before = $current[$row['module']][$roleKey]->permission ?? 'none';
                        if ($before !== $perm) {
                            $moduleChanged = true;
                        }
                        PermissionMatrixEntry::updateOrCreate(
                            ['company_id' => null, 'role_key' => $roleKey, 'module' => $row['module']],
                            ['permission' => $perm, 'updated_by_id' => $request->user()->id],
                        );
                    }
                    $changed += $moduleChanged ? 1 : 0;
                }

                return $changed;
            });

            $this->captureSnapshot($request->user(), $changedModules);

            return $this->index();
        });
    }

    /** GET /admin/permissions/history — paginated save log (FE completion request §2.3). */
    public function history(Request $request): JsonResponse
    {
        return $this->run(function () use ($request) {
            $perPage = min((int) $request->query('pageSize', 20), 100);
            $p = PermissionSnapshot::orderByDesc('created_at')
                ->paginate($perPage, ['*'], 'page', (int) $request->query('page', 1));

            return $this->paginated($p, array_map(fn (PermissionSnapshot $s) => [
                'id' => $s->id,
                'savedBy' => ['id' => $s->saved_by_id, 'name' => $s->saved_by_name],
                'savedAt' => optional($s->created_at)->toIso8601String(),
                'changesCount' => $s->changes_count,
                'summaryAr' => $s->summary_ar,
            ], $p->items()));
        });
    }

    /** GET /admin/permissions/history/{snapshotId} — full matrix at that point. */
    public function historyShow(string $snapshotId): JsonResponse
    {
        return $this->run(fn () => $this->ok(PermissionSnapshot::findOrFail($snapshotId)->snapshot));
    }

    /** POST /admin/permissions/history/{snapshotId}/restore — re-apply a snapshot. */
    public function restore(Request $request, AuditService $audit, RealtimeBroadcaster $rt, string $snapshotId): JsonResponse
    {
        return $this->run(function () use ($request, $audit, $rt, $snapshotId) {
            $snap = PermissionSnapshot::findOrFail($snapshotId);
            $rows = $snap->snapshot['matrix'] ?? [];

            DB::transaction(function () use ($rows, $request) {
                foreach ($rows as $row) {
                    foreach (self::ROLES as $i => $roleKey) {
                        PermissionMatrixEntry::updateOrCreate(
                            ['company_id' => null, 'role_key' => $roleKey, 'module' => $row['module']],
                            ['permission' => $row['perms'][$i] ?? 'none', 'updated_by_id' => $request->user()->id],
                        );
                    }
                }
            });

            $audit->record(
                'permissions', $request->user(), 'permission_matrix', $snapshotId,
                'استعادة صلاحيات من نسخة سابقة', [], ['restoredFrom' => $snapshotId], $request,
            );
            $rt->permissionsMatrixUpdated($request->user()->company_id);
            $this->captureSnapshot($request->user(), $snap->changes_count, 'استعادة نسخة سابقة');

            return $this->index();
        });
    }

    /** Persist the current matrix as an immutable snapshot. */
    private function captureSnapshot(\Modules\Admin\Models\AsabUser $actor, int $changesCount, ?string $summaryAr = null): void
    {
        PermissionSnapshot::create([
            'company_id' => $actor->company_id,
            'saved_by_id' => $actor->id,
            'saved_by_name' => $actor->name,
            'snapshot' => $this->buildMatrix(),
            'changes_count' => $changesCount,
            'summary_ar' => $summaryAr ?? 'تم تعديل صلاحيات '.$changesCount.' وحدة',
            'created_at' => now(),
        ]);
    }

    public function clone(Request $request): JsonResponse
    {
        return $this->run(function () use ($request) {
            $data = $request->validate([
                'fromRole' => 'required|in:'.implode(',', self::ROLES),
                'toRole' => 'required|in:'.implode(',', self::ROLES),
            ]);

            DB::transaction(function () use ($data, $request) {
                $source = PermissionMatrixEntry::whereNull('company_id')->where('role_key', $data['fromRole'])->get();
                foreach ($source as $entry) {
                    PermissionMatrixEntry::updateOrCreate(
                        ['company_id' => null, 'role_key' => $data['toRole'], 'module' => $entry->module],
                        ['permission' => $entry->permission, 'updated_by_id' => $request->user()->id],
                    );
                }
            });

            return $this->index();
        });
    }
}
