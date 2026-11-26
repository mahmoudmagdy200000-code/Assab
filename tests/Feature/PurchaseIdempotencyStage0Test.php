<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Modules\Admin\Models\AsabBrand;
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
use Modules\Purchase\Models\BranchInventory;
use Modules\Purchase\Models\BranchItem;
use Modules\Purchase\Models\GoodsReceiptItem;
use Modules\Purchase\Models\Item;
use Modules\Purchase\Models\PurchaseOrder;
use Modules\Purchase\Models\PurchaseOrderItem;
use Modules\Purchase\Models\ReturnOrder;
use Modules\RecurringOrder\Models\RecurringOrder;
use Modules\Supplier\Models\Supplier;
use Tests\TestCase;

/**
 * Stage 0 regression suite (T0.4 — PUR-F08 PARTIAL):
 * - Same key + same payload replays response without duplicate write
 * - Same key + different payload returns 409 IDEMPOTENCY_KEY_REUSED
 * - Cross-user / cross-tenant key reuse returns 409 IDEMPOTENCY_KEY_REUSED
 * - Failed request (422) releases reservation so legitimate retry succeeds
 * - Independent operations with distinct keys create distinct records
 * - Legacy callers omitting Idempotency-Key continue to work
 */
class PurchaseIdempotencyStage0Test extends TestCase
{
    use RefreshDatabase;

    private AsabCompany $companyA;

    private Branch $branchA1;

    private Branch $branchA2;

    private Branch $branchB1;

    private BranchManager $bmA1;

    private BranchManager $bmA2;

    private BranchManager $bmB1;

    private Supplier $supplierA;

    private Item $item;

    protected function setUp(): void
    {
        parent::setUp();

        $this->companyA = AsabCompany::create(['name' => 'Idem Co A', 'plan' => 'Professional', 'status' => 'active']);
        $companyB = AsabCompany::create(['name' => 'Idem Co B', 'plan' => 'Professional', 'status' => 'active']);

        $brandA = AsabBrand::create([
            'company_id' => $this->companyA->id, 'name' => 'Brand A', 'sub_status' => 'active', 'status' => 'active',
        ]);
        $brandB = AsabBrand::create([
            'company_id' => $companyB->id, 'name' => 'Brand B', 'sub_status' => 'active', 'status' => 'active',
        ]);

        $this->branchA1 = Branch::factory()->create(['asab_company_id' => $this->companyA->id, 'asab_brand_id' => $brandA->id]);
        $this->branchA2 = Branch::factory()->create(['asab_company_id' => $this->companyA->id, 'asab_brand_id' => $brandA->id]);
        $this->branchB1 = Branch::factory()->create(['asab_company_id' => $companyB->id, 'asab_brand_id' => $brandB->id]);

        $this->bmA1 = BranchManager::factory()->create(['branch_id' => $this->branchA1->id, 'is_active' => true, 'status' => 'active']);
        $this->bmA2 = BranchManager::factory()->create(['branch_id' => $this->branchA2->id, 'is_active' => true, 'status' => 'active']);
        $this->bmB1 = BranchManager::factory()->create(['branch_id' => $this->branchB1->id, 'is_active' => true, 'status' => 'active']);

        $this->supplierA = Supplier::forceCreate([
            'name' => 'Idem Supplier A', 'email' => 'idem-sup-a@test.local', 'is_active' => true,
        ]);
        AsabSupplier::create([
            'company_id' => $this->companyA->id, 'brand_id' => $brandA->id, 'name' => 'Idem Supplier A', 'status' => 'active',
        ])->forceFill(['legacy_supplier_id' => $this->supplierA->id])->save();

        $this->item = Item::create(['name' => 'Oil', 'unit' => 'liter', 'is_active' => true]);

        BranchItem::create(['branch_id' => $this->branchA1->id, 'item_id' => $this->item->id, 'price' => 25, 'quantity' => 50]);
        BranchItem::create(['branch_id' => $this->branchA2->id, 'item_id' => $this->item->id, 'price' => 25, 'quantity' => 50]);
        BranchInventory::create(['branch_id' => $this->branchA2->id, 'item_id' => $this->item->id, 'available_quantity' => 50, 'minimum_stock' => 2]);
    }

    private function orderPayload(float $qty = 4.0): array
    {
        return [
            'is_draft' => false,
            'direct_supplier' => [
                [
                    'supplier_id' => $this->supplierA->id,
                    'quality_level' => 'standard',
                    'notification_channels' => ['email'],
                    'items' => [
                        [
                            'item_id' => $this->item->id,
                            'quantity' => $qty,
                        ],
                    ],
                ],
            ],
        ];
    }

    public function test_purchase_order_creation_enforces_replay_payload_conflict_cross_tenant_and_retry(): void
    {
        $key1 = (string) Str::uuid();
        $payload = $this->orderPayload(4.0);

        // 1. Same key + same payload replays first response without creating duplicate order
        $first = $this->actingAs($this->bmA1, 'sanctum')
            ->withHeader('Idempotency-Key', $key1)
            ->postJson('/api/v1/purchase/orders', $payload)
            ->assertCreated();

        $replay = $this->actingAs($this->bmA1, 'sanctum')
            ->withHeader('Idempotency-Key', $key1)
            ->postJson('/api/v1/purchase/orders', $payload)
            ->assertCreated();

        $this->assertSame($first->json('data.0.id'), $replay->json('data.0.id'));
        $this->assertSame(1, PurchaseOrder::count());

        // 2. Same key + different payload returns 409 IDEMPOTENCY_KEY_REUSED
        $conflict = $this->actingAs($this->bmA1, 'sanctum')
            ->withHeader('Idempotency-Key', $key1)
            ->postJson('/api/v1/purchase/orders', $this->orderPayload(9.0))
            ->assertStatus(409);
        $this->assertSame('IDEMPOTENCY_KEY_REUSED', $conflict->json('code'));
        $this->assertSame(1, PurchaseOrder::count());

        // 3a. Cross-tenant actor reusing key1 with Tenant A's supplier is rejected 403 BEFORE idempotency replay
        $this->actingAs($this->bmB1, 'sanctum')
            ->withHeader('Idempotency-Key', $key1)
            ->postJson('/api/v1/purchase/orders', $payload)
            ->assertStatus(403);
        $this->assertSame(1, PurchaseOrder::count());

        // 3b. Different authorized actor reusing key1 gets 409 IDEMPOTENCY_KEY_REUSED without response disclosure
        $crossActor = $this->actingAs($this->bmA2, 'sanctum')
            ->withHeader('Idempotency-Key', $key1)
            ->postJson('/api/v1/purchase/orders', $payload)
            ->assertStatus(409);
        $this->assertSame('IDEMPOTENCY_KEY_REUSED', $crossActor->json('code'));
        $this->assertSame(1, PurchaseOrder::count());

        // 4. Failed validation (422) releases reservation so legitimate retry with same key succeeds
        $retryKey = (string) Str::uuid();
        $this->actingAs($this->bmA1, 'sanctum')
            ->withHeader('Idempotency-Key', $retryKey)
            ->postJson('/api/v1/purchase/orders', ['is_draft' => false])
            ->assertStatus(422);

        $this->actingAs($this->bmA1, 'sanctum')
            ->withHeader('Idempotency-Key', $retryKey)
            ->postJson('/api/v1/purchase/orders', $payload)
            ->assertCreated();
        $this->assertSame(2, PurchaseOrder::count());

        // 5. Independent operation with new key succeeds, and unkeyed request (legacy client compatibility) succeeds
        $this->actingAs($this->bmA1, 'sanctum')
            ->withHeader('Idempotency-Key', (string) Str::uuid())
            ->postJson('/api/v1/purchase/orders', $this->orderPayload(2.0))
            ->assertCreated();

        $this->flushHeaders();
        $this->actingAs($this->bmA1, 'sanctum')
            ->postJson('/api/v1/purchase/orders', $this->orderPayload(3.0))
            ->assertCreated();
        $this->assertSame(4, PurchaseOrder::count());
    }

    public function test_internal_transfer_returns_and_recurring_orders_deduplicate_replays(): void
    {
        $transfer = PurchaseOrder::factory()->create([
            'order_number' => 'TR-'.strtoupper(Str::random(8)),
            'order_type' => OrderType::INTERNAL_TRANSFER,
            'status' => OrderStatus::PENDING,
            'branch_id' => $this->branchA1->id,
            'to_branch_id' => $this->branchA1->id,
            'from_branch_id' => $this->branchA2->id,
            'requested_by' => $this->bmA1->id,
        ]);
        $orderItem = PurchaseOrderItem::factory()->create([
            'purchase_order_id' => $transfer->id,
            'item_id' => $this->item->id,
            'item_name' => $this->item->name,
            'status' => OrderItemStatus::PENDING,
            'quantity_ordered' => 5,
            'unit_price' => 25,
            'total_price' => 125,
        ]);

        $transferKey = (string) Str::uuid();
        $this->actingAs($this->bmA2, 'sanctum')
            ->withHeader('Idempotency-Key', $transferKey)
            ->postJson("/api/v1/purchase/pending/{$transfer->id}/approve-transfer", [
                'action' => 'approve_all',
                'ready_time' => '1_hour',
            ])
            ->assertOk();

        // Replay must return 200 (not 400 already approved) and deduct stock once
        $this->actingAs($this->bmA2, 'sanctum')
            ->withHeader('Idempotency-Key', $transferKey)
            ->postJson("/api/v1/purchase/pending/{$transfer->id}/approve-transfer", [
                'action' => 'approve_all',
                'ready_time' => '1_hour',
            ])
            ->assertOk();
        $this->assertSame(1, InventoryStockMovement::where('source_id', $transfer->id)->count());

        // Return order idempotency
        $deliveredOrder = PurchaseOrder::factory()->create([
            'order_number' => 'PO-'.strtoupper(Str::random(8)),
            'order_type' => OrderType::DIRECT_SUPPLIER,
            'status' => OrderStatus::CONFIRMED,
            'branch_id' => $this->branchA1->id,
            'requested_by' => $this->bmA1->id,
            'supplier_id' => $this->supplierA->id,
        ]);
        $deliveredItem = PurchaseOrderItem::factory()->create([
            'purchase_order_id' => $deliveredOrder->id,
            'item_id' => $this->item->id,
            'item_name' => $this->item->name,
            'status' => OrderItemStatus::CONFIRMED,
            'quantity_ordered' => 5,
            'quantity_confirmed' => 5,
            'unit_price' => 25,
        ]);

        // Record receipt through the production receiving flow so a return is
        // backed by posted inventory, not only a confirmed order quantity.
        $receiptResponse = $this->actingAs($this->bmA1, 'sanctum')
            ->withHeader('Idempotency-Key', (string) Str::uuid())
            ->postJson("/api/v1/purchase/receiving/orders/{$deliveredOrder->id}/start", [
                'items' => [[
                    'item_id' => $deliveredItem->id,
                    'quantity_received' => 5,
                    'quality' => 'normal',
                ]],
                'document_type' => 'receipt_without_document',
            ]);
        $receiptResponse->assertCreated();

        $postedReceiptItem = GoodsReceiptItem::where('purchase_order_item_id', $deliveredItem->id)->firstOrFail();
        $this->assertSame(5.0, (float) $postedReceiptItem->quantity_available_posted);

        // Return creation is intentionally limited to closed purchase orders.
        $deliveredOrder->refresh()->update(['status' => OrderStatus::CLOSED]);

        $returnKey = (string) Str::uuid();
        $returnPayload = [
            'purchase_order_id' => $deliveredOrder->id,
            'required_action' => 'replacement',
            'items' => [
                [
                    'purchase_order_item_id' => $deliveredItem->id,
                    'return_quantity' => 2,
                    'quality_reason' => 'poor',
                ],
            ],
        ];
        $this->actingAs($this->bmA1, 'sanctum')->withHeader('Idempotency-Key', $returnKey)->postJson('/api/v1/purchase/returns', $returnPayload)->assertCreated();
        $this->actingAs($this->bmA1, 'sanctum')->withHeader('Idempotency-Key', $returnKey)->postJson('/api/v1/purchase/returns', $returnPayload)->assertCreated();
        $this->assertSame(1, ReturnOrder::count());

        // Recurring order idempotency
        $recKey = (string) Str::uuid();
        $recPayload = [
            'order_name' => 'Weekly Oil',
            'direct_supplier' => [
                'supplier_id' => $this->supplierA->id,
                'items' => [
                    [
                        'item_id' => $this->item->id,
                        'quantity' => 5,
                    ],
                ],
            ],
            'repeat_frequency' => 'weekly',
            'repeat_config' => [
                'repeat_days' => [1],
            ],
            'scheduling_time_am' => '09:00',
            'start_date' => now()->addDay()->toDateString(),
            'end_type' => 'repeat',
        ];
        $this->actingAs($this->bmA1, 'sanctum')->withHeader('Idempotency-Key', $recKey)->postJson('/api/v1/recurring-orders', $recPayload)->assertCreated();
        $this->actingAs($this->bmA1, 'sanctum')->withHeader('Idempotency-Key', $recKey)->postJson('/api/v1/recurring-orders', $recPayload)->assertCreated();
        $this->assertSame(1, RecurringOrder::count());

        // Platform procurement approve idempotency
        $procUser = AsabUser::create([
            'company_id' => $this->companyA->id, 'name' => 'Proc', 'email' => 'proc-idem@test.local',
            'password' => 'secret-password', 'status' => 'active',
        ]);
        AsabUserRole::create(['user_id' => $procUser->id, 'role_key' => 'procurement', 'scope' => 'all']);
        CompanyUser::create([
            'company_id' => $this->companyA->id, 'user_id' => $procUser->id, 'role_key' => 'procurement', 'status' => 'active',
        ]);

        $pendingOrder = PurchaseOrder::factory()->create([
            'order_number' => 'PO-'.strtoupper(Str::random(8)),
            'order_type' => OrderType::DIRECT_SUPPLIER,
            'status' => OrderStatus::PENDING,
            'branch_id' => $this->branchA1->id,
            'submitted_at' => now(),
        ]);
        PurchaseOrderItem::factory()->create([
            'purchase_order_id' => $pendingOrder->id,
            'item_id' => $this->item->id,
            'status' => OrderItemStatus::PENDING,
            'quantity_ordered' => 5,
        ]);

        $procKey = (string) Str::uuid();
        $this->actingAs($procUser, 'sanctum')
            ->withHeader('Idempotency-Key', $procKey)
            ->postJson("/api/v1/procurement/purchase-orders/{$pendingOrder->id}/approve")
            ->assertOk();
        $this->actingAs($procUser, 'sanctum')
            ->withHeader('Idempotency-Key', $procKey)
            ->postJson("/api/v1/procurement/purchase-orders/{$pendingOrder->id}/approve")
            ->assertOk();
    }
}
