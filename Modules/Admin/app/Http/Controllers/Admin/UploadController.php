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
use Modules\Admin\Models\InventoryCatalogItem;
use Modules\Admin\Models\UploadStatus;
use Modules\Branch\Models\Branch;
use Modules\Purchase\Models\Item as PurchaseItem;

/**
 * Admin Excel/CSV bulk uploads (BACKEND_API_SPEC.md §6.1.5). Parses CSV rows
 * (Arabic headers per spec) into catalog / suppliers / employees / assets.
 */
class UploadController extends AsabController
{
    // Item templates use 'التصنيف' for the grouping column to match the mobile
    // app's item labels (client meeting: rename the 'category' field).
    private const TEMPLATES = [
        'sales-items' => ['رمز الصنف', 'اسم الصنف', 'التصنيف', 'وحدة البيع', 'السعر'],
        'raw-materials' => ['رمز المادة', 'اسم المادة', 'التصنيف', 'وحدة القياس', 'التكلفة'],
        'suppliers' => ['رقم المورد', 'اسم المورد', 'الفئة', 'جهة الاتصال', 'شروط الدفع'],
        'fixed-assets' => ['اسم الأصل', 'الفئة', 'اسم الفرع', 'رقم الفاتورة', 'التكلفة (ر.س)', 'العمر الافتراضي (شهر)', 'أمين العهدة', 'ملاحظات'],
    ];

    /** Upload types accepted by brandUpload(); anything else is a client error. */
    private const BRAND_UPLOAD_TYPES = ['sales-items', 'raw-materials', 'suppliers'];

    public function brandUpload(Request $request, \Modules\Admin\Services\RealtimeBroadcaster $rt, string $brandId, string $type): JsonResponse
    {
        return $this->run(function () use ($request, $rt, $brandId, $type) {
            if (! in_array($type, self::BRAND_UPLOAD_TYPES, true)) {
                return $this->fail('INVALID_INPUT', 'Unknown upload type', 'نوع رفع غير معروف', [], 400);
            }

            $brand = AsabBrand::findOrFail($brandId);
            // Legacy .xls is not supported by the OpenSpout XLSX reader — reject at validation.
            $request->validate(['file' => 'required|file|mimes:xlsx,csv,txt']);
            $rows = $this->parse($request->file('file'));

            // FE completion request §1.7 (Option B) — emit a processing tick, then a terminal tick.
            $rt->brandUploadProgress($brand->company_id, $brand->id, $type, 'processing', 0, 0, 0);

            $count = 0;
            $errors = [];
            DB::transaction(function () use ($rows, $brand, $type, &$count, &$errors) {
                foreach ($rows as $i => $row) {
                    try {
                        if ($type === 'sales-items' || $type === 'raw-materials') {
                            $this->importCatalogRow($brand, $type, $row);
                        } else {
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

            return $this->ok([
                'uploadId' => (string) \Illuminate\Support\Str::uuid(),
                'rowsImported' => $count,
                'uploadedCount' => $count,
                'errors' => $errors,
                'status' => 'done',
            ]);
        });
    }

    /**
     * One catalog row: sales items and raw materials share the brand catalog
     * table but stay separable via `type`, and the price column is persisted
     * in halalas. Raw materials additionally upsert into the Purchase module's
     * items table so uploaded materials actually reach the purchasing flows.
     */
    private function importCatalogRow(AsabBrand $brand, string $type, array $row): void
    {
        $code = trim((string) ($row[0] ?? ''));
        $name = trim((string) ($row[1] ?? $row['اسم الصنف'] ?? $row['اسم المادة'] ?? ''));
        $category = $row[2] ?? $row['التصنيف'] ?? $row['الفئة'] ?? null;
        $unit = $row[3] ?? null;
        $priceHalalas = (int) round(((float) ($row[4] ?? 0)) * 100);

        InventoryCatalogItem::create([
            'brand_id' => $brand->id,
            'type' => $type === 'raw-materials' ? InventoryCatalogItem::TYPE_RAW_MATERIAL : InventoryCatalogItem::TYPE_SALES_ITEM,
            'name' => $name,
            'category' => $category,
            'unit' => $unit,
            'unit_price' => $priceHalalas,
            'status' => 'active',
        ]);

        if ($type === 'raw-materials' && $name !== '') {
            // Create-only into the (global) purchasing items table: never
            // overwrite an existing row — another brand may own that code.
            $existing = PurchaseItem::withTrashed()
                ->where($code !== '' ? 'code' : 'name', $code !== '' ? $code : $name)
                ->first();

            if ($existing === null) {
                PurchaseItem::create([
                    'name' => $name,
                    'code' => $code !== '' ? $code : null,
                    'unit' => $unit,
                    'category' => $category,
                    'is_active' => true,
                ]);
            } elseif ($existing->trashed()) {
                $existing->restore();
            }
        }
    }

    public function fixedAssets(Request $request, string $branchId): JsonResponse
    {
        return $this->run(function () use ($request, $branchId) {
            $branch = Branch::findOrFail($branchId);
            $request->validate(['file' => 'required|file|mimes:xlsx,csv,txt']);
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

    public function template(Request $request, string $type): Response
    {
        // Unknown/retired template types (e.g. the dropped employees upload)
        // must 404, not hand out an empty workbook.
        abort_unless(isset(self::TEMPLATES[$type]), 404);
        $headers = self::TEMPLATES[$type];

        // CSV stays available via ?format=csv (UTF-8 BOM for Excel Arabic).
        if ($request->query('format') === 'csv') {
            $csv = "\xEF\xBB\xBF".implode(',', $headers)."\n";

            return response($csv, 200, [
                'Content-Type' => 'text/csv; charset=UTF-8',
                'Content-Disposition' => "attachment; filename=\"{$type}-template.csv\"",
            ]);
        }

        // Default: a real .xlsx workbook with the same header row (OpenSpout).
        $tmp = tempnam(sys_get_temp_dir(), 'tpl_').'.xlsx';
        $writer = new \OpenSpout\Writer\XLSX\Writer;
        $writer->openToFile($tmp);
        $writer->addRow(\OpenSpout\Common\Entity\Row::fromValues($headers));
        $writer->close();

        $contents = file_get_contents($tmp);
        @unlink($tmp);

        return response($contents, 200, [
            'Content-Type' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
            'Content-Disposition' => "attachment; filename=\"{$type}-template.xlsx\"",
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

    /**
     * Parse an uploaded CSV or XLSX into positional row arrays. XLSX support
     * matters because template() hands out .xlsx files by default — the same
     * file must round-trip through the upload endpoints.
     *
     * @return array<int, array>
     */
    private function parse($file): array
    {
        $ext = strtolower($file->getClientOriginalExtension());

        if ($ext === 'xlsx') {
            $reader = new \OpenSpout\Reader\XLSX\Reader;
            $reader->open($file->getRealPath());
            $rows = [];
            foreach ($reader->getSheetIterator() as $sheet) {
                foreach ($sheet->getRowIterator() as $row) {
                    $rows[] = array_map(
                        fn ($cell) => $cell instanceof \DateTimeInterface ? $cell->format('Y-m-d') : $cell,
                        $row->toArray(),
                    );
                }
                break; // first sheet only, matching the single-sheet templates
            }
            $reader->close();
        } else {
            $rows = array_map('str_getcsv', file($file->getRealPath()));
        }

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
