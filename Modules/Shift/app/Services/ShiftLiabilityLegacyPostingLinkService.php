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
            ->where('transaction_type', 'Variance')
            // Discovery concerns today's effective SHORT entries. Immutable links
            // already established before a later deletion are summed separately.
            ->whereNull('deleted_at')
            ->where('is_cash_in', false);
        if (Schema::hasColumn('cashier_custody_transactions', 'posting_id')) {
            $cashierRows->whereNull('posting_id');
        }
        if (Schema::hasColumn('cashier_custody_transactions', 'lineage_key')) {
            $cashierRows->whereNull('lineage_key');
        }
        $cashierRows = $cashierRows->orderBy('id')->lockForUpdate()->get();
        // The legacy replacement writer does not store a detail/replacement ID.
        // Multiple live rows for one actor cannot safely be inferred as additive.
        if ($cashierRows->groupBy('cashier_id')->contains(fn ($rows) => $rows->count() > 1)) {
            throw new ConflictHttpException('LEGACY_LIABILITY_RECONCILIATION_REQUIRED');
        }
        foreach ($cashierRows->groupBy('cashier_id') as $cashierId => $rows) {
            $priorDifferentRow = ShiftLiabilityLegacyPostingLink::query()
                ->where('cashier_shift_id', $shift->id)
                ->where('responsible_type', 'cashier')->where('responsible_id', (string) $cashierId)
                ->where('ledger_table', 'cashier_custody_transactions')
                ->whereNotIn('ledger_id', $rows->pluck('id'))->lockForUpdate()->first();
            if ($priorDifferentRow) {
                // An immutable prior link remains effective. A new legacy row
                // might replace it; guessing an additional charge is unsafe.
                throw new ConflictHttpException('LEGACY_LIABILITY_RECONCILIATION_REQUIRED');
            }
        }
        $this->linkRows(
            $shift,
            $cashierRows,
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
                    'signed' => ShiftFinancialCalculator::sarToHalalas((string) $row->amount),
                ];
            }
        );

        // "Variance from Cashier" is the manager's custody mirror, not evidence
        // of manager responsibility. Never discover it as manager liability.
        // Previously verified immutable links are not rewritten or removed.

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
            ->orderBy('movement.id')
            ->lockForUpdate()
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
                ->where('ledger_table', $table)->where('ledger_id', (string) $row->id)->lockForUpdate()->first();
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
        // Current reads are required after waiting for the shift mutex: an outer
        // REPEATABLE READ transaction may already have an older snapshot.
        $modernPostedHalalas = (int) ShiftLiabilityPosting::where('lineage_key', $lineageKey)
            ->where('status', 'posted')
            ->orderBy('id')->lockForUpdate()->get(['delta_halalas'])->sum('delta_halalas');

        $legacyPostedHalalas = (int) ShiftLiabilityLegacyPostingLink::where('lineage_key', $lineageKey)
            ->orderBy('id')->lockForUpdate()->get(['signed_halalas'])->sum('signed_halalas');

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
