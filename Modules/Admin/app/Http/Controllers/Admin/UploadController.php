<?php

namespace Modules\Admin\Http\Controllers\Admin;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\DB;
use Modules\Admin\Exceptions\AsabException;
use Modules\Admin\Http\Controllers\AsabController;
use Modules\Admin\Http\Controllers\Concerns\GeneratesEmployeeNumbers;
use Modules\Admin\Http\Controllers\Concerns\MapsAssetSpreadsheet;
use Modules\Admin\Models\AsabBrand;
use Modules\Admin\Models\AsabRestaurant;
use Modules\Admin\Models\AsabSupplier;
use Modules\Admin\Models\Asset;
use Modules\Admin\Models\Employee;
use Modules\Admin\Models\InventoryCatalogItem;
use Modules\Admin\Models\UploadStatus;
use Modules\Admin\Services\CashierProvisioningService;
use Modules\Admin\Support\AssetEnums;
use Modules\Branch\Models\Branch;
use Modules\Purchase\Models\Item as PurchaseItem;

/**
 * Admin Excel/CSV bulk uploads (BACKEND_API_SPEC.md §6.1.5). Parses CSV/XLSX
 * rows (Arabic headers per spec) into catalog / suppliers / assets.
 */
class UploadController extends AsabController
{
    use GeneratesEmployeeNumbers, MapsAssetSpreadsheet;

    // Item templates use 'التصنيف' for the grouping column to match the mobile
    // app's item labels (client meeting: rename the 'category' field).
    private const TEMPLATES = [
        'sales-items' => ['رمز الصنف', 'اسم الصنف', 'التصنيف', 'وحدة البيع', 'السعر'],
        'raw-materials' => ['رمز المادة', 'اسم المادة', 'التصنيف', 'وحدة القياس', 'التكلفة'],
        'suppliers' => ['رقم المورد', 'اسم المورد', 'الفئة', 'جهة الاتصال', 'شروط الدفع'],
        'fixed-assets' => ['اسم الأصل', 'الفئة', 'اسم الفرع', 'رقم الفاتورة', 'التكلفة (ر.س)', 'العمر الافتراضي (شهر)', 'أمين العهدة', 'ملاحظات'],
        // Restored: the employees template was dropped to avoid confusion with
        // user management, but «موظفي المطاعم» is an operational roster
        // (asab_employees), not a set of dashboard logins — the two never met.
        // Cashier-role rows still provision a mobile login, exactly as the
        // one-by-one add already does.
        'employees' => ['اسم الموظف', 'الوظيفة', 'اسم الفرع', 'رقم الجوال', 'رقم الهوية', 'الراتب الشهري (ر.س)', 'نوع الوردية', 'تاريخ التعيين'],
    ];

    /** Aliases per employees column, so a hand-made sheet still maps. */
    private const EMPLOYEE_HEADER_ALIASES = [
        'name' => ['اسم الموظف', 'الاسم', 'name', 'employee name'],
        'role' => ['الوظيفة', 'الدور', 'role', 'job title'],
        'branch' => ['اسم الفرع', 'الفرع', 'branch', 'branch name'],
        'phone' => ['رقم الجوال', 'الجوال', 'الهاتف', 'phone', 'mobile'],
        'nationalId' => ['رقم الهوية', 'الهوية', 'national id', 'iqama'],
        'salary' => ['الراتب الشهري (ر.س)', 'الراتب الشهري', 'الراتب', 'salary', 'monthly salary'],
        'shift' => ['نوع الوردية', 'الوردية', 'shift', 'shift type'],
        'hireDate' => ['تاريخ التعيين', 'تاريخ التوظيف', 'hire date', 'joining date'],
    ];

    /** The client's own fixed-assets workbook; accepted alongside TEMPLATES['fixed-assets']. */
    private const FIXED_ASSETS_EN_TEMPLATE = [
        'Serial Number', 'Zone', 'Asset Category (Type)', 'Asset Name', 'Total Quantity',
        'Excellent', 'Maintenance', 'Problem', 'Purchase Date', 'Purchase Value', 'Notes',
    ];

    /** Upload types accepted by brandUpload(); anything else is a client error. */
    private const BRAND_UPLOAD_TYPES = ['sales-items', 'raw-materials', 'suppliers'];

    /**
     * `mimes:` resolves the extension by sniffing content (guessExtension()),
     * which delegates to the host's fileinfo/libmagic: where that is missing or
     * stale, getMimeType() returns null and the rule then rejects EVERY upload —
     * a failure that depends on the machine and so never shows up locally.
     * `extensions:` compares the client extension, which is what parse() already
     * branches on, so the gate and the parser agree by construction.
     * `file` is kept for its is_uploaded_file integrity check.
     */
    private const FILE_RULES = ['required', 'file', 'extensions:xlsx,csv,txt', 'max:2048'];

    public function brandUpload(Request $request, \Modules\Admin\Services\RealtimeBroadcaster $rt, string $brandId, string $type): JsonResponse
    {
        return $this->run(function () use ($request, $rt, $brandId, $type) {
            if (! in_array($type, self::BRAND_UPLOAD_TYPES, true)) {
                return $this->fail('INVALID_INPUT', 'Unknown upload type', 'نوع رفع غير معروف', [], 400);
            }

            $brand = AsabBrand::findOrFail($brandId);
            $request->validate(['file' => self::FILE_RULES]);
            // Throws before the first tick: a file with no data rows must not
            // stamp an UploadStatus row that reads as a completed upload.
            [, $rows] = $this->parse($request->file('file'), $type);

            // FE completion request §1.7 (Option B) — emit a processing tick, then a terminal tick.
            $rt->brandUploadProgress($brand->company_id, $brand->id, $type, 'processing', 0, 0, 0);

            $count = 0;
            $errors = [];
            DB::transaction(function () use ($rows, $brand, $type, &$count, &$errors) {
                foreach ($rows as $i => $row) {
                    try {
                        if ($type === 'suppliers') {
                            $this->importSupplierRow($brand, $row);
                        } else {
                            $this->importCatalogRow($brand, $type, $row);
                        }
                        $count++;
                    } catch (\Throwable $e) {
                        $errors[] = ['row' => $i + 2, 'message' => $e->getMessage()];
                    }
                }
            });

            $status = $this->terminalStatus($count);
            $this->stampStatus('brand', $brand->id, $type, $count, $request, $errors);
            $rt->brandUploadProgress($brand->company_id, $brand->id, $type, $status, 100, $count, count($errors));

            return $this->ok([
                'uploadId' => (string) \Illuminate\Support\Str::uuid(),
                'rowsImported' => $count,
                'uploadedCount' => $count,
                'errors' => $errors,
                'status' => $status,
            ]);
        });
    }

    private function importSupplierRow(AsabBrand $brand, array $row): void
    {
        AsabSupplier::create([
            'company_id' => $brand->company_id,
            'brand_id' => $brand->id,
            'code' => $this->nullIfBlank($row[0] ?? null),
            'name' => $row[1] ?? '',
            'category' => $row[2] ?? null,
            'contact_name' => $row[3] ?? null,
            'payment_terms' => $row[4] ?? null,
            'status' => 'active',
        ]);
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
        $name = trim((string) ($row[1] ?? ''));
        $category = $row[2] ?? null;
        $unit = $row[3] ?? null;
        $priceHalalas = $this->toHalalas($row[4] ?? 0);

        InventoryCatalogItem::create([
            'brand_id' => $brand->id,
            'type' => $type === 'raw-materials' ? InventoryCatalogItem::TYPE_RAW_MATERIAL : InventoryCatalogItem::TYPE_SALES_ITEM,
            'name' => $name,
            'code' => $code !== '' ? $code : null,
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
            $request->validate(['file' => self::FILE_RULES]);
            [$header, $rows] = $this->parse($request->file('file'), 'fixed-assets');

            $result = $this->importAssetRows($rows, $header, $request, [
                'company_id' => $branch->asab_company_id ?? $request->user()->company_id,
                'branch_id' => $branch->id,
                'case_type' => 'branch_upload',
            ]);

            $this->stampStatus('branch', $branch->id, 'fixed-assets', $result['count'], $request, $result['errors']);

            return $this->ok(['assetCount' => $result['count'], 'errors' => $result['errors']]);
        });
    }

    /**
     * Brand-level fixed-assets upload — the Data Upload tab is per brand.
     * Neither accepted layout names a branch (the English one carries a Zone
     * instead), so rows land with a NULL branch_id (the column is nullable and
     * indexed) and stay pending assignment to a branch.
     */
    public function brandFixedAssets(Request $request, string $brandId): JsonResponse
    {
        return $this->run(function () use ($request, $brandId) {
            $brand = AsabBrand::findOrFail($brandId);
            $request->validate(['file' => self::FILE_RULES]);
            [$header, $rows] = $this->parse($request->file('file'), 'fixed-assets');

            $result = $this->importAssetRows($rows, $header, $request, [
                'company_id' => $brand->company_id,
                'branch_id' => null,
                'case_type' => 'brand_upload',
            ]);

            $this->stampStatus('brand', $brand->id, 'fixed-assets', $result['count'], $request, $result['errors']);

            return $this->ok(['assetCount' => $result['count'], 'errors' => $result['errors']]);
        });
    }

    /**
     * @param  array<string, mixed>  $owner
     * @return array{count:int, errors:array<int, array{row:int, message:string}>}
     */
    private function importAssetRows(array $rows, array $header, Request $request, array $owner): array
    {
        $map = $this->mapAssetHeaders($header);
        $count = 0;
        $errors = [];

        DB::transaction(function () use ($rows, $map, $owner, $request, &$count, &$errors) {
            // public_id is UNIQUE: seed the counter from the highest existing
            // FA-#### suffix (withTrashed — soft-deleted rows still hold their
            // id), not from count(), which collides after deletes.
            $seq = $this->maxFixedAssetSequence();
            foreach ($rows as $i => $row) {
                try {
                    $this->importAssetRow($row, $map, $owner, $request, $seq);
                    $count++;
                } catch (\Throwable $e) {
                    $errors[] = ['row' => $i + 2, 'message' => $e->getMessage()];
                }
            }
        });

        return ['count' => $count, 'errors' => $errors];
    }

    /** @param  array<string, mixed>  $owner */
    private function importAssetRow(array $row, array $map, array $owner, Request $request, int &$seq): void
    {
        $qty = $this->toInt($this->cell($row, $map, 'quantity'));
        $excellent = $this->toInt($this->cell($row, $map, 'excellent'));
        $maintenance = $this->toInt($this->cell($row, $map, 'maintenance'));
        $problem = $this->toInt($this->cell($row, $map, 'problem'));
        $counted = (int) $excellent + (int) $maintenance + (int) $problem;

        if ($qty !== null && $counted > $qty) {
            throw new AsabException(
                'INVALID_INPUT',
                "condition counts ({$counted}) exceed total quantity ({$qty})",
                'مجموع حالات الأصل أكبر من الكمية الإجمالية',
                422,
            );
        }

        $cost = $this->toHalalas($this->cell($row, $map, 'cost'));

        Asset::create([
            'company_id' => $owner['company_id'],
            'public_id' => $this->nextFixedAssetPublicId($seq),
            'name' => (string) ($this->cell($row, $map, 'name') ?? ''),
            'category' => AssetEnums::canonicalCategory($this->text($row, $map, 'category')),
            'branch_id' => $owner['branch_id'],
            'zone' => $this->text($row, $map, 'zone'),
            'inv_num' => $this->text($row, $map, 'invNum'),
            'serial' => $this->text($row, $map, 'serial'),
            'cost' => $cost,
            'book_value' => $cost,
            'useful_life_months' => $this->toInt($this->cell($row, $map, 'usefulLife')) ?? 60,
            'custodian' => $this->text($row, $map, 'custodian'),
            'notes' => $this->text($row, $map, 'notes'),
            'quantity' => $qty,
            'qty_excellent' => $excellent,
            'qty_maintenance' => $maintenance,
            'qty_problem' => $problem,
            'case_type' => $owner['case_type'],
            'status' => 'pending_branch',
            'submitted_by_id' => $request->user()->id,
            'purchased_at' => $this->toDate($this->cell($row, $map, 'purchasedAt')),
        ]);
    }

    /** Highest numeric FA- suffix across all assets, including soft-deleted. */
    private function maxFixedAssetSequence(): int
    {
        // LENGTH-first ordering keeps FA-1000 above FA-999 (portable MySQL/SQLite).
        $last = Asset::withTrashed()
            ->where('public_id', 'like', 'FA-%')
            ->orderByRaw('LENGTH(public_id) DESC')
            ->orderBy('public_id', 'desc')
            ->value('public_id');

        return $last ? (int) substr($last, 3) : 0;
    }

    /** Next free FA-### id — skips ids taken since the sequence was seeded (e.g. a concurrent upload). */
    private function nextFixedAssetPublicId(int &$seq): string
    {
        do {
            $candidate = 'FA-'.str_pad((string) (++$seq), 3, '0', STR_PAD_LEFT);
        } while (Asset::withTrashed()->where('public_id', $candidate)->exists());

        return $candidate;
    }

    /**
     * Two illustrative rows shipped inside the fixed-assets template, mirroring
     * the client's own workbook. «Sample only» flags them for deletion before
     * upload; the importer keys purely off headers, so leaving them in only
     * imports two throwaway rows — it never breaks the parse.
     */
    private const FIXED_ASSETS_SAMPLE_ROWS = [
        ['SN-12345', 'Kitchen', 'Kitchen Equipment', 'Coffee Machine', 8, 5, 2, 1, '2025-07-01', 28000.00, 'Sample only'],
        ['SN-12346', 'Dining', 'Furniture & Fixtures', 'Dining Table', 9, 4, 3, 2, '2025-07-01', 22000.00, 'Sample only'],
    ];

    public function template(Request $request, string $type): Response
    {
        // Unknown/retired template types (e.g. the dropped employees upload)
        // must 404, not hand out an empty workbook.
        abort_unless(isset(self::TEMPLATES[$type]), 404);

        // Fixed assets ships the client's own English layout (11 columns +
        // sample rows); every other type is its ratified single header row.
        // The importer accepts both asset layouts, so the download matching the
        // client's file is purely a UX alignment.
        $isAssets = $type === 'fixed-assets';
        $headers = $isAssets ? self::FIXED_ASSETS_EN_TEMPLATE : self::TEMPLATES[$type];
        $sampleRows = $isAssets ? self::FIXED_ASSETS_SAMPLE_ROWS : [];

        // CSV stays available via ?format=csv (UTF-8 BOM for Excel Arabic).
        if ($request->query('format') === 'csv') {
            $csv = "\xEF\xBB\xBF".implode(',', $headers)."\n";
            foreach ($sampleRows as $row) {
                $csv .= implode(',', $row)."\n";
            }

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
        foreach ($sampleRows as $row) {
            $writer->addRow(\OpenSpout\Common\Entity\Row::fromValues($row));
        }
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
            // Brand uploads are the only rows keyed to a brand id; branch
            // uploads are stamped owner_type='branch' with a Branch id and are
            // read back through branchStatus().
            $rows = UploadStatus::where('owner_type', 'brand')
                ->where('owner_id', $brandId)
                ->get();

            $has = $this->completedPredicate($rows);
            // The four brand-level steps. fixed-assets used to be excluded from
            // the denominator while still appearing in uploads[], so a brand that
            // had uploaded its assets read as 0% credit for that step and the two
            // fields disagreed.
            $steps = ['sales-items', 'raw-materials', 'suppliers', 'fixed-assets'];

            return $this->ok([
                'uploads' => $this->presentUploads($rows),
                'shared' => [
                    'sales' => $has('sales-items'),
                    'materials' => $has('raw-materials'),
                    'suppliers' => $has('suppliers'),
                    'fixedAssets' => $has('fixed-assets'),
                ],
                'completionPct' => (int) round(collect($steps)->filter($has)->count() / count($steps) * 100),
            ]);
        });
    }

    /**
     * GET /admin/branches/{branchId}/upload-status — the per-branch الأصول
     * الثابتة column. fixedAssets() has always stamped owner_type='branch' rows,
     * but status() hard-filters to brand rows and no branch route existed, so the
     * data was written and unreadable: the column could never leave «لم يُرفع».
     */
    public function branchStatus(string $branchId): JsonResponse
    {
        return $this->run(function () use ($branchId) {
            $branch = Branch::findOrFail($branchId);
            $this->assertBranchAssigned($branch->id);

            $rows = UploadStatus::where('owner_type', 'branch')
                ->where('owner_id', $branch->id)
                ->get();

            $has = $this->completedPredicate($rows);

            return $this->ok([
                'branchId' => $branch->id,
                'uploads' => $this->presentUploads($rows),
                'fixedAssets' => $has('fixed-assets'),
                'completionPct' => $has('fixed-assets') ? 100 : 0,
            ]);
        });
    }

    /**
     * POST /admin/restaurants/{restaurantId}/upload/employees — the «موظفي
     * المطاعم» roster. Deliberately per RESTAURANT: the screen states كل مطعم له
     * قائمة موظفين مستقلة, and asab_employees is keyed to a branch, so each row's
     * «اسم الفرع» is resolved against the branches of THIS restaurant only.
     *
     * These are operational employees, not dashboard logins — POST /admin/users
     * remains the only way to create an account that can sign in. The one
     * overlap is a cashier-role row, which provisions the same mobile login the
     * one-by-one add already does.
     */
    public function employees(Request $request, CashierProvisioningService $cashiers, string $restaurantId): JsonResponse
    {
        return $this->run(function () use ($request, $cashiers, $restaurantId) {
            $restaurant = AsabRestaurant::withoutGlobalScope('tenant')->findOrFail($restaurantId);
            $this->assertBrandAssigned($restaurant->brand_id);
            $request->validate(['file' => self::FILE_RULES]);
            [$header, $rows] = $this->parse($request->file('file'), 'employees');

            $map = $this->mapEmployeeHeaders($header);
            // Branch names are matched inside this restaurant only, so a name
            // that also exists under another restaurant cannot capture the row.
            $branches = Branch::where('asab_restaurant_id', $restaurant->id)
                ->pluck('id', 'name')
                ->mapWithKeys(fn ($id, $name) => [$this->foldBranchName($name) => $id]);

            $count = 0;
            $errors = [];
            $cell = fn (array $row, string $key) => isset($map[$key]) ? trim((string) ($row[$map[$key]] ?? '')) : '';

            DB::transaction(function () use ($rows, $cell, $branches, $restaurant, $cashiers, &$count, &$errors) {
                foreach ($rows as $i => $row) {
                    try {
                        $name = $cell($row, 'name');
                        $role = $cell($row, 'role');
                        if ($name === '' || $role === '') {
                            throw new \RuntimeException('اسم الموظف والوظيفة مطلوبان');
                        }

                        $branchName = $cell($row, 'branch');
                        $branchId = $branchName === '' ? null : $branches->get($this->foldBranchName($branchName));
                        // Unlike the fixed-assets importer, an unmatched branch
                        // name is reported rather than silently nulled — a roster
                        // row landing on no branch is invisible to the branch
                        // screens that consume it.
                        if ($branchName !== '' && $branchId === null) {
                            throw new \RuntimeException("لا يوجد فرع باسم «{$branchName}» ضمن هذا المطعم");
                        }

                        $this->importEmployeeRow($restaurant, $branchId, $cashiers, [
                            'name' => $name,
                            'role' => $role,
                            'phone' => $cell($row, 'phone') ?: null,
                            'nationalId' => $cell($row, 'nationalId') ?: null,
                            'salary' => $cell($row, 'salary'),
                            'shift' => $cell($row, 'shift') ?: null,
                            'hireDate' => $cell($row, 'hireDate') ?: null,
                        ]);
                        $count++;
                    } catch (\Throwable $e) {
                        $errors[] = ['row' => $i + 2, 'message' => $e->getMessage()];
                    }
                }
            });

            $this->stampStatus('restaurant', $restaurant->id, 'employees', $count, $request, $errors);

            return $this->ok(['employeeCount' => $count, 'errors' => $errors]);
        });
    }

    /**
     * GET /admin/restaurants/{restaurantId}/upload-status — feeds the «حالة
     * الرفع» column on the موظفي المطاعم table.
     */
    public function restaurantStatus(string $restaurantId): JsonResponse
    {
        return $this->run(function () use ($restaurantId) {
            $restaurant = AsabRestaurant::withoutGlobalScope('tenant')->findOrFail($restaurantId);
            $this->assertBrandAssigned($restaurant->brand_id);

            $rows = UploadStatus::where('owner_type', 'restaurant')
                ->where('owner_id', $restaurant->id)
                ->get();

            $has = $this->completedPredicate($rows);

            return $this->ok([
                'restaurantId' => $restaurant->id,
                'uploads' => $this->presentUploads($rows),
                'employees' => $has('employees'),
                'completionPct' => $has('employees') ? 100 : 0,
            ]);
        });
    }

    /**
     * Case/whitespace-insensitive branch-name key. Arabic sheets routinely carry
     * a trailing space or a doubled inner space that an exact match would miss.
     */
    private function foldBranchName(?string $name): string
    {
        return mb_strtolower(trim(preg_replace('/\s+/u', ' ', (string) $name)));
    }

    /** @param  array<string, string|null>  $data */
    private function importEmployeeRow(AsabRestaurant $restaurant, ?string $branchId, CashierProvisioningService $cashiers, array $data): void
    {
        $employee = Employee::create([
            'company_id' => $restaurant->company_id,
            'branch_id' => $branchId,
            'emp_number' => $this->nextEmpNumber($restaurant->company_id),
            'name' => $data['name'],
            'phone' => $data['phone'],
            'national_id' => $data['nationalId'],
            'role' => $data['role'],
            // Halalas, matching POST /company/me/branch/employees. Sheets are
            // written in riyals, so the template header says (ر.س) and the value
            // is scaled here rather than storing two different units.
            'monthly_salary' => (int) round(((float) str_replace(',', '', (string) $data['salary'])) * 100),
            'shift_type' => $data['shift'],
            'hire_date' => $data['hireDate'] ? \Illuminate\Support\Carbon::parse($data['hireDate']) : now(),
            'status' => 'active',
        ]);

        // Cashier-role employees also get a mobile-app login (WS2 bridge) —
        // same rule as the one-by-one add, or an imported cashier could never
        // open a shift.
        if ($branchId !== null && $cashiers->isCashierRole($data['role'])) {
            $provision = $cashiers->provision(
                $branchId, $restaurant->company_id, $data['name'], null, $data['phone'], $employee->id,
            );
            if ($provision['cashierId'] ?? null) {
                $employee->forceFill(['legacy_cashier_id' => $provision['cashierId']])->save();
            }
        }
    }

    /**
     * Resolve employee columns by alias, returning header-name => column index.
     *
     * @param  array<int, string>  $header
     * @return array<string, int>
     */
    private function mapEmployeeHeaders(array $header): array
    {
        $normalized = array_map(fn (string $h) => mb_strtolower(trim(preg_replace('/\s+/u', ' ', $h))), $header);
        $map = [];

        foreach (self::EMPLOYEE_HEADER_ALIASES as $key => $aliases) {
            foreach ($aliases as $alias) {
                $index = array_search(mb_strtolower($alias), $normalized, true);
                if ($index !== false) {
                    $map[$key] = $index;
                    break;
                }
            }
        }

        return $map;
    }

    /**
     * Completion means a *successful* upload. Rows predating the status column
     * carry a NULL status alongside a real uploaded_count, so NULL reads as done.
     *
     * @param  \Illuminate\Support\Collection<int, UploadStatus>  $rows
     * @return callable(string): bool
     */
    private function completedPredicate($rows): callable
    {
        $byType = $rows->keyBy->upload_type;

        return fn (string $type) => ($s = $byType->get($type)) !== null
            && $s->uploaded_count > 0
            && ($s->status ?? 'done') === 'done';
    }

    /**
     * FE completion request §1.7 (Option A) — per-upload progress for polling.
     *
     * @param  \Illuminate\Support\Collection<int, UploadStatus>  $rows
     * @return array<int, array<string, mixed>>
     */
    private function presentUploads($rows): array
    {
        return $rows->map(fn (UploadStatus $s) => [
            'type' => $s->upload_type,
            'status' => $s->status ?? ($s->uploaded_count > 0 ? 'done' : 'queued'),
            'progressPct' => (int) ($s->progress_pct ?? ($s->uploaded_count > 0 ? 100 : 0)),
            'parsedRows' => (int) ($s->parsed_rows ?? $s->uploaded_count),
            'failedRows' => (int) ($s->failed_rows ?? 0),
            'failureReason' => $s->failure_reason,
            'startedAt' => optional($s->started_at)->toIso8601String(),
            'finishedAt' => optional($s->finished_at)->toIso8601String(),
        ])->values()->all();
    }

    /**
     * Parse an uploaded CSV or XLSX into a validated header plus positional
     * data rows. XLSX support matters because template() hands out .xlsx files
     * by default — the same file must round-trip through the upload endpoints.
     *
     * @return array{0: array<int, string>, 1: array<int, array>}
     */
    private function parse($file, string $type): array
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
            $rows = array_map('str_getcsv', file($file->getRealPath(), FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES));
        }

        if ($rows === []) {
            throw $this->emptyFile();
        }

        $header = $this->normalizeHeader(array_shift($rows));
        $this->assertHeader($header, $type);

        $data = array_values(array_filter($rows, fn ($r) => count(array_filter($r)) > 0));
        if ($data === []) {
            throw $this->emptyFile();
        }

        return [$header, $data];
    }

    /**
     * Trim the header cells, drop the UTF-8 BOM that Excel writes ahead of the
     * first one, and drop trailing empty cells.
     *
     * @return array<int, string>
     */
    private function normalizeHeader(array $header): array
    {
        $header = array_values(array_map(fn ($c) => trim((string) $c), $header));
        if (isset($header[0])) {
            $header[0] = trim(preg_replace('/^\xEF\xBB\xBF/', '', $header[0]));
        }
        while ($header !== [] && end($header) === '') {
            array_pop($header);
        }

        return $header;
    }

    /**
     * The header is mandatory: no positional heuristic can tell a header row
     * from a data row, so a mismatch is reported rather than guessed at.
     */
    private function assertHeader(array $header, string $type): void
    {
        if ($type === 'fixed-assets') {
            // Both accepted layouts resolve by header name; the asset name is
            // the one column neither of them can omit.
            if (! isset($this->mapAssetHeaders($header)['name'])) {
                throw $this->headerMismatch($header, self::TEMPLATES[$type], self::FIXED_ASSETS_EN_TEMPLATE);
            }

            return;
        }

        if ($type === 'employees') {
            // Alias-mapped like fixed-assets: rosters arrive as the client's own
            // sheet at least as often as the template. Name and role are the two
            // columns a row cannot be created without.
            $map = $this->mapEmployeeHeaders($header);
            if (! isset($map['name'], $map['role'])) {
                throw $this->headerMismatch($header, self::TEMPLATES[$type]);
            }

            return;
        }

        // The FE labels the grouping column «الفئة» while the ratified *item*
        // templates say «التصنيف» — accept both so real user files import.
        // Suppliers legitimately use «الفئة»; that split is intentional.
        $actual = in_array($type, ['sales-items', 'raw-materials'], true)
            ? array_map(fn (string $h) => $h === 'الفئة' ? 'التصنيف' : $h, $header)
            : $header;

        if ($actual !== self::TEMPLATES[$type]) {
            throw $this->headerMismatch($header, self::TEMPLATES[$type]);
        }
    }

    /** @param  array<int, string>  ...$expected  one entry per accepted layout */
    private function headerMismatch(array $received, array ...$expected): AsabException
    {
        $layouts = array_map(fn (array $cols) => implode(' | ', $cols), $expected);

        return new AsabException(
            'INVALID_HEADER',
            'Unexpected column headers. Expected: '.implode('  — or —  ', $layouts),
            'أعمدة الملف لا تطابق القالب. الأعمدة المتوقعة: '.implode('  أو  ', $layouts),
            422,
            ['expected' => $expected, 'received' => $received],
        );
    }

    private function emptyFile(): AsabException
    {
        return new AsabException(
            'EMPTY_FILE',
            'The uploaded file contains no data rows',
            'الملف المرفوع لا يحتوي على صفوف بيانات',
            422,
        );
    }

    /** '' (str_getcsv's empty trailing cell) → null. */
    private function nullIfBlank(mixed $v): ?string
    {
        $v = trim((string) ($v ?? ''));

        return $v === '' ? null : $v;
    }

    private function text(array $row, array $map, string $field): ?string
    {
        return $this->nullIfBlank($this->cell($row, $map, $field));
    }

    private function toInt(mixed $v): ?int
    {
        $v = $this->nullIfBlank($v);

        return $v === null ? null : (int) $v;
    }

    /** A sheet date cell → Y-m-d, or null when absent or unreadable. */
    private function toDate(mixed $v): ?string
    {
        $v = $this->nullIfBlank($v);
        if ($v === null) {
            return null;
        }
        $ts = strtotime($v);

        return $ts === false ? null : date('Y-m-d', $ts);
    }

    /** 0 imported rows is never a success, even when no row raised an error. */
    private function terminalStatus(int $count): string
    {
        return $count > 0 ? 'done' : 'failed';
    }

    /** @param  array<int, array{row:int, message:string}>  $errors */
    private function stampStatus(string $ownerType, string $ownerId, string $type, int $count, Request $request, array $errors = []): void
    {
        UploadStatus::updateOrCreate(
            ['owner_type' => $ownerType, 'owner_id' => $ownerId, 'upload_type' => $type],
            [
                'uploaded_count' => $count,
                'uploaded_at' => now(),
                'uploaded_by_id' => $request->user()->id,
                'status' => $this->terminalStatus($count),
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
