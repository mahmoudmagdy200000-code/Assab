<?php

namespace Modules\Admin\Listeners;

use Illuminate\Support\Facades\DB;
use Modules\Admin\Models\AsabUser;
use Modules\Admin\Models\Operation;
use Modules\Admin\Services\BranchHierarchyLinker;
use Modules\Admin\Services\OperationFactory;
use Modules\Branch\Models\Branch;
use Modules\Shift\Events\DailyReportSubmittedEvent;

/**
 * FR-SAL — the manager's end-of-day sales sheet reaches the accountant. The
 * mobile daily close only lived in `branch_manager_shifts`; the المبيعات screen
 * lists `module_key='sales'` operations, so without this bridge the sheet
 * "بعت المبيعات ومجتش للمحاسب". Mints one sales operation per manager daily
 * close (guarded by payload.managerShiftId), origin='mobile'.
 */
class BridgeManagerDailyClose
{
    public function __construct(
        private readonly OperationFactory $factory,
        private readonly BranchHierarchyLinker $branches,
        private readonly \Psr\Log\LoggerInterface $log,
    ) {}

    public function handle(DailyReportSubmittedEvent $event): void
    {
        DB::transaction(function () use ($event): void {
            // The source report row serializes this projection's duplicate check
            // with the operation insert below.
            $shift = \Modules\Shift\Models\BranchManagerShift::query()
                ->whereKey($event->managerShift->id)
                ->lockForUpdate()
                ->firstOrFail();
            $this->bridge($shift);
        });
    }

    private function bridge(\Modules\Shift\Models\BranchManagerShift $shift): void
    {

        $alreadyBridged = Operation::where('module_key', 'sales')
            ->where('payload->managerShiftId', $shift->id)
            ->exists();
        if ($alreadyBridged) {
            return;
        }

        $branch = Branch::whereKey($shift->branch_id)->first();
        if ($branch === null) {
            return;
        }

        // Heal the branch's ASAB tags so a scoped accountant sees the operation
        // (same reasoning as the cashier shift bridge).
        $this->branches->ensure($branch);
        $branch->refresh();

        if ($branch->asab_company_id === null) {
            $this->log->warning('daily-close-bridge: skipped — branch has no ASAB company link', [
                'manager_shift_id' => $shift->id, 'branch_id' => $shift->branch_id,
                'reason' => 'BRANCH_NOT_LINKED',
                'fix' => 'php artisan asab:bridge-backfill (links branches), then resubmit the daily report',
            ]);

            return;
        }

        $actor = $this->systemActor($branch->asab_company_id);
        if ($actor === null) {
            $this->log->warning('daily-close-bridge: skipped — company has no ASAB user to attribute the operation to', [
                'manager_shift_id' => $shift->id, 'company_id' => $branch->asab_company_id,
                'reason' => 'NO_ASAB_ACTOR',
            ]);

            return;
        }

        $shift->loadMissing('branchManager:id,name');

        $payload = [
            'date' => $shift->shift_date?->format('Y-m-d'),
            'managerShiftId' => $shift->id,
            'managerName' => $shift->branchManager?->name,
            'totalHalalas' => $this->toHalalas($shift->total_sales),
            'totalSalesHalalas' => $this->toHalalas($shift->total_sales),
            'cashHalalas' => $this->toHalalas($shift->cash_collected),
            'cardHalalas' => $this->toHalalas($shift->card_payments),
            'appsHalalas' => $this->toHalalas($shift->aggregator_payments),
            'varianceHalalas' => $this->toHalalas($shift->variance),
            'notes' => $shift->daily_report_notes,
        ];

        $this->factory->createFromUpload(
            'sales',
            $payload,
            $actor,
            $shift->branch_id,
            $payload['totalHalalas'],
            'mobile',
            'mobile_app',
        );
    }

    private function systemActor(string $companyId): ?AsabUser
    {
        return AsabUser::where('company_id', $companyId)
            ->whereHas('roleAssignments', fn ($q) => $q->whereIn('role_key', ['accountant', 'head', 'admin']))
            ->orderBy('created_at')
            ->first()
            ?? AsabUser::where('company_id', $companyId)->orderBy('created_at')->first();
    }

    private function toHalalas(mixed $sar): int
    {
        return (int) round(((float) $sar) * 100);
    }
}
