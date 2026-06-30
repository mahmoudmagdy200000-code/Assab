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
        return $this->report($request, fn () => ['totalPayroll' => (int) \Modules\Admin\Models\Employee::sum('monthly_salary')]);
    }

    public function wasteAnalysis(Request $request): JsonResponse
    {
        return $this->report($request, fn ($s) => ['totalWaste' => (int) (clone $s)->where('module_key', 'waste')->sum('amount')]);
    }

    public function supplierPerformance(Request $request): JsonResponse
    {
        return $this->report($request, fn () => ['suppliers' => []]);
    }

    public function menuEngineering(Request $request): JsonResponse
    {
        return $this->report($request, fn () => ['items' => []]);
    }

    public function breakeven(Request $request): JsonResponse
    {
        return $this->report($request, fn ($s) => [
            'fixedCosts' => (int) (clone $s)->where('module_key', 'expenses')->sum('amount'),
            'revenue' => (int) (clone $s)->where('module_key', 'sales')->sum('amount'),
        ]);
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

    public function generate(Request $request, \Modules\Admin\Services\ReportService $reports): JsonResponse
    {
        return $this->run(function () use ($request, $reports) {
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

            $report = $reports->build($data);

            return $this->ok(array_merge([
                'reportId' => 'rpt_'.Str::upper(Str::random(10)),
                'generatedAt' => now()->toIso8601String(),
                'format' => $data['format'] ?? 'json',
                'downloadUrl' => null, // file export deferred to async exporter; json payload is inline
            ], $report));
        });
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
                'channels' => 'required|array|min:1',
                'channels.*' => 'in:email,inApp',
            ]);

            $companyId = $request->user()->company_id ?? null;
            $channels = array_values(array_unique($data['channels']));

            // Resolve target restaurants: explicit list, else all subscribed (active) in tenant.
            $restaurantQuery = AsabRestaurant::query();
            if ($companyId !== null) {
                $restaurantQuery->where('company_id', $companyId);
            }
            if (! empty($data['restaurantIds'])) {
                $restaurantQuery->whereIn('id', $data['restaurantIds']);
            } else {
                $restaurantQuery->where('status', 'active');
            }
            $restaurants = $restaurantQuery->get(['id', 'company_id', 'name']);

            $sentAt = now();
            $recipients = [];

            DB::transaction(function () use ($restaurants, $reportKey, $channels, $data, $companyId, $sentAt, $request, $notifications, &$recipients) {
                foreach ($restaurants as $restaurant) {
                    ReportDistribution::create([
                        'company_id' => $restaurant->company_id ?? $companyId,
                        'report_key' => $reportKey,
                        'restaurant_id' => $restaurant->id,
                        'channels' => $channels,
                        'period_from' => $data['period']['from'],
                        'period_to' => $data['period']['to'],
                        'sent' => true,
                        'sent_at' => $sentAt,
                        'sent_by_id' => $request->user()->id ?? null,
                    ]);

                    if (in_array('inApp', $channels, true) && ($request->user()->id ?? null)) {
                        $notifications->push(
                            $request->user()->id,
                            'report.sent',
                            'تم إرسال التقرير',
                            'Report '.$reportKey.' sent to '.$restaurant->name,
                            null,
                            ['type' => 'report', 'id' => $reportKey],
                        );
                    }

                    $recipients[] = [
                        'restaurantId' => $restaurant->id,
                        'sent' => true,
                        'sentDate' => $sentAt->toIso8601String(),
                    ];
                }
            });

            return $this->ok([
                'reportKey' => $reportKey,
                'sentAt' => $sentAt->toIso8601String(),
                'recipients' => $recipients,
            ]);
        });
    }

    /**
     * POST /admin/reports/{reportKey}/upload (BACKEND_API_SPEC.md §1.7b).
     * Stores an uploaded report file via the module's attachment approach.
     */
    public function uploadReport(Request $request, string $reportKey): JsonResponse
    {
        return $this->run(function () use ($request, $reportKey) {
            $request->validate(['file' => 'required|file']);

            $file = $request->file('file');
            $dir = ($request->user()->company_id ?? 'platform').'/reports/'.$reportKey;
            $path = $file->store($dir, 'public');

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
            ]);

            return $this->ok([
                'uploadId' => $attachment->id,
                'reportKey' => $reportKey,
                'status' => 'stored',
            ]);
        });
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
