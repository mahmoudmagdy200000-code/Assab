<?php

namespace Modules\Admin\Http\Controllers\Branch;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Modules\Admin\Http\Controllers\AsabController;
use Modules\Admin\Models\Employee;
use Modules\Admin\Models\Operation;
use Modules\Admin\Services\OperationFactory;

/**
 * Branch Manager (مدير الفرع) web companion (BACKEND_API_SPEC.md §6.4).
 * Upload endpoints create pending Operations that enter the approval pipeline.
 */
class BranchDashboardController extends AsabController
{
    public function __construct(private readonly OperationFactory $factory) {}

    public function overview(Request $request): JsonResponse
    {
        return $this->run(function () use ($request) {
            $branchId = $this->branchId($request);
            $today = Operation::where('branch_id', $branchId)->whereDate('operation_date', today());

            return $this->ok([
                'branch' => ['id' => $branchId],
                'kpis' => [
                    'todaySales' => (int) (clone $today)->where('module_key', 'sales')->sum('amount'),
                    'todayOrders' => (clone $today)->count(),
                    'activeEmployees' => Employee::where('branch_id', $branchId)->where('status', 'active')->count(),
                    'requiredReportsCount' => 6,
                ],
                'requiredReports' => $this->requiredReports($branchId),
            ]);
        });
    }

    public function uploadStatus(Request $request): JsonResponse
    {
        return $this->run(function () use ($request) {
            $branchId = $this->branchId($request);

            return $this->ok(['date' => today()->toIso8601String(), 'reports' => $this->requiredReports($branchId)]);
        });
    }

    public function upload(Request $request, string $reportType): JsonResponse
    {
        return $this->run(function () use ($request, $reportType) {
            $moduleMap = [
                'sales' => 'sales', 'inventory' => 'inventory', 'cash' => 'cash',
                'waste' => 'waste', 'purchases' => 'purchases', 'expenses' => 'expenses',
            ];
            if (! isset($moduleMap[$reportType])) {
                return $this->fail('INVALID_INPUT', 'Unknown report type', 'نوع تقرير غير معروف', [], 400);
            }

            $payload = $request->all();
            $amount = (int) ($request->input('totalSales') ?? $request->input('amount') ?? 0);
            $op = $this->factory->createFromUpload(
                $moduleMap[$reportType],
                $payload,
                $request->user(),
                $this->branchId($request),
                $amount,
            );

            return $this->created([
                'id' => $op->id,
                'publicId' => $op->public_id,
                'moduleKey' => $op->module_key,
                'status' => $op->status,
                'origin' => $op->origin,
            ]);
        });
    }

    public function employees(Request $request): JsonResponse
    {
        return $this->run(function () use ($request) {
            $items = Employee::where('branch_id', $this->branchId($request))->orderBy('name')->get();

            return $this->listResponse($items->map(fn ($e) => [
                'id' => $e->id,
                'empNumber' => $e->emp_number,
                'name' => $e->name,
                'role' => $e->role,
                'monthlySalary' => $e->monthly_salary,
                'shiftType' => $e->shift_type,
                'status' => $e->status,
            ])->all());
        });
    }

    public function storeEmployee(Request $request): JsonResponse
    {
        return $this->run(function () use ($request) {
            $data = $request->validate([
                'empNumber' => 'required|string|max:32',
                'name' => 'required|string|max:200',
                'nationalId' => 'nullable|string|max:32',
                'role' => 'required|string|max:80',
                'monthlySalary' => 'required|integer|min:0',
                'shiftType' => 'nullable|string|max:16',
                'hireDate' => 'nullable|date',
            ]);

            $emp = Employee::create([
                'branch_id' => $this->branchId($request),
                'emp_number' => $data['empNumber'],
                'name' => $data['name'],
                'national_id' => $data['nationalId'] ?? null,
                'role' => $data['role'],
                'monthly_salary' => $data['monthlySalary'],
                'shift_type' => $data['shiftType'] ?? null,
                'hire_date' => $data['hireDate'] ?? now(),
                'status' => 'active',
            ]);

            return $this->created(['id' => $emp->id, 'empNumber' => $emp->emp_number, 'name' => $emp->name]);
        });
    }

    public function items(Request $request): JsonResponse
    {
        return $this->run(function () {
            try {
                $items = \Modules\Inventory\Models\InventoryItem::query()->limit(500)->get()
                    ->map(fn ($i) => ['name' => $i->name ?? null, 'unit' => $i->unit ?? null, 'cat' => $i->category ?? null])->all();
            } catch (\Throwable $e) {
                $items = [];
            }

            return $this->ok(['items' => $items, 'configuredBy' => null]);
        });
    }

    public function suppliers(Request $request): JsonResponse
    {
        try {
            $items = \Modules\Supplier\Models\Supplier::orderBy('name')->limit(200)->get()
                ->map(fn ($s) => ['id' => $s->id, 'name' => $s->name ?? null])->all();
        } catch (\Throwable $e) {
            $items = [];
        }

        return $this->listResponse($items);
    }

    public function settings(Request $request): JsonResponse
    {
        return $this->run(function () use ($request) {
            $row = \Modules\Admin\Models\Setting::where('group_key', 'branch:'.$this->branchId($request))->first();

            return $this->ok($row->payload ?? [
                'workingHours' => ['open' => '08:00', 'close' => '23:00'],
                'autoCloseShift' => false,
                'cashAlertThreshold' => 0,
            ]);
        });
    }

    public function updateSettings(Request $request): JsonResponse
    {
        return $this->run(function () use ($request) {
            $payload = $request->all();
            \Modules\Admin\Models\Setting::updateOrCreate(
                ['company_id' => $request->user()->company_id, 'group_key' => 'branch:'.$this->branchId($request)],
                ['payload' => $payload],
            );

            return $this->ok($payload);
        });
    }

    public function confirmAsset(Request $request, string $id): JsonResponse
    {
        return $this->run(function () use ($id) {
            $asset = \Modules\Admin\Models\Asset::findOrFail($id);
            $asset->update(['status' => 'pending_accountant']);

            return $this->ok(['id' => $asset->id, 'status' => $asset->status]);
        });
    }

    public function reconfirmInventory(Request $request, string $id): JsonResponse
    {
        return $this->run(function () use ($id) {
            $op = \Modules\Admin\Models\Operation::where('id', $id)->orWhere('public_id', $id)->firstOrFail();
            $payload = $op->payload ?? [];
            $payload['branchReconfirmedAt'] = now()->toIso8601String();
            $op->update(['payload' => $payload]);

            return $this->ok(['id' => $op->id, 'reconfirmed' => true]);
        });
    }

    private function branchId(Request $request): ?string
    {
        $ctx = app(\Modules\Admin\Support\TenantContext::class);

        return $request->query('branchId') ?? ($ctx->branchIds[0] ?? null);
    }

    private function requiredReports(?string $branchId): array
    {
        $types = [
            ['id' => 'sales', 'name' => 'تقرير المبيعات', 'module' => 'sales'],
            ['id' => 'inventory', 'name' => 'جرد المخزون اليومي', 'module' => 'inventory'],
            ['id' => 'cash', 'name' => 'تقرير النقدية', 'module' => 'cash'],
            ['id' => 'waste', 'name' => 'تقرير الهدر', 'module' => 'waste'],
            ['id' => 'purchases', 'name' => 'المشتريات', 'module' => 'purchases'],
            ['id' => 'expenses', 'name' => 'المصروفات', 'module' => 'expenses'],
        ];

        return array_map(function ($t) use ($branchId) {
            $uploaded = Operation::where('branch_id', $branchId)
                ->where('module_key', $t['module'])
                ->whereDate('operation_date', today())->exists();

            return [
                'id' => $t['id'],
                'name' => $t['name'],
                'required' => true,
                'uploadedToday' => $uploaded,
                'lastStatus' => $uploaded ? 'success' : 'missing',
            ];
        }, $types);
    }
}
