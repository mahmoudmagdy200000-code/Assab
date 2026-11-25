<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Str;
use Modules\Admin\Models\AsabBrand;
use Modules\Admin\Models\AsabCompany;
use Modules\Admin\Models\AsabIdentityMap;
use Modules\Admin\Models\AsabSupplier;
use Modules\Admin\Models\AsabUser;
use Modules\Admin\Models\AsabUserRole;
use Modules\Branch\Models\Branch;
use Modules\BranchManagers\Models\BranchManager;
use Modules\BrandOwner\Models\BrandOwner;
use Modules\Cashier\Models\Cashier;
use Modules\Purchase\Enums\OrderItemStatus;
use Modules\Purchase\Enums\OrderStatus;
use Modules\Purchase\Enums\OrderType;
use Modules\Purchase\Enums\ReturnRequiredAction;
use Modules\Purchase\Enums\ReturnStatus;
use Modules\Purchase\Enums\VarianceType;
use Modules\Purchase\Models\GoodsReceipt;
use Modules\Purchase\Models\GoodsReceiptItem;
use Modules\Purchase\Models\Item;
use Modules\Purchase\Models\PurchaseOrder;
use Modules\Purchase\Models\PurchaseOrderItem;
use Modules\Purchase\Models\PurchaseVariance;
use Modules\Purchase\Models\ReturnOrder;
use Modules\Supplier\Models\Supplier;
use Tests\TestCase;

/**
 * Stage 0 regression suite (T0.1 + T0.2):
 * - PUR-F01: Action-specific role/guard authorization & Policy enforcement
 * - PUR-F02 / PUR-F03: Tenant, brand, branch, supplier, and return isolation
 */
class PurchaseStage0AuthorizationAndIdorTest extends TestCase
{
    use RefreshDatabase;

    private AsabCompany $companyA;

    private AsabCompany $companyB;

    private AsabBrand $brandA;

    private AsabBrand $brandB;

    private Branch $branchA1;

    private Branch $branchA2;

    private Branch $branchB1;

    private BranchManager $bmA1;

    private BranchManager $bmA2;

    private BranchManager $bmB1;

    private Cashier $cashierA1;

    private Supplier $supplierA;

    private Supplier $supplierB;

    private BrandOwner $ownerA;

    private BrandOwner $ownerB;

    private Item $item;

    protected function setUp(): void
    {
        parent::setUp();

        $this->companyA = AsabCompany::create(['name' => 'Tenant A Co', 'plan' => 'Professional', 'status' => 'active']);
        $this->companyB = AsabCompany::create(['name' => 'Tenant B Co', 'plan' => 'Professional', 'status' => 'active']);

        $this->brandA = AsabBrand::create([
            'company_id' => $this->companyA->id, 'name' => 'Brand A', 'sub_status' => 'active', 'status' => 'active',
        ]);
        $this->brandB = AsabBrand::create([
            'company_id' => $this->companyB->id, 'name' => 'Brand B', 'sub_status' => 'active', 'status' => 'active',
        ]);

        $this->branchA1 = Branch::factory()->create([
            'name' => 'A1', 'asab_company_id' => $this->companyA->id, 'asab_brand_id' => $this->brandA->id,
        ]);
        $this->branchA2 = Branch::factory()->create([
            'name' => 'A2', 'asab_company_id' => $this->companyA->id, 'asab_brand_id' => $this->brandA->id,
        ]);
        $this->branchB1 = Branch::factory()->create([
            'name' => 'B1', 'asab_company_id' => $this->companyB->id, 'asab_brand_id' => $this->brandB->id,
        ]);

        $this->bmA1 = BranchManager::factory()->create(['branch_id' => $this->branchA1->id, 'is_active' => true, 'status' => 'active']);
        $this->bmA2 = BranchManager::factory()->create(['branch_id' => $this->branchA2->id, 'is_active' => true, 'status' => 'active']);
        $this->bmB1 = BranchManager::factory()->create(['branch_id' => $this->branchB1->id, 'is_active' => true, 'status' => 'active']);

        $this->cashierA1 = Cashier::factory()->create(['branch_id' => $this->branchA1->id]);

        $this->supplierA = Supplier::forceCreate([
            'name' => 'Supplier A', 'email' => 'sup-a@test.local', 'is_active' => true,
        ]);
        $this->supplierB = Supplier::forceCreate([
            'name' => 'Supplier B', 'email' => 'sup-b@test.local', 'is_active' => true,
        ]);

        AsabSupplier::create([
            'company_id' => $this->companyA->id, 'brand_id' => $this->brandA->id, 'name' => 'Supplier A', 'status' => 'active',
        ])->forceFill(['legacy_supplier_id' => $this->supplierA->id])->save();

        AsabSupplier::create([
            'company_id' => $this->companyB->id, 'brand_id' => $this->brandB->id, 'name' => 'Supplier B', 'status' => 'active',
        ])->forceFill(['legacy_supplier_id' => $this->supplierB->id])->save();

        $this->ownerA = $this->makeLinkedBrandOwner($this->companyA, $this->brandA, 'owner-a@test.local');
        $this->ownerB = $this->makeLinkedBrandOwner($this->companyB, $this->brandB, 'owner-b@test.local');

        $this->item = Item::create(['name' => 'Flour', 'unit' => 'kg', 'is_active' => true]);
    }

    private function makeLinkedBrandOwner(AsabCompany $company, AsabBrand $brand, string $email): BrandOwner
    {
        $owner = BrandOwner::create([
            'name' => 'Owner '.$brand->name,
            'email' => $email,
            'phone' => '05'.random_int(10000000, 99999999),
            'password' => 'secret-password',
            'is_active' => true,
            'is_first_login' => false,
            'status' => 'active',
        ]);

        $asabUser = AsabUser::create([
            'company_id' => $company->id,
            'name' => $owner->name,
            'email' => $email,
            'password' => 'secret-password',
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
            'linked_email' => $email,
            'source' => 'test',
        ]);

        return $owner;
    }

    private function createOrderWithReceiptAndVariance(Branch $branch, BranchManager $bm, Supplier $supplier): array
    {
        $order = PurchaseOrder::factory()->create([
            'order_number' => 'PO-'.strtoupper(Str::random(8)),
            'order_type' => OrderType::DIRECT_SUPPLIER,
            'status' => OrderStatus::DELIVERED,
            'branch_id' => $branch->id,
            'requested_by' => $bm->id,
            'supplier_id' => $supplier->id,
        ]);

        $orderItem = PurchaseOrderItem::factory()->create([
            'purchase_order_id' => $order->id,
            'item_id' => $this->item->id,
            'item_name' => $this->item->name,
            'status' => OrderItemStatus::CONFIRMED,
            'quantity_ordered' => 10,
            'quantity_confirmed' => 10,
            'unit_price' => 20,
        ]);

        $receipt = GoodsReceipt::create([
            'receipt_number' => 'GR-'.strtoupper(Str::random(8)),
            'purchase_order_id' => $order->id,
            'branch_id' => $branch->id,
            'received_by' => $bm->id,
            'status' => 'draft',
        ]);

        $receiptItem = GoodsReceiptItem::create([
            'goods_receipt_id' => $receipt->id,
            'purchase_order_item_id' => $orderItem->id,
            'item_id' => $this->item->id,
            'item_name' => $this->item->name,
            'unit' => 'kg',
            'quantity_ordered' => 10,
            'quantity_received' => 8,
            'quantity_variance' => -2,
            'unit_price' => 20,
            'expected_total' => 200,
            'received_total' => 160,
            'variance_amount' => 40,
            'variance_type' => VarianceType::SHORT->value,
        ]);

        $variance = PurchaseVariance::create([
            'goods_receipt_id' => $receipt->id,
            'goods_receipt_item_id' => $receiptItem->id,
            'purchase_order_id' => $order->id,
            'item_name' => $this->item->name,
            'variance_type' => VarianceType::SHORT,
            'quantity_ordered' => 10,
            'quantity_received' => 8,
            'quantity_variance' => -2,
            'unit_price' => 20,
            'variance_amount' => 40,
            'status' => 'pending',
        ]);

        return [$order, $receipt, $receiptItem, $variance];
    }

    public function test_role_and_guard_matrix_blocks_unauthorized_actors_and_inactive_accounts(): void
    {
        [$orderA, , , $varianceA] = $this->createOrderWithReceiptAndVariance($this->branchA1, $this->bmA1, $this->supplierA);

        // Cashier must receive 403 across all Purchase and RecurringOrder endpoints
        $this->actingAs($this->cashierA1, 'sanctum')->getJson('/api/v1/purchase/orders')->assertStatus(403);
        $this->actingAs($this->cashierA1, 'sanctum')->getJson('/api/v1/purchase/pending')->assertStatus(403);
        $this->actingAs($this->cashierA1, 'sanctum')->getJson('/api/v1/purchase/receiving/in-progress')->assertStatus(403);
        $this->actingAs($this->cashierA1, 'sanctum')->getJson('/api/v1/purchase/returns/in-progress')->assertStatus(403);
        $this->actingAs($this->cashierA1, 'sanctum')->getJson('/api/v1/purchase/suppliers')->assertStatus(403);
        $this->actingAs($this->cashierA1, 'sanctum')->getJson('/api/v1/recurring-orders/in-progress')->assertStatus(403);

        // Supplier is blocked from general Purchase routes, allowed ONLY on own variance supplier-response
        $this->actingAs($this->supplierA, 'sanctum')->getJson('/api/v1/purchase/orders')->assertStatus(403);
        $this->actingAs($this->supplierA, 'sanctum')->getJson('/api/v1/purchase/receiving/in-progress')->assertStatus(403);
        $this->actingAs($this->supplierA, 'sanctum')->getJson('/api/v1/purchase/returns/in-progress')->assertStatus(403);
        $this->actingAs($this->supplierA, 'sanctum')->getJson('/api/v1/recurring-orders/in-progress')->assertStatus(403);

        // Foreign supplier cannot respond to Supplier A's variance
        $this->actingAs($this->supplierB, 'sanctum')
            ->postJson("/api/v1/purchase/receiving/variances/{$varianceA->id}/supplier-response", ['action' => 'approve'])
            ->assertStatus(403);

        // Owning active supplier CAN respond to own variance
        $this->actingAs($this->supplierA, 'sanctum')
            ->postJson("/api/v1/purchase/receiving/variances/{$varianceA->id}/supplier-response", ['action' => 'approve'])
            ->assertOk();

        // BrandOwner is blocked from orders/pending/receiving/return-writes & allowed on escalated return views
        $this->actingAs($this->ownerA, 'sanctum')->getJson('/api/v1/purchase/orders')->assertStatus(403);
        $this->actingAs($this->ownerA, 'sanctum')->getJson('/api/v1/purchase/receiving/in-progress')->assertStatus(403);
        $this->actingAs($this->ownerA, 'sanctum')->getJson('/api/v1/purchase/returns/drafts')->assertStatus(403);
        $this->actingAs($this->ownerA, 'sanctum')->postJson('/api/v1/purchase/returns', [])->assertStatus(403);
        $this->actingAs($this->ownerA, 'sanctum')->getJson('/api/v1/purchase/returns/in-progress')->assertOk();

        // Inactive BranchManager is rejected with 403
        $this->bmA1->update(['is_active' => false]);
        $this->actingAs($this->bmA1->fresh(), 'sanctum')
            ->getJson("/api/v1/purchase/pending/{$orderA->id}/timeline")
            ->assertStatus(403);
        $this->actingAs($this->bmA1->fresh(), 'sanctum')
            ->getJson('/api/v1/recurring-orders/in-progress')
            ->assertStatus(403);
    }

    public function test_cross_tenant_and_cross_branch_idors_are_blocked_and_authorized_calls_succeed(): void
    {
        [$orderB, $receiptB, $receiptItemB, $varianceB] = $this->createOrderWithReceiptAndVariance(
            $this->branchB1,
            $this->bmB1,
            $this->supplierB
        );
        [$orderA, $receiptA] = $this->createOrderWithReceiptAndVariance(
            $this->branchA1,
            $this->bmA1,
            $this->supplierA
        );

        // NewOrder: branch_id override & cross-tenant transfer-items
        $this->actingAs($this->bmA1, 'sanctum')
            ->getJson("/api/v1/purchase/orders/branch-items?branch_id={$this->branchB1->id}")
            ->assertStatus(403);
        $this->actingAs($this->bmA1, 'sanctum')
            ->getJson("/api/v1/purchase/orders/branch-items?branch_id={$this->branchA1->id}")
            ->assertOk();
        $this->actingAs($this->bmA1, 'sanctum')
            ->getJson("/api/v1/purchase/orders/transfer-items?branch_id={$this->branchB1->id}")
            ->assertStatus(403);

        // Pending & History timelines
        $this->actingAs($this->bmA1, 'sanctum')->getJson("/api/v1/purchase/pending/{$orderB->id}/timeline")->assertStatus(403);
        $this->actingAs($this->bmA1, 'sanctum')->getJson("/api/v1/purchase/history/{$orderB->id}/timeline")->assertStatus(403);
        $this->actingAs($this->bmA1, 'sanctum')->getJson("/api/v1/purchase/pending/{$orderA->id}/timeline")->assertOk();

        $missingReceiptB = GoodsReceipt::create([
            'receipt_number' => 'GR-MIS-'.strtoupper(Str::random(6)),
            'purchase_order_id' => $orderB->id,
            'branch_id' => $this->branchB1->id,
            'received_by' => $this->bmB1->id,
            'status' => 'has_variance',
            'quantity_variances' => 1,
        ]);

        $cleanCompletedReceiptB = GoodsReceipt::create([
            'receipt_number' => 'GR-CMP-'.strtoupper(Str::random(6)),
            'purchase_order_id' => $orderB->id,
            'branch_id' => $this->branchB1->id,
            'received_by' => $this->bmB1->id,
            'status' => 'completed',
            'quantity_variances' => 0,
            'quality_variances' => 0,
        ]);

        // GoodsReceiving IDOR endpoints
        $this->actingAs($this->bmA1, 'sanctum')->getJson("/api/v1/purchase/receiving/{$receiptB->id}")->assertStatus(403);
        $this->actingAs($this->bmA1, 'sanctum')->getJson("/api/v1/purchase/receiving/{$receiptB->id}/summary")->assertStatus(403);
        $this->actingAs($this->bmA1, 'sanctum')->getJson("/api/v1/purchase/receiving/drafts/{$receiptB->id}")->assertStatus(403);
        $this->actingAs($this->bmA1, 'sanctum')->getJson("/api/v1/purchase/receiving/missing/{$missingReceiptB->id}")->assertStatus(403);
        $this->actingAs($this->bmA1, 'sanctum')->getJson("/api/v1/purchase/receiving/completed/{$cleanCompletedReceiptB->id}")->assertStatus(403);
        $this->actingAs($this->bmA1, 'sanctum')->getJson("/api/v1/purchase/receiving/receipts/{$receiptB->id}/variance-summary")->assertStatus(403);
        $this->actingAs($this->bmA1, 'sanctum')->getJson("/api/v1/purchase/receiving/orders/{$orderB->id}/tracking")->assertStatus(403);
        $this->actingAs($this->bmA1, 'sanctum')->getJson("/api/v1/purchase/receiving/orders/{$orderB->id}/inspection")->assertStatus(403);
        $this->actingAs($this->bmA1, 'sanctum')->postJson("/api/v1/purchase/receiving/{$receiptB->id}/items/{$receiptItemB->id}/inspect", [
            'quantity_received' => 5,
            'quality' => 'normal',
        ])->assertStatus(403);
        $this->actingAs($this->bmA1, 'sanctum')->postJson("/api/v1/purchase/receiving/variances/{$varianceB->id}/action", [
            'action' => 'accept',
        ])->assertStatus(403);
        $this->actingAs($this->bmA1, 'sanctum')->postJson("/api/v1/purchase/receiving/variances/{$varianceB->id}/accept-rejection")->assertStatus(403);
        $this->actingAs($this->bmA1, 'sanctum')->postJson("/api/v1/purchase/receiving/variances/{$varianceB->id}/escalate", [
            'reason' => 'Cross-tenant test',
            'escalated_to' => $this->ownerA->id,
        ])->assertStatus(403);

        // Authorized BranchManager A1 can view own receipt & variance summary
        $this->actingAs($this->bmA1, 'sanctum')->getJson("/api/v1/purchase/receiving/{$receiptA->id}")->assertOk();
        $this->actingAs($this->bmA1, 'sanctum')->getJson("/api/v1/purchase/receiving/receipts/{$receiptA->id}/variance-summary")->assertOk();

        // Suppliers IDOR
        $this->actingAs($this->bmA1, 'sanctum')->getJson("/api/v1/purchase/suppliers/{$this->supplierB->id}")->assertStatus(403);
        $this->actingAs($this->bmA1, 'sanctum')->getJson("/api/v1/purchase/suppliers/{$this->supplierB->id}/items")->assertStatus(403);
        $this->actingAs($this->bmA1, 'sanctum')->getJson("/api/v1/purchase/suppliers/{$this->supplierB->id}/orders")->assertStatus(403);
        $this->actingAs($this->bmA1, 'sanctum')->getJson("/api/v1/purchase/suppliers/{$this->supplierB->id}/statistics")->assertStatus(403);
        $this->actingAs($this->bmA1, 'sanctum')->getJson("/api/v1/purchase/suppliers/{$this->supplierA->id}")->assertOk();

        // Shared brand supplier order history must not leak sibling branch A2 orders to A1
        [$orderA2] = $this->createOrderWithReceiptAndVariance($this->branchA2, $this->bmA2, $this->supplierA);
        $historyOrderIds = collect(
            $this->actingAs($this->bmA1, 'sanctum')->getJson("/api/v1/purchase/suppliers/{$this->supplierA->id}/orders")->assertOk()->json('data')
        )->pluck('id')->all();
        $this->assertContains($orderA->id, $historyOrderIds);
        $this->assertNotContains($orderA2->id, $historyOrderIds);

        // Policy assertions
        $this->assertTrue(Gate::forUser($this->bmA1)->allows('view', $orderA));
        $this->assertFalse(Gate::forUser($this->bmA1)->allows('view', $orderB));
        $this->assertTrue(Gate::forUser($this->bmA1)->allows('view', $receiptA));
        $this->assertFalse(Gate::forUser($this->bmA1)->allows('view', $receiptB));
    }

    public function test_returns_and_brand_owner_escalated_views_and_recurring_pickers_are_tenant_scoped(): void
    {
        [$orderA] = $this->createOrderWithReceiptAndVariance($this->branchA1, $this->bmA1, $this->supplierA);
        [$orderB] = $this->createOrderWithReceiptAndVariance($this->branchB1, $this->bmB1, $this->supplierB);

        $returnA = ReturnOrder::create([
            'purchase_order_id' => $orderA->id,
            'supplier_id' => $this->supplierA->id,
            'branch_id' => $this->branchA1->id,
            'created_by' => $this->bmA1->id,
            'status' => ReturnStatus::ESCALATED,
            'required_action' => ReturnRequiredAction::REPLACEMENT->value,
            'escalated_at' => now(),
        ]);

        $returnB = ReturnOrder::create([
            'purchase_order_id' => $orderB->id,
            'supplier_id' => $this->supplierB->id,
            'branch_id' => $this->branchB1->id,
            'created_by' => $this->bmB1->id,
            'status' => ReturnStatus::ESCALATED,
            'required_action' => ReturnRequiredAction::REPLACEMENT->value,
            'escalated_at' => now(),
        ]);

        $draftB = ReturnOrder::create([
            'purchase_order_id' => $orderB->id,
            'supplier_id' => $this->supplierB->id,
            'branch_id' => $this->branchB1->id,
            'created_by' => $this->bmB1->id,
            'status' => ReturnStatus::DRAFT,
            'required_action' => ReturnRequiredAction::REPLACEMENT->value,
        ]);

        // BrandOwner A sees ONLY Brand A escalated returns
        $listedA = collect(
            $this->actingAs($this->ownerA, 'sanctum')->getJson('/api/v1/purchase/returns/in-progress')->assertOk()->json('data')
        )->pluck('id')->all();
        $this->assertContains($returnA->id, $listedA);
        $this->assertNotContains($returnB->id, $listedA);

        $this->actingAs($this->ownerA, 'sanctum')->getJson("/api/v1/purchase/returns/{$returnA->id}")->assertOk();
        $this->actingAs($this->ownerA, 'sanctum')->getJson("/api/v1/purchase/returns/{$returnB->id}")->assertStatus(403);
        $this->actingAs($this->ownerA, 'sanctum')->getJson("/api/v1/purchase/returns/{$returnB->id}/timeline")->assertStatus(403);
        $this->actingAs($this->ownerA, 'sanctum')->getJson("/api/v1/purchase/returns/{$draftB->id}")->assertStatus(403);

        // Cross-tenant BranchManager return mutations blocked
        $this->actingAs($this->bmA1, 'sanctum')->postJson("/api/v1/purchase/returns/{$draftB->id}/submit")->assertStatus(403);
        $this->actingAs($this->bmA1, 'sanctum')->postJson("/api/v1/purchase/returns/{$returnB->id}/accept-rejection")->assertStatus(403);
        $this->actingAs($this->bmA1, 'sanctum')->postJson("/api/v1/purchase/returns/{$returnB->id}/escalate", ['reason' => 'nope'])->assertStatus(403);
        $this->actingAs($this->bmA1, 'sanctum')->deleteJson("/api/v1/purchase/returns/{$draftB->id}/draft")->assertStatus(403);
        $this->actingAs($this->bmA1, 'sanctum')->getJson("/api/v1/purchase/returns/{$returnB->id}/timeline")->assertStatus(403);

        // RecurringOrder pickers are strictly scoped even with search query
        $supIds = collect(
            $this->actingAs($this->bmA1, 'sanctum')->getJson('/api/v1/recurring-orders/suppliers?search=Supplier')->assertOk()->json('data')
        )->pluck('id')->all();
        $this->assertContains($this->supplierA->id, $supIds);
        $this->assertNotContains($this->supplierB->id, $supIds);

        $officerIds = collect(
            $this->actingAs($this->bmA1, 'sanctum')->getJson('/api/v1/recurring-orders/purchasing-officers')->assertOk()->json('data')
        )->pluck('id')->all();
        $this->assertContains($this->bmA2->id, $officerIds);
        $this->assertNotContains($this->bmB1->id, $officerIds);
    }
}
