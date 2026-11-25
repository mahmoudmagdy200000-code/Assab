<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Str;
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
use Modules\Cashier\Models\Cashier;
use Modules\Purchase\Enums\OrderStatus;
use Modules\Purchase\Enums\OrderType;
use Modules\Purchase\Enums\ReturnStatus;
use Modules\Purchase\Models\BranchInventory;
use Modules\Purchase\Models\BranchItem;
use Modules\Purchase\Models\GoodsReceipt;
use Modules\Purchase\Models\GoodsReceiptItem;
use Modules\Purchase\Models\Item;
use Modules\Purchase\Models\PurchaseOrder;
use Modules\Purchase\Models\PurchaseOrderItem;
use Modules\Purchase\Models\ReturnOrder;
use Modules\Purchase\Models\SavedPriceComparison;
use Modules\Purchase\Models\SupplierItem;
use Modules\RecurringOrder\Models\RecurringOrder;
use Modules\Supplier\Models\Supplier;
use Tests\TestCase;

class PurchaseStage0AuditRemediationR2Test extends TestCase
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

    private Item $itemAlt;

    private Supplier $supplierA;

    private Supplier $supplierB;

    protected function setUp(): void
    {
        parent::setUp();

        $this->companyA = AsabCompany::create(['name' => 'R2 Co A', 'plan' => 'Professional', 'status' => 'active']);
        $this->companyB = AsabCompany::create(['name' => 'R2 Co B', 'plan' => 'Professional', 'status' => 'active']);
        $this->brandA = AsabBrand::create(['company_id' => $this->companyA->id, 'name' => 'R2 Brand A', 'sub_status' => 'active', 'status' => 'active']);
        $this->brandB = AsabBrand::create(['company_id' => $this->companyB->id, 'name' => 'R2 Brand B', 'sub_status' => 'active', 'status' => 'active']);

        $this->branchA1 = Branch::factory()->create(['name' => 'R2-A1', 'asab_brand_id' => $this->brandA->id, 'asab_company_id' => $this->companyA->id]);
        $this->branchA2 = Branch::factory()->create(['name' => 'R2-A2', 'asab_brand_id' => $this->brandA->id, 'asab_company_id' => $this->companyA->id]);
        $this->branchB1 = Branch::factory()->create(['name' => 'R2-B1', 'asab_brand_id' => $this->brandB->id, 'asab_company_id' => $this->companyB->id]);

        $this->mgrA1 = BranchManager::create([
            'branch_id' => $this->branchA1->id, 'name' => 'Mgr A1', 'email' => 'r2mgra1@test.local',
            'phone' => '0500100101', 'password' => 'secret', 'is_active' => true, 'status' => 'active',
        ]);
        $this->mgrA2 = BranchManager::create([
            'branch_id' => $this->branchA2->id, 'name' => 'Mgr A2', 'email' => 'r2mgra2@test.local',
            'phone' => '0500100102', 'password' => 'secret', 'is_active' => true, 'status' => 'active',
        ]);
        $this->mgrB1 = BranchManager::create([
            'branch_id' => $this->branchB1->id, 'name' => 'Mgr B1', 'email' => 'r2mgrb1@test.local',
            'phone' => '0500100103', 'password' => 'secret', 'is_active' => true, 'status' => 'active',
        ]);

        $this->itemAllowed = Item::create(['name' => 'R2 Flour', 'unit_of_measurement' => 'kg', 'is_active' => true]);
        $this->itemAlt = Item::create(['name' => 'R2 Sugar', 'unit_of_measurement' => 'kg', 'is_active' => true]);

        foreach ([$this->branchA1, $this->branchA2, $this->branchB1] as $b) {
            foreach ([$this->itemAllowed, $this->itemAlt] as $it) {
                BranchItem::create(['branch_id' => $b->id, 'item_id' => $it->id, 'quantity' => 50, 'unit_price' => 10]);
                BranchInventory::create(['branch_id' => $b->id, 'item_id' => $it->id, 'available_quantity' => 50]);
            }
        }

        $this->supplierA = Supplier::create([
            'name' => 'R2 Sup A', 'company_name' => 'R2 Sup A', 'email' => 'r2supa@test.local',
            'phone' => '0500100201', 'password' => 'secret', 'is_active' => true, 'status' => 'online',
        ]);
        $this->supplierB = Supplier::create([
            'name' => 'R2 Sup B', 'company_name' => 'R2 Sup B', 'email' => 'r2supb@test.local',
            'phone' => '0500100202', 'password' => 'secret', 'is_active' => true, 'status' => 'online',
        ]);

        AsabSupplier::withoutGlobalScope('tenant')->create([
            'company_id' => $this->companyA->id, 'brand_id' => $this->brandA->id,
            'name' => 'R2 Sup A', 'status' => 'active',
        ])->forceFill(['legacy_supplier_id' => $this->supplierA->id])->save();
        AsabSupplier::withoutGlobalScope('tenant')->create([
            'company_id' => $this->companyB->id, 'brand_id' => $this->brandB->id,
            'name' => 'R2 Sup B', 'status' => 'active',
        ])->forceFill(['legacy_supplier_id' => $this->supplierB->id])->save();

        foreach ([$this->itemAllowed, $this->itemAlt] as $it) {
            SupplierItem::create([
                'supplier_id' => $this->supplierA->id,
                'item_id' => $it->id,
                'unit_price' => 10,
                'is_available' => true,
            ]);
        }
    }

    private function linkOwner(BrandOwner $owner, AsabCompany $company, AsabBrand $brand): BrandOwner
    {
        $asab = AsabUser::create([
            'company_id' => $company->id, 'name' => $owner->name, 'email' => $owner->email,
            'password' => 'secret', 'status' => 'active',
        ]);
        AsabUserRole::create([
            'user_id' => $asab->id, 'role_key' => 'brand-owner', 'scope' => 'brand', 'brand_ids' => [$brand->id],
        ]);
        AsabIdentityMap::create([
            'entity_type' => AsabIdentityMap::ENTITY_BRAND_OWNER,
            'legacy_type' => 'brand_owner', 'legacy_id' => $owner->id,
            'dashboard_type' => 'asab_user', 'dashboard_id' => $asab->id,
            'company_id' => $company->id, 'match_method' => 'email', 'match_value' => $owner->email,
            'status' => 'matched', 'verified_at' => now(),
        ]);

        return $owner;
    }

    public function test_group_a_a1_to_a10_replay_authorization_fail_closed_contracts(): void
    {
        // A1 & A7: Authorized creation + legitimate replay without duplicating DB rows
        $createKey = (string) Str::uuid();
        $createPayload = [
            'is_draft' => false,
            'direct_supplier' => [[
                'supplier_id' => $this->supplierA->id,
                'quality_level' => 'standard',
                'notification_channels' => ['email'],
                'items' => [['item_id' => $this->itemAllowed->id, 'quantity' => 4]],
            ]],
        ];

        $c1 = $this->actingAs($this->mgrA1, 'sanctum')
            ->withHeader('Idempotency-Key', $createKey)
            ->postJson('/api/v1/purchase/orders', $createPayload)
            ->assertCreated();
        $c2 = $this->actingAs($this->mgrA1, 'sanctum')
            ->withHeader('Idempotency-Key', $createKey)
            ->postJson('/api/v1/purchase/orders', $createPayload)
            ->assertCreated();
        $this->assertSame($c1->json('data.0.id'), $c2->json('data.0.id'));
        $this->assertSame(1, PurchaseOrder::count());

        // A10: Same authorized actor + different payload -> 409 without disclosing snapshot
        $createdId = (string) $c1->json('data.0.id');
        $conflict = $this->actingAs($this->mgrA1, 'sanctum')
            ->withHeader('Idempotency-Key', $createKey)
            ->postJson('/api/v1/purchase/orders', [
                'is_draft' => false,
                'direct_supplier' => [[
                    'supplier_id' => $this->supplierA->id,
                    'quality_level' => 'standard',
                    'notification_channels' => ['email'],
                    'items' => [['item_id' => $this->itemAllowed->id, 'quantity' => 9]],
                ]],
            ])->assertStatus(409);
        $this->assertStringNotContainsString($createdId, $conflict->getContent());

        // A1 + A2 + A3 + A5: Submit draft, replay, then revoke, reassign, and hard-delete
        $order = PurchaseOrder::create([
            'order_number' => 'PO-SUB-A1', 'order_type' => OrderType::DIRECT_SUPPLIER,
            'branch_id' => $this->branchA1->id, 'supplier_id' => $this->supplierA->id,
            'sourceable_type' => Supplier::class, 'sourceable_id' => $this->supplierA->id,
            'requested_by' => $this->mgrA1->id, 'status' => OrderStatus::DRAFT, 'is_draft' => true, 'total_amount' => 10,
        ]);
        $orderId = (string) $order->id;
        $subKey = (string) Str::uuid();
        $s1 = $this->actingAs($this->mgrA1, 'sanctum')
            ->withHeader('Idempotency-Key', $subKey)
            ->postJson("/api/v1/purchase/orders/{$orderId}/submit")
            ->assertOk();
        $s2 = $this->actingAs($this->mgrA1, 'sanctum')
            ->withHeader('Idempotency-Key', $subKey)
            ->postJson("/api/v1/purchase/orders/{$orderId}/submit")
            ->assertOk();
        $this->assertSame($s1->json('data.order_number'), $s2->json('data.order_number'));

        // A2: Revoked user gets 403 and NO protected body snapshot
        $this->mgrA1->update(['is_active' => false]);
        $revoked = $this->actingAs($this->mgrA1->fresh(), 'sanctum')
            ->withHeader('Idempotency-Key', $subKey)
            ->postJson("/api/v1/purchase/orders/{$orderId}/submit")
            ->assertStatus(403);
        $this->assertStringNotContainsString((string) $order->order_number, $revoked->getContent());
        $this->mgrA1->update(['is_active' => true]);

        // A3: Hard-deleted required resource must NOT disclose cached snapshot
        $order->forceDelete();
        $hardDeleted = $this->actingAs($this->mgrA1->fresh(), 'sanctum')
            ->withHeader('Idempotency-Key', $subKey)
            ->postJson("/api/v1/purchase/orders/{$orderId}/submit")
            ->assertStatus(403);
        $this->assertStringNotContainsString((string) $order->order_number, $hardDeleted->getContent());

        // A4: Unmapped protected handler on purchase.chain.branch must fail closed (403), never default true
        Route::middleware(['api', 'auth:sanctum', 'purchase.chain.branch'])
            ->post('api/v1/purchase/unmapped-audit-probe', fn () => response()->json(['leaked' => true]))
            ->name('apilocale.purchase.unmapped.audit-probe');
        $this->actingAs($this->mgrA1->fresh(), 'sanctum')
            ->postJson('/api/v1/purchase/unmapped-audit-probe')
            ->assertStatus(403)
            ->assertJsonMissing(['leaked' => true]);

        // A6: Explicit DELETE soft-delete contract vs non-DELETE soft-deleted blocking + saved-comparisons ownership
        $draft = PurchaseOrder::create([
            'order_number' => 'PO-DEL-1', 'order_type' => OrderType::DIRECT_SUPPLIER,
            'branch_id' => $this->branchA1->id, 'supplier_id' => $this->supplierA->id,
            'sourceable_type' => Supplier::class, 'sourceable_id' => $this->supplierA->id,
            'requested_by' => $this->mgrA1->id, 'status' => OrderStatus::DRAFT, 'is_draft' => true, 'total_amount' => 10,
        ]);
        $delKey = (string) Str::uuid();
        $this->actingAs($this->mgrA1->fresh(), 'sanctum')
            ->withHeader('Idempotency-Key', $delKey)
            ->deleteJson("/api/v1/purchase/orders/{$draft->id}/draft")
            ->assertOk();
        // Same authorized actor replaying DELETE gets cached 200 without re-deleting
        $this->actingAs($this->mgrA1->fresh(), 'sanctum')
            ->withHeader('Idempotency-Key', $delKey)
            ->deleteJson("/api/v1/purchase/orders/{$draft->id}/draft")
            ->assertOk();
        // Non-DELETE route targeting soft-deleted order must FAIL CLOSED 403
        $this->actingAs($this->mgrA1->fresh(), 'sanctum')
            ->postJson("/api/v1/purchase/orders/{$draft->id}/submit")
            ->assertStatus(403);
        // DELETE replay after ownership transfer to branchB1 must FAIL CLOSED 403
        $draft->update(['branch_id' => $this->branchB1->id]);
        $this->actingAs($this->mgrA1->fresh(), 'sanctum')
            ->withHeader('Idempotency-Key', $delKey)
            ->deleteJson("/api/v1/purchase/orders/{$draft->id}/draft")
            ->assertStatus(403);

        // SavedPriceComparison delete replay when moved to branchB1 must FAIL CLOSED 403
        $savedComp = SavedPriceComparison::create([
            'branch_id' => $this->branchA1->id, 'created_by' => $this->mgrA1->id,
            'item_id' => $this->itemAllowed->id, 'item_name' => 'R2 Flour', 'quantity' => 5, 'snapshot' => ['k' => 'v'],
        ]);
        $compKey = (string) Str::uuid();
        $this->actingAs($this->mgrA1->fresh(), 'sanctum')
            ->withHeader('Idempotency-Key', $compKey)
            ->deleteJson("/api/v1/purchase/orders/saved-comparisons/{$savedComp->id}")
            ->assertOk();
        $savedComp->update(['branch_id' => $this->branchB1->id]);
        $this->actingAs($this->mgrA1->fresh(), 'sanctum')
            ->withHeader('Idempotency-Key', $compKey)
            ->deleteJson("/api/v1/purchase/orders/saved-comparisons/{$savedComp->id}")
            ->assertStatus(403);

        // A7 on returns.store: body purchase_order_id belonging to another branch is blocked 403 before replay
        $poB = PurchaseOrder::create([
            'order_number' => 'PO-BR-B', 'order_type' => OrderType::DIRECT_SUPPLIER,
            'branch_id' => $this->branchB1->id, 'supplier_id' => $this->supplierB->id,
            'sourceable_type' => Supplier::class, 'sourceable_id' => $this->supplierB->id,
            'requested_by' => $this->mgrB1->id, 'status' => OrderStatus::CLOSED, 'total_amount' => 10,
        ]);
        $this->actingAs($this->mgrA1->fresh(), 'sanctum')
            ->withHeader('Idempotency-Key', (string) Str::uuid())
            ->postJson('/api/v1/purchase/returns', ['purchase_order_id' => $poB->id])
            ->assertStatus(403);

        // A8: Nested resource authorization (foreign GoodsReceiptItem / PurchaseOrderItem in URL or body)
        $ownPo = PurchaseOrder::create([
            'order_number' => 'PO-NEST-A', 'order_type' => OrderType::DIRECT_SUPPLIER,
            'branch_id' => $this->branchA1->id, 'supplier_id' => $this->supplierA->id,
            'sourceable_type' => Supplier::class, 'sourceable_id' => $this->supplierA->id,
            'requested_by' => $this->mgrA1->id, 'status' => OrderStatus::DRAFT, 'is_draft' => true, 'total_amount' => 10,
        ]);
        $otherItemLine = PurchaseOrderItem::create([
            'purchase_order_id' => $poB->id, 'item_id' => $this->itemAllowed->id, 'item_name' => 'R2 Flour',
            'unit_of_measurement' => 'kg', 'quantity_ordered' => 2, 'unit_price' => 10, 'total_price' => 20, 'status' => 'pending',
        ]);
        $this->actingAs($this->mgrA1->fresh(), 'sanctum')
            ->withHeader('Idempotency-Key', (string) Str::uuid())
            ->putJson("/api/v1/purchase/orders/{$ownPo->id}/items", [
                'items' => [['id' => $otherItemLine->id, 'quantity' => 9]],
            ])->assertStatus(403);
        $this->assertEquals(2.0, (float) $otherItemLine->fresh()->quantity_ordered);
    }

    public function test_group_c_s0_002_003_004_005_007_missing_evidence(): void
    {
        Notification::fake();

        // AUD-S0-002: Invalid bulk, foreign/unauthorized recurring update, zero side effects/notifications
        $beforeOrders = PurchaseOrder::count();
        $beforeInv = (float) BranchInventory::where('branch_id', $this->branchA1->id)->where('item_id', $this->itemAllowed->id)->value('available_quantity');
        $this->actingAs($this->mgrA1, 'sanctum')
            ->postJson('/api/v1/purchase/orders', [
                'branches' => [[
                    'branch_id' => $this->branchA2->id,
                    'items' => [['item_id' => $this->itemAllowed->id, 'quantity' => 3]],
                ]],
                'direct_supplier' => [[
                    'supplier_id' => $this->supplierB->id, // cross-brand in 2nd group
                    'notification_channels' => ['email'],
                    'items' => [['item_id' => $this->itemAllowed->id, 'quantity' => 2]],
                ]],
            ])->assertStatus(403);
        $this->assertSame($beforeOrders, PurchaseOrder::count());
        $this->assertEquals($beforeInv, (float) BranchInventory::where('branch_id', $this->branchA1->id)->where('item_id', $this->itemAllowed->id)->value('available_quantity'));
        Notification::assertNothingSent();

        // Unauthorized recurring order update cannot persist foreign supplier
        $rec = RecurringOrder::create([
            'branch_id' => $this->branchA1->id, 'created_by' => $this->mgrA1->id,
            'order_name' => 'Rec R2', 'order_source_type' => 'direct_supplier',
            'status' => 'pending', 'sourceable_type' => Supplier::class, 'sourceable_id' => $this->supplierA->id,
            'repeat_frequency' => 'weekly', 'start_date' => now()->addDay()->toDateString(),
            'end_type' => 'repeat', 'next_run_at' => now()->addDay(),
        ]);
        $this->actingAs($this->mgrA1, 'sanctum')
            ->putJson("/api/v1/recurring-orders/{$rec->id}", [
                'direct_supplier' => [
                    'supplier_id' => $this->supplierB->id,
                    'items' => [['item_id' => $this->itemAllowed->id, 'quantity' => 2]],
                ],
            ])->assertStatus(403);
        $this->assertSame($this->supplierA->id, (string) $rec->fresh()->sourceable_id);

        // AUD-S0-003: Empty BrandOwner scope, inactive actor, invalid state, wrong recipient across both prefixes
        $emptyOwner = BrandOwner::create([
            'name' => 'Empty Scope', 'email' => 'empty@test.local', 'phone' => '0500100399',
            'password' => 'secret', 'is_active' => true, 'status' => 'active',
        ]);
        $ownerA1 = $this->linkOwner(BrandOwner::create([
            'name' => 'Owner A1', 'email' => 'r2ownera1@test.local', 'phone' => '0500100301',
            'password' => 'secret', 'is_active' => true, 'status' => 'active',
        ]), $this->companyA, $this->brandA);
        $closedPo = PurchaseOrder::create([
            'order_number' => 'PO-R2-RET', 'order_type' => OrderType::DIRECT_SUPPLIER,
            'branch_id' => $this->branchA1->id, 'supplier_id' => $this->supplierA->id,
            'sourceable_type' => Supplier::class, 'sourceable_id' => $this->supplierA->id,
            'requested_by' => $this->mgrA1->id, 'status' => OrderStatus::CLOSED, 'total_amount' => 10,
        ]);
        $draftRet = ReturnOrder::create([
            'return_number' => 'RET-DRAFT', 'purchase_order_id' => $closedPo->id,
            'branch_id' => $this->branchA1->id, 'supplier_id' => $this->supplierA->id,
            'created_by' => $this->mgrA1->id, 'status' => ReturnStatus::DRAFT,
            'required_action' => 'cash_refund', 'escalated_to' => $ownerA1->id, 'total_amount' => 10,
        ]);
        $this->actingAs($emptyOwner, 'sanctum')
            ->postJson("/api/brand-owner/purchase/returns/{$draftRet->id}/approve-escalation")
            ->assertStatus(403);
        $ownerA1->update(['is_active' => false]);
        $this->actingAs($ownerA1->fresh(), 'sanctum')
            ->postJson("/api/v1/brand-owner/purchase/returns/{$draftRet->id}/reject-escalation", ['reason' => 'inactive'])
            ->assertStatus(403);
        $ownerA1->update(['is_active' => true]);
        $this->actingAs($ownerA1->fresh(), 'sanctum')
            ->postJson("/api/v1/brand-owner/purchase/returns/{$draftRet->id}/approve-escalation")
            ->assertStatus(400);
        $this->assertSame(ReturnStatus::DRAFT, $draftRet->fresh()->status);
        $this->assertNull($draftRet->fresh()->resolved_by);

        // AUD-S0-004: Multi-branch AsabUser + unauthorized X-Branch-Id + request-to-request isolation
        $asabMulti = AsabUser::create([
            'company_id' => $this->companyA->id, 'name' => 'Multi Asab', 'email' => 'r2multi@test.local',
            'password' => 'secret', 'status' => 'active',
        ]);
        AsabUserRole::create([
            'user_id' => $asabMulti->id, 'role_key' => 'branch-manager', 'scope' => 'branch',
            'branch_ids' => [$this->branchA1->id, $this->branchA2->id, $this->branchB1->id],
        ]);
        CompanyUser::create([
            'company_id' => $this->companyA->id, 'user_id' => $asabMulti->id, 'role_key' => 'branch-manager', 'status' => 'active',
        ]);
        // X-Branch-Id pointing at branchB1 must NOT expand permissions
        $poB1 = PurchaseOrder::create([
            'order_number' => 'IT-R2-B1', 'order_type' => OrderType::INTERNAL_TRANSFER,
            'branch_id' => $this->branchB1->id, 'from_branch_id' => $this->branchA1->id,
            'sourceable_type' => Branch::class, 'sourceable_id' => $this->branchA1->id,
            'requested_by' => $this->mgrB1->id, 'status' => OrderStatus::FULLY_APPROVED, 'total_amount' => 10,
        ]);
        $this->actingAs($asabMulti, 'sanctum')
            ->withHeader('X-Branch-Id', $this->branchB1->id)
            ->postJson("/api/v1/purchase/receiving/orders/{$poB1->id}/receive-internal-transfer", [])
            ->assertStatus(403);

        // Request 1 uses X-Branch-Id = branchA2; Request 2 without header resets in-memory branch_id cleanly
        $draftA2 = PurchaseOrder::create([
            'order_number' => 'PO-R2-XBR', 'order_type' => OrderType::DIRECT_SUPPLIER,
            'branch_id' => $this->branchA2->id, 'supplier_id' => $this->supplierA->id,
            'sourceable_type' => Supplier::class, 'sourceable_id' => $this->supplierA->id,
            'requested_by' => $this->mgrA2->id, 'status' => OrderStatus::PENDING, 'total_amount' => 10,
        ]);
        $this->actingAs($asabMulti, 'sanctum')
            ->withHeader('X-Branch-Id', $this->branchA2->id)
            ->getJson('/api/v1/purchase/orders')
            ->assertOk();
        $freshAsab = $asabMulti->fresh();
        $this->actingAs($freshAsab, 'sanctum')
            ->getJson("/api/v1/purchase/pending/{$draftA2->id}")
            ->assertOk();

        // AUD-S0-005: Repeat approve-transfer with DIFFERENT idempotency key fails closed 400 (no double deduction)
        $tx = PurchaseOrder::create([
            'order_number' => 'IT-R2-DUP', 'order_type' => OrderType::INTERNAL_TRANSFER,
            'branch_id' => $this->branchA1->id, 'from_branch_id' => $this->branchA2->id,
            'sourceable_type' => Branch::class, 'sourceable_id' => $this->branchA2->id,
            'requested_by' => $this->mgrA1->id, 'status' => OrderStatus::PENDING, 'total_amount' => 50,
        ]);
        PurchaseOrderItem::create([
            'purchase_order_id' => $tx->id, 'item_id' => $this->itemAllowed->id, 'item_name' => 'R2 Flour',
            'unit_of_measurement' => 'kg', 'quantity_ordered' => 5, 'unit_price' => 10, 'total_price' => 50, 'status' => 'pending',
        ]);
        $this->actingAs($this->mgrA2, 'sanctum')
            ->withHeader('Idempotency-Key', (string) Str::uuid())
            ->postJson("/api/v1/purchase/pending/{$tx->id}/approve-transfer", ['action' => 'approve_all', 'ready_time' => '1_hour'])
            ->assertOk();
        $afterFirst = (float) BranchInventory::where('branch_id', $this->branchA2->id)->where('item_id', $this->itemAllowed->id)->value('available_quantity');
        $this->actingAs($this->mgrA2, 'sanctum')
            ->withHeader('Idempotency-Key', (string) Str::uuid())
            ->postJson("/api/v1/purchase/pending/{$tx->id}/approve-transfer", ['action' => 'approve_all', 'ready_time' => '1_hour'])
            ->assertStatus(400);
        $this->assertEquals($afterFirst, (float) BranchInventory::where('branch_id', $this->branchA2->id)->where('item_id', $this->itemAllowed->id)->value('available_quantity'));

        // AUD-S0-007: Representative HTTP endpoint Policy checks across actors/resources
        $cashier = Cashier::factory()->create(['branch_id' => $this->branchA1->id]);
        $this->actingAs($cashier, 'sanctum')->getJson('/api/v1/purchase/orders')->assertStatus(403);
        $this->actingAs($this->mgrA2, 'sanctum')->getJson("/api/v1/purchase/pending/{$draftA2->id}")->assertOk();
        $receiptB = GoodsReceipt::create([
            'receipt_number' => 'GR-R2-B', 'purchase_order_id' => $poB1->id, 'branch_id' => $this->branchB1->id,
            'received_by' => $this->mgrB1->id, 'status' => 'in_progress', 'received_at' => now(),
        ]);
        $this->actingAs($this->mgrA1, 'sanctum')->getJson("/api/v1/purchase/receiving/{$receiptB->id}")->assertStatus(403);
    }

    public function test_aud_s0_002_duplicate_nested_purchase_item_id_is_rejected_atomically(): void
    {
        Notification::fake();

        $order = PurchaseOrder::create([
            'order_number' => 'PO-R3-DUPLICATE', 'order_type' => OrderType::DIRECT_SUPPLIER,
            'branch_id' => $this->branchA1->id, 'supplier_id' => $this->supplierA->id,
            'sourceable_type' => Supplier::class, 'sourceable_id' => $this->supplierA->id,
            'requested_by' => $this->mgrA1->id, 'status' => OrderStatus::DRAFT,
            'is_draft' => true, 'total_amount' => 20,
        ]);
        $line = PurchaseOrderItem::create([
            'purchase_order_id' => $order->id, 'item_id' => $this->itemAllowed->id,
            'item_name' => 'R3 Flour', 'unit_of_measurement' => 'kg',
            'quantity_ordered' => 2, 'unit_price' => 10, 'total_price' => 20, 'status' => 'pending',
        ]);

        $beforeQuantity = (float) $line->quantity_ordered;
        $beforeUnitPrice = (float) $line->unit_price;
        $beforeInventory = (float) BranchInventory::where('branch_id', $this->branchA1->id)
            ->where('item_id', $this->itemAllowed->id)->value('available_quantity');
        $beforeMovements = \Modules\Inventory\Models\InventoryStockMovement::count();

        $this->actingAs($this->mgrA1, 'sanctum')
            ->withHeader('Idempotency-Key', (string) Str::uuid())
            ->putJson("/api/v1/purchase/orders/{$order->id}/items", [
                'items' => [
                    ['id' => $line->id, 'quantity' => 7, 'unit_price' => 12],
                    ['id' => $line->id, 'quantity' => 9, 'unit_price' => 15],
                ],
            ])->assertStatus(422);

        $this->assertEquals($beforeQuantity, (float) $line->fresh()->quantity_ordered);
        $this->assertEquals($beforeUnitPrice, (float) $line->fresh()->unit_price);
        $this->assertEquals($beforeInventory, (float) BranchInventory::where('branch_id', $this->branchA1->id)
            ->where('item_id', $this->itemAllowed->id)->value('available_quantity'));
        $this->assertSame($beforeMovements, \Modules\Inventory\Models\InventoryStockMovement::count());
        Notification::assertNothingSent();
    }
}
