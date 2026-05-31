<?php

namespace Modules\Admin\Http\Controllers\Admin;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Modules\Admin\Http\Controllers\AsabController;
use Modules\Admin\Models\PermissionMatrixEntry;

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
        return $this->run(function () {
            $entries = PermissionMatrixEntry::whereNull('company_id')->get();
            $byModule = $entries->groupBy('module');

            $matrix = $byModule->map(function ($rows, $module) {
                $map = $rows->keyBy('role_key');

                return [
                    'module' => $module,
                    'perms' => array_map(fn ($role) => $map[$role]->permission ?? 'none', self::ROLES),
                ];
            })->values()->all();

            return $this->ok([
                'matrix' => $matrix,
                'roles' => self::ROLES,
                'legend' => self::LEGEND,
            ]);
        });
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

            DB::transaction(function () use ($data, $request) {
                foreach ($data['matrix'] as $row) {
                    foreach (self::ROLES as $i => $roleKey) {
                        $perm = $row['perms'][$i] ?? 'none';
                        PermissionMatrixEntry::updateOrCreate(
                            ['company_id' => null, 'role_key' => $roleKey, 'module' => $row['module']],
                            ['permission' => $perm, 'updated_by_id' => $request->user()->id],
                        );
                    }
                }
            });

            return $this->index();
        });
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
