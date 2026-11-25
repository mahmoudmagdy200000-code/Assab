<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Gate;
use Modules\Admin\Models\AsabBrand;
use Modules\Admin\Models\AsabCompany;
use Modules\Admin\Models\AsabIdentityMap;
use Modules\Admin\Models\AsabSupplier;
use Modules\Admin\Models\AsabUser;
use Modules\Admin\Models\AsabUserRole;
use Modules\Admin\Models\CompanyUser;
use Modules\Branch\Models\Branch;
use Modules\BranchManagers\Models\BranchManager;
use Modules\BrandOwner\Models\BrandOwner;
use Modules\Inventory\Models\InternalTransferVariance;
use Modules\Inventory\Models\InventoryStockMovement;
use Modules\Purchase\Enums\OrderStatus;
use Modules\Purchase\Enums\OrderType;
use Modules\Purchase\Enums\ReturnStatus;
use Modules\Purchase\Models\BranchInventory;
use Modules\Purchase\Models\BranchItem;
use Modules\Purchase\Models\Item;
use Modules\Purchase\Models\PurchaseOrder;
use Modules\Purchase\Models\PurchaseOrderItem;
use Modules\Purchase\Models\ReturnOrder;
use Modules\Purchase\Models\SupplierItem;
use Modules\Supplier\Models\Supplier;
use Tests\TestCase;

class PurchaseStage0AuditRemediationR1Test extends TestCase
{
    use RefreshDatabase;

    private AsabCompany $companyA;

    private AsabCompany $companyB;

    private AsabBrand $brandA;

    private AsabBrand $brandB;

    private Branch $branchA1;

    private Branch $branchA2;

    private Branch $branchB1;

    private BranchManager $mgrA1;

    private BranchManager $mgrA2;

    private BranchManager $mgrB1;

    private Item $itemAllowed;

    private Item $itemOtherCatalog;

    private Item $itemInactive;

    private Supplier $supplierA;

    private Supplier $supplierB;

    protected function setUp(): void
    {
        parent::setUp();

        $this->companyA = AsabCompany::create(['name' => 'Co A', 'plan' => 'Professional', 'status' => 'active']);
        $this->companyB = AsabCompany::create(['name' => 'Co B', 'plan' => 'Professional', 'status' => 'active']);
        $this->brandA = AsabBrand::create(['company_id' => $this->companyA->id, 'name' => 'Brand A', 'sub_status' => 'active', 'status' => 'active']);
        $this->brandB = AsabBrand::create(['company_id' => $this->companyB->id, 'name' => 'Brand B', 'sub_status' => 'active', 'status' => 'active']);

        $this->branchA1 = Branch::factory()->create(['name' => 'A1', 'asab_brand_id' => $this->brandA->id, 'asab_company_id' => $this->companyA->id]);
        $this->branchA2 = Branch::factory()->create(['name' => 'A2', 'asab_brand_id' => $this->brandA->id, 'asab_company_id' => $this->companyA->id]);
        $this->branchB1 = Branch::factory()->create(['name' => 'B1', 'asab_brand_id' => $this->brandB->id, 'asab_company_id' => $this->companyB->id]);

        $this->mgrA1 = BranchManager::create([
            'branch_id' => $this->branchA1->id, 'name' => 'Mgr A1', 'email' => 'mgra1@test.local',
            'phone' => '0500000101', 'password' => 'secret', 'is_active' => true, 'status' => 'active',
        ]);
        $this->mgrA2 = BranchManager::create([
            'branch_id' => $this->branchA2->id, 'name' => 'Mgr A2', 'email' => 'mgra2@test.local',
            'phone' => '0500000102', 'password' => 'secret', 'is_active' => true, 'status' => 'active',
        ]);
        $this->mgrB1 = BranchManager::create([
            'branch_id' => $this->branchB1->id, 'name' => 'Mgr B1', 'email' => 'mgrb1@test.local',
            'phone' => '0500000103', 'password' => 'secret', 'is_active' => true, 'status' => 'active',
        ]);

        $this->itemAllowed = Item::create(['name' => 'Allowed Flour', 'unit_of_measurement' => 'kg', 'is_active' => true]);
        $this->itemOtherCatalog = Item::create(['name' => 'Uncataloged Oil', 'unit_of_measurement' => 'l', 'is_active' => true]);
        $this->itemInactive = Item::create(['name' => 'Inactive Salt', 'unit_of_measurement' => 'kg', 'is_active' => false]);

        foreach ([$this->branchA1, $this->branchA2, $this->branchB1] as $b) {
            BranchItem::create(['branch_id' => $b->id, 'item_id' => $this->itemAllowed->id, 'quantity' => 50, 'unit_price' => 10]);
            BranchInventory::create(['branch_id' => $b->id, 'item_id' => $this->itemAllowed->id, 'available_quantity' => 50]);
        }

        $this->supplierA = Supplier::create([
            'name' => 'Sup A', 'company_name' => 'Sup A', 'email' => 'supa@test.local',
            'phone' => '0500000201', 'password' => 'secret', 'is_active' => true, 'status' => 'online',
        ]);
        $this->supplierB = Supplier::create([
            'name' => 'Sup B', 'company_name' => 'Sup B', 'email' => 'supb@test.local',
            'phone' => '0500000202', 'password' => 'secret', 'is_active' => true, 'status' => 'online',
        ]);

        AsabSupplier::withoutGlobalScope('tenant')->create([
            'company_id' => $this->companyA->id, 'brand_id' => $this->brandA->id,
            'name' => 'Sup A', 'status' => 'active',
        ])->forceFill(['legacy_supplier_id' => $this->supplierA->id])->save();
        AsabSupplier::withoutGlobalScope('tenant')->create([
            'company_id' => $this->companyB->id, 'brand_id' => $this->brandB->id,
            'name' => 'Sup B', 'status' => 'active',
        ])->forceFill(['legacy_supplier_id' => $this->supplierB->id])->save();

        SupplierItem::create([
            'supplier_id' => $this->supplierA->id,
            'item_id' => $this->itemAllowed->id,
            'unit_price' => 10,
            'is_available' => true,
        ]);
    }

    public function test_aud_s0_001_idempotency_replay_blocked_when_actor_loses_ownership_or_is_suspended(): void
    {
        $order = PurchaseOrder::create([
            'order_number' => 'PO-REPLAY-1',
            'order_type' => OrderType::DIRECT_SUPPLIER,
            'branch_id' => $this->branchA1->id,
            'supplier_id' => $this->supplierA->id,
            'sourceable_type' => Supplier::class,
            'sourceable_id' => $this->supplierA->id,
            'requested_by' => $this->mgrA1->id,
            'status' => OrderStatus::DRAFT,
            'is_draft' => true,
            'total_amount' => 10,
        ]);

        $key = '11111111-1111-4111-8111-111111111111';

        $this->actingAs($this->mgrA1, 'sanctum')
            ->withHeader('Idempotency-Key', $key)
            ->postJson("/api/v1/purchase/orders/{$order->id}/submit")
            ->assertOk();

        // Ownership moved away -> replaying the SAME key must fail closed with 403, not replay 200
        $order->update(['branch_id' => $this->branchA2->id]);
        $this->actingAs($this->mgrA1, 'sanctum')
            ->withHeader('Idempotency-Key', $key)
            ->postJson("/api/v1/purchase/orders/{$order->id}/submit")
            ->assertStatus(403);

        // Ownership restored, actor suspended -> still 403
        $order->update(['branch_id' => $this->branchA1->id]);
        $this->mgrA1->update(['is_active' => false]);
        $this->actingAs($this->mgrA1->fresh(), 'sanctum')
            ->withHeader('Idempotency-Key', $key)
            ->postJson("/api/v1/purchase/orders/{$order->id}/submit")
            ->assertStatus(403);
    }

    public function test_aud_s0_002_write_time_supplier_catalog_branch_and_nested_item_validation_is_atomic(): void
    {
        $foreignOrder = PurchaseOrder::create([
            'order_number' => 'PO-FOREIGN-1',
            'order_type' => OrderType::DIRECT_SUPPLIER,
            'branch_id' => $this->branchB1->id,
            'supplier_id' => $this->supplierB->id,
            'sourceable_type' => Supplier::class,
            'sourceable_id' => $this->supplierB->id,
            'requested_by' => $this->mgrB1->id,
            'status' => OrderStatus::DRAFT,
            'is_draft' => true,
            'total_amount' => 10,
        ]);
        $foreignItem = PurchaseOrderItem::create([
            'purchase_order_id' => $foreignOrder->id,
            'item_id' => $this->itemAllowed->id,
            'item_name' => 'Allowed Flour',
            'unit_of_measurement' => 'kg',
            'quantity_ordered' => 5,
            'unit_price' => 10,
            'total_price' => 50,
            'status' => 'pending',
        ]);
        $ownOrder = PurchaseOrder::create([
            'order_number' => 'PO-OWN-1',
            'order_type' => OrderType::DIRECT_SUPPLIER,
            'branch_id' => $this->branchA1->id,
            'supplier_id' => $this->supplierA->id,
            'sourceable_type' => Supplier::class,
            'sourceable_id' => $this->supplierA->id,
            'requested_by' => $this->mgrA1->id,
            'status' => OrderStatus::DRAFT,
            'is_draft' => true,
            'total_amount' => 10,
        ]);

        // Foreign child item ID in updateItems must be rejected 403 and leave foreignItem untouched
        $this->actingAs($this->mgrA1, 'sanctum')
            ->putJson("/api/v1/purchase/orders/{$ownOrder->id}/items", [
                'items' => [['id' => $foreignItem->id, 'quantity' => 99]],
            ])->assertStatus(403);
        $this->assertEquals(5.0, (float) $foreignItem->fresh()->quantity_ordered);

        // Mixed valid + uncataloged item in store() must roll back atomically (0 orders created)
        $beforeCount = PurchaseOrder::count();
        $this->actingAs($this->mgrA1, 'sanctum')
            ->postJson('/api/v1/purchase/orders', [
                'branches' => [[
                    'branch_id' => $this->branchA2->id,
                    'items' => [['item_id' => $this->itemAllowed->id, 'quantity' => 2]],
                ]],
                'direct_supplier' => [[
                    'supplier_id' => $this->supplierA->id,
                    'notification_channels' => ['email'],
                    'items' => [['item_id' => $this->itemOtherCatalog->id, 'quantity' => 3]],
                ]],
            ])->assertStatus(422);
        $this->assertSame($beforeCount, PurchaseOrder::count());

        // Cross-brand supplier rejected 403
        $this->actingAs($this->mgrA1, 'sanctum')
            ->postJson('/api/v1/purchase/orders', [
                'direct_supplier' => [[
                    'supplier_id' => $this->supplierB->id,
                    'notification_channels' => ['email'],
                    'items' => [['item_id' => $this->itemAllowed->id, 'quantity' => 1]],
                ]],
            ])->assertStatus(403);

        // Inactive item rejected 422 on RecurringOrder
        $this->actingAs($this->mgrA1, 'sanctum')
            ->postJson('/api/v1/recurring-orders', [
                'repeat_frequency' => 'weekly',
                'start_date' => now()->addDay()->toDateString(),
                'direct_supplier' => [
                    'supplier_id' => $this->supplierA->id,
                    'items' => [['item_id' => $this->itemInactive->id, 'quantity' => 1]],
                ],
            ])->assertStatus(422);
    }

    private function linkBrandOwner(BrandOwner $owner, AsabCompany $company, AsabBrand $brand): BrandOwner
    {
        $asabUser = AsabUser::create([
            'company_id' => $company->id,
            'name' => $owner->name,
            'email' => $owner->email,
            'password' => 'secret',
            'status' => 'active',
        ]);
        AsabUserRole::create([
            'user_id' => $asabUser->id,
            'role_key' => 'brand-owner',
            'scope' => 'brand',
            'brand_ids' => [$brand->id],
        ]);
        AsabIdentityMap::create([
            'entity_type' => AsabIdentityMap::ENTITY_BRAND_OWNER,
            'legacy_type' => 'brand_owner',
            'legacy_id' => $owner->id,
            'dashboard_type' => 'asab_user',
            'dashboard_id' => $asabUser->id,
            'company_id' => $company->id,
            'match_method' => 'email',
            'match_value' => $owner->email,
            'status' => 'matched',
            'verified_at' => now(),
        ]);

        return $owner;
    }

    public function test_aud_s0_003_and_007_brand_owner_escalation_and_policy_enforced(): void
    {
        $ownerA1 = $this->linkBrandOwner(BrandOwner::create([
            'name' => 'Owner A1', 'email' => 'ownera1@test.local', 'phone' => '0500000301',
            'password' => 'secret', 'is_active' => true, 'status' => 'active',
        ]), $this->companyA, $this->brandA);
        $ownerA2 = $this->linkBrandOwner(BrandOwner::create([
            'name' => 'Owner A2', 'email' => 'ownera2@test.local', 'phone' => '0500000302',
            'password' => 'secret', 'is_active' => true, 'status' => 'active',
        ]), $this->companyA, $this->brandA);
        $ownerB = $this->linkBrandOwner(BrandOwner::create([
            'name' => 'Owner B', 'email' => 'ownerb@test.local', 'phone' => '0500000303',
            'password' => 'secret', 'is_active' => true, 'status' => 'active',
        ]), $this->companyB, $this->brandB);

        $order = PurchaseOrder::create([
            'order_number' => 'PO-RET-1', 'order_type' => OrderType::DIRECT_SUPPLIER,
            'branch_id' => $this->branchA1->id, 'supplier_id' => $this->supplierA->id,
            'sourceable_type' => Supplier::class, 'sourceable_id' => $this->supplierA->id,
            'requested_by' => $this->mgrA1->id, 'status' => OrderStatus::CLOSED, 'total_amount' => 10,
        ]);
        $return = ReturnOrder::create([
            'return_number' => 'RET-001',
            'purchase_order_id' => $order->id,
            'branch_id' => $this->branchA1->id,
            'supplier_id' => $this->supplierA->id,
            'created_by' => $this->mgrA1->id,
            'status' => ReturnStatus::ESCALATED,
            'required_action' => 'cash_refund',
            'escalated_to' => $ownerA1->id,
            'total_amount' => 10,
        ]);

        // Cross-brand owner denied on both /api and /api/v1
        $this->actingAs($ownerB, 'sanctum')
            ->postJson("/api/brand-owner/purchase/returns/{$return->id}/approve-escalation")
            ->assertStatus(403);
        $this->actingAs($ownerB, 'sanctum')
            ->postJson("/api/v1/brand-owner/purchase/returns/{$return->id}/reject-escalation", ['reason' => 'no'])
            ->assertStatus(403);

        // Same-brand owner who is NOT the escalated_to recipient denied
        $this->actingAs($ownerA2, 'sanctum')
            ->postJson("/api/v1/brand-owner/purchase/returns/{$return->id}/approve-escalation")
            ->assertStatus(403);

        // Policy Gate reflects exact decision rights; rightful recipient succeeds
        $this->assertFalse(Gate::forUser($ownerA2)->allows('decideEscalation', $return));
        $this->assertTrue(Gate::forUser($ownerA1)->allows('decideEscalation', $return));
        $this->actingAs($ownerA1, 'sanctum')
            ->postJson("/api/v1/brand-owner/purchase/returns/{$return->id}/approve-escalation")
            ->assertOk();
    }

    public function test_aud_s0_004_asab_user_company_membership_and_multi_branch_receiving(): void
    {
        $multiUser = AsabUser::create([
            'company_id' => $this->companyA->id,
            'name' => 'Multi Branch Asab', 'email' => 'multi@test.local',
            'password' => 'secret', 'status' => 'active',
        ]);
        AsabUserRole::create([
            'user_id' => $multiUser->id,
            'role_key' => 'branch-manager',
            'scope' => 'branch',
            // Include branchB1 (other company) alongside branchA1 and branchA2 — B1 must be filtered out
            'branch_ids' => [$this->branchA1->id, $this->branchA2->id, $this->branchB1->id],
        ]);
        CompanyUser::create([
            'company_id' => $this->companyA->id, 'user_id' => $multiUser->id,
            'role_key' => 'branch-manager', 'status' => 'active',
        ]);

        // Transfer destined to branchA2 (user's 2nd branch)
        $orderA2 = PurchaseOrder::create([
            'order_number' => 'IT-MULTI-A2',
            'order_type' => OrderType::INTERNAL_TRANSFER,
            'branch_id' => $this->branchA2->id,
            'from_branch_id' => $this->branchA1->id,
            'sourceable_type' => Branch::class,
            'sourceable_id' => $this->branchA1->id,
            'requested_by' => $this->mgrA2->id,
            'status' => OrderStatus::PENDING,
            'total_amount' => 100,
        ]);
        $itemA2 = PurchaseOrderItem::create([
            'purchase_order_id' => $orderA2->id, 'item_id' => $this->itemAllowed->id,
            'item_name' => 'Allowed Flour', 'unit_of_measurement' => 'kg',
            'quantity_ordered' => 10, 'unit_price' => 10, 'total_price' => 100, 'status' => 'pending',
        ]);

        $this->actingAs($this->mgrA1, 'sanctum')
            ->postJson("/api/v1/purchase/pending/{$orderA2->id}/approve-transfer", [
                'action' => 'approve_all', 'ready_time' => '1_hour',
            ])->assertOk();

        // Receive on 2nd branch succeeds
        $this->actingAs($multiUser, 'sanctum')
            ->postJson("/api/v1/purchase/receiving/orders/{$orderA2->id}/receive-internal-transfer", [
                'items' => [['purchase_order_item_id' => $itemA2->id, 'quantity_received' => 6]],
            ])->assertCreated();

        // Close transfer variance on 2nd branch succeeds even though primary branch is A1
        $this->actingAs($multiUser, 'sanctum')
            ->postJson("/api/v1/purchase/receiving/orders/{$orderA2->id}/close-transfer-variance", [
                'resolution' => 'standing_variance',
                'reason' => 'multi-branch test',
                'idempotency_key' => 'multi-branch-close-1',
            ])->assertOk();

        // Other-company branchB1 must NOT be accessible even though listed in AsabUserRole.branch_ids
        $orderB1 = PurchaseOrder::create([
            'order_number' => 'IT-B1-DENIED',
            'order_type' => OrderType::INTERNAL_TRANSFER,
            'branch_id' => $this->branchB1->id,
            'from_branch_id' => $this->branchA1->id,
            'sourceable_type' => Branch::class,
            'sourceable_id' => $this->branchA1->id,
            'requested_by' => $this->mgrB1->id,
            'status' => OrderStatus::FULLY_APPROVED,
            'total_amount' => 10,
        ]);
        $this->actingAs($multiUser, 'sanctum')
            ->postJson("/api/v1/purchase/receiving/orders/{$orderB1->id}/receive-internal-transfer", [])
            ->assertStatus(403);
    }

    public function test_aud_s0_005_and_006_transfer_states_and_exact_50_10_5_partial_dispatch(): void
    {
        // Terminal / draft / already-approved states must reject approve-transfer
        foreach ([OrderStatus::DRAFT, OrderStatus::CONFIRMED, OrderStatus::FULLY_APPROVED, OrderStatus::REJECTED, OrderStatus::CLOSED] as $badStatus) {
            $badOrder = PurchaseOrder::create([
                'order_number' => 'IT-BAD-'.$badStatus->value,
                'order_type' => OrderType::INTERNAL_TRANSFER,
                'branch_id' => $this->branchA1->id,
                'from_branch_id' => $this->branchA2->id,
                'sourceable_type' => Branch::class,
                'sourceable_id' => $this->branchA2->id,
                'requested_by' => $this->mgrA1->id,
                'status' => $badStatus,
                'total_amount' => 100,
            ]);
            $this->actingAs($this->mgrA2, 'sanctum')
                ->postJson("/api/v1/purchase/pending/{$badOrder->id}/approve-transfer", [
                    'action' => 'approve_all', 'ready_time' => '1_hour',
                ])->assertStatus(400);
        }

        // Exact 50 / 10 / 5 partial transfer scenario
        $order = PurchaseOrder::create([
            'order_number' => 'IT-50-10-5',
            'order_type' => OrderType::INTERNAL_TRANSFER,
            'branch_id' => $this->branchA1->id,
            'from_branch_id' => $this->branchA2->id,
            'sourceable_type' => Branch::class,
            'sourceable_id' => $this->branchA2->id,
            'requested_by' => $this->mgrA1->id,
            'status' => OrderStatus::PENDING,
            'total_amount' => 100,
        ]);
        $line = PurchaseOrderItem::create([
            'purchase_order_id' => $order->id,
            'item_id' => $this->itemAllowed->id,
            'item_name' => 'Allowed Flour',
            'unit_of_measurement' => 'kg',
            'quantity_ordered' => 10,
            'unit_price' => 10,
            'total_price' => 100,
            'status' => 'pending',
        ]);

        // Excess quantity (> ordered) rolls back atomically
        $this->actingAs($this->mgrA2, 'sanctum')
            ->postJson("/api/v1/purchase/pending/{$order->id}/approve-transfer", [
                'action' => 'partial_approve',
                'ready_time' => '1_hour',
                'items' => [['item_id' => $line->id, 'new_quantity' => 15]],
            ])->assertStatus(400);
        $this->assertEquals(50.0, (float) BranchInventory::where('branch_id', $this->branchA2->id)->where('item_id', $this->itemAllowed->id)->value('available_quantity'));

        // Valid partial approval of 5 out of 10
        $this->actingAs($this->mgrA2, 'sanctum')
            ->postJson("/api/v1/purchase/pending/{$order->id}/approve-transfer", [
                'action' => 'partial_approve',
                'ready_time' => '1_hour',
                'items' => [['item_id' => $line->id, 'new_quantity' => 5]],
            ])->assertOk();

        $this->assertEquals(5.0, (float) $line->fresh()->quantity_confirmed);
        $this->assertEquals(45.0, (float) BranchInventory::where('branch_id', $this->branchA2->id)->where('item_id', $this->itemAllowed->id)->value('available_quantity'));
        $this->assertEquals(45.0, (float) BranchItem::where('branch_id', $this->branchA2->id)->where('item_id', $this->itemAllowed->id)->value('quantity'));
        $this->assertEquals(-5.0, (float) InventoryStockMovement::where('source_id', $order->id)->where('movement_type', 'transfer_out')->value('qty_delta'));
        $this->assertEquals(5.0, (float) InternalTransferVariance::where('purchase_order_id', $order->id)->value('dispatched_qty'));
    }
}
