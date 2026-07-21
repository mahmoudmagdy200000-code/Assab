<?php

namespace Modules\Admin\Services;

use Modules\Admin\Models\Operation;

/**
 * Single source of truth for the branch manager's «تقارير اليوم» checklist
 * (BRM-1 / BRM-2). Each report carries today's upload state plus the last
 * submission's review outcome, so the traffic-light reflects the full branch →
 * accountant → head loop (an accountant approval flips the chip to «success»,
 * a rejection to «late»). Consumed by BOTH the overview KPIs
 * (requiredReportsCount + requiredReports) and the dedicated upload/status
 * endpoint, so the two surfaces can never drift.
 */
class BranchDailyReportsService
{
    /**
     * The daily checklist. `module` is the Operation.module_key the report maps
     * to; the reportType a branch POSTs to .../upload matches the `id`.
     * `shift-close` (the evening close) is optional and carries no time deadline.
     */
    private const REPORTS = [
        ['id' => 'sales', 'module' => 'sales', 'name' => 'مبيعات اليوم', 'description' => 'إجمالي مبيعات اليوم', 'required' => true, 'deadline' => '23:00'],
        ['id' => 'expenses', 'module' => 'expenses', 'name' => 'المصروفات', 'description' => 'مصروفات وفواتير اليوم', 'required' => true, 'deadline' => '23:00'],
        ['id' => 'inventory', 'module' => 'inventory', 'name' => 'جرد المخزون اليومي', 'description' => 'جرد أصناف الفرع اليومي', 'required' => true, 'deadline' => '23:00'],
        ['id' => 'shift-close', 'module' => 'shifts', 'name' => 'إغلاق الوردية المسائية', 'description' => 'تقرير إغلاق وردية المساء', 'required' => false, 'deadline' => 'اختياري'],
    ];

    /**
     * The Operation.module_key a checklist reportType maps to, or null for an
     * unknown reportType. Lets the upload endpoint translate `shift-close` →
     * `shifts` from one place.
     */
    public function moduleFor(string $reportId): ?string
    {
        foreach (self::REPORTS as $r) {
            if ($r['id'] === $reportId) {
                return $r['module'];
            }
        }

        return null;
    }

    /** @return array<int, array<string, mixed>> the checklist for a branch. */
    public function reports(?string $branchId): array
    {
        return array_map(fn ($r) => $this->reportRow($r, $branchId), self::REPORTS);
    }

    /** Required reports still outstanding today — drives the overview KPI badge. */
    public function requiredCount(array $reports): int
    {
        return count(array_filter($reports, fn ($r) => $r['required'] && ! $r['uploadedToday']));
    }

    private function reportRow(array $r, ?string $branchId): array
    {
        // Most recent submission for this report on this branch (any status) —
        // powers lastUpload + lastStatus even when nothing landed today.
        $last = $branchId === null ? null : Operation::where('branch_id', $branchId)
            ->where('module_key', $r['module'])
            ->orderByDesc('submitted_at')
            ->first(['status', 'submitted_at']);

        // A rejected upload does NOT satisfy the checklist — the branch must
        // re-upload — so uploadedToday only counts a still-alive submission.
        $uploadedToday = $branchId !== null && Operation::where('branch_id', $branchId)
            ->where('module_key', $r['module'])
            ->whereDate('operation_date', today())
            ->whereIn('status', [Operation::STATUS_PENDING, Operation::STATUS_APPROVED, Operation::STATUS_FINAL])
            ->exists();

        return [
            'id' => $r['id'],
            'name' => $r['name'],
            'description' => $r['description'],
            'required' => $r['required'],
            'lastUpload' => optional($last?->submitted_at)->toIso8601String(),
            'lastStatus' => $this->lastStatus($r, $last),
            'todayDeadline' => $r['deadline'],
            'uploadedToday' => $uploadedToday,
        ];
    }

    /**
     * success | late | missing — the report chip's traffic-light.
     *  - missing: never submitted.
     *  - late: the last submission was rejected by the accountant (needs
     *          re-upload) OR it crossed the day's deadline.
     *  - success: submitted and accepted / in review, on time.
     */
    private function lastStatus(array $r, ?Operation $last): string
    {
        if ($last === null) {
            return 'missing';
        }

        if ($last->status === Operation::STATUS_REJECTED || $this->submittedLate($r, $last)) {
            return 'late';
        }

        return 'success';
    }

    /** True when a timed report's last submission crossed its HH:MM deadline. */
    private function submittedLate(array $r, Operation $last): bool
    {
        if ($last->submitted_at === null || ! preg_match('/^\d{2}:\d{2}$/', (string) $r['deadline'])) {
            return false;
        }

        [$h, $m] = array_map('intval', explode(':', $r['deadline']));

        return $last->submitted_at->gt($last->submitted_at->copy()->setTime($h, $m));
    }
}
