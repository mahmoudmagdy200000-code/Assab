<?php

namespace Modules\Admin\Services;

use Illuminate\Support\Facades\DB;
use Modules\Admin\Exceptions\AsabException;
use Modules\Admin\Models\ApprovalStep;
use Modules\Admin\Models\AsabUser;
use Modules\Admin\Models\Operation;
use Modules\Admin\Support\OperationEnums;

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
        private readonly ShiftSalesFeed $shiftFeed,
    ) {}

    /**
     * @param  string  $origin  SRS §5.2b — where the record entered the pipeline
     *                          (`mobile` branch app, `procurement` flow, `system` import).
     * @param  string  $channel  physical surface the record was submitted from
     *                           (`mobile_app` | `dashboard`); orthogonal to $origin.
     */
    public function createFromUpload(string $moduleKey, array $payload, AsabUser $submitter, ?string $branchId, int $amount = 0, string $origin = 'mobile', string $channel = 'mobile_app', bool $nativeLegacySales = false): Operation
    {
        if (! OperationEnums::isValidOrigin($origin)) {
            throw new AsabException(
                'INVALID_ORIGIN',
                'Unknown operation origin',
                'مصدر العملية غير معروف',
                422,
                ['allowed' => array_keys(OperationEnums::ORIGIN)],
            );
        }

        $op = OperationSequence::createWithPublicId(
            self::PREFIX[$moduleKey] ?? 'OPS',
            fn (string $publicId) => DB::transaction(function () use ($publicId, $moduleKey, $payload, $submitter, $branchId, $amount, $origin, $channel, $nativeLegacySales) {
                if ($nativeLegacySales && ($moduleKey !== 'sales' || ! \Modules\Branch\Models\Branch::whereKey($branchId)
                    ->where('asab_company_id', $submitter->company_id)->exists()
                    || ! $submitter->roleAssignments()->where('role_key', 'branch')->get()
                        ->contains(fn ($grant) => in_array($branchId, $grant->branch_ids ?? [], true)))) {
                    throw new AsabException('WORKFLOW_SOURCE_SCOPE_INVALID', 'Invalid native upload scope', 'نطاق مصدر العملية غير صالح', 403);
                }
                $op = Operation::create([
                    'public_id' => $publicId,
                    'company_id' => $submitter->company_id,
                    'branch_id' => $branchId,
                    'module_key' => $moduleKey,
                    'source_module' => null,
                    'payload' => $payload,
                    'amount' => $amount,
                    'match' => 'exact',
                    'origin' => $origin,
                    'channel' => $channel,
                    'status' => Operation::STATUS_PENDING,
                    'submitted_by_id' => $submitter->id,
                    'submitted_at' => now(),
                    'operation_date' => now(),
                ]);

                ApprovalStep::create([
                    'operation_id' => $op->id,
                    'stage_id' => 'submit',
                    // This flag is supplied only by trusted native upload call sites.
                    // Proof and operation commit/rollback together; payload cannot grant it.
                    'meta' => $nativeLegacySales ? ['workflowSource' => [
                        'kind' => OperationWorkflowClassifier::LEGACY, 'source' => 'native_branch_upload',
                        'operationId' => $op->id, 'companyId' => $op->company_id,
                        'branchId' => $op->branch_id, 'actorId' => $submitter->id,
                    ]] : null,
                    'action' => 'أُنشئ السجل: '.$op->public_id,
                    'actor_user_id' => $submitter->id,
                    'actor_label' => $submitter->name,
                    'occurred_at' => now(),
                ]);

                // ACC-6.1 interim live feed — a sales upload bumps the open shift.
                $this->shiftFeed->record($op);

                return $op;
            }),
        );

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
}
