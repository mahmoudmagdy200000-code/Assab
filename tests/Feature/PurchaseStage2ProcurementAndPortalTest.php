<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Modules\Admin\Models\AsabCompany;
use Modules\Admin\Models\AsabSupplier;
use Modules\Admin\Models\AsabUser;
use Modules\Admin\Models\AsabUserRole;
use Modules\Admin\Models\CompanyUser;
use Modules\Branch\Models\Branch;
use Modules\BranchManagers\Models\BranchManager;
use Modules\Inventory\Models\InventoryStockMovement;
use Modules\Purchase\Enums\OrderItemStatus;
use Modules\Purchase\Enums\OrderStatus;
use Modules\Purchase\Enums\OrderType;
use Modules\Purchase\Models\GoodsReceipt;
use Modules\Purchase\Models\OrderTimeline;
use Modules\Purchase\Models\PurchaseOrder;
use Modules\Purchase\Models\PurchaseOrderGroup;
use Modules\Purchase\Models\PurchaseOrderItem;
use Modules\Supplier\Models\Supplier;
use Tests\TestCase;

class PurchaseStage2ProcurementAndPortalTest extends TestCase
{
    use RefreshDatabase;

    private AsabCompany $company;

    private AsabCompany $otherCompany;

    private Branch $branch;

    private Branch $otherBranch;

    private BranchManager $branchManager;

    private BranchManager $otherBranchManager;

    private AsabUser $procurementUser;

    private AsabUser $supplierUser;

    private AsabUser $otherSupplierUser;

    private Supplier $subscribedLegacySupplier;

    private Supplier $externalLegacySupplier;

    private Supplier $otherLegacySupplier;

    private AsabSupplier $subscribedAsabSupplier;

    private AsabSupplier $externalAsabSupplier;

    public function createApplication()
    {
        putenv('FEATURE_ASAB_SUPPLIER_PORTAL=true');
        $_ENV['FEATURE_ASAB_SUPPLIER_PORTAL'] = 'true';
        $_SERVER['FEATURE_ASAB_SUPPLIER_PORTAL'] = 'true';

        return parent::createApplication();
    }

    protected function setUp(): void
    {
        parent::setUp();

        $this->company = AsabCompany::create(['name' => 'Stage2 Co', 'plan' => 'Professional', 'status' => 'active']);
        $this->otherCompany = AsabCompany::create(['name' => 'Other Co', 'plan' => 'Professional', 'status' => 'active']);

        $this->branch = Branch::factory()->create(['asab_company_id' => $this->company->id, 'name' => 'فرع العليا']);
        $this->otherBranch = Branch::factory()->create(['asab_company_id' => $this->otherCompany->id, 'name' => 'فرع آخر']);
        $this->branchManager = BranchManager::factory()->create(['branch_id' => $this->branch->id]);
        $this->otherBranchManager = BranchManager::factory()->create(['branch_id' => $this->otherBranch->id]);

        $this->procurementUser = $this->makeUser($this->company->id, 'proc@stage2.test', 'procurement');
        $this->supplierUser = $this->makeUser($this->company->id, 'sup@stage2.test', 'supplier');
        $this->otherSupplierUser = $this->makeUser($this->otherCompany->id, 'sup2@stage2.test', 'supplier');

        $this->subscribedLegacySupplier = Supplier::create([
            'name' => 'مورد مشترك',
            'email' => 'sub@stage2.test',
            'phone' => '0551112233',
            'password' => bcrypt('secret-password'),
            'is_active' => true,
            'status' => 'online',
        ]);
        $this->externalLegacySupplier = Supplier::create([
            'name' => 'مورد خارجي غير مشترك',
            'email' => 'ext@stage2.test',
            'phone' => '0554445566',
            'password' => bcrypt('secret-password'),
            'is_active' => true,
            'status' => 'online',
        ]);
        $this->otherLegacySupplier = Supplier::create([
            'name' => 'مورد شركة أخرى',
            'email' => 'other@stage2.test',
            'phone' => '0557778899',
            'password' => bcrypt('secret-password'),
            'is_active' => true,
            'status' => 'online',
        ]);

        $this->subscribedAsabSupplier = AsabSupplier::create([
            'company_id' => $this->company->id,
            'name' => 'مورد مشترك',
            'user_id' => $this->supplierUser->id,
            'legacy_supplier_id' => $this->subscribedLegacySupplier->id,
            'status' => 'active',
            'is_external' => false,
        ]);
        $this->externalAsabSupplier = AsabSupplier::create([
            'company_id' => $this->company->id,
            'name' => 'مورد خارجي غير مشترك',
            'user_id' => null,
            'legacy_supplier_id' => $this->externalLegacySupplier->id,
            'status' => 'active',
            'is_external' => true,
        ]);
        AsabSupplier::create([
            'company_id' => $this->otherCompany->id,
            'name' => 'مورد شركة أخرى',
            'user_id' => $this->otherSupplierUser->id,
            'legacy_supplier_id' => $this->otherLegacySupplier->id,
            'status' => 'active',
            'is_external' => false,
        ]);
    }

    private function makeUser(string $companyId, string $email, string $role): AsabUser
    {
        $user = AsabUser::create([
            'company_id' => $companyId,
            'name' => $role,
            'email' => $email,
            'password' => 'secret-password',
            'status' => 'active',
        ]);
        AsabUserRole::create(['user_id' => $user->id, 'role_key' => $role, 'scope' => 'all']);
        CompanyUser::create(['company_id' => $companyId, 'user_id' => $user->id, 'role_key' => $role, 'status' => 'active']);

        return $user;
    }

    private function createOrder(
        OrderType $type,
        OrderStatus $status,
        ?Supplier $supplier = null,
        ?Branch $branch = null,
        ?BranchManager $manager = null,
    ): PurchaseOrder {
        $targetBranch = $branch ?? $this->branch;
        $targetManager = $manager ?? $this->branchManager;

        $order = PurchaseOrder::create([
            'order_type' => $type,
            'status' => $status,
            'branch_id' => $targetBranch->id,
            'requested_by' => $targetManager->id,
            'sourceable_type' => $supplier ? Supplier::class : BranchManager::class,
            'sourceable_id' => $supplier?->id ?? $targetManager->id,
            'supplier_id' => $supplier?->id,
            'subtotal' => 200,
            'tax_amount' => 0,
            'total_amount' => 200,
            'total_items' => 1,
            'submitted_at' => now(),
        ]);

        PurchaseOrderItem::create([
            'purchase_order_id' => $order->id,
            'item_name' => 'زيت طبخ',
            'unit_of_measurement' => 'liter',
            'quantity_ordered' => 10,
            'quantity_confirmed' => $status === OrderStatus::CONFIRMED ? 10 : null,
            'unit_price' => 20,
            'total_price' => 200,
            'status' => $status === OrderStatus::CONFIRMED ? OrderItemStatus::CONFIRMED : OrderItemStatus::PENDING,
        ]);

        return $order;
    }

    // ---- T2.1: DEC-01 Procurement approval authority & fail-closed for others ----

    public function test_t2_1_procurement_can_approve_and_non_procurement_roles_get_403(): void
    {
        $order = $this->createOrder(OrderType::VIA_PURCHASING_OFFICER, OrderStatus::PENDING, $this->subscribedLegacySupplier);

        foreach (['branch', 'accountant', 'head', 'supplier', 'company-admin'] as $role) {
            $actor = $this->makeUser($this->company->id, "{$role}@stage2.test", $role);
            $this->actingAs($actor, 'sanctum')
                ->postJson("/api/v1/company/me/procurement/purchase-orders/{$order->id}/approve")
                ->assertStatus(403);
        }

        $this->actingAs($this->procurementUser, 'sanctum')
            ->postJson("/api/v1/company/me/procurement/purchase-orders/{$order->id}/approve")
            ->assertOk()
            ->assertJsonPath('status', 'confirmed');

        $overview = $this->actingAs($this->procurementUser, 'sanctum')
            ->getJson('/api/v1/company/me/procurement/overview')
            ->assertOk();
        $this->assertSame(0, $overview->json('kpis.incoming'));
        $this->assertSame(1, $overview->json('kpis.readyToSend'));
    }

    // ---- T2.2 & REG-DEC-07 / REG-DEC-08: Mandatory ETA, Idempotency & Sent-Awaiting-Response ----

    public function test_t2_2_and_t2_5_send_requires_eta_is_idempotent_and_sets_sent_awaiting_response(): void
    {
        $order = $this->createOrder(OrderType::VIA_PURCHASING_OFFICER, OrderStatus::CONFIRMED, $this->externalLegacySupplier);

        // Missing expectedDeliveryDate → 422, status remains confirmed (REG-DEC-07)
        $this->actingAs($this->procurementUser, 'sanctum')
            ->postJson('/api/v1/company/me/procurement/purchase-orders/grouped/send', [
                'supplierId' => $this->externalLegacySupplier->id,
            ])
            ->assertStatus(422);
        $this->assertSame(OrderStatus::CONFIRMED, $order->fresh()->status);

        // Valid send with Idempotency-Key → 201 + sent_awaiting_response (REG-DEC-08)
        $key = (string) Str::uuid();
        $first = $this->actingAs($this->procurementUser, 'sanctum')
            ->withHeader('Idempotency-Key', $key)
            ->postJson('/api/v1/company/me/procurement/purchase-orders/grouped/send', [
                'supplierId' => $this->externalLegacySupplier->id,
                'expectedDeliveryDate' => '2026-12-01',
            ])
            ->assertCreated()
            ->assertJsonPath('eta', '2026-12-01')
            ->assertJsonPath('whatsapp.deliverable', true);

        $this->assertSame(OrderStatus::SENT_AWAITING_RESPONSE, $order->fresh()->status);

        // Replay with same Idempotency-Key → single group only
        $replay = $this->actingAs($this->procurementUser, 'sanctum')
            ->withHeader('Idempotency-Key', $key)
            ->postJson('/api/v1/company/me/procurement/purchase-orders/grouped/send', [
                'supplierId' => $this->externalLegacySupplier->id,
                'expectedDeliveryDate' => '2026-12-01',
            ])
            ->assertCreated();
        $this->assertSame($first->json('groupId'), $replay->json('groupId'));
        $this->assertSame(1, PurchaseOrderGroup::count());

        // Sent list shows sent_awaiting_response
        $sent = $this->actingAs($this->procurementUser, 'sanctum')
            ->getJson('/api/v1/company/me/procurement/purchase-orders/sent')
            ->assertOk();
        $row = collect($sent->json('data'))->firstWhere('groupId', $first->json('groupId'));
        $this->assertSame('sent_awaiting_response', $row['status']);
        $this->assertSame('أُرسل — بانتظار الرد', $row['statusLabel']);
    }

    // ---- T2.3: PORTAL-01 on purchase_orders (direct + grouped, no inventory on mark-delivered, isolation) ----

    public function test_t2_3_supplier_portal_reads_direct_and_grouped_orders_and_mark_delivered_creates_no_inventory(): void
    {
        $direct = $this->createOrder(OrderType::DIRECT_SUPPLIER, OrderStatus::PENDING, $this->subscribedLegacySupplier);
        $procOrder = $this->createOrder(OrderType::VIA_PURCHASING_OFFICER, OrderStatus::CONFIRMED, $this->subscribedLegacySupplier);

        // Direct order must NOT appear in procurement consolidation preview
        $groupedPreview = $this->actingAs($this->procurementUser, 'sanctum')
            ->getJson('/api/v1/company/me/procurement/purchase-orders/grouped?by=supplier')
            ->assertOk();
        $supGroup = collect($groupedPreview->json('suppliers'))->firstWhere('supplierId', $this->subscribedLegacySupplier->id);
        $this->assertSame([$procOrder->id], $supGroup['orderIds']);

        // Send consolidated procurement order
        $send = $this->actingAs($this->procurementUser, 'sanctum')
            ->postJson('/api/v1/company/me/procurement/purchase-orders/grouped/send', [
                'supplierId' => $this->subscribedLegacySupplier->id,
                'expectedDeliveryDate' => '2026-12-05',
            ])
            ->assertCreated();

        // Supplier sees BOTH direct and sent procurement orders with exact order_number
        $pending = $this->actingAs($this->supplierUser, 'sanctum')
            ->getJson('/api/v1/asab/supplier/orders?status=pending')
            ->assertOk();
        $rows = collect($pending->json('data'));
        $this->assertSame($direct->order_number, $rows->firstWhere('id', $direct->id)['publicId']);
        $this->assertSame($send->json('groupNumber'), $rows->firstWhere('id', $procOrder->id)['groupNumber']);

        // Foreign supplier cannot see or act on them (404)
        $this->actingAs($this->otherSupplierUser, 'sanctum')
            ->postJson("/api/v1/asab/supplier/orders/{$direct->id}/accept")
            ->assertStatus(404);

        // Supplier accepts direct order with delivery date → prepare → dispatch → mark-delivered
        $this->actingAs($this->supplierUser, 'sanctum')
            ->postJson("/api/v1/asab/supplier/orders/{$direct->id}/accept", ['deliveryDate' => '2026-12-06'])
            ->assertOk()
            ->assertJsonPath('statusKey', 'accepted')
            ->assertJsonPath('deliveryDate', '2026-12-06');

        $this->actingAs($this->supplierUser, 'sanctum')
            ->postJson("/api/v1/asab/supplier/orders/{$direct->id}/prepare")
            ->assertOk()
            ->assertJsonPath('status', 'preparing');

        $this->actingAs($this->supplierUser, 'sanctum')
            ->postJson("/api/v1/asab/supplier/orders/{$direct->id}/dispatch")
            ->assertOk()
            ->assertJsonPath('status', 'on_the_way');

        $this->actingAs($this->supplierUser, 'sanctum')
            ->postJson("/api/v1/asab/supplier/orders/{$direct->id}/mark-delivered")
            ->assertOk()
            ->assertJsonPath('statusKey', 'delivered');

        // ZERO GoodsReceipt & ZERO InventoryStockMovement created on supplier mark-delivered
        $this->assertSame(0, GoodsReceipt::count());
        $this->assertSame(0, InventoryStockMovement::count());
    }

    // ---- T2.5: REG-DEC-09 & REG-DEC-10 Manual Supplier Response ----

    public function test_t2_5_manual_supplier_response_records_audit_for_external_and_rejects_subscribed_or_foreign(): void
    {
        $extOrder = $this->createOrder(OrderType::VIA_PURCHASING_OFFICER, OrderStatus::CONFIRMED, $this->externalLegacySupplier);
        $subOrder = $this->createOrder(OrderType::VIA_PURCHASING_OFFICER, OrderStatus::CONFIRMED, $this->subscribedLegacySupplier);
        $foreignOrder = $this->createOrder(
            OrderType::VIA_PURCHASING_OFFICER,
            OrderStatus::CONFIRMED,
            $this->otherLegacySupplier,
            $this->otherBranch,
            $this->otherBranchManager,
        );

        $sendExt = $this->actingAs($this->procurementUser, 'sanctum')
            ->postJson('/api/v1/company/me/procurement/purchase-orders/grouped/send', [
                'supplierId' => $this->externalLegacySupplier->id,
                'expectedDeliveryDate' => '2026-12-10',
            ])
            ->assertCreated();

        $this->actingAs($this->procurementUser, 'sanctum')
            ->postJson('/api/v1/company/me/procurement/purchase-orders/grouped/send', [
                'supplierId' => $this->subscribedLegacySupplier->id,
                'expectedDeliveryDate' => '2026-12-10',
            ])
            ->assertCreated();

        // REG-DEC-10: Subscribed supplier cannot have manual response recorded (422)
        $this->actingAs($this->procurementUser, 'sanctum')
            ->postJson("/api/v1/company/me/procurement/purchase-orders/{$subOrder->id}/manual-supplier-response", [
                'decision' => 'accepted',
                'source' => 'whatsapp',
                'respondedAt' => '2026-11-26T08:00:00Z',
            ])
            ->assertStatus(422)
            ->assertJsonPath('error.code', 'SUPPLIER_IS_SUBSCRIBED');

        // REG-DEC-10: Unauthorized role (branch) → 403; cross-tenant order → 404
        $branchUser = $this->makeUser($this->company->id, 'branch2@stage2.test', 'branch');
        $this->actingAs($branchUser, 'sanctum')
            ->postJson("/api/v1/company/me/procurement/purchase-orders/{$extOrder->id}/manual-supplier-response", [
                'decision' => 'accepted',
                'source' => 'phone',
                'respondedAt' => '2026-11-26T08:00:00Z',
            ])
            ->assertStatus(403);

        $this->actingAs($this->procurementUser, 'sanctum')
            ->postJson("/api/v1/company/me/procurement/purchase-orders/{$foreignOrder->id}/manual-supplier-response", [
                'decision' => 'accepted',
                'source' => 'phone',
                'respondedAt' => '2026-11-26T08:00:00Z',
            ])
            ->assertStatus(404);

        // REG-DEC-09: Authorized manual response for external supplier persists metadata & timeline
        $res = $this->actingAs($this->procurementUser, 'sanctum')
            ->postJson("/api/v1/company/me/procurement/purchase-orders/{$extOrder->id}/manual-supplier-response", [
                'decision' => 'accepted',
                'source' => 'whatsapp',
                'respondedAt' => '2026-11-26T08:15:00Z',
                'note' => 'تم تأكيد التوريد عبر واتساب',
            ])
            ->assertOk()
            ->assertJsonPath('status', 'confirmed')
            ->assertJsonPath('manualResponse.decision', 'accepted')
            ->assertJsonPath('manualResponse.source', 'whatsapp')
            ->assertJsonPath('manualResponse.recordedByUserId', $this->procurementUser->id);

        $this->assertSame(OrderStatus::CONFIRMED, $extOrder->fresh()->status);

        $timeline = OrderTimeline::where('timelineable_id', $extOrder->id)
            ->where('actor_id', $this->procurementUser->id)
            ->where('title', 'Manual supplier response recorded')
            ->first();
        $this->assertNotNull($timeline);
        $this->assertSame('whatsapp', $timeline->metadata['response_source']);
        $this->assertSame('accepted', $timeline->metadata['response_decision']);

        // Group details exposes manualResponse & isSubscribedSupplier=false for ProcSent.tsx
        $groupDet = $this->actingAs($this->procurementUser, 'sanctum')
            ->getJson('/api/v1/company/me/procurement/purchase-orders/groups/'.$sendExt->json('groupId'))
            ->assertOk()
            ->assertJsonPath('isSubscribedSupplier', false);
        $this->assertSame('whatsapp', $groupDet->json('orders.0.manualResponse.source'));
    }

    // ---- R1 Regressions: Platform supplier cross-company, export isolation, mixed-branch totals ----

    public function test_r1_platform_supplier_works_cross_company_while_company_supplier_export_is_isolated(): void
    {
        $platformSupUser = $this->makeUser($this->company->id, 'plat-sup@stage2.test', 'supplier');
        $platformLegacySup = Supplier::create([
            'name' => 'مورد منصة مشترك',
            'email' => 'plat-legacy@stage2.test',
            'phone' => '0559990000',
            'password' => bcrypt('secret-password'),
            'is_active' => true,
            'status' => 'online',
        ]);
        AsabSupplier::create([
            'company_id' => null,
            'name' => 'مورد منصة مشترك',
            'user_id' => $platformSupUser->id,
            'legacy_supplier_id' => $platformLegacySup->id,
            'status' => 'active',
            'is_external' => false,
        ]);

        // Other company's confirmed order assigned to the shared platform supplier
        $otherOrder = $this->createOrder(
            OrderType::VIA_PURCHASING_OFFICER,
            OrderStatus::CONFIRMED,
            null,
            $this->otherBranch,
            $this->otherBranchManager,
        );
        $otherProc = $this->makeUser($this->otherCompany->id, 'proc-other@stage2.test', 'procurement');
        $this->actingAs($otherProc, 'sanctum')
            ->postJson('/api/v1/company/me/procurement/purchase-orders/grouped/send', [
                'supplierId' => $platformLegacySup->id,
                'orderIds' => [$otherOrder->id],
                'expectedDeliveryDate' => '2026-12-15',
            ])
            ->assertCreated();

        // Shared platform supplier sees the order in portal & CSV export
        $list = $this->actingAs($platformSupUser, 'sanctum')
            ->getJson('/api/v1/asab/supplier/orders')
            ->assertOk();
        $this->assertTrue(collect($list->json('data'))->contains('id', $otherOrder->id));

        $csv = $this->actingAs($platformSupUser, 'sanctum')
            ->get('/api/v1/asab/supplier/orders/export?format=csv')
            ->assertOk();
        $csvBody = file_get_contents($csv->baseResponse->getFile()->getPathname());
        $this->assertStringContainsString($otherOrder->order_number, $csvBody);

        // Company-owned supplier (supplierUser) cannot see otherCompany's direct order in CSV export
        $leakedDirect = $this->createOrder(
            OrderType::DIRECT_SUPPLIER,
            OrderStatus::PENDING,
            $this->subscribedLegacySupplier,
            $this->otherBranch,
            $this->otherBranchManager,
        );
        $ownedCsv = $this->actingAs($this->supplierUser, 'sanctum')
            ->get('/api/v1/asab/supplier/orders/export?format=csv')
            ->assertOk();
        $ownedCsvBody = file_get_contents($ownedCsv->baseResponse->getFile()->getPathname());
        $this->assertStringNotContainsString($leakedDirect->order_number, $ownedCsvBody);
    }

    public function test_r1_mixed_branch_group_details_and_sent_totals_exclude_unauthorized_branches(): void
    {
        $a = $this->createOrder(OrderType::VIA_PURCHASING_OFFICER, OrderStatus::CONFIRMED, $this->externalLegacySupplier);
        $bBranch = Branch::factory()->create(['asab_company_id' => $this->company->id, 'name' => 'فرع محجوب']);
        $bManager = BranchManager::factory()->create(['branch_id' => $bBranch->id]);
        $b = $this->createOrder(OrderType::VIA_PURCHASING_OFFICER, OrderStatus::CONFIRMED, $this->externalLegacySupplier, $bBranch, $bManager);

        $send = $this->actingAs($this->procurementUser, 'sanctum')
            ->postJson('/api/v1/company/me/procurement/purchase-orders/grouped/send', [
                'supplierId' => $this->externalLegacySupplier->id,
                'orderIds' => [$a->id, $b->id],
                'expectedDeliveryDate' => '2026-12-20',
            ])
            ->assertCreated();

        // Restrict procurementUser to $this->branch only
        AsabUserRole::where('user_id', $this->procurementUser->id)
            ->update(['scope' => 'branch', 'branch_ids' => json_encode([$this->branch->id])]);
        $this->procurementUser->unsetRelations();

        $det = $this->actingAs($this->procurementUser, 'sanctum')
            ->getJson('/api/v1/company/me/procurement/purchase-orders/groups/'.$send->json('groupId'))
            ->assertOk();
        $this->assertCount(1, $det->json('orders'));
        $this->assertEquals(200.0, $det->json('totalAmount'));
        $this->assertEquals(10.0, $det->json('items.0.totalQuantity'));

        $sent = $this->actingAs($this->procurementUser, 'sanctum')
            ->getJson('/api/v1/company/me/procurement/purchase-orders/sent')
            ->assertOk();
        $row = collect($sent->json('data'))->firstWhere('groupId', $send->json('groupId'));
        $this->assertSame(1, $row['ordersCount']);
        $this->assertEquals(200.0, $row['totalAmount']);
    }

    public function test_r2_manual_direct_acceptance_and_rejection_keep_items_consistent_and_idempotent(): void
    {
        // Acceptance: confirms pending line while preserving existing partial and rejected lines
        $acceptOrder = $this->createOrder(OrderType::DIRECT_SUPPLIER, OrderStatus::PENDING, $this->externalLegacySupplier);
        $pendingItem = $acceptOrder->items()->firstOrFail();
        $partialItem = PurchaseOrderItem::create([
            'purchase_order_id' => $acceptOrder->id,
            'item_name' => 'أرز',
            'unit_of_measurement' => 'kg',
            'quantity_ordered' => 20,
            'quantity_confirmed' => 12,
            'unit_price' => 10,
            'total_price' => 120,
            'status' => OrderItemStatus::PARTIAL,
        ]);
        $rejectedItem = PurchaseOrderItem::create([
            'purchase_order_id' => $acceptOrder->id,
            'item_name' => 'سكر',
            'unit_of_measurement' => 'kg',
            'quantity_ordered' => 5,
            'quantity_confirmed' => 0,
            'unit_price' => 8,
            'total_price' => 0,
            'status' => OrderItemStatus::REJECTED,
        ]);

        $key = (string) Str::uuid();
        $first = $this->actingAs($this->procurementUser, 'sanctum')
            ->withHeader('Idempotency-Key', $key)
            ->postJson("/api/v1/company/me/procurement/purchase-orders/{$acceptOrder->id}/manual-supplier-response", [
                'decision' => 'accepted',
                'source' => 'phone',
                'respondedAt' => '2026-11-26T08:00:00Z',
            ])
            ->assertOk();

        $this->assertSame(OrderStatus::CONFIRMED, $acceptOrder->fresh()->status);
        $this->assertSame(OrderItemStatus::CONFIRMED, $pendingItem->fresh()->status);
        $this->assertEquals(10.0, (float) $pendingItem->fresh()->quantity_confirmed);
        $this->assertSame(OrderItemStatus::PARTIAL, $partialItem->fresh()->status);
        $this->assertEquals(12.0, (float) $partialItem->fresh()->quantity_confirmed);
        $this->assertSame(OrderItemStatus::REJECTED, $rejectedItem->fresh()->status);

        // Same-key replay returns original timeline; new-key duplicate fails with 409
        $replay = $this->actingAs($this->procurementUser, 'sanctum')
            ->withHeader('Idempotency-Key', $key)
            ->postJson("/api/v1/company/me/procurement/purchase-orders/{$acceptOrder->id}/manual-supplier-response", [
                'decision' => 'accepted',
                'source' => 'phone',
                'respondedAt' => '2026-11-26T08:00:00Z',
            ])
            ->assertOk();
        $this->assertSame($first->json('manualResponse.timelineId'), $replay->json('manualResponse.timelineId'));

        $this->actingAs($this->procurementUser, 'sanctum')
            ->withHeader('Idempotency-Key', (string) Str::uuid())
            ->postJson("/api/v1/company/me/procurement/purchase-orders/{$acceptOrder->id}/manual-supplier-response", [
                'decision' => 'accepted',
                'source' => 'phone',
                'respondedAt' => '2026-11-26T08:00:00Z',
            ])
            ->assertStatus(409);

        // Rejection: updates pending line to REJECTED (qty_confirmed=0) and blocks contradictory accept
        $rejectOrder = $this->createOrder(OrderType::DIRECT_SUPPLIER, OrderStatus::PENDING, $this->externalLegacySupplier);
        $rejectPending = $rejectOrder->items()->firstOrFail();

        $this->actingAs($this->procurementUser, 'sanctum')
            ->withHeader('Idempotency-Key', (string) Str::uuid())
            ->postJson("/api/v1/company/me/procurement/purchase-orders/{$rejectOrder->id}/manual-supplier-response", [
                'decision' => 'rejected',
                'source' => 'whatsapp',
                'respondedAt' => '2026-11-26T08:10:00Z',
                'reason' => 'غير متوفر',
            ])
            ->assertOk()
            ->assertJsonPath('status', 'rejected');

        $this->assertSame(OrderItemStatus::REJECTED, $rejectPending->fresh()->status);
        $this->assertEquals(0.0, (float) $rejectPending->fresh()->quantity_confirmed);

        $this->actingAs($this->procurementUser, 'sanctum')
            ->withHeader('Idempotency-Key', (string) Str::uuid())
            ->postJson("/api/v1/company/me/procurement/purchase-orders/{$rejectOrder->id}/manual-supplier-response", [
                'decision' => 'accepted',
                'source' => 'whatsapp',
                'respondedAt' => '2026-11-26T08:12:00Z',
            ])
            ->assertStatus(409);
    }
}
