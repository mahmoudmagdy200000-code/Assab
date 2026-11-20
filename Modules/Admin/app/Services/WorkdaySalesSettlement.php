<?php

namespace Modules\Admin\Services;

use App\Support\ShiftFinancialCalculator;
use Illuminate\Support\Facades\DB;
use Modules\Admin\Exceptions\AsabException;
use Modules\Admin\Models\AsabUser;
use Modules\Admin\Models\Operation;
use Modules\Shift\Models\ShiftSalesBreakdown;
use Modules\Shift\Services\ShiftReportReviewService;

class WorkdaySalesSettlement
{
    public function __construct(
        private ShiftReportReviewService $reviews,
        private EmployeeAllocationService $employees,
    ) {}

    public function present(Operation $op): array
    {
        $payload = $op->payload;
        $saved = $payload['daySettlement'] ?? null;
        $delivery = [[
            'id' => 'delivery', 'name' => null, 'amountHalalas' => (int) $payload['appsHalalas'],
        ]];
        $members = $this->members($payload['managerShiftId']);
        $appAmounts = ShiftSalesBreakdown::whereIn('cashier_shift_id', $members)
            ->with('aggregator')->orderBy('aggregator_id')->get()->groupBy('aggregator_id')
            ->map(fn ($rows, $id) => [
                'id' => (string) $id,
                'name' => $rows->first()->aggregator?->name,
                'amountHalalas' => $rows->sum(fn ($row) => ShiftFinancialCalculator::storedSarToHalalas($row->amount)),
            ])->values();
        if ($appAmounts->isNotEmpty() && $appAmounts->every(fn ($app) => $app['id'] !== '' && $app['name'])
            && $appAmounts->sum('amountHalalas') === $delivery[0]['amountHalalas']) {
            $delivery = $appAmounts->all();
        }
        $result = $saved ?? [
            'revision' => 0,
            'cashHalalas' => (int) $payload['cashHalalas'],
            'cardsHalalas' => (int) $payload['cardHalalas'],
            'delivery' => $delivery,
            'allocations' => [],
            'reason' => null,
        ];
        $result['grossHalalas'] = (int) $payload['totalSalesHalalas'];
        $result['collectedHalalas'] = $result['cashHalalas'] + $result['cardsHalalas']
            + array_sum(array_column($result['delivery'], 'amountHalalas'));
        $result['varianceHalalas'] = $result['collectedHalalas'] - $result['grossHalalas'];

        return $result;
    }

    public function save(Operation $op, AsabUser $actor, array $input): array
    {
        return DB::transaction(function () use ($op, $actor, $input) {
            $op = $this->reviews->lockOperation($op);
            abort_unless(app(OperationWorkflowClassifier::class)->classify($op) === OperationWorkflowClassifier::SAL, 404);
            $this->reviews->authorizeReview($op, $actor, 'accountant');
            $fingerprint = hash('sha256', json_encode($input, JSON_THROW_ON_ERROR));
            $previous = $op->steps()->where('stage_id', 'day_settlement')
                ->where('meta->commandId', $input['commandId'])->first();
            if ($previous) {
                abort_unless($previous->actor_user_id === $actor->id && $previous->meta['fingerprint'] === $fingerprint, 409);

                return $this->present($op);
            }
            $this->reviews->assertMutableOperation($op);
            abort_unless($op->status === Operation::STATUS_PENDING, 409);
            abort_unless($this->members($op->payload['managerShiftId'])->isNotEmpty(), 409);
            $before = $this->present($op);
            if ($before['revision'] !== (int) $input['expectedRevision']) {
                throw new AsabException('STALE_DAY_SETTLEMENT', 'Reload the updated settlement', 'أعد تحميل أحدث نسخة من التسوية', 409);
            }
            $amounts = array_column($input['delivery'], 'amountHalalas', 'id');
            $delivery = $before['delivery'];
            if (count($amounts) !== count($delivery) || array_diff(array_column($delivery, 'id'), array_keys($amounts))) {
                throw new AsabException('INVALID_DELIVERY_CHANNELS', 'Delivery channels changed', 'قنوات التوصيل لا تطابق التقرير', 422);
            }
            foreach ($delivery as &$channel) {
                $channel['amountHalalas'] = (int) $amounts[$channel['id']];
            }
            unset($channel);
            $allocations = [];
            foreach ($input['allocations'] as $row) {
                $employee = $this->employees->resolveEmployee($op, ['empNumber' => $row['empNumber']]);
                if ($employee->status !== 'active' || isset($allocations[$employee->id])) {
                    throw new AsabException('INVALID_DAY_EMPLOYEE', 'Employee must be active and unique', 'اختر موظفًا نشطًا مرة واحدة', 422);
                }
                $allocations[$employee->id] = [
                    'employeeId' => $employee->id, 'empNumber' => $employee->emp_number,
                    'name' => $employee->name, 'amountHalalas' => (int) $row['amountHalalas'],
                ];
            }
            $collected = $input['cashHalalas'] + $input['cardsHalalas'] + array_sum($amounts);
            $this->employees->assertSum(array_column($allocations, 'amountHalalas'), max(0, $before['grossHalalas'] - $collected));
            $payload = $op->payload;
            $payload['daySettlement'] = [
                'revision' => $before['revision'] + 1,
                'dayRevision' => app(\Modules\Shift\Services\ShiftReportRevisionService::class)->currentManagerRevision(
                    \Modules\Shift\Models\BranchManagerShift::withoutEagerLoads()->findOrFail($payload['managerShiftId'])
                )?->id,
                'cashHalalas' => (int) $input['cashHalalas'], 'cardsHalalas' => (int) $input['cardsHalalas'],
                'delivery' => $delivery, 'allocations' => array_values($allocations), 'reason' => $input['reason'],
            ];
            abort_if($payload['daySettlement']['dayRevision'] === null, 409);
            $op->update(['payload' => $payload]);
            $after = $this->present($op);
            $op->steps()->create([
                'stage_id' => 'day_settlement', 'actor_user_id' => $actor->id,
                'action' => 'حفظ تسوية مبيعات اليوم', 'note' => $input['reason'],
                'actor_label' => $actor->name, 'occurred_at' => now(),
                'meta' => ['commandId' => $input['commandId'], 'fingerprint' => $fingerprint, 'before' => $before, 'after' => $after],
            ]);

            return $after;
        });
    }

    public function postOnFinalApproval(Operation $op, AsabUser $actor): void
    {
        $settlement = $op->payload['daySettlement'] ?? null;
        if ($settlement === null || app(OperationWorkflowClassifier::class)->classify($op) !== OperationWorkflowClassifier::SAL) {
            return;
        }
        $members = $this->members($op->payload['managerShiftId']);
        foreach ($members as $memberId) {
            $source = \Modules\Shift\Models\CashierShift::withoutEagerLoads()->findOrFail($memberId);
            app(\Modules\Shift\Services\ShortagePostingService::class)
                ->reverseVerifiedForDay($source, $actor, $op->id);
        }
        $rows = [];
        foreach ($settlement['allocations'] as $allocation) {
            $employee = $this->employees->resolveEmployee($op, $allocation);
            abort_unless($employee->status === 'active', 409);
            $rows[] = ['employee' => $employee, 'amountHalalas' => $allocation['amountHalalas']];
        }
        if ($rows !== []) {
            $this->employees->post($op, 'cash_variance', 'عجز تسوية مبيعات اليوم', $rows, $actor, $op->operation_date);
        }
    }

    private function members(string $dayId)
    {
        return DB::table('shift_liability_daily_locks')->where('branch_manager_shift_id', $dayId)
            ->whereNull('released_at')->whereNull('superseded_at')->pluck('cashier_shift_id');
    }
}
