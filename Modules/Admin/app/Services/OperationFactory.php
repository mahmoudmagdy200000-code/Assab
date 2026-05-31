<?php

namespace Modules\Admin\Services;

use Illuminate\Support\Facades\DB;
use Modules\Admin\Models\ApprovalStep;
use Modules\Admin\Models\AsabUser;
use Modules\Admin\Models\Operation;

/**
 * Creates a new pending Operation when a branch uploads a daily report
 * (BACKEND_API_SPEC.md §6.4.5). Module-specific data is kept in `payload`.
 */
class OperationFactory
{
    private const PREFIX = [
        'sales' => 'OPS', 'expenses' => 'EXP', 'purchases' => 'PUR', 'inventory' => 'INV',
        'shifts' => 'SHF', 'employees' => 'EMP', 'cash' => 'CSH', 'waste' => 'WD',
    ];

    public function __construct(
        private readonly RealtimeBroadcaster $rt,
        private readonly NotificationService $notifications,
    ) {}

    public function createFromUpload(string $moduleKey, array $payload, AsabUser $submitter, ?string $branchId, int $amount = 0): Operation
    {
        $op = DB::transaction(function () use ($moduleKey, $payload, $submitter, $branchId, $amount) {
            $op = Operation::create([
                'public_id' => $this->nextPublicId($moduleKey),
                'company_id' => $submitter->company_id,
                'branch_id' => $branchId,
                'module_key' => $moduleKey,
                'source_module' => null,
                'payload' => $payload,
                'amount' => $amount,
                'match' => 'exact',
                'origin' => 'mobile',
                'status' => Operation::STATUS_PENDING,
                'submitted_by_id' => $submitter->id,
                'submitted_at' => now(),
                'operation_date' => now(),
            ]);

            ApprovalStep::create([
                'operation_id' => $op->id,
                'stage_id' => 'submit',
                'action' => 'أُنشئ السجل: '.$op->public_id,
                'actor_user_id' => $submitter->id,
                'actor_label' => $submitter->name,
                'occurred_at' => now(),
            ]);

            return $op;
        });

        $this->rt->operationCreated($op);
        if ($submitter->company_id) {
            $this->notifications->pushToRole(
                $submitter->company_id, 'accountant', 'operation.created',
                'سجل جديد بانتظار المراجعة', $op->public_id.' — '.$moduleKey,
                null, ['type' => 'operation', 'id' => $op->id],
            );
        }

        return $op;
    }

    private function nextPublicId(string $moduleKey): string
    {
        $prefix = self::PREFIX[$moduleKey] ?? 'OPS';
        $n = Operation::where('public_id', 'like', "{$prefix}-%")->count() + 1;

        return $prefix.'-'.str_pad((string) $n, 4, '0', STR_PAD_LEFT);
    }
}
