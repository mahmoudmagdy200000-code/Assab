<?php

namespace Modules\Shift\Services;

use Illuminate\Support\Facades\DB;
use Modules\Shift\Models\CashierShift;
use Modules\Shift\Models\ShiftLiabilityLegacyPostingLink;
use Modules\Shift\Models\ShiftLiabilityPosting;
use Modules\Shift\Models\ShiftLiabilityShare;
use Symfony\Component\HttpKernel\Exception\ConflictHttpException;

class ShiftLiabilityLegacyPostingLinkService
{
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
