<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Modules\Branch\Models\Branch;
use Modules\BranchManagers\Models\BranchManager;
use Modules\BrandOwner\Models\BrandOwner;
use Modules\Custody\Models\CustodyRequest;
use Modules\Custody\Models\CustodyTransaction;
use Tests\TestCase;

/**
 * Meeting 2026-08-03 «عند تحويل مبلغ من صاحب المطعم إلى مدير الفرع لا يوجد
 * إشعار ولا يوجد استلام للمدير»: an owner transfer landed as a Pending row on
 * the manager's custody screen labelled as if the MANAGER had raised it, with
 * no notification, no way to acknowledge it, and a branch custody balance that
 * stayed 0.00 for ever.
 */
class CustodyOwnerTransferReceiptTest extends TestCase
{
    use RefreshDatabase;

    private Branch $branch;

    private BranchManager $manager;

    private BrandOwner $owner;

    protected function setUp(): void
    {
        parent::setUp();

        $this->branch = Branch::factory()->create();
        $this->manager = BranchManager::factory()->create(['branch_id' => $this->branch->id]);
        $this->owner = BrandOwner::create([
            'name' => 'صاحب المطعم',
            'email' => 'owner@custody.test',
            'phone' => '0500000001',
            'password' => 'secret-password',
            'is_active' => true,
            'is_first_login' => false,
            'status' => 'active',
        ]);
    }

    private function ownerTransfer(array $overrides = []): CustodyRequest
    {
        return CustodyRequest::create(array_merge([
            'branch_manager_id' => $this->manager->id,
            'branch_id' => $this->branch->id,
            'created_by_brand_owner_id' => $this->owner->id,
            'requested_amount' => 5000,
            'purpose' => 'تشغيل',
            'preferred_receipt_method' => 'Cash Handover',
            'status' => 'Pending',
        ], $overrides));
    }

    public function test_the_owner_payment_form_notifies_the_recipient_manager(): void
    {
        $this->actingAs($this->owner, 'sanctum')
            ->postJson('/api/brand-owner/payment-form', [
                'recipientEmployeeId' => $this->manager->id,
                'amount' => 5000,
                'purpose' => 'تشغيل',
                'note' => 'دفعة تشغيل',
                'preferredReceiptMethod' => 'Cash Handover',
                'handoverDate' => now()->toDateString(),
            ])
            ->assertSuccessful();

        $this->assertDatabaseHas('custody_requests', [
            'created_by_brand_owner_id' => $this->owner->id,
            'branch_manager_id' => $this->manager->id,
            'status' => 'Pending',
        ]);

        // In-app rows are written straight into `notifications` (the service
        // does not route through $notifiable->notify()), so assert the row.
        $notification = DB::table('notifications')
            ->where('notifiable_id', $this->manager->id)
            ->first();

        $this->assertNotNull($notification, 'the recipient manager was not notified');
        $this->assertSame(
            'custody_cash_transfer',
            json_decode($notification->data, true)['type'] ?? null,
        );
    }

    public function test_confirming_receipt_credits_the_branch_custody_balance(): void
    {
        $request = $this->ownerTransfer();

        // Before: the screen the user photographed — a pending row, 0.00 balance.
        $this->actingAs($this->manager, 'sanctum')
            ->getJson('/api/branch-manager/ledger/branch-custody-balance')
            ->assertSuccessful()
            ->assertJsonPath('data.currentBalance', 0);

        $this->actingAs($this->manager, 'sanctum')
            ->postJson("/api/custody/requests/{$request->id}/confirm-receipt")
            ->assertSuccessful()
            ->assertJsonPath('data.status', 'completed');

        $this->assertDatabaseHas('custody_transactions', [
            'related_custody_request_id' => $request->id,
            'branch_id' => $this->branch->id,
            'is_cash_in' => true,
            'type' => 'Cash Handover',
        ]);

        $this->actingAs($this->manager, 'sanctum')
            ->getJson('/api/branch-manager/ledger/branch-custody-balance')
            ->assertSuccessful()
            ->assertJsonPath('data.currentBalance', 5000);
    }

    public function test_receipt_cannot_be_confirmed_twice(): void
    {
        $request = $this->ownerTransfer();

        $this->actingAs($this->manager, 'sanctum')
            ->postJson("/api/custody/requests/{$request->id}/confirm-receipt")
            ->assertSuccessful();

        $this->actingAs($this->manager, 'sanctum')
            ->postJson("/api/custody/requests/{$request->id}/confirm-receipt")
            ->assertStatus(422);

        $this->assertSame(1, CustodyTransaction::where('related_custody_request_id', $request->id)->count());
    }

    public function test_a_manager_cannot_receive_another_managers_custody(): void
    {
        $otherManager = BranchManager::factory()->create(['branch_id' => Branch::factory()->create()->id]);
        $request = $this->ownerTransfer(['branch_manager_id' => $otherManager->id]);

        $this->actingAs($this->manager, 'sanctum')
            ->postJson("/api/custody/requests/{$request->id}/confirm-receipt")
            ->assertStatus(422);

        $this->assertSame(0, CustodyTransaction::count());
    }

    public function test_an_owner_transfer_is_not_labelled_as_the_managers_own_request(): void
    {
        $this->ownerTransfer();

        $response = $this->actingAs($this->manager, 'sanctum')
            ->getJson('/api/custody/requests')
            ->assertSuccessful();

        $row = $response->json('data.requests.0');
        $this->assertSame('صاحب المطعم (Brand Owner)', $row['submittedBy']);
        $this->assertTrue($row['canConfirmReceipt']);
    }

    /** A manager's OWN request only becomes receivable once the owner approves. */
    public function test_an_unapproved_manager_request_cannot_be_received(): void
    {
        $request = CustodyRequest::create([
            'branch_manager_id' => $this->manager->id,
            'branch_id' => $this->branch->id,
            'requested_amount' => 1200,
            'purpose' => 'تشغيل',
            'preferred_receipt_method' => 'Bank Transfer',
            'status' => 'Pending',
        ]);

        $this->actingAs($this->manager, 'sanctum')
            ->postJson("/api/custody/requests/{$request->id}/confirm-receipt")
            ->assertStatus(422);

        $request->update(['status' => 'Approved']);

        $this->actingAs($this->manager, 'sanctum')
            ->postJson("/api/custody/requests/{$request->id}/confirm-receipt")
            ->assertSuccessful();

        $this->assertDatabaseHas('custody_transactions', [
            'related_custody_request_id' => $request->id,
            'type' => 'Bank Transfer',
        ]);
    }

    /** Branch scope: a manager moved to a new branch starts that branch clean. */
    public function test_requests_from_a_previous_branch_do_not_follow_the_manager(): void
    {
        $this->ownerTransfer();

        $newBranch = Branch::factory()->create();
        $this->manager->forceFill(['branch_id' => $newBranch->id])->save();

        $this->actingAs($this->manager->fresh(), 'sanctum')
            ->getJson('/api/custody/requests')
            ->assertSuccessful()
            ->assertJsonPath('data.requests', []);
    }
}
