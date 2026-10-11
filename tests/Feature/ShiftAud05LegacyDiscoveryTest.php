<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Modules\Custody\Models\CashierCustodyTransaction;
use Modules\Custody\Models\PersonalLedgerTransaction;
use Modules\Shift\Models\ShiftLiabilityAllocation;
use Modules\Shift\Models\ShiftLiabilityLegacyPostingLink;
use Modules\Shift\Models\ShiftLiabilityPosting;
use Modules\Shift\Services\ShiftLiabilityLegacyPostingLinkService;
use Modules\Shift\Services\ShiftReportRevisionService;
use Modules\Shift\Services\ShortagePostingService;
use Tests\TestCase;

class ShiftAud05LegacyDiscoveryTest extends TestCase
{
    use RefreshDatabase;

    private array $fixtures;

    protected function setUp(): void
    {
        parent::setUp();
        $company = \Modules\Admin\Models\AsabCompany::create([
            'name' => 'AUD05 Company', 'plan' => 'Professional', 'status' => 'active',
        ]);
        $brand = \Modules\Admin\Models\AsabBrand::create([
            'company_id' => $company->id, 'name' => 'AUD05 Brand', 'sub_status' => 'active', 'status' => 'active',
        ]);
        $branch = \Modules\Branch\Models\Branch::factory()->create([
            'asab_brand_id' => $brand->id, 'asab_company_id' => $company->id,
        ]);
        $manager = \Modules\BranchManagers\Models\BranchManager::factory()->create(['branch_id' => $branch->id]);
        $cashier = \Modules\Cashier\Models\Cashier::factory()->create(['branch_id' => $branch->id]);
        $template = \Modules\Shift\Models\Shift::create([
            'branch_id' => $branch->id, 'name' => 'AUD05 Shift', 'start_time' => '08:00:00', 'end_time' => '16:00:00',
        ]);
        $cashierShift = \Modules\Shift\Models\CashierShift::create([
            'cashier_id' => $cashier->id, 'shift_id' => $template->id, 'shift_date' => today(), 'status' => 'completed',
        ]);
        $this->fixtures = compact('company', 'branch', 'manager', 'cashier', 'cashierShift');
    }

    private function fixture(string $name): mixed
    {
        return $this->fixtures[$name];
    }

    private function variance(string $amount, bool $cashIn = false): CashierCustodyTransaction
    {
        return CashierCustodyTransaction::create([
            'cashier_id' => $this->fixture('cashier')->id,
            'transaction_type' => 'Variance', 'amount' => $amount, 'is_cash_in' => $cashIn,
            'related_shift_id' => $this->fixture('cashierShift')->id, 'transaction_date' => now(),
        ]);
    }

    private function reconcileTarget(int $amount): array
    {
        $shift = $this->fixture('cashierShift');
        $manager = $this->fixture('manager');

        return DB::transaction(function () use ($shift, $manager, $amount) {
            app(ShiftReportRevisionService::class)->recordCashierRevision($shift, 'cashier', $shift->cashier_id, 0);
            $allocationVersion = null;
            if ($amount > 0) {
                $allocation = ShiftLiabilityAllocation::create([
                    'company_id' => $this->fixture('company')->id, 'branch_id' => $this->fixture('branch')->id,
                    'cashier_shift_id' => $shift->id, 'version' => 1, 'report_revision' => '1',
                    'variance_halalas' => -$amount, 'created_by_type' => 'branch_manager',
                    'created_by_id' => $manager->id, 'manager_approval_status' => 'approved',
                    'manager_approved_by' => $manager->id, 'manager_approved_at' => now(),
                ]);
                $allocation->shares()->create([
                    'responsible_type' => 'cashier', 'responsible_id' => $shift->cashier_id, 'amount_halalas' => $amount,
                ]);
                $allocationVersion = 1;
            }

            return app(ShortagePostingService::class)->reconcile($shift, $manager, 1, $allocationVersion, (string) Str::uuid());
        });
    }

    public function test_aud05a_discovery_excludes_preexisting_deleted_replacement_history(): void
    {
        $old = $this->variance('100.00');
        $old->delete();
        $live = $this->variance('120.00');
        $originalLive = $live->fresh()->getAttributes();
        $originalOld = $old->fresh()->getAttributes();

        $result = $this->reconcileTarget(12000);

        $this->assertSame(0, $result['total_deltas']);
        $this->assertSame(0, ShiftLiabilityPosting::count());
        $this->assertSame(1, ShiftLiabilityLegacyPostingLink::count());
        $this->assertSame($live->id, ShiftLiabilityLegacyPostingLink::sole()->ledger_id);
        $this->assertSame(12000, ShiftLiabilityLegacyPostingLink::sole()->signed_halalas);
        $this->assertSame($originalLive, $live->fresh()->getAttributes());
        $this->assertSame($originalOld, $old->fresh()->getAttributes());
    }

    public function test_aud05a_existing_link_survives_later_source_soft_deletion(): void
    {
        $row = $this->variance('100.00');
        $service = app(ShiftLiabilityLegacyPostingLinkService::class);
        $service->reconcileShiftLegacyEvidence($this->fixture('cashierShift'));
        $link = ShiftLiabilityLegacyPostingLink::sole();
        $originalLink = $link->getAttributes();
        $row->delete();
        $service->reconcileShiftLegacyEvidence($this->fixture('cashierShift'));

        $this->assertSame(10000, $service->resolveEffectivePostedHalalas($link->lineage_key));
        $this->assertSame($originalLink, $link->fresh()->getAttributes());
        $this->assertSame(1, ShiftLiabilityLegacyPostingLink::count());
    }

    public function test_aud05b_manager_custody_mirror_is_not_manager_liability(): void
    {
        $shift = $this->fixture('cashierShift');
        \Modules\Shift\Models\CashierShiftHandover::create([
            'cashier_shift_id' => $shift->id, 'handover_to_type' => 'branch_manager',
            'handover_to_id' => $this->fixture('manager')->id, 'handover_amount' => '0.00',
            'handover_date' => today(), 'handover_time' => now(), 'status' => 'approved',
        ]);
        $shift->varianceDetails()->create([
            'variance_amount' => '100.00', 'variance_type' => 'short',
            'responsibility_type' => 'self', 'responsible_cashier_id' => $shift->cashier_id,
            'assigned_amount' => '100.00', 'reason' => 'Historical shortage', 'responsibility_status' => 'approved',
        ]);
        app(\Modules\Custody\Listeners\CreateCustodyLedgerEntriesForVariance::class)
            ->handle(new \Modules\Shift\Events\VarianceRecorded($shift));
        $mirror = PersonalLedgerTransaction::where('related_shift_id', $shift->id)->sole();
        $this->assertSame('Variance from Cashier', $mirror->transaction_type);
        $this->assertTrue($mirror->is_cash_in);
        $original = $mirror->fresh()->getAttributes();

        $result = $this->reconcileTarget(10000);

        $this->assertSame(0, $result['total_deltas']);
        $this->assertSame(0, ShiftLiabilityPosting::count());
        $this->assertSame(1, ShiftLiabilityLegacyPostingLink::count());
        $this->assertSame(0, ShiftLiabilityLegacyPostingLink::where('responsible_type', 'branch_manager')->count());
        $this->assertSame(0, app(ShiftLiabilityLegacyPostingLinkService::class)->resolveEffectivePostedHalalas(
            $this->fixture('cashierShift')->id.':branch_manager:'.$this->fixture('manager')->id
        ));
        $this->assertSame($original, $mirror->fresh()->getAttributes());
    }

    public function test_aud05c_surplus_does_not_seed_a_liability_or_positive_charge(): void
    {
        $surplus = $this->variance('100.00', true);
        $original = $surplus->fresh()->getAttributes();

        $result = $this->reconcileTarget(0);

        $this->assertSame(0, $result['total_deltas']);
        $this->assertSame(0, ShiftLiabilityLegacyPostingLink::count());
        $this->assertSame(0, ShiftLiabilityPosting::count());
        $this->assertSame(1, CashierCustodyTransaction::count());
        $this->assertSame($original, $surplus->fresh()->getAttributes());
    }

    public function test_aud05a_ambiguous_multiple_live_shortage_rows_fail_closed(): void
    {
        $this->variance('100.00');
        $this->variance('120.00');

        try {
            $this->reconcileTarget(12000);
            $this->fail('Ambiguous live historical replacements were auto-linked.');
        } catch (\Symfony\Component\HttpKernel\Exception\ConflictHttpException $exception) {
            $this->assertSame('LEGACY_LIABILITY_RECONCILIATION_REQUIRED', $exception->getMessage());
        }

        $this->assertSame(0, ShiftLiabilityLegacyPostingLink::count());
        $this->assertSame(0, ShiftLiabilityPosting::count());
    }

    public function test_aud05a_new_replacement_after_an_immutable_link_requires_reconciliation(): void
    {
        $old = $this->variance('100.00');
        $service = app(ShiftLiabilityLegacyPostingLinkService::class);
        $service->reconcileShiftLegacyEvidence($this->fixture('cashierShift'));
        $link = ShiftLiabilityLegacyPostingLink::sole();
        $original = $link->getAttributes();
        $old->delete();
        $this->variance('120.00');

        try {
            $service->reconcileShiftLegacyEvidence($this->fixture('cashierShift'));
            $this->fail('A later replacement must not be guessed as additional liability.');
        } catch (\Symfony\Component\HttpKernel\Exception\ConflictHttpException $exception) {
            $this->assertSame('LEGACY_LIABILITY_RECONCILIATION_REQUIRED', $exception->getMessage());
        }

        $this->assertSame(1, ShiftLiabilityLegacyPostingLink::count());
        $this->assertSame($original, $link->fresh()->getAttributes());
        $this->assertSame(10000, $service->resolveEffectivePostedHalalas($link->lineage_key));
    }
}
