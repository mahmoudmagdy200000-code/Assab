<?php

namespace Modules\Admin\Http\Controllers\Admin;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\DB;
use Modules\Admin\Http\Controllers\AsabController;
use Modules\Admin\Models\AsabBrand;
use Modules\Admin\Models\AsabRestaurant;
use Modules\Admin\Models\AsabSupplier;
use Modules\Admin\Models\Asset;
use Modules\Admin\Models\Employee;
use Modules\Admin\Models\InventoryCatalogItem;
use Modules\Admin\Models\UploadStatus;
use Modules\Branch\Models\Branch;

/**
 * Admin Excel/CSV bulk uploads (BACKEND_API_SPEC.md §6.1.5). Parses CSV rows
 * (Arabic headers per spec) into catalog / suppliers / employees / assets.
 */
class UploadController extends AsabController
{
    private const TEMPLATES = [
        'sales-items' => ['رمز الصنف', 'اسم الصنف', 'الفئة', 'وحدة البيع', 'السعر'],
        'raw-materials' => ['رمز المادة', 'اسم المادة', 'الفئة', 'وحدة القياس', 'التكلفة'],
        'suppliers' => ['رقم المورد', 'اسم المورد', 'الفئة', 'جهة الاتصال', 'شروط الدفع'],
        'employees' => ['الاسم', 'رقم الهوية', 'الوظيفة', 'الراتب', 'تاريخ التعيين'],
        'fixed-assets' => ['اسم الأصل', 'الفئة', 'اسم الفرع', 'رقم الفاتورة', 'التكلفة (ر.س)', 'العمر الافتراضي (شهر)', 'أمين العهدة', 'ملاحظات'],
    ];

    public function brandUpload(Request $request, \Modules\Admin\Services\RealtimeBroadcaster $rt, string $brandId, string $type): JsonResponse
    {
        return $this->run(function () use ($request, $rt, $brandId, $type) {
            $brand = AsabBrand::findOrFail($brandId);
            $request->validate(['file' => 'required|file']);
            $rows = $this->parse($request->file('file'));

            // FE completion request §1.7 (Option B) — emit a processing tick, then a terminal tick.
            $rt->brandUploadProgress($brand->company_id, $brand->id, $type, 'processing', 0, 0, 0);

            $count = 0;
            $errors = [];
            DB::transaction(function () use ($rows, $brand, $type, &$count, &$errors) {
                foreach ($rows as $i => $row) {
                    try {
                        if ($type === 'sales-items' || $type === 'raw-materials') {
                            InventoryCatalogItem::create([
                                'brand_id' => $brand->id,
                                'name' => $row[1] ?? $row['اسم الصنف'] ?? $row['اسم المادة'] ?? '',
                                'category' => $row[2] ?? $row['الفئة'] ?? null,
                                'unit' => $row[3] ?? null,
                                'status' => 'active',
                            ]);
                        } elseif ($type === 'suppliers') {
                            AsabSupplier::create([
                                'company_id' => $brand->company_id,
                                'brand_id' => $brand->id,
                                'name' => $row[1] ?? $row['اسم المورد'] ?? '',
                                'category' => $row[2] ?? null,
                                'contact_name' => $row[3] ?? null,
                                'payment_terms' => $row[4] ?? null,
                                'status' => 'active',
                            ]);
                        }
                        $count++;
                    } catch (\Throwable $e) {
                        $errors[] = ['row' => $i + 2, 'message' => $e->getMessage()];
                    }
                }
            });

            $this->stampStatus('brand', $brand->id, $type, $count, $request, $errors);
            $rt->brandUploadProgress($brand->company_id, $brand->id, $type, 'done', 100, $count, count($errors));

            return $this->ok(['uploadedCount' => $count, 'errors' => $errors]);
        });
    }

    public function employees(Request $request, string $restaurantId): JsonResponse
    {
        return $this->run(function () use ($request, $restaurantId) {
            $restaurant = AsabRestaurant::findOrFail($restaurantId);
            $request->validate(['file' => 'required|file']);
            $rows = $this->parse($request->file('file'));
            $branchId = optional(Branch::where('asab_restaurant_id', $restaurant->id)->first())->id;

            $count = 0;
            $errors = [];
            DB::transaction(function () use ($rows, $restaurant, $branchId, &$count, &$errors) {
                foreach ($rows as $i => $row) {
                    try {
                        Employee::create([
                            'company_id' => $restaurant->company_id,
                            'branch_id' => $branchId,
                            'emp_number' => $row[1] ?? $row['رقم الهوية'] ?? (string) ($i + 1),
                            'name' => $row[0] ?? $row['الاسم'] ?? '',
                            'national_id' => $row[1] ?? null,
                            'role' => $row[2] ?? $row['الوظيفة'] ?? 'موظف',
                            'monthly_salary' => (int) round(((float) ($row[3] ?? 0)) * 100),
                            'hire_date' => $row[4] ?? now(),
                            'status' => 'active',
                        ]);
                        $count++;
                    } catch (\Throwable $e) {
                        $errors[] = ['row' => $i + 2, 'message' => $e->getMessage()];
                    }
                }
            });

            $this->stampStatus('restaurant', $restaurant->id, 'employees', $count, $request, $errors);

            return $this->ok(['uploadedCount' => $count, 'errors' => $errors]);
        });
    }

    public function fixedAssets(Request $request, string $branchId): JsonResponse
    {
        return $this->run(function () use ($request, $branchId) {
            $branch = Branch::findOrFail($branchId);
            $request->validate(['file' => 'required|file']);
            $rows = $this->parse($request->file('file'));

            $count = 0;
            $errors = [];
            DB::transaction(function () use ($rows, $branch, $request, &$count, &$errors) {
                foreach ($rows as $i => $row) {
                    try {
                        $cost = (int) round(((float) ($row[4] ?? 0)) * 100);
                        Asset::create([
                            'company_id' => $branch->asab_company_id ?? $request->user()->company_id,
                            'public_id' => 'FA-'.str_pad((string) (Asset::count() + 1), 3, '0', STR_PAD_LEFT),
                            'name' => $row[0] ?? $row['اسم الأصل'] ?? '',
                            'category' => $row[1] ?? null,
                            'branch_id' => $branch->id,
                            'inv_num' => $row[3] ?? null,
                            'cost' => $cost,
                            'book_value' => $cost,
                            'useful_life_months' => (int) ($row[5] ?? 60),
                            'custodian' => $row[6] ?? null,
                            'case_type' => 'branch_upload',
                            'status' => 'pending_branch',
                            'submitted_by_id' => $request->user()->id,
                            'purchased_at' => now(),
                        ]);
                        $count++;
                    } catch (\Throwable $e) {
                        $errors[] = ['row' => $i + 2, 'message' => $e->getMessage()];
                    }
                }
            });

            $this->stampStatus('branch', $branch->id, 'fixed-assets', $count, $request, $errors);

            return $this->ok(['assetCount' => $count, 'errors' => $errors]);
        });
    }

    public function template(string $type): Response
    {
        $headers = self::TEMPLATES[$type] ?? [];
        $csv = "\xEF\xBB\xBF".implode(',', $headers)."\n"; // UTF-8 BOM for Excel Arabic

        return response($csv, 200, [
            'Content-Type' => 'text/csv; charset=UTF-8',
            'Content-Disposition' => "attachment; filename=\"{$type}-template.csv\"",
        ]);
    }

    public function status(string $brandId): JsonResponse
    {
        return $this->run(function () use ($brandId) {
            $brand = AsabBrand::find($brandId);
            $restIds = $brand ? AsabRestaurant::where('brand_id', $brand->id)->pluck('id') : collect();

            $rows = UploadStatus::where('owner_id', $brandId)
                ->orWhereIn('owner_id', $restIds)
                ->get();

            $statuses = $rows->keyBy(fn ($s) => $s->owner_type.':'.$s->upload_type);
            $has = fn ($k) => $statuses->has($k);

            // FE completion request §1.7 (Option A) — per-upload progress for polling.
            $uploads = $rows->map(fn (UploadStatus $s) => [
                'type' => $s->upload_type,
                'status' => $s->status ?? ($s->uploaded_count > 0 ? 'done' : 'queued'),
                'progressPct' => (int) ($s->progress_pct ?? ($s->uploaded_count > 0 ? 100 : 0)),
                'parsedRows' => (int) ($s->parsed_rows ?? $s->uploaded_count),
                'failedRows' => (int) ($s->failed_rows ?? 0),
                'failureReason' => $s->failure_reason,
                'startedAt' => optional($s->started_at)->toIso8601String(),
                'finishedAt' => optional($s->finished_at)->toIso8601String(),
            ])->values()->all();

            return $this->ok([
                'uploads' => $uploads,
                'shared' => [
                    'sales' => $has('brand:sales-items'),
                    'materials' => $has('brand:raw-materials'),
                    'suppliers' => $has('brand:suppliers'),
                ],
                'completionPct' => (int) round(
                    collect(['brand:sales-items', 'brand:raw-materials', 'brand:suppliers'])->filter($has)->count() / 3 * 100
                ),
            ]);
        });
    }

    /** @return array<int, array> */
    private function parse($file): array
    {
        $rows = array_map('str_getcsv', file($file->getRealPath()));
        if (empty($rows)) {
            return [];
        }
        // Drop a header row if the first cell isn't numeric-ish data.
        array_shift($rows);

        return array_values(array_filter($rows, fn ($r) => count(array_filter($r)) > 0));
    }

    /** @param  array<int, array{row:int, message:string}>  $errors */
    private function stampStatus(string $ownerType, string $ownerId, string $type, int $count, Request $request, array $errors = []): void
    {
        $status = $errors && $count === 0 ? 'failed' : 'done';
        UploadStatus::updateOrCreate(
            ['owner_type' => $ownerType, 'owner_id' => $ownerId, 'upload_type' => $type],
            [
                'uploaded_count' => $count,
                'uploaded_at' => now(),
                'uploaded_by_id' => $request->user()->id,
                'status' => $status,
                'progress_pct' => 100,
                'parsed_rows' => $count,
                'failed_rows' => count($errors),
                'failure_reason' => $errors[0]['message'] ?? null,
                'started_at' => now(),
                'finished_at' => now(),
            ],
        );
    }
}
