<?php

namespace Modules\Admin\Database\Seeders;

use Illuminate\Database\Seeder;
use Modules\Admin\Models\ApprovalStep;
use Modules\Admin\Models\AsabCompany;
use Modules\Admin\Models\AsabUser;
use Modules\Admin\Models\Operation;

/**
 * Sample operations across modules/statuses so the pipeline + dashboards have data.
 * Idempotent by public_id. branch_id reuses a legacy Branch row when available.
 */
class AsabOperationSeeder extends Seeder
{
    public function run(): void
    {
        $company = AsabCompany::where('contact_email', 'group@asab.sa')->first();
        if (! $company) {
            return;
        }

        $branchId = $this->legacyBranchId();
        $accountant = AsabUser::where('email', 'accountant@asab.sa')->first();
        $branchUser = AsabUser::where('email', 'branch@asab.sa')->first();

        $samples = [
            ['public_id' => 'OPS-2401', 'module_key' => 'sales', 'amount' => 1850000, 'match' => 'exact', 'status' => Operation::STATUS_PENDING],
            ['public_id' => 'OPS-2402', 'module_key' => 'expenses', 'amount' => 320000, 'match' => 'review', 'status' => Operation::STATUS_PENDING, 'diff_note' => 'فاتورة غير واضحة'],
            ['public_id' => 'OPS-2403', 'module_key' => 'purchases', 'amount' => 540000, 'match' => 'diff', 'status' => Operation::STATUS_APPROVED, 'diff_note' => 'فرق في الكمية: 5 كجم'],
            ['public_id' => 'OPS-2404', 'module_key' => 'inventory', 'amount' => 0, 'match' => 'exact', 'status' => Operation::STATUS_APPROVED],
            ['public_id' => 'OPS-2405', 'module_key' => 'sales', 'amount' => 2110000, 'match' => 'exact', 'status' => Operation::STATUS_FINAL],
            ['public_id' => 'OPS-2406', 'module_key' => 'waste', 'amount' => 45000, 'match' => 'exact', 'status' => Operation::STATUS_FINAL, 'erp_posted' => true, 'erp_batch_id' => 'ERP-BATCH-20260531-001'],
            ['public_id' => 'OPS-2407', 'module_key' => 'expenses', 'amount' => 78000, 'match' => 'review', 'status' => Operation::STATUS_REJECTED, 'reject_reason' => 'فاتورة مفقودة أو غير واضحة'],
            ['public_id' => 'OPS-2408', 'module_key' => 'cash', 'amount' => 150000, 'match' => 'exact', 'status' => Operation::STATUS_PENDING],
        ];

        foreach ($samples as $s) {
            $op = Operation::updateOrCreate(
                ['public_id' => $s['public_id']],
                array_merge([
                    'company_id' => $company->id,
                    'branch_id' => $branchId,
                    'origin' => 'mobile',
                    'submitted_by_id' => $branchUser?->id,
                    'submitted_at' => now()->subDays(2),
                    'operation_date' => now()->subDays(2),
                    'diff_note' => null,
                    'erp_posted' => false,
                    'erp_batch_id' => null,
                ], $s, $this->actorStamps($s['status'], $accountant)),
            );

            ApprovalStep::updateOrCreate(
                ['operation_id' => $op->id, 'stage_id' => 'submit'],
                ['action' => 'أُنشئ السجل: '.$op->public_id, 'actor_user_id' => $branchUser?->id, 'actor_label' => $branchUser?->name, 'occurred_at' => now()->subDays(2)],
            );
        }
    }

    private function actorStamps(string $status, ?AsabUser $accountant): array
    {
        $out = [];
        if (in_array($status, [Operation::STATUS_APPROVED, Operation::STATUS_FINAL], true)) {
            $out['approved_by_id'] = $accountant?->id;
            $out['approved_at'] = now()->subDay();
        }
        if ($status === Operation::STATUS_FINAL) {
            $out['final_approved_at'] = now()->subHours(6);
        }
        return $out;
    }

    private function legacyBranchId(): ?string
    {
        try {
            return optional(\Modules\Branch\Models\Branch::query()->first())->id;
        } catch (\Throwable $e) {
            return null;
        }
    }
}
