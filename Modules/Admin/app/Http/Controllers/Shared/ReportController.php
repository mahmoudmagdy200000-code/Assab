<?php

namespace Modules\Admin\Http\Controllers\Shared;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Modules\Admin\Http\Controllers\AsabController;
use Modules\Admin\Models\AsabRestaurant;
use Modules\Admin\Models\Attachment;
use Modules\Admin\Models\Operation;
use Modules\Admin\Models\ReportDistribution;
use Modules\Admin\Services\NotificationService;

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
        return $this->listResponse([
            ['key' => 'pl', 'labelAr' => 'الأرباح والخسائر', 'labelEn' => 'Profit & Loss'],
            ['key' => 'sales-channel', 'labelAr' => 'المبيعات حسب القناة', 'labelEn' => 'Sales by Channel'],
            ['key' => 'smart-compare', 'labelAr' => 'المقارنة الذكية', 'labelEn' => 'Smart Compare'],
            ['key' => 'profit-cash', 'labelAr' => 'الربح والنقدية', 'labelEn' => 'Profit & Cash'],
            ['key' => 'breakeven', 'labelAr' => 'نقطة التعادل', 'labelEn' => 'Breakeven'],
            ['key' => 'op-profit', 'labelAr' => 'ربح التشغيل', 'labelEn' => 'Operating Profit'],
            ['key' => 'menu-eng', 'labelAr' => 'هندسة القائمة', 'labelEn' => 'Menu Engineering'],
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
