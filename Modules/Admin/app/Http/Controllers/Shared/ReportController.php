<?php

namespace Modules\Admin\Http\Controllers\Shared;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Modules\Admin\Exceptions\AsabException;
use Modules\Admin\Http\Controllers\AsabController;
use Modules\Admin\Models\AsabBrand;
use Modules\Admin\Models\AsabRestaurant;
use Modules\Admin\Models\Attachment;
use Modules\Admin\Models\Operation;
use Modules\Admin\Models\ReportDistribution;
use Modules\Admin\Services\NotificationService;
use OpenSpout\Reader\XLSX\Reader as XlsxReader;

/**
 * Reports (BACKEND_API_SPEC.md §7.5). Each returns { reportId, generatedAt, data, downloadUrl }.
 * Figures computed from operations where applicable.
 */
class ReportController extends AsabController
{
    public function profitLoss(Request $request): JsonResponse
    {
        return $this->report($request, function ($scope) {
            $sales = (int) (clone $scope)->where('module_key', 'sales')->sum('amount');
            $expenses = (int) (clone $scope)->where('module_key', 'expenses')->sum('amount');
            $purchases = (int) (clone $scope)->where('module_key', 'purchases')->sum('amount');

            return ['income' => $sales, 'expenses' => $expenses, 'purchases' => $purchases, 'netProfit' => $sales - $expenses - $purchases];
        });
    }

    public function salesSummary(Request $request): JsonResponse
    {
        return $this->report($request, fn ($s) => [
            'totalSales' => (int) (clone $s)->where('module_key', 'sales')->sum('amount'),
            'count' => (clone $s)->where('module_key', 'sales')->count(),
        ]);
    }

    public function expenseSummary(Request $request): JsonResponse
    {
        return $this->report($request, fn ($s) => [
            'totalExpenses' => (int) (clone $s)->where('module_key', 'expenses')->sum('amount'),
            'count' => (clone $s)->where('module_key', 'expenses')->count(),
        ]);
    }

    public function inventoryValuation(Request $request): JsonResponse
    {
        return $this->report($request, fn ($s) => ['valuation' => (int) (clone $s)->where('module_key', 'inventory')->sum('amount')]);
    }

    public function payroll(Request $request): JsonResponse
    {
        return $this->run(function () use ($request) {
            [$from, $to, $branchIds] = $this->reportArgs($request);
            $q = \Modules\Admin\Models\Employee::query();
            if ($branchIds) {
                $q->whereIn('branch_id', $branchIds);
            }
            // Salaries are monthly, not dated: honor the period by counting staff
            // on the payroll as of the period end (hired on/before `to`).
            if ($to) {
                $q->whereDate('hire_date', '<=', $to);
            }

            return $this->ok($this->envelope([
                'totalPayroll' => (int) (clone $q)->sum('monthly_salary'),
                'headcount' => (clone $q)->count(),
            ]));
        });
    }

    public function wasteAnalysis(Request $request): JsonResponse
    {
        return $this->report($request, fn ($s) => ['totalWaste' => (int) (clone $s)->where('module_key', 'waste')->sum('amount')]);
    }

    public function supplierPerformance(Request $request, \Modules\Admin\Services\ReportService $reports): JsonResponse
    {
        return $this->run(function () use ($request, $reports) {
            [$from, $to, $branchIds] = $this->reportArgs($request);

            return $this->ok($this->envelope($reports->supplierPerformance($from, $to, $branchIds)));
        });
    }

    public function menuEngineering(Request $request, \Modules\Admin\Services\ReportService $reports): JsonResponse
    {
        return $this->run(function () use ($request, $reports) {
            [$from, $to, $branchIds] = $this->reportArgs($request);

            return $this->ok($this->envelope($reports->menuEngineering($from, $to, $branchIds)));
        });
    }

    public function breakeven(Request $request, \Modules\Admin\Services\ReportService $reports): JsonResponse
    {
        return $this->run(function () use ($request, $reports) {
            [$from, $to, $branchIds] = $this->reportArgs($request);

            return $this->ok($this->envelope($reports->breakeven($from, $to, $branchIds)));
        });
    }

    public function cashFlow(Request $request): JsonResponse
    {
        return $this->report($request, fn ($s) => [
            'inflow' => (int) (clone $s)->where('module_key', 'sales')->sum('amount'),
            'outflow' => (int) (clone $s)->whereIn('module_key', ['expenses', 'purchases'])->sum('amount'),
        ]);
    }

    public function catalog(): JsonResponse
    {
        // `category` distinguishes core vs specialized for the Admin Reports page
        // grouping (contract batch 1, Part B / AdminReportItem).
        return $this->listResponse([
            ['key' => 'pl', 'labelAr' => 'الأرباح والخسائر', 'labelEn' => 'Profit & Loss', 'category' => 'core'],
            ['key' => 'sales-channel', 'labelAr' => 'المبيعات حسب القناة', 'labelEn' => 'Sales by Channel', 'category' => 'core'],
            ['key' => 'smart-compare', 'labelAr' => 'المقارنة الذكية', 'labelEn' => 'Smart Compare', 'category' => 'specialized'],
            ['key' => 'profit-cash', 'labelAr' => 'الربح والنقدية', 'labelEn' => 'Profit & Cash', 'category' => 'core'],
            ['key' => 'breakeven', 'labelAr' => 'نقطة التعادل', 'labelEn' => 'Breakeven', 'category' => 'core'],
            ['key' => 'op-profit', 'labelAr' => 'ربح التشغيل', 'labelEn' => 'Operating Profit', 'category' => 'core'],
            ['key' => 'menu-eng', 'labelAr' => 'هندسة القائمة', 'labelEn' => 'Menu Engineering', 'category' => 'specialized'],
        ]);
    }

    public function generate(Request $request, \Modules\Admin\Services\ReportService $reports, \Modules\Admin\Services\ExportService $exports): \Symfony\Component\HttpFoundation\Response
    {
        try {
            $data = $request->validate([
                'reportKey' => 'required|string|in:pl,sales-channel,smart-compare,profit-cash,breakeven,op-profit,menu-eng',
                'period' => 'sometimes|array',
                'period.from' => 'sometimes|date',
                'period.to' => 'sometimes|date',
                'brandIds' => 'sometimes|array',
                'restaurantIds' => 'sometimes|array',
                'branchIds' => 'sometimes|array',
                'format' => 'sometimes|in:json,pdf,xlsx',
            ]);
        } catch (\Illuminate\Validation\ValidationException $e) {
            return $this->fail('VALIDATION_ERROR', 'Validation failed', 'فشل التحقق من البيانات', $e->errors(), 422);
        }

        // §6.1/§14.2: honor brand/restaurant scope by resolving down to branch ids
        // (build() only understands branchIds). Null = unscoped (all tenant branches).
        $resolved = $this->resolveBranchScope($data);
        if ($resolved !== null) {
            $data['branchIds'] = $resolved;
        }

        $report = $reports->build($data);
        $format = $data['format'] ?? 'json';

        // pdf|xlsx → real binary download (mirrors /company/me/reports/{key}/download);
        // json → the inline payload envelope.
        if (in_array($format, ['pdf', 'xlsx'], true)) {
            return $exports->report($report, $format);
        }

        return $this->ok(array_merge([
            'reportId' => 'rpt_'.Str::upper(Str::random(10)),
            'generatedAt' => now()->toIso8601String(),
            'format' => 'json',
            'downloadUrl' => null,
        ], $report));
    }

    /**
     * Resolve a report's brand/restaurant/branch scope down to a branch-id list
     * for ReportService. Returns null when no scope was requested (all tenant
     * branches); when a brand/restaurant scope was requested but matches no
     * branches, returns a non-matching sentinel so the report is genuinely empty
     * rather than silently widening to the whole tenant.
     *
     * @param  array<string, mixed>  $data
     * @return string[]|null
     */
    private function resolveBranchScope(array $data): ?array
    {
        $branchIds = array_values(array_filter((array) ($data['branchIds'] ?? [])));
        $restaurantIds = array_values(array_filter((array) ($data['restaurantIds'] ?? [])));
        $brandIds = array_values(array_filter((array) ($data['brandIds'] ?? [])));

        if (! $restaurantIds && ! $brandIds) {
            return $branchIds ?: null;
        }

        $ids = \Modules\Branch\Models\Branch::query()
            ->where(function ($w) use ($branchIds, $restaurantIds, $brandIds) {
                if ($branchIds) {
                    $w->orWhereIn('id', $branchIds);
                }
                if ($restaurantIds) {
                    $w->orWhereIn('asab_restaurant_id', $restaurantIds);
                }
                if ($brandIds) {
                    $w->orWhereIn('asab_brand_id', $brandIds);
                }
            })
            ->pluck('id')->all();

        return $ids ?: ['00000000-0000-0000-0000-000000000000'];
    }

    /**
     * POST /admin/reports/{reportKey}/send (BACKEND_API_SPEC.md §1.7a).
     * Records the distribution and dispatches in-app notifications to each
     * subscribed restaurant in the tenant (empty restaurantIds → all of them).
     */
    public function send(Request $request, NotificationService $notifications, string $reportKey): JsonResponse
    {
        return $this->run(function () use ($request, $notifications, $reportKey) {
            $data = $request->validate([
                'period' => 'required|array',
                'period.from' => 'required|date',
                'period.to' => 'required|date',
                'restaurantIds' => 'sometimes|array',
                'restaurantIds.*' => 'string',
                // New RPT-3 shape: method + format + coverMessage. `channels` kept for back-compat.
                'method' => 'sometimes|in:email,inApp,both',
                'channels' => 'sometimes|array',
                'channels.*' => 'in:email,inApp',
                'format' => 'sometimes|in:pdf,excel,both',
                'coverMessage' => 'sometimes|nullable|string|max:2000',
            ]);

            $companyId = $request->user()->company_id ?? null;
            $channels = $this->resolveChannels($data);
            if ($channels === []) {
                throw new AsabException('VALIDATION_ERROR', 'A delivery method is required',
                    'يجب تحديد وسيلة الإرسال (method أو channels)', 422);
            }
            $formats = $this->resolveFormats($data['format'] ?? 'pdf');
            $coverMessage = $data['coverMessage'] ?? null;

            // A platform admin (no company_id) must not bulk-send across every
            // tenant's restaurants — require an explicit restaurant list.
            if ($companyId === null && empty($data['restaurantIds'])) {
                throw new AsabException('VALIDATION_ERROR', 'restaurantIds is required without a company context',
                    'يجب تحديد المطاعم عند غياب سياق الشركة', 422);
            }

            // Target restaurants: explicit list, else all active in tenant (bulk «إرسال الكل»).
            $rq = AsabRestaurant::query();
            if ($companyId !== null) {
                $rq->where('company_id', $companyId);
            }
            if (! empty($data['restaurantIds'])) {
                $rq->whereIn('id', $data['restaurantIds']);
            } else {
                $rq->where('status', 'active');
            }
            $restaurants = $rq->get(['id', 'company_id', 'name', 'brand_id']);

            // Owners live on the brand; branches scope each restaurant's P&L.
            $brands = AsabBrand::whereIn('id', $restaurants->pluck('brand_id')->filter()->unique())
                ->get(['id', 'owner', 'owner_email', 'owner_user_id'])->keyBy('id');
            $branchesByRestaurant = \Modules\Branch\Models\Branch::whereIn('asab_restaurant_id', $restaurants->pluck('id'))
                ->get(['id', 'asab_restaurant_id'])->groupBy('asab_restaurant_id')->map(fn ($g) => $g->pluck('id')->all());

            $sentAt = now();
            $recipients = [];
            $emailJobs = [];

            DB::transaction(function () use ($restaurants, $reportKey, $channels, $formats, $coverMessage, $data, $companyId, $sentAt, $request, $notifications, $brands, $branchesByRestaurant, &$recipients, &$emailJobs) {
                foreach ($restaurants as $restaurant) {
                    $brand = $brands->get($restaurant->brand_id);
                    $branchIds = $branchesByRestaurant->get($restaurant->id, []);

                    // Idempotent per (company, reportKey, restaurant, period-from): a re-send
                    // updates the row rather than duplicating it.
                    ReportDistribution::updateOrCreate(
                        [
                            'company_id' => $restaurant->company_id ?? $companyId,
                            'report_key' => $reportKey,
                            'restaurant_id' => $restaurant->id,
                            'period_from' => $data['period']['from'],
                        ],
                        [
                            'channels' => $channels,
                            'format' => implode(',', $formats),
                            'cover_message' => $coverMessage,
                            'period_to' => $data['period']['to'],
                            'sent' => true,
                            'sent_at' => $sentAt,
                            'sent_by_id' => $request->user()->id ?? null,
                        ],
                    );

                    // In-app to the BRAND OWNER (BRO-1.2) — not the sending admin.
                    if (in_array('inApp', $channels, true) && $brand?->owner_user_id) {
                        $notifications->push(
                            $brand->owner_user_id,
                            'report.received',
                            'تقرير جديد من الإدارة',
                            $coverMessage ?? ('التقرير: '.$reportKey),
                            null,
                            ['type' => 'report', 'id' => $reportKey],
                        );
                    }

                    // Queue an email per requested format (pdf/excel) to the owner's inbox.
                    // Empty branch set → non-matching sentinel (never an unscoped null):
                    // the queued job has no tenant context, so an unscoped P&L would
                    // otherwise aggregate every company. companyId is also passed as
                    // the authoritative tenant filter (defense in depth).
                    if (in_array('email', $channels, true) && $brand?->owner_email) {
                        $jobBranchIds = $branchIds ?: ['00000000-0000-0000-0000-000000000000'];
                        $jobCompanyId = $restaurant->company_id ?? $companyId;
                        foreach ($formats as $fmt) {
                            $emailJobs[] = [$jobCompanyId, $reportKey, $brand->owner_email, $data['period']['from'], $data['period']['to'], $jobBranchIds, $fmt, $coverMessage, $restaurant->name];
                        }
                    }

                    $recipients[] = [
                        'restaurantId' => $restaurant->id,
                        'owner' => $brand?->owner,
                        'email' => $brand?->owner_email,
                        'sent' => true,
                        'sentDate' => $sentAt->toIso8601String(),
                    ];
                }
            });

            // Dispatch email jobs after commit (keeps the transaction short).
            foreach ($emailJobs as $j) {
                \Modules\Admin\Jobs\SendOwnerReportJob::dispatch(...$j);
            }

            return $this->ok([
                'reportKey' => $reportKey,
                'sentAt' => $sentAt->toIso8601String(),
                'sentCount' => count($recipients),
                'recipients' => $recipients,
            ]);
        });
    }

    /**
     * Delivery channels from the new `method` (preferred) or legacy `channels`.
     *
     * @return string[]
     */
    private function resolveChannels(array $data): array
    {
        if (! empty($data['method'])) {
            return match ($data['method']) {
                'email' => ['email'],
                'inApp' => ['inApp'],
                'both' => ['email', 'inApp'],
                default => [],
            };
        }

        return array_values(array_unique($data['channels'] ?? []));
    }

    /**
     * Attachment formats from `format` (pdf|excel|both). `excel` → xlsx.
     *
     * @return string[]
     */
    private function resolveFormats(string $format): array
    {
        return match ($format) {
            'excel' => ['xlsx'],
            'both' => ['pdf', 'xlsx'],
            default => ['pdf'],
        };
    }

    /**
     * POST /admin/reports/{reportKey}/upload (BACKEND_API_SPEC.md §1.7b).
     * Stores an uploaded report file via the module's attachment approach.
     */
    public function uploadReport(Request $request, string $reportKey): JsonResponse
    {
        return $this->run(function () use ($request, $reportKey) {
            $request->validate(['file' => 'required|file|max:20480']); // ≤20MB

            $file = $request->file('file');
            // Client-extension guard (Laravel's `mimes:csv` mis-detects text/plain).
            $ext = strtolower((string) $file->getClientOriginalExtension());
            if (! in_array($ext, ['xlsx', 'xls', 'csv'], true)) {
                throw new AsabException('VALIDATION_ERROR', 'Unsupported file type',
                    'نوع الملف غير مدعوم (المسموح: xlsx, xls, csv)', 422);
            }

            $dir = ($request->user()->company_id ?? 'platform').'/reports/'.$reportKey;
            $path = $file->store($dir, 'public');

            // RPT-1 ② parse dry-run: only a readable, non-empty sheet is «✓ تم التحقق».
            [$status, $errors, $rowCount] = $this->verifyUpload(Storage::disk('public')->path($path), $ext);

            $attachment = Attachment::create([
                'owner_type' => 'report',
                'owner_id' => $reportKey,
                'filename' => $file->getClientOriginalName(),
                'mime_type' => $file->getClientMimeType(),
                'size' => $file->getSize(),
                'storage_key' => $path,
                'public_url' => Storage::disk('public')->url($path),
                'label' => $reportKey,
                'uploaded_by_id' => $request->user()->id ?? null,
                'uploaded_at' => now(),
                'verified_at' => $status === 'verified' ? now() : null,
                'verified_by_id' => $status === 'verified' ? ($request->user()->id ?? null) : null,
            ]);

            return $this->ok([
                'uploadId' => $attachment->id,
                'reportKey' => $reportKey,
                'status' => $status, // verified | failed
                'rowCount' => $rowCount,
                'errors' => $errors,
            ]);
        });
    }

    /**
     * Dry-run parse to decide RPT-1 ② verification state without persisting a P&L.
     *
     * @return array{0:string,1:array<int,string>,2:int} [status, errors, dataRowCount]
     */
    private function verifyUpload(string $absolutePath, string $ext): array
    {
        try {
            $matrix = $ext === 'csv' ? $this->readCsvMatrix($absolutePath) : $this->readXlsxMatrix($absolutePath);
        } catch (\Throwable $e) {
            return ['failed', ['الملف تالف أو غير قابل للقراءة'], 0];
        }

        $dataRows = max(0, count($matrix) - 1); // minus the header row
        if (count($matrix) < 2) {
            return ['failed', ['الملف لا يحتوي على بيانات (صف عنوان + صف واحد على الأقل)'], $dataRows];
        }

        return ['verified', [], $dataRows];
    }

    /**
     * GET /admin/reports/{reportKey}/preview?uploadId=&period= (contract batch 1, A9).
     * Parses a previously uploaded CSV/xlsx into preview rows.
     */
    public function preview(Request $request, string $reportKey): JsonResponse
    {
        return $this->run(function () use ($request) {
            $uploadId = $request->query('uploadId');
            if (! $uploadId) {
                throw new AsabException('VALIDATION_ERROR', 'uploadId is required', 'معرف الرفع مطلوب', 422);
            }

            $attachment = Attachment::where('id', $uploadId)->where('owner_type', 'report')->firstOrFail();

            // Tenant guard: report uploads are stored under "{companyId}/reports/...".
            $companyId = $request->user()->company_id ?? null;
            if ($companyId !== null && ! str_starts_with((string) $attachment->storage_key, $companyId.'/')) {
                throw new AsabException('FORBIDDEN', 'Upload is not in your tenant', 'الملف لا يخص شركتك', 403);
            }

            return $this->ok(['rows' => $this->parseUpload($attachment)]);
        });
    }

    /**
     * GET /admin/reports/{reportKey}/status?period=YYYY-MM (contract batch 1, A10).
     * Sent/viewed coverage across the tenant's subscribed restaurants.
     */
    public function status(Request $request, string $reportKey): JsonResponse
    {
        return $this->run(function () use ($request, $reportKey) {
            $companyId = $request->user()->company_id ?? null;
            $period = $request->query('period');

            $dq = ReportDistribution::query()->where('report_key', $reportKey);
            if ($companyId !== null) {
                $dq->where('company_id', $companyId);
            }
            if ($period) {
                $dq->where('period_from', 'like', $period.'%');
            }
            $byRestaurant = $dq->get()->keyBy('restaurant_id');

            $rq = AsabRestaurant::query();
            if ($companyId !== null) {
                $rq->where('company_id', $companyId);
            }
            $restaurants = $rq->orderBy('name')->get(['id', 'name', 'brand_id']);
            $brands = AsabBrand::whereIn('id', $restaurants->pluck('brand_id')->filter()->unique())
                ->get(['id', 'owner', 'owner_email'])->keyBy('id');

            $sent = 0;
            $viewed = 0;
            $rows = $restaurants->map(function ($r) use ($byRestaurant, $brands, &$sent, &$viewed) {
                $d = $byRestaurant->get($r->id);
                $isSent = (bool) ($d?->sent);
                $isViewed = (bool) ($d?->viewed);
                $sent += $isSent ? 1 : 0;
                $viewed += $isViewed ? 1 : 0;
                $brand = $brands->get($r->brand_id);

                return [
                    'restaurantId' => $r->id,
                    'restaurantName' => $r->name,
                    'owner' => $brand?->owner,
                    'email' => $brand?->owner_email,
                    'sent' => $isSent,
                    'sentDate' => optional($d?->sent_at)->toIso8601String(),
                    'viewed' => $isViewed,
                    'viewedDate' => optional($d?->viewed_at)->toIso8601String(),
                ];
            })->all();

            $total = $restaurants->count();

            return $this->ok([
                'reportKey' => $reportKey,
                'period' => $period,
                'summary' => [
                    'sent' => $sent,
                    'notSent' => max(0, $total - $sent),
                    'viewed' => $viewed,
                    'notViewed' => max(0, $total - $viewed),
                ],
                'rows' => $rows,
            ]);
        });
    }

    /**
     * POST /company/me/reports/distributions/{id}/viewed (RPT-3 read receipt).
     * Marks a sent distribution as viewed. Scoped to the caller's company so a
     * foreign distribution id reads as 404, never a cross-tenant write.
     */
    public function markDistributionViewed(Request $request, string $id): JsonResponse
    {
        return $this->run(function () use ($request, $id) {
            $companyId = $request->user()->company_id ?? null;
            $q = ReportDistribution::where('id', $id);
            if ($companyId !== null) {
                $q->where('company_id', $companyId);
            }
            $dist = $q->firstOrFail();
            if (! $dist->viewed) {
                $dist->update(['viewed' => true, 'viewed_at' => now()]);
            }

            return $this->ok([
                'id' => $dist->id,
                'viewed' => true,
                'viewedAt' => optional($dist->viewed_at)->toIso8601String(),
            ]);
        });
    }

    /**
     * GET /admin/reports/periods (contract batch 1, A11).
     * Distinct YYYY-MM from past distributions, unioned with the trailing 12 months.
     */
    public function periods(Request $request): JsonResponse
    {
        return $this->run(function () use ($request) {
            $companyId = $request->user()->company_id ?? null;

            $q = ReportDistribution::query();
            if ($companyId !== null) {
                $q->where('company_id', $companyId);
            }
            $fromDistributions = $q->pluck('period_from')->filter()
                ->map(fn ($p) => substr((string) $p, 0, 7));

            $recent = [];
            for ($i = 0; $i < 12; $i++) {
                $recent[] = now()->copy()->subMonths($i)->format('Y-m');
            }

            $periods = $fromDistributions->merge($recent)->unique()->sortDesc()->values()->all();

            return $this->ok(['periods' => $periods]);
        });
    }

    /** Read a stored report upload (CSV/xlsx) into preview rows. */
    private function parseUpload(Attachment $attachment): array
    {
        $disk = Storage::disk('public');
        if (! $disk->exists($attachment->storage_key)) {
            return [];
        }

        $absolute = $disk->path($attachment->storage_key);
        $ext = strtolower(pathinfo((string) $attachment->filename, PATHINFO_EXTENSION));
        $matrix = $ext === 'csv' ? $this->readCsvMatrix($absolute) : $this->readXlsxMatrix($absolute);

        return $this->matrixToRows($matrix);
    }

    /** @return array<int, array<int, mixed>> */
    private function readCsvMatrix(string $path): array
    {
        $out = [];
        if (($handle = fopen($path, 'r')) !== false) {
            while (($row = fgetcsv($handle)) !== false) {
                $out[] = $row;
            }
            fclose($handle);
        }

        return $out;
    }

    /** @return array<int, array<int, mixed>> */
    private function readXlsxMatrix(string $path): array
    {
        $out = [];
        $reader = new XlsxReader;
        $reader->open($path);
        foreach ($reader->getSheetIterator() as $sheet) {
            foreach ($sheet->getRowIterator() as $row) {
                $out[] = $row->toArray();
            }
            break; // first sheet only
        }
        $reader->close();

        return $out;
    }

    /**
     * Flatten a header + data matrix into {label,value,type,header} preview rows.
     * Capped to keep the preview payload bounded (no unbounded collections).
     *
     * @param  array<int, array<int, mixed>>  $matrix
     */
    private function matrixToRows(array $matrix, int $limit = 200): array
    {
        if (empty($matrix)) {
            return [];
        }

        $headers = array_map(fn ($c) => (string) $c, array_shift($matrix));
        $rows = [];
        foreach (array_slice($matrix, 0, $limit) as $line) {
            $label = isset($line[0]) ? (string) $line[0] : '';
            foreach ($line as $i => $value) {
                if ($i === 0) {
                    continue;
                }
                $rows[] = [
                    'label' => $label,
                    'value' => $value,
                    'type' => is_numeric($value) ? 'number' : 'string',
                    'header' => $headers[$i] ?? ('col'.$i),
                ];
            }
        }

        return $rows;
    }

    /**
     * from/to/branchIds pulled from a typed-report request body.
     *
     * @return array{0:?string,1:?string,2:?array}
     */
    private function reportArgs(Request $request): array
    {
        $branchIds = $request->input('branchIds');

        return [$request->input('from'), $request->input('to'), $branchIds ? (array) $branchIds : null];
    }

    /**
     * The §7.5 typed-report envelope. `downloadUrl` stays null on the inline JSON
     * surface — the binary lives at /company/me/reports/{key}/download.
     *
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    private function envelope(array $data): array
    {
        return [
            'reportId' => 'rpt_'.Str::upper(Str::random(10)),
            'generatedAt' => now()->toIso8601String(),
            'data' => $data,
            'downloadUrl' => null,
        ];
    }

    private function report(Request $request, callable $compute): JsonResponse
    {
        return $this->run(function () use ($request, $compute) {
            $scope = Operation::query();
            if ($from = $request->input('from')) {
                $scope->where('operation_date', '>=', $from);
            }
            if ($to = $request->input('to')) {
                $scope->where('operation_date', '<=', $to);
            }
            if ($branchIds = $request->input('branchIds')) {
                $scope->whereIn('branch_id', (array) $branchIds);
            }

            return $this->ok([
                'reportId' => 'rpt_'.Str::upper(Str::random(10)),
                'generatedAt' => now()->toIso8601String(),
                'data' => $compute($scope),
                'downloadUrl' => null,
            ]);
        });
    }
}
