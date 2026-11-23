<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Modules\BrandOwner\Models\CashSalesTransferRequest;
use Modules\Custody\Models\CustodyTransaction;
use Modules\Custody\Models\PersonalLedgerTransaction;
use Tests\Concerns\BuildsExpenseTenantFixtures;
use Tests\TestCase;

class CashSalesTransferTenantScopeTest extends TestCase
{
    use BuildsExpenseTenantFixtures;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        DB::statement('PRAGMA defer_foreign_keys = ON');
        $this->buildExpenseTenants();
    }

    private function seedManagerPersonalBalance(float $amount = 1000.00): void
    {
        PersonalLedgerTransaction::create([
            'branch_manager_id' => $this->bmA1->id,
            'transaction_type' => 'Total Sales',
            'amount' => $amount,
            'is_cash_in' => true,
            'transaction_date' => now(),
        ]);
    }

    public function test_recipients_for_branch_manager_include_only_own_brands_owners(): void
    {
        $response = $this->actingAs($this->bmA1, 'sanctum')
            ->getJson('/api/v1/custody/recipients')
            ->assertOk();

        $brandOwnerIds = collect($response->json('data.recipients'))
            ->where('type', 'Brand Owner')
            ->pluck('id')
            ->values()
            ->all();

        $this->assertContains($this->ownerA1->id, $brandOwnerIds);
        $this->assertContains($this->ownerA12->id, $brandOwnerIds);
        $this->assertNotContains($this->ownerB1->id, $brandOwnerIds);
        $this->assertNotContains($this->ownerUnlinked->id, $brandOwnerIds);
    }

    public function test_branch_manager_cannot_handover_to_cross_tenant_or_unlinked_brand_owner(): void
    {
        $this->seedManagerPersonalBalance(1000);

        $beforeTransfers = CashSalesTransferRequest::count();
        $beforeCustody = CustodyTransaction::count();
        $beforeLedger = PersonalLedgerTransaction::count();

        foreach ([$this->ownerB1->id, $this->ownerUnlinked->id] as $foreignOwnerId) {
            $this->actingAs($this->bmA1, 'sanctum')
                ->postJson('/api/v1/custody/handover', [
                    'recipientId' => $foreignOwnerId,
                    'recipientType' => 'Brand Owner',
                    'handoverAmount' => 150,
                    'handoverMethod' => 'cash_handover',
                    'handoverDate' => today()->toDateString(),
                ])
                ->assertStatus(422);
        }

        $this->assertSame($beforeTransfers, CashSalesTransferRequest::count());
        $this->assertSame($beforeCustody, CustodyTransaction::count());
        $this->assertSame($beforeLedger, PersonalLedgerTransaction::count());
    }

    public function test_handover_to_assigned_brand_owner_appears_only_for_that_owner_and_allows_approve_and_reject(): void
    {
        $this->seedManagerPersonalBalance(1000);

        $this->actingAs($this->bmA1, 'sanctum')
            ->postJson('/api/v1/custody/handover', [
                'recipientId' => $this->ownerA1->id,
                'recipientType' => 'Brand Owner',
                'handoverAmount' => 250,
                'handoverMethod' => 'cash_handover',
                'handoverDate' => today()->toDateString(),
                'additionalNotes' => 'Daily cash handover',
            ])
            ->assertOk();

        $transfer = CashSalesTransferRequest::query()->firstOrFail();
        $this->assertSame($this->ownerA1->id, $transfer->brand_owner_id);
        $this->assertSame($this->branchA1->id, $transfer->branch_id);
        $this->assertSame('pending', $transfer->status);

        // Ledger entry stores recipient's actual name (no longer null)
        $ledgerRow = PersonalLedgerTransaction::query()
            ->where('branch_manager_id', $this->bmA1->id)
            ->where('transaction_type', 'Handover to Brand Owner')
            ->latest('id')
            ->firstOrFail();
        $this->assertSame($this->ownerA1->name, $ledgerRow->brand_owner_name);

        // Assigned owner lists the transfer; foreign/unlinked owners do not
        $listA1 = $this->actingAs($this->ownerA1, 'sanctum')
            ->getJson('/api/v1/brand-owner/cash-sales-transfers')
            ->assertOk()
            ->json('data.data');
        $this->assertCount(1, $listA1);
        $this->assertSame($transfer->id, $listA1[0]['id']);

        foreach ([$this->ownerB1, $this->ownerUnlinked] as $otherOwner) {
            $otherList = $this->actingAs($otherOwner, 'sanctum')
                ->getJson('/api/v1/brand-owner/cash-sales-transfers')
                ->assertOk()
                ->json('data.data');
            $this->assertSame([], $otherList);

            $this->actingAs($otherOwner, 'sanctum')
                ->getJson("/api/v1/brand-owner/cash-sales-transfers/{$transfer->id}")
                ->assertNotFound();

            $this->actingAs($otherOwner, 'sanctum')
                ->postJson("/api/v1/brand-owner/cash-sales-transfers/{$transfer->id}/approve")
                ->assertNotFound();

            $this->actingAs($otherOwner, 'sanctum')
                ->postJson("/api/v1/brand-owner/cash-sales-transfers/{$transfer->id}/reject", [
                    'reason' => 'Not authorized',
                ])
                ->assertNotFound();
        }

        // Assigned owner can view details and approve
        $this->actingAs($this->ownerA1, 'sanctum')
            ->getJson("/api/v1/brand-owner/cash-sales-transfers/{$transfer->id}")
            ->assertOk()
            ->assertJsonPath('data.requestId', $transfer->id)
            ->assertJsonPath('data.status', 'pending');

        $this->actingAs($this->ownerA1, 'sanctum')
            ->postJson("/api/v1/brand-owner/cash-sales-transfers/{$transfer->id}/approve")
            ->assertOk()
            ->assertJsonPath('data.status', 'approved');

        // Second handover to test reject path by assigned owner
        $this->actingAs($this->bmA1, 'sanctum')
            ->postJson('/api/v1/custody/handover', [
                'recipientId' => $this->ownerA1->id,
                'recipientType' => 'Brand Owner',
                'handoverAmount' => 100,
                'handoverMethod' => 'bank_transfer',
                'handoverDate' => today()->toDateString(),
            ])
            ->assertOk();

        $secondTransfer = CashSalesTransferRequest::query()
            ->where('id', '!=', $transfer->id)
            ->firstOrFail();

        $this->actingAs($this->ownerA1, 'sanctum')
            ->postJson("/api/v1/brand-owner/cash-sales-transfers/{$secondTransfer->id}/reject", [
                'reason' => 'Receipt mismatch',
            ])
            ->assertOk()
            ->assertJsonPath('data.status', 'rejected');
    }
}
