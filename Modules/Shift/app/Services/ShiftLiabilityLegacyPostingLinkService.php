<?php

namespace Modules\Shift\Services;

use App\Support\ShiftFinancialCalculator;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Modules\Shift\Models\CashierShift;
use Modules\Shift\Models\ShiftLiabilityLegacyPostingLink;
use Modules\Shift\Models\ShiftLiabilityPosting;
use Modules\Shift\Models\ShiftLiabilityShare;
use Symfony\Component\HttpKernel\Exception\ConflictHttpException;

class ShiftLiabilityLegacyPostingLinkService
{
    /** Link only legacy liability rows whose source and signed meaning can be proven exactly. */
    public function reconcileShiftLegacyEvidence(CashierShift $shift): void
    {
        $branchId = $shift->shift?->branch_id ?? DB::table('shifts')->where('id', $shift->shift_id)->value('branch_id');
        $companyId = DB::table('branches')->where('id', $branchId)->value('asab_company_id');
        if (! $branchId || ! $companyId) {
            throw new ConflictHttpException('LEGACY_LIABILITY_RECONCILIATION_REQUIRED');
        }

        $cashierRows = DB::table('cashier_custody_transactions')->where('related_shift_id', $shift->id)
            ->where('transaction_type', 'Variance');
        if (Schema::hasColumn('cashier_custody_transactions', 'posting_id')) {
            $cashierRows->whereNull('posting_id');
        }
        if (Schema::hasColumn('cashier_custody_transactions', 'lineage_key')) {
            $cashierRows->whereNull('lineage_key');
        }
        $this->linkRows(
            $shift,
            $cashierRows->get(),
            'cashier_custody_transactions',
            function ($row) use ($branchId): ?array {
                if (Schema::hasColumn('cashier_custody_transactions', 'category')
                    && $row->category !== null && $row->category !== 'cash_variance') {
                    throw new ConflictHttpException('LEGACY_LIABILITY_RECONCILIATION_REQUIRED');
                }
                $cashier = DB::table('cashiers')->where('id', $row->cashier_id)->where('branch_id', $branchId)->exists();
                if (! $cashier || ! is_numeric($row->amount) || (float) $row->amount < 0) {
                    throw new ConflictHttpException('LEGACY_LIABILITY_RECONCILIATION_REQUIRED');
                }

                return [
                    'type' => 'cashier',
                    'id' => (string) $row->cashier_id,
                    'signed' => ShiftFinancialCalculator::sarToHalalas((string) $row->amount) * ($row->is_cash_in ? -1 : 1),
                ];
            }
        );

        $managerRows = DB::table('personal_ledger_transactions')->where('related_shift_id', $shift->id)
            ->where('transaction_type', 'Variance from Cashier');
        if (Schema::hasColumn('personal_ledger_transactions', 'posting_id')) {
            $managerRows->whereNull('posting_id');
        }
        if (Schema::hasColumn('personal_ledger_transactions', 'lineage_key')) {
            $managerRows->whereNull('lineage_key');
        }
        $this->linkRows(
            $shift,
            $managerRows->get(),
            'personal_ledger_transactions',
            function ($row) use ($branchId): ?array {
                if (Schema::hasColumn('personal_ledger_transactions', 'category')
                    && $row->category !== null && $row->category !== 'cash_variance') {
                    throw new ConflictHttpException('LEGACY_LIABILITY_RECONCILIATION_REQUIRED');
                }
                $manager = DB::table('branch_managers')->where('id', $row->branch_manager_id)->where('branch_id', $branchId)->exists();
                if (! $manager || ! is_numeric($row->amount) || (float) $row->amount < 0) {
                    throw new ConflictHttpException('LEGACY_LIABILITY_RECONCILIATION_REQUIRED');
                }

                return [
                    'type' => 'branch_manager',
                    'id' => (string) $row->branch_manager_id,
                    'signed' => ShiftFinancialCalculator::sarToHalalas((string) $row->amount) * ($row->is_cash_in ? -1 : 1),
                ];
            }
        );

        $employeeRows = DB::table('asab_employee_movements as movement')
            ->join('asab_operations as operation', 'operation.id', '=', 'movement.ref_operation_id')
            ->join('asab_shifts as admin_shift', 'admin_shift.id', '=', 'operation.source_id')
            ->where('admin_shift.legacy_shift_id', $shift->id)
            ->where('admin_shift.role', 'cashier')
            ->where('operation.module_key', 'shifts')
            ->where('movement.category', 'cash_variance')
            ->whereNull('movement.posting_id')
            ->whereNull('movement.lineage_key')
            ->select('movement.*')
            ->get();

        $this->linkRows(
            $shift,
            $employeeRows,
            'asab_employee_movements',
            function ($row) use ($branchId, $companyId): ?array {
                $employee = DB::table('asab_employees')->where('id', $row->employee_id)
                    ->where('branch_id', $branchId)->where('company_id', $companyId)->exists();
                if (! $employee || ! in_array($row->movement_type, ['debit', 'credit'], true) || (int) $row->amount < 0) {
                    throw new ConflictHttpException('LEGACY_LIABILITY_RECONCILIATION_REQUIRED');
                }

                return [
                    'type' => 'employee',
                    'id' => (string) $row->employee_id,
                    'signed' => (int) $row->amount * ($row->movement_type === 'debit' ? 1 : -1),
                ];
            }
        );
    }

    private function linkRows(CashierShift $shift, iterable $rows, string $table, callable $resolve): void
    {
        foreach ($rows as $row) {
            $identity = $resolve($row);
            if ($identity === null) {
                continue;
            }
            $existing = ShiftLiabilityLegacyPostingLink::query()
                ->where('ledger_table', $table)->where('ledger_id', (string) $row->id)->first();
            $lineageKey = ShiftLiabilityShare::buildLineageKey($shift->id, $identity['type'], $identity['id']);
            if ($existing !== null) {
                if ($existing->cashier_shift_id !== $shift->id || $existing->lineage_key !== $lineageKey
                    || (int) $existing->signed_halalas !== $identity['signed']) {
                    throw new ConflictHttpException('LEGACY_LIABILITY_RECONCILIATION_REQUIRED');
                }

                continue;
            }
            $this->linkLegacyRow(
                $shift,
                $identity['type'],
                $identity['id'],
                $table,
                (string) $row->id,
                (int) $identity['signed'],
                ['source' => 'deterministic_pre_s111_variance', 'transaction_type' => $row->transaction_type ?? null]
            );
        }
    }

    /**
     * Link an existing legacy ledger entry to a stable lineage identity without altering or deleting the original row.
     */
    public function linkLegacyRow(
        CashierShift $shift,
        string $responsibleType,
        string $responsibleId,
        string $ledgerTable,
        string $ledgerId,
        int $signedHalalas,
        array $provenance = []
    ): ShiftLiabilityLegacyPostingLink {
        $lineageKey = ShiftLiabilityShare::buildLineageKey($shift->id, $responsibleType, $responsibleId);

        $branchId = $shift->shift?->branch_id ?? DB::table('shifts')->where('id', $shift->shift_id)->value('branch_id');
        $companyId = DB::table('branches')->where('id', $branchId)->value('asab_company_id')
            ?? DB::table('branches')->where('id', $branchId)->value('company_id')
            ?? $shift->company_id;

        return ShiftLiabilityLegacyPostingLink::create([
            'company_id' => $companyId,
            'cashier_shift_id' => $shift->id,
            'responsible_type' => $responsibleType,
            'responsible_id' => $responsibleId,
            'lineage_key' => $lineageKey,
            'ledger_table' => $ledgerTable,
            'ledger_id' => $ledgerId,
            'signed_halalas' => $signedHalalas,
            'provenance' => $provenance,
        ]);
    }

    /**
     * Compute total effective already-posted halalas for a given lineage identity,
     * including both modern post entries and verified legacy links.
     */
    public function resolveEffectivePostedHalalas(string $lineageKey): int
    {
        $modernPostedHalalas = (int) ShiftLiabilityPosting::where('lineage_key', $lineageKey)
            ->where('status', 'posted')
            ->sum('delta_halalas');

        $legacyPostedHalalas = (int) ShiftLiabilityLegacyPostingLink::where('lineage_key', $lineageKey)
            ->sum('signed_halalas');

        return $modernPostedHalalas + $legacyPostedHalalas;
    }

    /**
     * Assert that no unresolvable or contradictory legacy liability records exist for the shift.
     */
    public function assertNoUnreconciledLegacyLiabilities(string $cashierShiftId): void
    {
        // Fail closed if ambiguous legacy rows exist without verified provenance
        $unlinkedCount = DB::table('shift_liability_legacy_posting_links')
            ->where('cashier_shift_id', $cashierShiftId)
            ->whereNull('signed_halalas')
            ->count();

        if ($unlinkedCount > 0) {
            throw new ConflictHttpException('LEGACY_LIABILITY_RECONCILIATION_REQUIRED');
        }
    }
}
