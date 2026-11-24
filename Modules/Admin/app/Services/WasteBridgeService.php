<?php

namespace Modules\Admin\Services;

use Illuminate\Support\Facades\DB;
use Modules\Admin\Models\ApprovalStep;
use Modules\Admin\Models\Employee;
use Modules\Admin\Models\Operation;
use Modules\Admin\Support\WasteEnums;
use Modules\Branch\Models\Branch;
use Modules\Inventory\Enums\ProblemType;
use Modules\Inventory\Models\WasteDamageReport;

/**
 * Bridge mobile WasteDamageReport submissions into `asab_operations`
 * (`module_key='waste'`, `source_module='waste_damage_report'`) so Accountant
 * review -> Brand Owner attribution approval -> Head final approval share one
 * canonical record without splitting multi-item reports.
 */
class WasteBridgeService
{
    public const SOURCE = 'waste_damage_report';

    public function __construct(
        private readonly BranchHierarchyLinker $branches,
        private readonly RealtimeBroadcaster $rt,
        private readonly NotificationService $notifications,
        private readonly \Psr\Log\LoggerInterface $log,
    ) {}

    public function sync(WasteDamageReport $report, ?array $ownerApprovalOverride = null): ?Operation
    {
        $branch = Branch::whereKey($report->branch_id)
            ->first(['id', 'name', 'asab_company_id', 'asab_brand_id', 'asab_restaurant_id']);
        if ($branch === null) {
            return null;
        }

        $this->branches->ensure($branch);
        $companyId = $branch->asab_company_id;
        if ($companyId === null) {
            $this->log->warning('waste-bridge: skipped — branch not linked to ASAB company', [
                'report_id' => $report->id,
                'branch_id' => $report->branch_id,
            ]);

            return null;
        }

        $existing = Operation::withoutGlobalScopes()
            ->whereNull('deleted_at')
            ->where('source_module', self::SOURCE)
            ->where('source_id', $report->id)
            ->first();

        $report->loadMissing([
            'items.item',
            'items.responsibleEmployees.cashier',
            'items.responsibleEmployees.branchManager',
            'createdBy',
        ]);

        $revision = (int) ($existing?->payload['revision'] ?? 1);
        $payload = $this->buildPayload($report, $companyId, $revision, $existing?->payload['ownerApproval'] ?? null, $ownerApprovalOverride);
        $totalAmountHalalas = array_sum(array_map(fn ($p) => (int) ($p['value'] ?? 0), $payload['products']));

        if ($existing) {
            if ($existing->status === Operation::STATUS_PENDING) {
                $existingPayload = $existing->payload ?? [];
                $existingSig = $this->productsSignature($existingPayload['products'] ?? []);
                $newSig = $this->productsSignature($payload['products'] ?? []);
                if ($ownerApprovalOverride === null && $existingSig !== $newSig) {
                    $payload['revision'] = $revision + 1;
                }
                $existing->update([
                    'payload' => $payload,
                    'amount' => $totalAmountHalalas,
                ]);
            } elseif ($existing->status === Operation::STATUS_REJECTED && $ownerApprovalOverride === null) {
                $nextRev = $revision + 1;
                $payload['revision'] = $nextRev;
                $payload['ownerApproval'] = null;
                $existing->update([
                    'payload' => $payload,
                    'amount' => $totalAmountHalalas,
                    'status' => Operation::STATUS_PENDING,
                    'rejected_by_id' => null,
                    'rejected_at' => null,
                    'reject_reason' => null,
                    'submitted_at' => now(),
                ]);
                ApprovalStep::create([
                    'operation_id' => $existing->id,
                    'stage_id' => 'submit',
                    'action' => 'إعادة إرسال بلاغ هدر (نسخة '.$nextRev.'): '.$existing->public_id,
                    'actor_label' => 'تطبيق الفرع',
                    'occurred_at' => now(),
                ]);
            } elseif ($ownerApprovalOverride !== null && in_array($existing->status, [Operation::STATUS_PENDING, Operation::STATUS_APPROVED], true)) {
                $existing->update(['payload' => $payload]);
            }

            return $existing->fresh();
        }

        $op = OperationSequence::createWithPublicId(
            'WST',
            fn (string $publicId) => DB::transaction(function () use ($publicId, $report, $companyId, $payload, $totalAmountHalalas) {
                $op = Operation::create([
                    'public_id' => $publicId,
                    'company_id' => $companyId,
                    'branch_id' => $report->branch_id,
                    'module_key' => 'waste',
                    'source_module' => self::SOURCE,
                    'source_id' => $report->id,
                    'payload' => $payload,
                    'amount' => $totalAmountHalalas,
                    'match' => 'exact',
                    'origin' => 'mobile',
                    'channel' => 'mobile_app',
                    'status' => Operation::STATUS_PENDING,
                    'submitted_at' => $report->submitted_at ?? now(),
                    'operation_date' => $report->submitted_at ?? $report->created_at ?? now(),
                ]);

                ApprovalStep::create([
                    'operation_id' => $op->id,
                    'stage_id' => 'submit',
                    'action' => 'بلاغ هدر وتالف من تطبيق الفرع: '.$op->public_id,
                    'actor_label' => 'تطبيق الفرع',
                    'occurred_at' => now(),
                ]);

                return $op;
            }),
        );

        $this->rt->operationCreated($op);
        $this->notifications->pushToBranch(
            $companyId,
            $report->branch_id,
            'accountant',
            'waste.submitted',
            'بلاغ هدر وتالف جديد',
            'بلاغ هدر — '.$branch->name.' — '.$op->public_id,
            null,
            ['type' => 'operation', 'id' => $op->id],
        );

        return $op;
    }

    private function buildPayload(
        WasteDamageReport $report,
        string $companyId,
        int $revision,
        ?array $existingOwnerApproval,
        ?array $ownerApprovalOverride
    ): array {
        $products = [];

        foreach ($report->items as $item) {
            $qty = (float) $item->quantity;
            $unitPriceHalalas = (int) round(((float) ($item->price_per_unit ?? 0)) * 100);
            $valueHalalas = (int) round(abs((float) $item->total_value) * 100);
            if ($valueHalalas === 0 && $unitPriceHalalas > 0 && $qty > 0) {
                $valueHalalas = (int) round($qty * $unitPriceHalalas);
            }

            $isDamage = $item->problem_type === ProblemType::DAMAGE;
            $classification = $isDamage ? WasteEnums::CLASS_DAMAGE : WasteEnums::CLASS_WASTE;

            $empAllocs = [];
            $responsibleRows = $item->responsibleEmployees ?? collect();
            foreach ($responsibleRows as $re) {
                $accQty = (float) $re->quantity_accountable;
                if ($accQty <= 0) {
                    continue;
                }
                $shareHalalas = $qty > 0
                    ? (int) round(($accQty / $qty) * $valueHalalas)
                    : 0;
                if ($shareHalalas <= 0) {
                    continue;
                }

                $emp = $this->resolveAdminEmployee($companyId, $report->branch_id, $re->cashier?->email, $re->cashier?->name);
                if ($emp) {
                    $empAllocs[] = [
                        'employeeId' => $emp->id,
                        'employeeName' => $emp->name,
                        'amountHalalas' => $shareHalalas,
                        'qty' => $accQty,
                    ];
                }
            }

            $hasEmployeeResponsibility = ! empty($empAllocs) || ($item->cause_of_damage !== null && $item->cause_of_damage->requiresResponsibleEmployees());

            $products[] = [
                'itemId' => $item->item_id,
                'name' => $item->item?->name ?? '—',
                'qty' => $qty,
                'unit' => $item->unit ?? $item->item?->unit ?? 'kg',
                'unitPriceHalalas' => $unitPriceHalalas,
                'value' => $valueHalalas,
                'classification' => $classification,
                'responsibility' => $hasEmployeeResponsibility ? WasteEnums::RESP_EMPLOYEE : WasteEnums::RESP_RESTAURANT,
                'hasImg' => ! empty($item->photo_path),
                'photoUrl' => $item->photo_path ? (str_starts_with($item->photo_path, 'http') ? $item->photo_path : asset('storage/'.$item->photo_path)) : null,
                'reason' => $item->reason instanceof \BackedEnum ? $item->reason->value : (string) $item->reason,
                'justification' => $item->justification_text,
                'empAllocs' => $empAllocs,
            ];
        }

        return [
            'legacyReportId' => $report->id,
            'revision' => $revision,
            'incidentDate' => ($report->submitted_at ?? $report->created_at ?? now())->toDateString(),
            'reporterName' => $report->createdBy?->name,
            'ownerApproval' => $ownerApprovalOverride ?? $existingOwnerApproval,
            'products' => $products,
        ];
    }

    private function resolveAdminEmployee(string $companyId, string $branchId, ?string $email, ?string $name): ?Employee
    {
        if ($email) {
            $byEmail = Employee::where('company_id', $companyId)
                ->where('branch_id', $branchId)
                ->where('email', $email)
                ->first();
            if ($byEmail) {
                return $byEmail;
            }
        }
        if ($name) {
            return Employee::where('company_id', $companyId)
                ->where('branch_id', $branchId)
                ->where('name', $name)
                ->first();
        }

        return null;
    }

    private function productsSignature(array $products): string
    {
        $normalized = array_map(fn ($p) => [
            'itemId' => (string) ($p['itemId'] ?? ''),
            'qty' => (float) ($p['qty'] ?? 0),
            'value' => (int) ($p['value'] ?? 0),
            'responsibility' => (string) ($p['responsibility'] ?? ''),
            'empAllocs' => $p['empAllocs'] ?? [],
        ], $products);

        return md5(json_encode($normalized, JSON_UNESCAPED_UNICODE) ?: '');
    }
}
