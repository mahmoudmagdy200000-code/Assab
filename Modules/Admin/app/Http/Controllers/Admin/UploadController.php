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
use Modules\Admin\Services\ExpenseTaxonomyBridgeService;
use Modules\Admin\Services\ProcurementCatalogBridgeService;
use Modules\Admin\Support\AssetEnums;
use Modules\Admin\Support\CashierRole;
use Modules\Branch\Models\Branch;
use Modules\Purchase\Models\Item as PurchaseItem;

/**
 * Admin Excel/CSV bulk uploads (BACKEND_API_SPEC.md §6.1.5). Parses CSV/XLSX
 * rows (Arabic headers per spec) into catalog / suppliers / assets.
 */
class UploadController extends AsabController
{
    use GeneratesEmployeeNumbers, MapsAssetSpreadsheet;

    /**
     * The catalog bridge write-throughs run inside brandUpload's DB transaction
     * (BUG-9): a brand-level upload must reach the legacy tables the MOBILE app
     * reads, not just the asab_* dashboard tables. `catalogBridge` feeds the
     * purchasing pickers (suppliers / branch_item); `expenseTaxonomy` feeds the
     * mobile Expense category pickers (`categories`).
     */
    public function __construct(
        private readonly ProcurementCatalogBridgeService $catalogBridge,
        private readonly ExpenseTaxonomyBridgeService $expenseTaxonomy,
    ) {}

    // Item templates use 'التصنيف' for the grouping column to match the mobile
    // app's item labels (client meeting: rename the 'category' field).
    // «اسم الفئة» is the category's SUB-category: the mobile pickers are
    // hierarchical, so the downloadable template must carry both levels or
    // every uploaded category lands flat («No sub-categories found»).
    // Sheets without the column are still accepted (assertHeader).
    private const TEMPLATES = [
        'sales-items' => ['رمز الصنف', 'اسم الصنف', 'التصنيف', 'اسم الفئة', 'وحدة البيع', 'السعر'],
        'raw-materials' => ['رمز المادة', 'اسم المادة', 'التصنيف', 'اسم الفئة', 'وحدة القياس', 'التكلفة'],
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
            [$header, $rows] = $this->parse($request->file('file'), $type);
            // Client sheets may add «اسم الفئة» (sub-category) after «الفئة» —
            // it shifts the unit/price columns right by one.
            $hasSubCategory = in_array($type, ['sales-items', 'raw-materials'], true)
                && in_array('اسم الفئة', $header, true);

            // FE completion request §1.7 (Option B) — emit a processing tick, then a terminal tick.
            $rt->brandUploadProgress($brand->company_id, $brand->id, $type, 'processing', 0, 0, 0);

            // Resolve the brand's branch ids ONCE per upload — raw-materials seed
            // a branch_item per branch (BUG-9). Passing it into the row loop
            // avoids an N+1 across rows without caching on the bridge (whose
            // instance can outlive a request under a persistent runtime and
            // would then seed a stale branch set).
            $brandBranchIds = $type === 'raw-materials' ? $this->brandBranchIds($brand) : [];

            $count = 0;
            $errors = [];
            DB::transaction(function () use ($rows, $brand, $type, $brandBranchIds, $hasSubCategory, &$count, &$errors) {
                foreach ($rows as $i => $row) {
                    try {
                        if ($type === 'suppliers') {
                            $this->importSupplierRow($brand, $row);
                        } else {
                            $this->importCatalogRow($brand, $type, $row, $brandBranchIds, $hasSubCategory);
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
                // How far the mobile write-through actually reached. The catalog
                // rows are stored on the brand, but the app's item list is keyed
                // on branch_item of the USER's branch — with no branch linked to
                // the brand the whole write-through is a silent no-op and the
                // upload still reads «تم الرفع ✓» (reported 2026-07-26).
                'branchesSeeded' => count($brandBranchIds),
                'warnings' => $this->uploadWarnings($type, $brandBranchIds),
            ]);
        });
    }

    /**
     * Branches of a brand, for the mobile branch_item seeding.
     *
     * `branches.asab_brand_id` is the canonical link, but it is NULL on branches
     * that predate the dashboard (or came through a path that skipped the
     * hierarchy columns), so resolve through the brand's RESTAURANTS as well —
     * a branch of this brand's restaurant IS a branch of the brand. Reaching one
     * that way also BACKFILLS the column, so every later read is direct.
     *
     * @return string[]
     */
    private function brandBranchIds(AsabBrand $brand, bool $backfill = true): array
    {
        $branches = $this->brandBranches($brand, ['id', 'asab_brand_id'], $backfill);

        return $branches->pluck('id')->all();
    }

    /**
     * lowercased branch name → id, for the fixed-assets «اسم الفرع» column.
     * A duplicated name inside one brand is ambiguous, so it is dropped rather
     * than resolved to whichever row the database returned first.
     *
     * @return array<string, string>
     */
    private function brandBranchesByName(AsabBrand $brand): array
    {
        $byName = [];
        $ambiguous = [];

        foreach ($this->brandBranches($brand, ['id', 'name', 'asab_brand_id']) as $branch) {
            $key = mb_strtolower(trim((string) $branch->name));
            if ($key === '') {
                continue;
            }
            if (isset($byName[$key])) {
                $ambiguous[$key] = true;

                continue;
            }
            $byName[$key] = $branch->id;
        }

        return array_diff_key($byName, $ambiguous);
    }

    /**
     * The brand's branches, by the canonical link OR through its restaurants.
     *
     * Every reader of «branches of this brand» must go through here. The
     * الأصول الثابتة column used to filter on `asab_brand_id` alone, so for a
     * brand whose branches carry only `asab_restaurant_id` the status call
     * returned an EMPTY branch list while the screen (which builds its rows from
     * the restaurants) still drew them — every row then fell back to «لم يُرفع»
     * no matter how many times the assets were uploaded (reported 2026-08-03).
     *
     * @param  string[]  $columns
     * @return \Illuminate\Database\Eloquent\Collection<int, Branch>
     */
    private function brandBranches(AsabBrand $brand, array $columns = ['*'], bool $backfill = true)
    {
        $restaurantIds = AsabRestaurant::withoutGlobalScope('tenant')
            ->where('brand_id', $brand->id)->pluck('id')->all();

        // asab_brand_id is needed to decide the backfill even when the caller
        // asked for a narrower column list.
        $select = $columns === ['*'] ? ['*'] : array_values(array_unique([...$columns, 'id', 'asab_brand_id']));

        $branches = Branch::query()
            ->where(function ($q) use ($brand, $restaurantIds) {
                $q->where('asab_brand_id', $brand->id);
                if ($restaurantIds !== []) {
                    $q->orWhereIn('asab_restaurant_id', $restaurantIds);
                }
            })
            ->orderBy('name')
            ->get($select);

        if ($backfill) {
            $unlinked = $branches->filter(fn (Branch $b) => $b->asab_brand_id !== $brand->id)
                ->pluck('id')->all();
            if ($unlinked !== []) {
                Branch::whereIn('id', $unlinked)->update(['asab_brand_id' => $brand->id]);
            }
        }

        return $branches;
    }

    /**
     * Non-fatal warnings for an upload that stored its rows but could not reach
     * the mobile side. Returned rather than thrown: the catalog rows ARE saved,
     * and the admin needs to know why the app still shows nothing.
     *
     * @param  string[]  $brandBranchIds
     * @return array<int, array{code:string, message:string, messageAr:string}>
     */
    private function uploadWarnings(string $type, array $brandBranchIds): array
    {
        if ($type !== 'raw-materials' || $brandBranchIds !== []) {
            return [];
        }

        return [[
            'code' => 'NO_BRANCHES_LINKED',
            'message' => 'No branch is linked to this brand, so the items were not published to the mobile item list. Link the brand\'s restaurants/branches first.',
            'messageAr' => 'لا يوجد فرع مرتبط بهذه العلامة، فلم تُنشر الأصناف في قائمة أصناف التطبيق. اربط مطاعم/فروع العلامة أولاً.',
        ]];
    }

    private function importSupplierRow(AsabBrand $brand, array $row): void
    {
        $code = $this->nullIfBlank($row[0] ?? null);
        $name = $row[1] ?? '';

        // Idempotent on re-upload: match this brand's existing supplier by code
        // (or name when codeless) so a corrected re-upload UPDATES in place
        // instead of minting duplicate dashboard + legacy rows in the mobile
        // picker. (asab_suppliers.code is deliberately non-unique, and the
        // template has no email, so nothing at the DB layer blocks duplicates.)
        $existing = AsabSupplier::withoutGlobalScope('tenant')
            ->where('brand_id', $brand->id)
            ->when(
                $code !== null,
                fn ($q) => $q->where('code', $code),
                fn ($q) => $q->whereNull('code')->where('name', $name),
            )
            ->first();

        $attributes = [
            'company_id' => $brand->company_id,
            'brand_id' => $brand->id,
            'code' => $code,
            'name' => $name,
            'category' => $row[2] ?? null,
            'contact_name' => $row[3] ?? null,
            'payment_terms' => $row[4] ?? null,
            'status' => 'active',
        ];

        $supplier = $existing ?? new AsabSupplier;
        $supplier->fill($attributes)->save();

        // BUG-9 write-through: the legacy `suppliers` row the mobile
        // Expense/Purchase supplier pickers read. provisionSupplier with a null
        // email always mints a NEW legacy row, so only call it when the supplier
        // is not yet linked; on a re-upload keep the linked row fresh instead.
        if ($supplier->legacy_supplier_id === null) {
            $this->catalogBridge->provisionSupplier($supplier);
        } else {
            $this->catalogBridge->syncSupplier($supplier);
        }
    }

    /**
     * One catalog row: sales items and raw materials share the brand catalog
     * table but stay separable via `type`, and the price column is persisted
     * in halalas. Raw materials additionally upsert into the Purchase module's
     * items table so uploaded materials actually reach the purchasing flows.
     *
     * @param  string[]  $branchIds  the brand's branch ids (raw-materials seeding)
     * @param  bool  $hasSubCategory  sheet carries «اسم الفئة» after «الفئة» (shifts unit/price right)
     */
    private function importCatalogRow(AsabBrand $brand, string $type, array $row, array $branchIds = [], bool $hasSubCategory = false): void
    {
        $code = trim((string) ($row[0] ?? ''));
        $name = trim((string) ($row[1] ?? ''));
        // Whitespace-only Excel cells must not masquerade as data (the mobile
        // resources coalesce nulls, but keep the stored data honest too).
        $category = trim((string) ($row[2] ?? '')) ?: null;
        $subCategory = $hasSubCategory ? (trim((string) ($row[3] ?? '')) ?: null) : null;
        $unit = trim((string) ($row[$hasSubCategory ? 4 : 3] ?? '')) ?: null;
        $priceHalalas = $this->toHalalas($row[$hasSubCategory ? 5 : 4] ?? 0);

        $catalogType = $type === 'raw-materials' ? InventoryCatalogItem::TYPE_RAW_MATERIAL : InventoryCatalogItem::TYPE_SALES_ITEM;

        // Idempotent on re-upload, like importSupplierRow: the template download
        // now ships the brand's SAVED rows, so «download → correct → re-upload»
        // is the normal edit path and a plain create() would double the catalog
        // on every pass. Matched on the row's code, or on its name when the
        // sheet is codeless.
        InventoryCatalogItem::updateOrCreate(
            [
                'brand_id' => $brand->id,
                'type' => $catalogType,
            ] + ($code !== '' ? ['code' => $code] : ['code' => null, 'name' => $name]),
            [
                'name' => $name,
                'category' => $category,
                'unit' => $unit,
                'unit_price' => $priceHalalas,
                'status' => 'active',
            ],
        );

        // BUG-9 write-through: surface the row's category in the mobile Expense
        // taxonomy (`categories`) so the app's «الأصناف»/«المصروفات» pickers are
        // not empty (raw-materials → purchase tab, sales-items → expense tab).
        // «التصنيف» = parent, «اسم الفئة» = its sub-category. A sheet WITHOUT
        // the sub column nests the ITEM NAME under its التصنيف instead — the
        // mobile picker is strictly parent → children, so a flat parent renders
        // as «No sub-categories found» and the meeting ask («اختار معدات →
        // يجيب التلاجة والبوتاجاز») never shows without this.
        $this->expenseTaxonomy->syncCategoryFor(
            $type,
            $category,
            $hasSubCategory ? $subCategory : ($name !== '' ? $name : null),
        );

        if ($type === 'raw-materials' && $name !== '') {
            // Create-only into the (global) purchasing items table: never
            // overwrite an existing row — another brand may own that code.
            $existing = PurchaseItem::withTrashed()
                ->where($code !== '' ? 'code' : 'name', $code !== '' ? $code : $name)
                ->first();

            if ($existing === null) {
                $mobile = PurchaseItem::create([
                    'name' => $name,
                    'code' => $code !== '' ? $code : null,
                    'unit' => $unit,
                    'category' => $category,
                    'is_active' => true,
                ]);
            } else {
                if ($existing->trashed()) {
                    $existing->restore();
                }
                // A live match is reused as-is — its row is never overwritten
                // (the create-only invariant protects the item's name/price).
                $mobile = $existing;
            }

            // BUG-9 write-through: seed branch_item for the brand's branches so
            // the material surfaces in the mobile purchasing-officer picker
            // (keyed on branch_item), not just in asab_inventory_catalog.
            // Always seeded — branch_item is an additive, idempotent pivot that
            // never mutates the shared item row, so seeding against a
            // pre-existing catalog item is safe and also backfills branches
            // added since a prior upload.
            $this->catalogBridge->seedRawMaterialForBrandBranches($mobile, $branchIds, $priceHalalas);
        }
    }

    public function fixedAssets(Request $request, string $branchId): JsonResponse
    {
        return $this->run(function () use ($request, $branchId) {
            $branch = Branch::findOrFail($branchId);
            $request->validate(['file' => self::FILE_RULES]);
            // Resolved (and asserted) BEFORE parsing: asab_assets.company_id is
            // NOT NULL, so an unlinked branch used to fail every single row —
            // the endpoint answered 200 with assetCount 0 and the «حالة الرفع»
            // column stayed «لم يُرفع» after every re-upload.
            $companyId = $this->resolveBranchCompanyId($branch, $request);
            [$header, $rows] = $this->parse($request->file('file'), 'fixed-assets');

            $result = $this->importAssetRows($rows, $header, $request, [
                'company_id' => $companyId,
                'branch_id' => $branch->id,
                'case_type' => 'branch_upload',
            ]);

            $this->stampStatus('branch', $branch->id, 'fixed-assets', $result['count'], $request, $result['errors']);
            $this->assertSomethingImported($result);

            return $this->ok(['assetCount' => $result['count'], 'errors' => $result['errors']]);
        });
    }

    /**
     * The company that owns a branch's rows. `branches.asab_company_id` is the
     * canonical link but is NULL on branches that predate the dashboard (and on
     * any created through a path that skipped the hierarchy columns), so fall
     * back through restaurant → brand → the caller's own company. Resolving it
     * also BACKFILLS the branch, so the next screen does not re-derive it.
     *
     * A platform admin has no company of their own, so for an unlinked branch
     * nothing resolves — that is a 422 naming the problem, not 8 identical
     * constraint violations reported as row errors.
     */
    private function resolveBranchCompanyId(Branch $branch, Request $request): string
    {
        $companyId = $branch->asab_company_id;

        if (! $companyId && $branch->asab_restaurant_id) {
            $companyId = AsabRestaurant::withoutGlobalScope('tenant')
                ->whereKey($branch->asab_restaurant_id)->value('company_id');
        }
        if (! $companyId && $branch->asab_brand_id) {
            $companyId = AsabBrand::withoutGlobalScope('tenant')
                ->whereKey($branch->asab_brand_id)->value('company_id');
        }
        $companyId ??= $request->user()->company_id;

        if (! $companyId) {
            throw new AsabException(
                'BRANCH_NOT_LINKED',
                'This branch is not linked to a company, so its assets cannot be stored. Link the branch to a restaurant/brand first.',
                'هذا الفرع غير مرتبط بشركة، فلا يمكن حفظ أصوله. اربط الفرع بمطعم/براند أولاً.',
                422,
                ['branchId' => $branch->id],
            );
        }

        if ($branch->asab_company_id !== $companyId) {
            $branch->forceFill(['asab_company_id' => $companyId])->save();
        }

        return $companyId;
    }

    /**
     * An upload that stored NOTHING is not a success. It used to answer 200 with
     * `assetCount: 0`, which the screen showed as done while the status row said
     * «فشل» — the "I upload it, refresh, and it is not saved" report. The status
     * row is stamped first, so the failure and its reason stay readable.
     *
     * @param  array{count:int, errors:array<int, array{row:int, message:string}>}  $result
     */
    private function assertSomethingImported(array $result): void
    {
        if ($result['count'] > 0) {
            return;
        }

        throw new AsabException(
            'UPLOAD_FAILED',
            'No row from the file could be stored.',
            'لم يتم حفظ أي صف من الملف. راجع الأخطاء وأعد الرفع.',
            422,
            ['errors' => $result['errors']],
        );
    }

    /**
     * Brand-level fixed-assets upload — the Data Upload tab is per brand.
     * The Arabic template carries «اسم الفرع», so a row naming one of the
     * brand's branches lands IN that branch (and reaches the app's receive
     * queue); rows that name nothing keep a NULL branch_id (the column is
     * nullable and indexed) and stay pending assignment.
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
                'branches_by_name' => $this->brandBranchesByName($brand),
                'case_type' => 'brand_upload',
            ]);

            $this->stampStatus('brand', $brand->id, 'fixed-assets', $result['count'], $request, $result['errors']);
            $this->assertSomethingImported($result);

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
        $assigned = [];

        DB::transaction(function () use ($rows, $map, $owner, $request, &$count, &$errors, &$assigned) {
            // public_id is UNIQUE: seed the counter from the highest existing
            // FA-#### suffix (withTrashed — soft-deleted rows still hold their
            // id), not from count(), which collides after deletes.
            $seq = $this->maxFixedAssetSequence();
            foreach ($rows as $i => $row) {
                try {
                    $asset = $this->importAssetRow($row, $map, $owner, $request, $seq);
                    $count++;
                    if ($asset !== null && $asset->branch_id !== null && $asset->status === 'pending_branch') {
                        $assigned[] = $asset;
                    }
                } catch (\Throwable $e) {
                    $errors[] = ['row' => $i + 2, 'message' => $this->rowErrorMessage($e)];
                }
            }
        });

        // AFTER commit. A bulk upload used to write asab_assets and stop there:
        // the single-asset paths (AssetController, AssetDraftService) dispatch
        // this event, the importer did not — so «طلبات الاستلام» on the phone
        // stayed empty and the branch's asset screen read 0 for assets the
        // dashboard had already stored (reported 2026-08-03).
        foreach ($assigned as $asset) {
            \Modules\Admin\Events\AssetAssignedToBranch::dispatch($asset);
        }

        return ['count' => $count, 'errors' => $errors];
    }

    /**
     * A row error travels to the client and into `failure_reason`. A domain
     * message is the point of the field; a driver message is not — it leaked the
     * whole INSERT statement (column list, ids) into the upload screen. Report
     * those to the log and hand the user a message they can act on.
     */
    private function rowErrorMessage(\Throwable $e): string
    {
        if ($e instanceof \Illuminate\Database\QueryException) {
            report($e);

            return 'تعذّر حفظ الصف — راجع قيم الصف وأعد المحاولة';
        }

        return $e->getMessage();
    }

    /**
     * @param  array<string, mixed>  $owner
     * @return Asset|null the stored row, or null when an existing one was updated
     */
    private function importAssetRow(array $row, array $map, array $owner, Request $request, int &$seq): ?Asset
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
        $serial = $this->text($row, $map, 'serial');
        // A per-branch upload owns the branch outright; a brand-level one reads
        // it from the row's «اسم الفرع» so its rows reach a branch at all.
        $branchId = $owner['branch_id'] ?? $this->rowBranchId($row, $map, $owner);

        // A serial number identifies the physical asset, so a re-upload of the
        // register the download now hands back UPDATES that row rather than
        // minting a second FA-### for the same machine. Serial-less rows (the
        // Arabic template has no such column) still always create.
        $existing = $serial === null ? null : Asset::where('company_id', $owner['company_id'])
            ->where('branch_id', $branchId)
            ->where('serial', $serial)
            ->first();

        $attributes = [
            'company_id' => $owner['company_id'],
            'public_id' => $existing->public_id ?? $this->nextFixedAssetPublicId($seq),
            'name' => (string) ($this->cell($row, $map, 'name') ?? ''),
            'category' => AssetEnums::canonicalCategory($this->text($row, $map, 'category')),
            'branch_id' => $branchId,
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
        ];

        if ($existing !== null) {
            // A corrected sheet re-states the asset's DATA, never its workflow:
            // an asset the branch has already received must not be dragged back
            // to pending_branch by a re-upload.
            unset($attributes['status'], $attributes['submitted_by_id']);
            $existing->fill($attributes)->save();

            // Still a candidate for the receive bridge when the row never left
            // pending_branch — re-uploading is how a stranded asset is healed.
            return $existing->status === 'pending_branch' ? $existing : null;
        }

        return Asset::create($attributes);
    }

    /**
     * Resolve a row's «اسم الفرع» to a branch of the uploaded brand. Never
     * resolves across brands (a name is not unique platform-wide) and never
     * fails the row: an unmatched name leaves the asset brand-level, exactly
     * where it landed before this column was read at all.
     *
     * @param  array<string, mixed>  $owner
     */
    private function rowBranchId(array $row, array $map, array $owner): ?string
    {
        $name = $this->text($row, $map, 'branchId');
        if ($name === null || empty($owner['branches_by_name'])) {
            return null;
        }

        return $owner['branches_by_name'][mb_strtolower(trim($name))] ?? null;
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

    /**
     * GET /admin/upload/templates/{type} — the blank sheet, or the owner's
     * SAVED rows when the caller names one (`?brandId=` / `?branchId=` /
     * `?restaurantId=`).
     *
     * «بعد رفع جميع البيانات، عند تحميل النموذج مرة أخرى لا يكون فارغاً» —
     * reported 2026-08-03. The download is the only way to see (and correct)
     * what the system stored, so once an owner has rows the sheet ships them in
     * the very layout the importer reads back, and `?withData=0` opts out to the
     * blank one. An owner with nothing stored still gets the blank template.
     */
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

        $saved = $this->savedTemplateRows($request, $type);
        // Sample rows are scaffolding for an EMPTY sheet — shipping them above
        // real data would re-import two throwaway assets on the next upload.
        $rows = $saved ?? ($isAssets ? self::FIXED_ASSETS_SAMPLE_ROWS : []);
        $name = $saved === null ? "{$type}-template" : "{$type}-data";

        // CSV stays available via ?format=csv (UTF-8 BOM for Excel Arabic).
        if ($request->query('format') === 'csv') {
            $csv = "\xEF\xBB\xBF".implode(',', $headers)."\n";
            foreach ($rows as $row) {
                $csv .= implode(',', array_map([$this, 'csvCell'], $row))."\n";
            }

            return response($csv, 200, [
                'Content-Type' => 'text/csv; charset=UTF-8',
                'Content-Disposition' => "attachment; filename=\"{$name}.csv\"",
            ]);
        }

        // Default: a real .xlsx workbook with the same header row (OpenSpout).
        $tmp = tempnam(sys_get_temp_dir(), 'tpl_').'.xlsx';
        $writer = new \OpenSpout\Writer\XLSX\Writer;
        $writer->openToFile($tmp);
        $writer->addRow(\OpenSpout\Common\Entity\Row::fromValues($headers));
        foreach ($rows as $row) {
            $writer->addRow(\OpenSpout\Common\Entity\Row::fromValues($row));
        }
        $writer->close();

        $contents = file_get_contents($tmp);
        @unlink($tmp);

        return response($contents, 200, [
            'Content-Type' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
            'Content-Disposition' => "attachment; filename=\"{$name}.xlsx\"",
        ]);
    }

    /**
     * A cell in the CSV variant. Exported names carry commas and quotes far more
     * often than the hand-written sample rows did, and an unescaped one shifts
     * every later column of that row on re-upload.
     */
    private function csvCell(mixed $value): string
    {
        $value = (string) $value;

        return preg_match('/[",\r\n]/', $value) === 1
            ? '"'.str_replace('"', '""', $value).'"'
            : $value;
    }

    /**
     * The owner's stored rows for `$type`, laid out exactly like the header the
     * download ships, or null when no owner was named / nothing is stored yet.
     *
     * The owner id is authorized before it is read: these endpoints sit under
     * the admin group, but a scoped caller (head / accountant) must not be able
     * to export a brand or branch outside their assignment by guessing its id.
     *
     * @return array<int, array<int, mixed>>|null
     */
    private function savedTemplateRows(Request $request, string $type): ?array
    {
        // Explicit opt-out, for a client that wants the pristine sheet back.
        if (in_array($request->query('withData'), ['0', 'false'], true)) {
            return null;
        }

        $brandId = $request->query('brandId');
        $branchId = $request->query('branchId');
        $restaurantId = $request->query('restaurantId');

        $rows = match ($type) {
            'sales-items', 'raw-materials' => $brandId === null ? null : $this->catalogExportRows($brandId, $type),
            'suppliers' => $brandId === null ? null : $this->supplierExportRows($brandId),
            'fixed-assets' => $this->assetExportRows($brandId, $branchId),
            'employees' => $restaurantId === null ? null : $this->employeeExportRows($restaurantId),
            default => null,
        };

        return $rows === [] ? null : $rows;
    }

    /** @return array<int, array<int, mixed>> */
    private function catalogExportRows(string $brandId, string $type): array
    {
        $this->assertBrandAssigned($brandId);

        return InventoryCatalogItem::where('brand_id', $brandId)
            ->where('type', $type === 'raw-materials' ? InventoryCatalogItem::TYPE_RAW_MATERIAL : InventoryCatalogItem::TYPE_SALES_ITEM)
            ->orderBy('name')
            ->get(['code', 'name', 'category', 'unit', 'unit_price'])
            // «اسم الفئة» (the sub-category) is not stored on the catalog row —
            // the importer folds it into the mobile taxonomy only — so it is
            // exported blank and re-imports flat, exactly like a 5-column sheet.
            ->map(fn (InventoryCatalogItem $i) => [
                (string) ($i->code ?? ''), (string) $i->name, (string) ($i->category ?? ''), '',
                (string) ($i->unit ?? ''), $this->toRiyals($i->unit_price),
            ])->all();
    }

    /** @return array<int, array<int, mixed>> */
    private function supplierExportRows(string $brandId): array
    {
        $this->assertBrandAssigned($brandId);

        return AsabSupplier::where('brand_id', $brandId)
            ->orderBy('name')
            ->get(['code', 'name', 'category', 'contact_name', 'payment_terms'])
            ->map(fn (AsabSupplier $s) => [
                (string) ($s->code ?? ''), (string) $s->name, (string) ($s->category ?? ''),
                (string) ($s->contact_name ?? ''), (string) ($s->payment_terms ?? ''),
            ])->all();
    }

    /**
     * Assets in the English layout the download ships. A branch exports its own
     * register; a brand exports the registers of all its branches (assets carry
     * no brand column, so a brand-level upload — which lands with branch_id
     * NULL — is deliberately not attributed to one brand of a multi-brand
     * company).
     *
     * @return array<int, array<int, mixed>>|null
     */
    private function assetExportRows(?string $brandId, ?string $branchId): ?array
    {
        if ($branchId !== null) {
            $this->assertBranchAssigned($branchId);
            $branchIds = [$branchId];
        } elseif ($brandId !== null) {
            $this->assertBrandAssigned($brandId);
            $branchIds = $this->brandBranchIds(
                AsabBrand::withoutGlobalScope('tenant')->findOrFail($brandId),
                backfill: false,
            );
        } else {
            return null;
        }

        if ($branchIds === []) {
            return [];
        }

        return Asset::whereIn('branch_id', $branchIds)
            ->orderBy('public_id')
            ->get()
            ->map(fn (Asset $a) => [
                (string) ($a->serial ?? ''), (string) ($a->zone ?? ''), (string) ($a->category ?? ''),
                (string) $a->name, $a->quantity, $a->qty_excellent, $a->qty_maintenance, $a->qty_problem,
                optional($a->purchased_at)->format('Y-m-d') ?? '', $this->toRiyals($a->cost),
                (string) ($a->notes ?? ''),
            ])->all();
    }

    /** @return array<int, array<int, mixed>> */
    private function employeeExportRows(string $restaurantId): array
    {
        $restaurant = AsabRestaurant::withoutGlobalScope('tenant')->findOrFail($restaurantId);
        $this->assertBrandAssigned($restaurant->brand_id);

        $branchNames = Branch::where('asab_restaurant_id', $restaurant->id)->pluck('name', 'id');
        if ($branchNames->isEmpty()) {
            return [];
        }

        return Employee::whereIn('branch_id', $branchNames->keys()->all())
            ->orderBy('name')
            ->get()
            ->map(fn (Employee $e) => [
                (string) $e->name, (string) ($e->role ?? ''), (string) ($branchNames[$e->branch_id] ?? ''),
                (string) ($e->phone ?? ''), (string) ($e->national_id ?? ''),
                $this->toRiyals($e->monthly_salary), (string) ($e->shift_type ?? ''),
                optional($e->hire_date)->format('Y-m-d') ?? '',
            ])->all();
    }

    /** Halalas → the riyals string the sheets are written in (toHalalas' inverse). */
    private function toRiyals(?int $halalas): string
    {
        return number_format(((int) $halalas) / 100, 2, '.', '');
    }

    public function status(string $brandId): JsonResponse
    {
        return $this->run(function () use ($brandId) {
            $brand = AsabBrand::withoutGlobalScope('tenant')->findOrFail($brandId);
            // Read-only (no backfill on a GET): how many branches the brand's
            // catalog write-through can reach. 0 explains an app item list that
            // stays empty after a «تم الرفع ✓».
            $branchIds = $this->brandBranchIds($brand, backfill: false);
            $restaurantIds = AsabRestaurant::withoutGlobalScope('tenant')
                ->where('brand_id', $brand->id)->pluck('id')->all();

            // Brand uploads are the only rows keyed to a brand id; branch
            // uploads are stamped owner_type='branch' with a Branch id and are
            // read back through branchStatus().
            $rows = UploadStatus::where('owner_type', 'brand')
                ->where('owner_id', $brandId)
                ->get();

            $has = $this->completedPredicate($rows);
            // «بيانات مشتركة» is the three catalog cards the screen actually
            // offers at brand level. fixed-assets was folded in here on
            // 2026-07-20, which pinned the tile at 3/4 and «اكتمال الإعداد» at
            // 75% for a brand that had uploaded everything the screen asks for
            // (reported 2026-08-03) — assets are uploaded PER BRANCH and are
            // counted in `summary.branchAssets` instead.
            $sharedSteps = ['sales-items', 'raw-materials', 'suppliers'];
            $sharedDone = collect($sharedSteps)->filter($has)->count();

            $branchAssetsDone = $this->completedOwnerCount('branch', $branchIds, 'fixed-assets');
            $employeesDone = $this->completedOwnerCount('restaurant', $restaurantIds, 'employees');

            $done = $sharedDone + $branchAssetsDone + $employeesDone;
            $total = count($sharedSteps) + count($branchIds) + count($restaurantIds);

            return $this->ok([
                'uploads' => $this->presentUploads($rows),
                'shared' => [
                    'sales' => $has('sales-items'),
                    'materials' => $has('raw-materials'),
                    'suppliers' => $has('suppliers'),
                ],
                // Brand-level asset upload, kept OUT of `shared` so the three
                // shared cards and the tile that counts them agree.
                'brandFixedAssets' => $has('fixed-assets'),
                'completionPct' => $total === 0 ? 0 : (int) round($done / $total * 100),
                'branchesLinked' => count($branchIds),
                // «ملخص رفع البيانات» — every tile on the screen, computed here
                // so the FE stops deriving them from four different payloads.
                'summary' => [
                    'shared' => ['done' => $sharedDone, 'total' => count($sharedSteps)],
                    'branchAssets' => ['done' => $branchAssetsDone, 'total' => count($branchIds)],
                    'restaurantEmployees' => ['done' => $employeesDone, 'total' => count($restaurantIds)],
                    'completionPct' => $total === 0 ? 0 : (int) round($done / $total * 100),
                ],
            ]);
        });
    }

    /**
     * How many of the given owners have a SUCCESSFUL upload of `$type`.
     *
     * Mirrors completedPredicate() at the query layer (a NULL status predates
     * the column and reads as done, exactly as it does per row).
     *
     * @param  string[]  $ownerIds
     */
    private function completedOwnerCount(string $ownerType, array $ownerIds, string $type): int
    {
        if ($ownerIds === []) {
            return 0;
        }

        return UploadStatus::where('owner_type', $ownerType)
            ->whereIn('owner_id', $ownerIds)
            ->where('upload_type', $type)
            ->where('uploaded_count', '>', 0)
            ->where(fn ($q) => $q->where('status', 'done')->orWhereNull('status'))
            ->count();
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

            $done = (int) $has('fixed-assets') + (int) $has('employees');

            return $this->ok([
                'branchId' => $branch->id,
                'uploads' => $this->presentUploads($rows),
                'fixedAssets' => $has('fixed-assets'),
                // `completionPct` keeps its published meaning (fixed assets
                // only) — the setup screen's existing progress bar reads it.
                'completionPct' => $has('fixed-assets') ? 100 : 0,
                // …and this one covers BOTH per-branch datasets now that the
                // roster can be uploaded per branch too.
                'overallCompletionPct' => (int) round($done / 2 * 100),
            ] + $this->fixedAssetsState($rows) + $this->employeesState($rows));
        });
    }

    /**
     * GET /admin/brands/{brandId}/branches/upload-status — every branch of the
     * brand with its fixed-assets state, in ONE call.
     *
     * The «الأصول الثابتة» table is per branch but lives on a brand screen, so
     * reading it meant one request per row; a client that skipped that loop drew
     * the whole column as «لم يُرفع» regardless of what was uploaded.
     */
    public function brandBranchesStatus(string $brandId): JsonResponse
    {
        return $this->run(function () use ($brandId) {
            $brand = AsabBrand::withoutGlobalScope('tenant')->findOrFail($brandId);
            $this->assertBrandAssigned($brand->id);

            // Resolved through the shared helper: a branch linked only by
            // `asab_restaurant_id` belongs to this brand too, and filtering on
            // `asab_brand_id` alone answered «no branches» for it.
            $branches = $this->brandBranches($brand, ['id', 'name', 'asab_restaurant_id']);
            $restaurantNames = AsabRestaurant::withoutGlobalScope('tenant')
                ->whereIn('id', $branches->pluck('asab_restaurant_id')->filter()->unique()->all())
                ->pluck('name', 'id');
            // One query for every branch's rows, then grouped in memory.
            $byBranch = UploadStatus::where('owner_type', 'branch')
                ->whereIn('owner_id', $branches->pluck('id')->all())
                ->get()
                ->groupBy('owner_id');

            $rows = $branches->map(function (Branch $branch) use ($byBranch, $restaurantNames) {
                $statuses = $byBranch->get($branch->id, collect());

                return [
                    'branchId' => $branch->id,
                    'branchName' => $branch->name,
                    'restaurantId' => $branch->asab_restaurant_id,
                    'restaurantName' => $restaurantNames[$branch->asab_restaurant_id] ?? null,
                    'uploads' => $this->presentUploads($statuses),
                ] + $this->fixedAssetsState($statuses) + $this->employeesState($statuses);
            })->values();

            return $this->ok([
                'brandId' => $brand->id,
                'branches' => $rows->all(),
                'totals' => [
                    'branches' => $rows->count(),
                    'uploaded' => $rows->where('fixedAssets', true)->count(),
                    'failed' => $rows->where('fixedAssetsStatus', 'failed')->count(),
                    // The «موظفو الفروع» column reads these two.
                    'employeesUploaded' => $rows->where('employees', true)->count(),
                    'employeesFailed' => $rows->where('employeesStatus', 'failed')->count(),
                ],
            ]);
        });
    }

    /**
     * The fixed-assets column's real state. `fixedAssets` alone cannot tell
     * «لم يُرفع» from «رُفع وفشل», so a failed upload read as never attempted and
     * its reason was unreachable.
     *
     * @param  \Illuminate\Support\Collection<int, UploadStatus>  $rows
     * @return array<string, mixed>
     */
    private function fixedAssetsState($rows): array
    {
        $row = $rows->firstWhere('upload_type', 'fixed-assets');
        $done = $this->completedPredicate($rows)('fixed-assets');

        return [
            'fixedAssets' => $done,
            'fixedAssetsStatus' => $row === null ? 'not_uploaded' : ($done ? 'done' : 'failed'),
            'fixedAssetsCount' => (int) ($row->uploaded_count ?? 0),
            'fixedAssetsFailedRows' => (int) ($row->failed_rows ?? 0),
            'fixedAssetsFailureReason' => $row->failure_reason ?? null,
            'fixedAssetsUploadedAt' => optional($row->uploaded_at ?? null)->toIso8601String(),
        ];
    }

    /**
     * The per-branch roster column, same shape as fixedAssetsState so the table
     * can render both with one component.
     *
     * @param  \Illuminate\Support\Collection<int, UploadStatus>  $rows
     * @return array<string, mixed>
     */
    private function employeesState($rows): array
    {
        $row = $rows->firstWhere('upload_type', 'employees');
        $done = $this->completedPredicate($rows)('employees');

        return [
            'employees' => $done,
            'employeesStatus' => $row === null ? 'not_uploaded' : ($done ? 'done' : 'failed'),
            'employeesCount' => (int) ($row->uploaded_count ?? 0),
            'employeesFailedRows' => (int) ($row->failed_rows ?? 0),
            'employeesFailureReason' => $row->failure_reason ?? null,
            'employeesUploadedAt' => optional($row->uploaded_at ?? null)->toIso8601String(),
        ];
    }

    /**
     * POST /admin/restaurants/{restaurantId}/upload/employees — the «موظفي
     * المطاعم» roster. Deliberately per RESTAURANT: the screen states كل مطعم له
     * قائمة موظفين مستقلة, and asab_employees is keyed to a branch, so each row's
     * «اسم الفرع» is resolved against the branches of THIS restaurant only.
     *
     * These are operational employees, not dashboard logins — POST /admin/users
     * remains the only way to create an account that can sign in, and a
     * cashier-role row is rejected outright: cashier accounts are created in the
     * mobile app by the branch manager.
     */
    public function employees(Request $request, string $restaurantId): JsonResponse
    {
        return $this->run(function () use ($request, $restaurantId) {
            $restaurant = AsabRestaurant::withoutGlobalScope('tenant')->findOrFail($restaurantId);
            $this->assertBrandAssigned($restaurant->brand_id);
            $request->validate(['file' => self::FILE_RULES]);
            [$header, $rows] = $this->parse($request->file('file'), 'employees');

            // Branch names are matched inside this restaurant only, so a name
            // that also exists under another restaurant cannot capture the row.
            $branches = Branch::where('asab_restaurant_id', $restaurant->id)
                ->pluck('id', 'name')
                ->mapWithKeys(fn ($id, $name) => [$this->foldBranchName($name) => $id]);

            $result = $this->importEmployeeRows(
                $rows,
                $this->mapEmployeeHeaders($header),
                $restaurant,
                // Unlike the fixed-assets importer, an unmatched branch name is
                // reported rather than silently nulled — a roster row landing on
                // no branch is invisible to the branch screens that consume it.
                function (string $branchName) use ($branches): ?string {
                    if ($branchName === '') {
                        return null;
                    }
                    $id = $branches->get($this->foldBranchName($branchName));
                    if ($id === null) {
                        throw new \RuntimeException("لا يوجد فرع باسم «{$branchName}» ضمن هذا المطعم");
                    }

                    return $id;
                },
            );

            $this->stampStatus('restaurant', $restaurant->id, 'employees', $result['count'], $request, $result['errors']);

            return $this->ok(['employeeCount' => $result['count'], 'errors' => $result['errors']]);
        });
    }

    /**
     * POST /admin/branches/{branchId}/upload/employees — the SAME roster sheet,
     * uploaded for ONE branch.
     *
     * The screen sets up a brand branch by branch, and employees were the only
     * dataset with no per-branch entry point: the roster could be loaded per
     * restaurant only, so a newly added branch had nowhere to upload its own
     * staff from («أين رفع موظفي الفروع لكل فرع», 2026-08-04). Every row lands on
     * THIS branch; an «اسم الفرع» column naming a different branch is a row
     * error rather than a silent misfile.
     */
    public function branchEmployees(Request $request, string $branchId): JsonResponse
    {
        return $this->run(function () use ($request, $branchId) {
            $branch = Branch::findOrFail($branchId);
            $this->assertBranchAssigned($branch->id);
            $request->validate(['file' => self::FILE_RULES]);

            $restaurant = $this->branchRestaurant($branch);
            [$header, $rows] = $this->parse($request->file('file'), 'employees');

            $expected = $this->foldBranchName($branch->name);
            $result = $this->importEmployeeRows(
                $rows,
                $this->mapEmployeeHeaders($header),
                $restaurant,
                function (string $branchName) use ($branch, $expected): ?string {
                    if ($branchName !== '' && $this->foldBranchName($branchName) !== $expected) {
                        throw new \RuntimeException("هذا الملف يخص فرع «{$branch->name}» — الصف يذكر «{$branchName}»");
                    }

                    return $branch->id;
                },
            );

            $this->stampStatus('branch', $branch->id, 'employees', $result['count'], $request, $result['errors']);
            $this->assertSomethingImported(['count' => $result['count'], 'errors' => $result['errors']]);

            return $this->ok(['employeeCount' => $result['count'], 'errors' => $result['errors']]);
        });
    }

    /**
     * The restaurant a branch belongs to — employees carry the company through
     * it. A branch linked to no restaurant cannot own a roster: asab_employees
     * is NOT NULL on company_id, so say so instead of failing every row.
     */
    private function branchRestaurant(Branch $branch): AsabRestaurant
    {
        $restaurant = $branch->asab_restaurant_id
            ? AsabRestaurant::withoutGlobalScope('tenant')->find($branch->asab_restaurant_id)
            : null;

        if ($restaurant !== null) {
            $this->assertBrandAssigned($restaurant->brand_id);

            return $restaurant;
        }

        // A branch that carries the company link but no restaurant still has a
        // valid roster home; synthesise the carrier rather than refusing.
        if ($branch->asab_company_id) {
            return new AsabRestaurant(['company_id' => $branch->asab_company_id, 'brand_id' => $branch->asab_brand_id]);
        }

        throw new AsabException(
            'BRANCH_NOT_LINKED',
            'This branch is not linked to a restaurant/company, so its roster cannot be stored.',
            'هذا الفرع غير مرتبط بمطعم/شركة، فلا يمكن حفظ موظفيه. اربط الفرع أولاً.',
            422,
            ['branchId' => $branch->id],
        );
    }

    /**
     * Shared roster loop for both entry points. `$resolveBranch` receives the
     * row's «اسم الفرع» cell and returns the branch id (or throws a row error).
     *
     * @param  array<int, array<int, mixed>>  $rows
     * @param  array<string, int>  $map
     * @return array{count:int, errors:array<int, array{row:int, message:string}>}
     */
    private function importEmployeeRows(array $rows, array $map, AsabRestaurant $restaurant, callable $resolveBranch): array
    {
        $count = 0;
        $errors = [];
        $cell = fn (array $row, string $key) => isset($map[$key]) ? trim((string) ($row[$map[$key]] ?? '')) : '';

        DB::transaction(function () use ($rows, $cell, $restaurant, $resolveBranch, &$count, &$errors) {
            foreach ($rows as $i => $row) {
                try {
                    $name = $cell($row, 'name');
                    $role = $cell($row, 'role');
                    if ($name === '' || $role === '') {
                        throw new \RuntimeException('اسم الموظف والوظيفة مطلوبان');
                    }

                    $this->importEmployeeRow($restaurant, $resolveBranch($cell($row, 'branch')), [
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

        return ['count' => $count, 'errors' => $errors];
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
    private function importEmployeeRow(AsabRestaurant $restaurant, ?string $branchId, array $data): void
    {
        // Cashiers are created in the mobile app by the branch manager, so a
        // cashier row here would duplicate an account this sheet cannot make.
        // RuntimeException, not AsabException: the caller collects per-row
        // messages for the importer's error report rather than failing the file.
        if (CashierRole::matches($data['role'])) {
            throw new \RuntimeException('يتم إضافة الكاشير من تطبيق الموبايل بواسطة مدير الفرع');
        }

        Employee::create([
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
        $isItemSheet = in_array($type, ['sales-items', 'raw-materials'], true);
        $actual = $isItemSheet
            ? array_map(fn (string $h) => $h === 'الفئة' ? 'التصنيف' : $h, $header)
            : $header;

        if ($actual === self::TEMPLATES[$type]) {
            return;
        }

        // Item sheets may omit «اسم الفئة» (older 5-column files) — the
        // category then imports flat, exactly as before the column existed.
        if ($isItemSheet) {
            $withoutSub = self::TEMPLATES[$type];
            array_splice($withoutSub, 3, 1);
            if ($actual === $withoutSub) {
                return;
            }

            throw $this->headerMismatch($header, self::TEMPLATES[$type], $withoutSub);
        }

        throw $this->headerMismatch($header, self::TEMPLATES[$type]);
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
