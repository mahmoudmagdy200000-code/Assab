<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Modules\Admin\Models\AsabBrand;
use Modules\Admin\Models\AsabCompany;
use Modules\Admin\Models\AsabUser;
use Modules\Admin\Models\AsabUserRole;
use Modules\Admin\Models\CompanyUser;
use Modules\Admin\Models\Employee;
use Modules\Admin\Models\EmployeeMovement;
use Modules\Admin\Models\Operation;
use Modules\Admin\Services\ConsumptionImportService;
use Modules\Admin\Services\MonthlyWeightedCostService;
use Modules\Admin\Services\OperationSequence;
use Modules\Admin\Services\WasteKpiService;
use Modules\Branch\Models\Branch;
use Modules\Inventory\Models\InternalTransferVariance;
use Modules\Inventory\Models\InventoryMonthlyCost;
use Modules\Inventory\Models\InventoryStockMovement;
use Modules\Purchase\Enums\OrderStatus;
use Modules\Purchase\Enums\OrderType;
use Modules\Purchase\Models\BranchInventory;
use Modules\Purchase\Models\BranchItem;
use Modules\Purchase\Models\Item;
use Modules\Purchase\Models\PurchaseOrder;
use Modules\Purchase\Models\PurchaseOrderItem;
use Tests\TestCase;

class InventoryV35ClosureTest extends TestCase
{
    use RefreshDatabase;

    private AsabCompany $company;

    private AsabBrand $brandA;

    private AsabBrand $brandB;

    private Branch $branchA;

    private Branch $branchB;

    private Branch $branchOtherBrand;

    private AsabUser $accountant;

    private AsabUser $head;

    private AsabUser $ownerA;

    private AsabUser $managerA;

    private AsabUser $managerB;

    private Item $flour;

    private Item $cup;

    private Employee $empA;

    protected function setUp(): void
    {
        parent::setUp();

        $this->company = AsabCompany::create(['name' => 'ASSAB Test Co', 'plan' => 'Professional', 'status' => 'active']);
        $this->brandA = AsabBrand::create(['company_id' => $this->company->id, 'name' => 'Brand A', 'sub_status' => 'active', 'status' => 'active']);
        $this->brandB = AsabBrand::create(['company_id' => $this->company->id, 'name' => 'Brand B', 'sub_status' => 'active', 'status' => 'active']);

        $this->branchA = Branch::factory()->create(['name' => 'BR-A', 'asab_brand_id' => $this->brandA->id, 'asab_company_id' => $this->company->id]);
        $this->branchB = Branch::factory()->create(['name' => 'BR-B', 'asab_brand_id' => $this->brandA->id, 'asab_company_id' => $this->company->id]);
        $this->branchOtherBrand = Branch::factory()->create(['name' => 'BR-C', 'asab_brand_id' => $this->brandB->id, 'asab_company_id' => $this->company->id]);

        $this->accountant = $this->makeUser('acc@v35.test', 'accountant', null);
        $this->head = $this->makeUser('head@v35.test', 'head', null);
        $this->ownerA = $this->makeUser('ownerA@v35.test', 'brand-owner', $this->brandA->id);
        $this->managerA = $this->makeUser('mgrA@v35.test', 'branch-manager', $this->branchA->id);
        $this->managerB = $this->makeUser('mgrB@v35.test', 'branch-manager', $this->branchB->id);

        $this->flour = Item::create(['name' => 'SKU-FLOUR', 'unit_of_measurement' => 'kg']);
        $this->cup = Item::create(['name' => 'SKU-CUP', 'unit_of_measurement' => 'ea']);

        foreach ([$this->branchA, $this->branchB] as $b) {
            BranchItem::create(['branch_id' => $b->id, 'item_id' => $this->flour->id, 'quantity' => 50, 'unit_price' => 10]);
            BranchInventory::create(['branch_id' => $b->id, 'item_id' => $this->flour->id, 'available_quantity' => 50]);
            BranchItem::create(['branch_id' => $b->id, 'item_id' => $this->cup->id, 'quantity' => 100, 'unit_price' => 2]);
            BranchInventory::create(['branch_id' => $b->id, 'item_id' => $this->cup->id, 'available_quantity' => 100]);
        }

        $this->empA = Employee::create([
            'company_id' => $this->company->id,
            'branch_id' => $this->branchA->id,
            'emp_number' => '2001',
            'name' => 'عامل أ',
            'role' => 'كاشير',
            'status' => 'active',
        ]);
    }

    private function makeUser(string $email, string $roleKey, ?string $scopeId): AsabUser
    {
        $u = AsabUser::create([
            'company_id' => $this->company->id,
            'branch_id' => $roleKey === 'branch-manager' ? $scopeId : null,
            'name' => $email,
            'email' => $email,
            'password' => 'secret-password',
            'status' => 'active',
        ]);
        AsabUserRole::create([
            'user_id' => $u->id,
            'role_key' => $roleKey,
            'scope' => $scopeId ? ($roleKey === 'brand-owner' ? 'brand' : 'branch') : 'all',
            'brand_ids' => $scopeId && $roleKey === 'brand-owner' ? [$scopeId] : null,
            'branch_ids' => $scopeId && $roleKey === 'branch-manager' ? [$scopeId] : null,
        ]);
        CompanyUser::create([
            'company_id' => $this->company->id,
            'user_id' => $u->id,
            'role_key' => $roleKey,
            'status' => 'active',
        ]);

        return $u;
    }

    public function test_inv04_transfer_routes_and_variance_closure_enforce_receiver_only_and_idempotency(): void
    {
        // Route ordering fix: no 404 on internal-transfer, no 500 on branches without item_id
        $this->actingAs($this->managerA, 'sanctum')
            ->getJson('/api/v1/purchase/pending/internal-transfer')
            ->assertOk();

        $this->actingAs($this->managerA, 'sanctum')
            ->getJson('/api/v1/purchase/orders/branches')
            ->assertOk();

        // Monthly cost at sender branch (branchB): 12.00 SAR (1200 halalas)
        app(MonthlyWeightedCostService::class)->recalculate(
            $this->company->id,
            $this->branchB->id,
            $this->flour->id,
            now()->format('Y-m'),
            50,
            60000,
            0,
            0,
            0,
            0,
            InventoryMonthlyCost::STATUS_APPROVED,
            $this->accountant->id
        );

        $order = PurchaseOrder::create([
            'order_number' => 'IT-V35-001',
            'order_type' => OrderType::INTERNAL_TRANSFER,
            'branch_id' => $this->branchA->id,
            'from_branch_id' => $this->branchB->id,
            'sourceable_type' => Branch::class,
            'sourceable_id' => $this->branchB->id,
            'requested_by' => $this->managerA->id,
            'status' => OrderStatus::PENDING,
            'total_amount' => 100,
        ]);
        $poItem = PurchaseOrderItem::create([
            'purchase_order_id' => $order->id,
            'item_id' => $this->flour->id,
            'item_name' => 'دقيق',
            'unit_of_measurement' => 'kg',
            'quantity_ordered' => 10,
            'unit_price' => 15, // order price differs from sender cost; sender cost 1200 halalas must win
            'total_price' => 150,
            'status' => 'pending',
        ]);

        // Sender branch approves transfer -> deducts 10 from branchB, opens IN_TRANSIT at 1200 halalas
        $this->actingAs($this->managerB, 'sanctum')
            ->postJson("/api/v1/purchase/pending/{$order->id}/approve-transfer", [
                'action' => 'approve_all',
                'ready_time' => '1_hour',
            ])
            ->assertOk();

        $this->assertEquals(40.0, (float) BranchInventory::where('branch_id', $this->branchB->id)->where('item_id', $this->flour->id)->value('available_quantity'));
        $variance = InternalTransferVariance::where('purchase_order_id', $order->id)->sole();
        $this->assertSame('IN_TRANSIT', $variance->status);
        $this->assertSame(1200, (int) $variance->sender_unit_cost_halalas);
        $this->assertEquals(10.0, (float) $variance->in_transit_qty);

        // Receiver branch receives partial qty = 7 -> 3 remains IN_TRANSIT
        $this->actingAs($this->managerA, 'sanctum')
            ->postJson("/api/v1/purchase/receiving/orders/{$order->id}/receive-internal-transfer", [
                'items' => [['purchase_order_item_id' => $poItem->id, 'quantity_received' => 7]],
            ])->assertCreated();

        $this->assertEquals(57.0, (float) BranchInventory::where('branch_id', $this->branchA->id)->where('item_id', $this->flour->id)->value('available_quantity'));
        $variance->refresh();
        $this->assertSame('IN_TRANSIT', $variance->status);
        $this->assertEquals(3.0, (float) $variance->in_transit_qty);
        $this->assertSame(OrderStatus::VARIANCE, $order->fresh()->status);

        // Sender branch CANNOT close variance (403)
        $this->actingAs($this->managerB, 'sanctum')
            ->postJson("/api/v1/purchase/receiving/orders/{$order->id}/close-transfer-variance", [
                'resolution' => 'returned_to_sender',
                'reason' => 'unauthorized',
            ])->assertStatus(403);

        // Receiving branch manager closes remaining 3 as returned_to_sender
        $this->actingAs($this->managerA, 'sanctum')
            ->postJson("/api/v1/purchase/receiving/orders/{$order->id}/close-transfer-variance", [
                'resolution' => 'returned_to_sender',
                'reason' => 'تمت إعادة 3 كجم للفرع المرسل',
                'idempotency_key' => 'close-v35-1',
            ])->assertOk()
            ->assertJsonPath('data.order_status', 'closed');

        $this->assertEquals(43.0, (float) BranchInventory::where('branch_id', $this->branchB->id)->where('item_id', $this->flour->id)->value('available_quantity'));
        $this->assertSame('CLOSED', $variance->fresh()->status);

        // Re-closing returns 409 NO_OPEN_TRANSFER_VARIANCE (idempotent, no double stock restore)
        $this->actingAs($this->managerA, 'sanctum')
            ->postJson("/api/v1/purchase/receiving/orders/{$order->id}/close-transfer-variance", [
                'resolution' => 'returned_to_sender',
            ])->assertStatus(409);
    }

    public function test_waste_kpi_consumption_import_template_and_monthly_cost_gate(): void
    {
        $templatePath = base_path('tests/Fixtures/06-CONSUMPTION-TEMPLATE.xlsx');
        $this->assertFileExists($templatePath);

        $tmpCopy = sys_get_temp_dir().'/consumption_test_'.uniqid().'.xlsx';
        copy($templatePath, $tmpCopy);
        $upload = new UploadedFile($tmpCopy, '06-CONSUMPTION-TEMPLATE.xlsx', 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet', null, true);

        $previewRes = $this->actingAs($this->accountant, 'sanctum')
            ->post('/api/v1/accountant/consumption-imports/preview', ['file' => $upload], ['Accept' => 'application/json'])
            ->assertOk();
        $this->assertSame('PREVIEW', $previewRes->json('status'), json_encode($previewRes->json('validation_errors'), JSON_UNESCAPED_UNICODE));

        $batchId = $previewRes->json('id');

        $this->actingAs($this->accountant, 'sanctum')
            ->postJson("/api/v1/accountant/consumption-imports/{$batchId}/confirm")
            ->assertOk()
            ->assertJsonPath('status', 'CONFIRMED');

        // Before monthly cost approval -> KPI must be unavailable
        $kpiBefore = $this->actingAs($this->accountant, 'sanctum')
            ->getJson("/api/v1/accountant/waste/kpi-references?branchId={$this->branchA->id}&dateFrom=2026-11-01&dateTo=2026-11-02")
            ->assertOk();
        $this->assertFalse($kpiBefore->json('branches.0.available'));

        // Approve monthly cost for both items in 2026-11
        foreach ([$this->flour->id => 1000, $this->cup->id => 200] as $itemId => $unitCostHalalas) {
            app(MonthlyWeightedCostService::class)->recalculate(
                $this->company->id,
                $this->branchA->id,
                $itemId,
                '2026-11',
                10,
                10 * $unitCostHalalas,
                0,
                0,
                0,
                0,
                InventoryMonthlyCost::STATUS_APPROVED,
                $this->accountant->id
            );
        }

        // Record a final-approved waste_out movement posted on 2026-11-01
        InventoryStockMovement::create([
            'idempotency_key' => 'kpi-waste-1',
            'company_id' => $this->company->id,
            'branch_id' => $this->branchA->id,
            'item_id' => $this->flour->id,
            'movement_type' => 'waste_out',
            'qty_delta' => -2.0,
            'unit_cost_halalas' => 1000,
            'total_cost_halalas' => 2000,
            'source_type' => 'waste',
            'posted_at' => '2026-11-01 14:00:00',
            'created_by_id' => $this->head->id,
        ]);

        $kpiAfter = $this->actingAs($this->accountant, 'sanctum')
            ->getJson("/api/v1/accountant/waste/kpi-references?branchId={$this->branchA->id}&dateFrom=2026-11-01&dateTo=2026-11-02")
            ->assertOk();

        $this->assertTrue($kpiAfter->json('branches.0.available'));
        $this->assertSame(2000, $kpiAfter->json('branches.0.finalWasteCostHalalas'));
        $this->assertGreaterThan(0, $kpiAfter->json('branches.0.normalConsumptionCostHalalas'));
        $this->assertNotNull($kpiAfter->json('branches.0.wasteRatePct'));

        @unlink($tmpCopy);
    }

    public function test_rbac01_brand_owner_isolated_from_other_brand_branches(): void
    {
        $this->actingAs($this->ownerA, 'sanctum')
            ->getJson("/api/v1/brand-owner/inventory/daily-requests?branch_id={$this->branchOtherBrand->id}")
            ->assertStatus(403);
    }

    public function test_inv02a_and_inv02d_weighted_cost_and_consumption_revision_rules(): void
    {
        $costSvc = app(MonthlyWeightedCostService::class);
        // Opening 10kg @ 20 SAR + Purchases 10kg @ 30 SAR = 500 SAR / 20kg = 25 SAR (2500 halalas)
        $cost = $costSvc->recalculate(
            $this->company->id,
            $this->branchA->id,
            $this->flour->id,
            '2026-10',
            10.0,
            20000,
            10.0,
            30000,
            0.0,
            0,
            InventoryMonthlyCost::STATUS_APPROVED,
            $this->accountant->id
        );
        $this->assertSame(2500, (int) $cost->unit_cost_halalas);

        // Next month with zero purchases keeps opening unit cost (2500 halalas)
        $nextMonth = $costSvc->recalculate(
            $this->company->id,
            $this->branchA->id,
            $this->flour->id,
            '2026-11',
            5.0,
            12500,
            0.0,
            0
        );
        $this->assertSame(2500, (int) $nextMonth->unit_cost_halalas);
        $this->assertSame(InventoryMonthlyCost::STATUS_PROVISIONAL, $nextMonth->status);

        // INV-02D: revision & content_hash rules
        $impSvc = app(ConsumptionImportService::class);
        $cov1 = [[
            'branch_code' => 'BR-A',
            'business_date' => '2026-10-05',
            'revision' => 1,
            'day_status' => 'COMPLETE',
            'includes_waste' => 'TRUE',
            'source_ref' => 'pos-1',
        ]];
        $cons1 = [
            ['branch_code' => 'BR-A', 'business_date' => '2026-10-05', 'item_code' => 'SKU-FLOUR', 'unit_code' => 'KG', 'consumed_qty' => 4, 'included_waste_qty' => 0],
            ['branch_code' => 'BR-A', 'business_date' => '2026-10-05', 'item_code' => 'SKU-CUP', 'unit_code' => 'EA', 'consumed_qty' => 0, 'included_waste_qty' => 0],
        ];

        $b1 = $impSvc->previewParsedRows($cov1, $cons1, 'r1.xlsx', 'h1', $this->company->id, null, $this->accountant);
        $this->assertSame('PREVIEW', $b1->status);
        $this->actingAs($this->accountant, 'sanctum')->postJson("/api/v1/accountant/consumption-imports/{$b1->id}/confirm")->assertOk();

        // Idempotent replay of exact same rev=1 & content
        $b1Replay = $impSvc->previewParsedRows($cov1, $cons1, 'r1.xlsx', 'h1', $this->company->id, null, $this->accountant);
        $this->actingAs($this->accountant, 'sanctum')->postJson("/api/v1/accountant/consumption-imports/{$b1Replay->id}/confirm")->assertOk();

        // Same rev=1 with modified qty -> 409 Conflict
        $cons1Modified = [
            ['branch_code' => 'BR-A', 'business_date' => '2026-10-05', 'item_code' => 'SKU-FLOUR', 'unit_code' => 'KG', 'consumed_qty' => 9, 'included_waste_qty' => 0],
            ['branch_code' => 'BR-A', 'business_date' => '2026-10-05', 'item_code' => 'SKU-CUP', 'unit_code' => 'EA', 'consumed_qty' => 0, 'included_waste_qty' => 0],
        ];
        $b1Conflict = $impSvc->previewParsedRows($cov1, $cons1Modified, 'r1c.xlsx', 'h1c', $this->company->id, null, $this->accountant);
        $this->actingAs($this->accountant, 'sanctum')->postJson("/api/v1/accountant/consumption-imports/{$b1Conflict->id}/confirm")->assertStatus(409);

        // Valid rev=2 supersedes rev=1 cleanly
        $cov2 = $cov1;
        $cov2[0]['revision'] = 2;
        $b2 = $impSvc->previewParsedRows($cov2, $cons1Modified, 'r2.xlsx', 'h2', $this->company->id, null, $this->accountant);
        $this->actingAs($this->accountant, 'sanctum')->postJson("/api/v1/accountant/consumption-imports/{$b2->id}/confirm")->assertOk();
    }

    public function test_inv03c_late_receipt_standing_variance_and_3rd_branch_forbidden(): void
    {
        $thirdBranch = Branch::factory()->create(['name' => 'BR-3', 'asab_brand_id' => $this->brandA->id, 'asab_company_id' => $this->company->id]);
        $mgr3 = $this->makeUser('mgr3@v35.test', 'branch-manager', $thirdBranch->id);

        foreach (['late_receipt', 'standing_variance'] as $idx => $resolution) {
            $order = PurchaseOrder::create([
                'order_number' => 'IT-V35-RES-'.$idx,
                'order_type' => OrderType::INTERNAL_TRANSFER,
                'branch_id' => $this->branchA->id,
                'from_branch_id' => $this->branchB->id,
                'sourceable_type' => Branch::class,
                'sourceable_id' => $this->branchB->id,
                'requested_by' => $this->managerA->id,
                'status' => OrderStatus::PENDING,
                'total_amount' => 250,
            ]);
            $poItem = PurchaseOrderItem::create([
                'purchase_order_id' => $order->id,
                'item_id' => $this->flour->id,
                'item_name' => 'SKU-FLOUR',
                'unit_of_measurement' => 'kg',
                'quantity_ordered' => 10,
                'unit_price' => 25,
                'total_price' => 250,
                'status' => 'pending',
            ]);

            $this->actingAs($this->managerB, 'sanctum')
                ->postJson("/api/v1/purchase/pending/{$order->id}/approve-transfer", ['action' => 'approve_all', 'ready_time' => '1_hour'])
                ->assertOk();

            $this->actingAs($this->managerA, 'sanctum')
                ->postJson("/api/v1/purchase/receiving/orders/{$order->id}/receive-internal-transfer", [
                    'items' => [['purchase_order_item_id' => $poItem->id, 'quantity_received' => 8]],
                ])->assertCreated();

            // 3rd branch manager MUST be rejected 403
            $this->actingAs($mgr3, 'sanctum')
                ->postJson("/api/v1/purchase/receiving/orders/{$order->id}/close-transfer-variance", ['resolution' => $resolution])
                ->assertStatus(403);

            $beforeA = (float) BranchInventory::where('branch_id', $this->branchA->id)->where('item_id', $this->flour->id)->value('available_quantity');
            $res = $this->actingAs($this->managerA, 'sanctum')
                ->postJson("/api/v1/purchase/receiving/orders/{$order->id}/close-transfer-variance", [
                    'resolution' => $resolution,
                    'reason' => 'حسم فرق '.$resolution,
                    'evidence_path' => 'evidence/'.$resolution.'.jpg',
                    'idempotency_key' => 'idem-'.$resolution,
                ])->assertOk();

            $afterA = (float) BranchInventory::where('branch_id', $this->branchA->id)->where('item_id', $this->flour->id)->value('available_quantity');
            if ($resolution === 'late_receipt') {
                $this->assertEquals($beforeA + 2.0, $afterA);
            } else {
                $this->assertEquals($beforeA, $afterA);
                $this->assertDatabaseHas('internal_transfer_variances', [
                    'purchase_order_id' => $order->id,
                    'resolution' => 'standing_variance',
                    'status' => 'CLOSED',
                    'variance_qty' => 2,
                ]);
            }

            // Replaying with the exact same idempotency_key returns idempotent_replay without double-effect
            $this->actingAs($this->managerA, 'sanctum')
                ->postJson("/api/v1/purchase/receiving/orders/{$order->id}/close-transfer-variance", [
                    'resolution' => $resolution,
                    'idempotency_key' => 'idem-'.$resolution,
                ])->assertOk()->assertJsonPath('data.idempotent_replay', true);
            $this->assertEquals($afterA, (float) BranchInventory::where('branch_id', $this->branchA->id)->where('item_id', $this->flour->id)->value('available_quantity'));
        }
    }

    public function test_inv04a_inv04b_and_waste01cd_lifecycle_and_kpi_edge_cases(): void
    {
        // INV-04A: monthly compare (-30% vs missing prior month -> comparisonAvailable=false)
        Operation::create([
            'public_id' => OperationSequence::next('INV'),
            'company_id' => $this->company->id,
            'branch_id' => $this->branchA->id,
            'module_key' => 'inventory',
            'source_module' => 'monthly_inventory',
            'amount' => 0,
            'match' => 'exact',
            'origin' => 'mobile',
            'status' => Operation::STATUS_FINAL,
            'operation_date' => now()->subMonth(),
            'payload' => ['countType' => 'monthly', 'items' => [['itemId' => $this->flour->id, 'name' => 'SKU-FLOUR', 'actualQty' => 10]]],
        ]);
        $currMonthly = Operation::create([
            'public_id' => OperationSequence::next('INV'),
            'company_id' => $this->company->id,
            'branch_id' => $this->branchA->id,
            'module_key' => 'inventory',
            'source_module' => 'monthly_inventory',
            'amount' => 0,
            'match' => 'exact',
            'origin' => 'mobile',
            'status' => Operation::STATUS_PENDING,
            'operation_date' => now(),
            'payload' => ['countType' => 'monthly', 'revision' => 1, 'items' => [
                ['itemId' => $this->flour->id, 'name' => 'SKU-FLOUR', 'actualQty' => 7],
                ['itemId' => $this->cup->id, 'name' => 'SKU-CUP', 'actualQty' => 20],
            ]],
        ]);

        $ov = $this->actingAs($this->accountant, 'sanctum')->getJson('/api/v1/accountant/inventory?countType=monthly')->assertOk();
        $items = collect($ov->json('branches.0.items'))->keyBy('itemId');
        $this->assertEquals(-30.0, (float) $items[$this->flour->id]['changePct']);
        $this->assertTrue($items[$this->flour->id]['comparisonAvailable']);
        $this->assertNull($items[$this->cup->id]['prevQty']);
        $this->assertFalse($items[$this->cup->id]['comparisonAvailable']);

        // INV-04B: Final approval posts stock adjustment once and creates zero EmployeeMovement for operational variance
        $this->actingAs($this->accountant, 'sanctum')->postJson("/api/v1/company/me/operations/{$currMonthly->id}/approve")->assertOk();
        $this->actingAs($this->head, 'sanctum')->postJson("/api/v1/company/me/operations/{$currMonthly->id}/final-approve")->assertOk();
        $this->assertEquals(7.0, (float) BranchInventory::where('branch_id', $this->branchA->id)->where('item_id', $this->flour->id)->value('available_quantity'));
        $this->assertSame(0, EmployeeMovement::where('ref_operation_id', $currMonthly->id)->count());

        // WASTE-01C/D: changing responsibility bumps revision -> stale owner approval (409) until re-approved; finalApprove deducts stock once
        BranchInventory::where('branch_id', $this->branchA->id)->where('item_id', $this->flour->id)->update(['available_quantity' => 10]);
        $wasteOp = Operation::create([
            'public_id' => OperationSequence::next('WST'),
            'company_id' => $this->company->id,
            'branch_id' => $this->branchA->id,
            'module_key' => 'waste',
            'source_module' => 'waste_damage_report',
            'amount' => 2000,
            'match' => 'exact',
            'origin' => 'mobile',
            'status' => Operation::STATUS_PENDING,
            'operation_date' => now(),
            'payload' => [
                'revision' => 1,
                'ownerApproval' => ['approved' => true, 'revision' => 1],
                'products' => [[
                    'itemId' => $this->flour->id,
                    'name' => 'SKU-FLOUR',
                    'qty' => 2,
                    'unitPriceHalalas' => 1000,
                    'value' => 2000,
                    'classification' => 'هدر',
                    'responsibility' => 'مطعم',
                ]],
            ],
        ]);

        // Reclassify to موظف -> bumps revision to 2, invalidating rev 1 ownerApproval
        $this->actingAs($this->accountant, 'sanctum')
            ->patchJson("/api/v1/company/me/waste/{$wasteOp->id}/products/0", ['responsibility' => 'موظف'])
            ->assertOk()
            ->assertJsonPath('revision', 2);

        $this->actingAs($this->accountant, 'sanctum')
            ->putJson("/api/v1/company/me/waste/{$wasteOp->id}/products/0/allocations", [
                'empAllocs' => [['employeeId' => $this->empA->id, 'amountHalalas' => 2000]],
            ])->assertOk()->assertJsonPath('revision', 3);

        // Stale owner approval (rev 1 != rev 3) blocked with 409
        $this->actingAs($this->accountant, 'sanctum')->postJson("/api/v1/company/me/waste/{$wasteOp->id}/approve")->assertStatus(409);

        // Owner approves revision 3 -> Accountant approves (still 10kg stock & 0 debit) -> Head final-approves (8kg stock & 2000 debit)
        $p = $wasteOp->fresh()->payload;
        $p['ownerApproval'] = ['approved' => true, 'revision' => 3];
        $wasteOp->update(['payload' => $p]);

        $this->actingAs($this->accountant, 'sanctum')->postJson("/api/v1/company/me/waste/{$wasteOp->id}/approve")->assertOk();
        $this->assertEquals(10.0, (float) BranchInventory::where('branch_id', $this->branchA->id)->where('item_id', $this->flour->id)->value('available_quantity'));
        $this->assertSame(0, EmployeeMovement::where('ref_operation_id', $wasteOp->id)->count());

        $this->actingAs($this->head, 'sanctum')->postJson("/api/v1/company/me/operations/{$wasteOp->id}/final-approve")->assertOk();
        $this->assertEquals(8.0, (float) BranchInventory::where('branch_id', $this->branchA->id)->where('item_id', $this->flour->id)->value('available_quantity'));
        $this->assertSame(2000, (int) EmployeeMovement::where('ref_operation_id', $wasteOp->id)->sum('amount'));

        // WASTE-KPI-A: PARTIAL day -> unavailable; COMPLETE days with 0 waste -> 0%
        $impSvc = app(ConsumptionImportService::class);
        $partialCov = [[
            'branch_code' => 'BR-A', 'business_date' => '2026-09-01', 'revision' => 1,
            'day_status' => 'PARTIAL', 'includes_waste' => 'FALSE', 'source_ref' => 'p-1',
        ]];
        $partialLines = [['branch_code' => 'BR-A', 'business_date' => '2026-09-01', 'item_code' => 'SKU-FLOUR', 'unit_code' => 'KG', 'consumed_qty' => 5, 'included_waste_qty' => 0]];
        $bp = $impSvc->previewParsedRows($partialCov, $partialLines, 'p.xlsx', 'hp', $this->company->id, null, $this->accountant);
        $impSvc->confirm($bp->id, $this->company->id, $this->accountant);

        app(MonthlyWeightedCostService::class)->recalculate($this->company->id, $this->branchA->id, $this->flour->id, '2026-09', 10, 10000, 0, 0, 0, 0, InventoryMonthlyCost::STATUS_APPROVED, $this->accountant->id);
        app(MonthlyWeightedCostService::class)->recalculate($this->company->id, $this->branchA->id, $this->cup->id, '2026-09', 10, 2000, 0, 0, 0, 0, InventoryMonthlyCost::STATUS_APPROVED, $this->accountant->id);

        $kpiPartial = app(WasteKpiService::class)->calculateForBranch($this->company->id, $this->branchA->id, '2026-09-01', '2026-09-01');
        $this->assertFalse($kpiPartial['available']);

        $completeCov = [[
            'branch_code' => 'BR-A', 'business_date' => '2026-09-02', 'revision' => 1,
            'day_status' => 'COMPLETE', 'includes_waste' => 'FALSE', 'source_ref' => 'c-1',
        ]];
        $completeLines = [
            ['branch_code' => 'BR-A', 'business_date' => '2026-09-02', 'item_code' => 'SKU-FLOUR', 'unit_code' => 'KG', 'consumed_qty' => 5, 'included_waste_qty' => 0],
            ['branch_code' => 'BR-A', 'business_date' => '2026-09-02', 'item_code' => 'SKU-CUP', 'unit_code' => 'EA', 'consumed_qty' => 0, 'included_waste_qty' => 0],
        ];
        $bc = $impSvc->previewParsedRows($completeCov, $completeLines, 'c.xlsx', 'hc', $this->company->id, null, $this->accountant);
        $impSvc->confirm($bc->id, $this->company->id, $this->accountant);

        $kpiZero = app(WasteKpiService::class)->calculateForBranch($this->company->id, $this->branchA->id, '2026-09-02', '2026-09-02');
        $this->assertTrue($kpiZero['available']);
        $this->assertEquals(0.0, (float) $kpiZero['wasteRatePct']);
    }

    public function test_mahmoud_review_point1_inventory_approved_first_then_consumption_and_zero_day(): void
    {
        $date = '2026-11-10';
        $invOp = Operation::create([
            'public_id' => OperationSequence::next('INV'),
            'company_id' => $this->company->id,
            'branch_id' => $this->branchA->id,
            'module_key' => 'inventory',
            'amount' => 0,
            'match' => 'exact',
            'origin' => 'mobile',
            'status' => Operation::STATUS_PENDING,
            'operation_date' => $date,
            'payload' => [
                'countType' => 'daily',
                'items' => [[
                    'itemId' => $this->flour->id,
                    'name' => 'دقيق',
                    'opening' => 10,
                    'received' => 4,
                    'transfers' => 0,
                    'actualQty' => 14,
                    'unitPriceHalalas' => 1000,
                ]],
            ],
        ]);

        // Inventory can be approved & final-approved even while consumption is missing
        $this->actingAs($this->accountant, 'sanctum')->postJson("/api/v1/company/me/operations/{$invOp->id}/approve")->assertOk();
        $this->actingAs($this->head, 'sanctum')->postJson("/api/v1/company/me/operations/{$invOp->id}/final-approve")->assertOk();

        // Missing consumption -> awaiting_consumption, varianceQty = null
        $snapMissing = $this->actingAs($this->accountant, 'sanctum')
            ->getJson("/api/v1/accountant/inventory/branches/{$this->branchA->id}/daily-reconciliation?date={$date}")
            ->assertOk();
        $this->assertTrue($snapMissing->json('awaitingConsumption'));
        $this->assertSame('awaiting_consumption', $snapMissing->json('consumptionStatus'));
        $this->assertSame('بانتظار اكتمال الاستهلاك', $snapMissing->json('consumptionStatusAr'));
        $this->assertNull($snapMissing->json('items.0.varianceQty'));

        // Partial consumption -> still awaiting_consumption
        $impSvc = app(ConsumptionImportService::class);
        $bPartial = $impSvc->previewParsedRows(
            [['branch_code' => 'BR-A', 'business_date' => $date, 'revision' => 1, 'day_status' => 'PARTIAL', 'includes_waste' => 'FALSE', 'source_ref' => 'p-rev1']],
            [
                ['branch_code' => 'BR-A', 'business_date' => $date, 'item_code' => 'SKU-FLOUR', 'unit_code' => 'KG', 'consumed_qty' => 2, 'included_waste_qty' => 0],
                ['branch_code' => 'BR-A', 'business_date' => $date, 'item_code' => 'SKU-CUP', 'unit_code' => 'EA', 'consumed_qty' => 0, 'included_waste_qty' => 0],
            ],
            'p.xlsx',
            'hp-rev1',
            $this->company->id,
            null,
            $this->accountant
        );
        $impSvc->confirm($bPartial->id, $this->company->id, $this->accountant);

        $snapPartial = $this->actingAs($this->accountant, 'sanctum')
            ->getJson("/api/v1/accountant/inventory/branches/{$this->branchA->id}/daily-reconciliation?date={$date}")
            ->assertOk();
        $this->assertTrue($snapPartial->json('awaitingConsumption'));
        $this->assertNull($snapPartial->json('items.0.varianceQty'));

        // Explicit zero consumption with COMPLETE day_status -> computes final variance cleanly
        $bComplete = $impSvc->previewParsedRows(
            [['branch_code' => 'BR-A', 'business_date' => $date, 'revision' => 2, 'day_status' => 'COMPLETE', 'includes_waste' => 'FALSE', 'source_ref' => 'c-rev2']],
            [
                ['branch_code' => 'BR-A', 'business_date' => $date, 'item_code' => 'SKU-FLOUR', 'unit_code' => 'KG', 'consumed_qty' => 0, 'included_waste_qty' => 0],
                ['branch_code' => 'BR-A', 'business_date' => $date, 'item_code' => 'SKU-CUP', 'unit_code' => 'EA', 'consumed_qty' => 0, 'included_waste_qty' => 0],
            ],
            'c.xlsx',
            'hc-rev2',
            $this->company->id,
            null,
            $this->accountant
        );
        $impSvc->confirm($bComplete->id, $this->company->id, $this->accountant);

        $snapDone = $this->actingAs($this->accountant, 'sanctum')
            ->getJson("/api/v1/accountant/inventory/branches/{$this->branchA->id}/daily-reconciliation?date={$date}")
            ->assertOk();
        $this->assertFalse($snapDone->json('awaitingConsumption'));
        $this->assertSame('complete', $snapDone->json('consumptionStatus'));
        $this->assertEquals(0.0, (float) $snapDone->json('items.0.consumed'));
        $this->assertEquals(0.0, (float) $snapDone->json('items.0.varianceQty'));
        $this->assertSame('c-rev2', $snapDone->json('consumptionSourceRef'));
    }

    public function test_mahmoud_review_point2_waste_approved_after_count_before_inventory_final_preserves_8_units(): void
    {
        BranchInventory::where('branch_id', $this->branchA->id)->where('item_id', $this->flour->id)->update(['available_quantity' => 10]);
        $countedAt = now()->subMinutes(10);

        $invOp = Operation::create([
            'public_id' => OperationSequence::next('INV'),
            'company_id' => $this->company->id,
            'branch_id' => $this->branchA->id,
            'module_key' => 'inventory',
            'amount' => 0,
            'match' => 'exact',
            'origin' => 'mobile',
            'status' => Operation::STATUS_PENDING,
            'operation_date' => now()->toDateString(),
            'payload' => [
                'countType' => 'daily',
                'countedAt' => $countedAt->toIso8601String(),
                'items' => [[
                    'itemId' => $this->flour->id,
                    'name' => 'دقيق',
                    'opening' => 10,
                    'received' => 0,
                    'consumed' => 0,
                    'transfers' => 0,
                    'actualQty' => 10,
                    'unitPriceHalalas' => 1000,
                ]],
            ],
        ]);

        // Waste of 2 units is final-approved BEFORE inventory final approval -> stock becomes 8
        $wasteOp = Operation::create([
            'public_id' => OperationSequence::next('WST'),
            'company_id' => $this->company->id,
            'branch_id' => $this->branchA->id,
            'module_key' => 'waste',
            'source_module' => 'waste_damage_report',
            'amount' => 2000,
            'match' => 'exact',
            'origin' => 'mobile',
            'status' => Operation::STATUS_PENDING,
            'operation_date' => now(),
            'payload' => [
                'revision' => 1,
                'products' => [[
                    'itemId' => $this->flour->id,
                    'name' => 'دقيق',
                    'qty' => 2,
                    'unitPriceHalalas' => 1000,
                    'value' => 2000,
                    'classification' => 'هدر',
                    'responsibility' => 'مطعم',
                ]],
            ],
        ]);
        $this->actingAs($this->accountant, 'sanctum')->postJson("/api/v1/company/me/waste/{$wasteOp->id}/approve")->assertOk();
        $this->actingAs($this->head, 'sanctum')->postJson("/api/v1/company/me/operations/{$wasteOp->id}/final-approve")->assertOk();
        $this->assertEquals(8.0, (float) BranchInventory::where('branch_id', $this->branchA->id)->where('item_id', $this->flour->id)->value('available_quantity'));

        // Now final-approve inventory (counted 10 before the 2 waste) -> stock must STAY 8!
        $this->actingAs($this->accountant, 'sanctum')->postJson("/api/v1/company/me/operations/{$invOp->id}/approve")->assertOk();
        $this->actingAs($this->head, 'sanctum')->postJson("/api/v1/company/me/operations/{$invOp->id}/final-approve")->assertOk();
        $this->assertEquals(8.0, (float) BranchInventory::where('branch_id', $this->branchA->id)->where('item_id', $this->flour->id)->value('available_quantity'));

        $snap = $this->actingAs($this->accountant, 'sanctum')
            ->getJson("/api/v1/accountant/inventory/branches/{$this->branchA->id}/daily-reconciliation?date=".now()->toDateString())
            ->assertOk();
        $this->assertEquals(2.0, (float) $snap->json('items.0.waste'));
    }

    public function test_mahmoud_review_point3_accountant_only_post_approval_inventory_correction(): void
    {
        BranchInventory::where('branch_id', $this->branchA->id)->where('item_id', $this->flour->id)->update(['available_quantity' => 10]);
        $invOp = Operation::create([
            'public_id' => OperationSequence::next('INV'),
            'company_id' => $this->company->id,
            'branch_id' => $this->branchA->id,
            'module_key' => 'inventory',
            'amount' => 0,
            'match' => 'exact',
            'origin' => 'mobile',
            'status' => Operation::STATUS_FINAL,
            'operation_date' => '2026-11-12',
            'payload' => [
                'countType' => 'daily',
                'items' => [[
                    'itemId' => $this->flour->id,
                    'name' => 'دقيق',
                    'opening' => 10,
                    'received' => 0,
                    'consumed' => 0,
                    'transfers' => 0,
                    'actualQty' => 10,
                    'unitPriceHalalas' => 1000,
                ]],
            ],
        ]);

        // Head Accountant cannot correct (accountant only)
        $this->actingAs($this->head, 'sanctum')
            ->postJson("/api/v1/accountant/inventory/operations/{$invOp->id}/correct", [
                'items' => [['itemId' => $this->flour->id, 'actualQty' => 9]],
            ])->assertStatus(403);

        // Authorized accountant corrects 10 -> 9 -> posts -1 stocktake_correction once and logs audit trail
        $res = $this->actingAs($this->accountant, 'sanctum')
            ->postJson("/api/v1/accountant/inventory/operations/{$invOp->id}/correct", [
                'items' => [['itemId' => $this->flour->id, 'actualQty' => 9, 'reason' => 'تصحيح خطأ عد']],
            ])->assertOk();

        $this->assertSame(1, count($res->json('correctionHistory')));
        $this->assertEquals(10.0, (float) $res->json('correctionHistory.0.oldQty'));
        $this->assertEquals(9.0, (float) $res->json('correctionHistory.0.newQty'));
        $this->assertSame($this->accountant->id, $res->json('correctionHistory.0.correctedById'));
        $this->assertEquals(9.0, (float) BranchInventory::where('branch_id', $this->branchA->id)->where('item_id', $this->flour->id)->value('available_quantity'));
        $this->assertSame(1, InventoryStockMovement::where('operation_id', $invOp->id)->where('movement_type', 'stocktake_correction')->count());
    }

    public function test_mahmoud_review_point4_waste_freezes_weighted_cost_on_approval_and_employee_charge(): void
    {
        $ym = now()->format('Y-m');
        app(MonthlyWeightedCostService::class)->recalculate(
            $this->company->id,
            $this->branchA->id,
            $this->flour->id,
            $ym,
            10,
            25000, // 25.00 SAR (2500 halalas/kg)
            0,
            0,
            0,
            0,
            InventoryMonthlyCost::STATUS_APPROVED,
            $this->accountant->id
        );

        $wasteOp = Operation::create([
            'public_id' => OperationSequence::next('WST'),
            'company_id' => $this->company->id,
            'branch_id' => $this->branchA->id,
            'module_key' => 'waste',
            'source_module' => 'waste_damage_report',
            'amount' => 2000,
            'match' => 'exact',
            'origin' => 'mobile',
            'status' => Operation::STATUS_PENDING,
            'operation_date' => now(),
            'payload' => [
                'revision' => 1,
                'ownerApproval' => ['approved' => true, 'revision' => 1],
                'products' => [[
                    'itemId' => $this->flour->id,
                    'name' => 'دقيق',
                    'qty' => 2,
                    'unitPriceHalalas' => 1000, // stale payload price; frozen weighted cost 2500 must win
                    'value' => 2000,
                    'classification' => 'هدر',
                    'responsibility' => 'موظف',
                    'empAllocs' => [['employeeId' => $this->empA->id, 'employeeName' => 'EMP A', 'amountHalalas' => 2000]],
                ]],
            ],
        ]);

        $this->actingAs($this->accountant, 'sanctum')->postJson("/api/v1/company/me/waste/{$wasteOp->id}/approve")->assertOk();
        $this->actingAs($this->head, 'sanctum')->postJson("/api/v1/company/me/operations/{$wasteOp->id}/final-approve")->assertOk();

        // Both stock movement AND employee debit use frozen 2500 * 2 = 5000 halalas
        $this->assertSame(5000, (int) InventoryStockMovement::where('operation_id', $wasteOp->id)->where('movement_type', 'waste_out')->value('total_cost_halalas'));
        $this->assertSame(5000, (int) EmployeeMovement::where('ref_operation_id', $wasteOp->id)->sum('amount'));

        // Later purchase changes monthly weighted cost to 40.00 SAR (4000 halalas) -> frozen waste & charge stay 5000!
        app(MonthlyWeightedCostService::class)->recalculate(
            $this->company->id,
            $this->branchA->id,
            $this->flour->id,
            $ym,
            10,
            25000,
            20,
            95000,
            0,
            0,
            InventoryMonthlyCost::STATUS_APPROVED,
            $this->accountant->id
        );

        $this->assertSame(5000, (int) InventoryStockMovement::where('operation_id', $wasteOp->id)->where('movement_type', 'waste_out')->value('total_cost_halalas'));
        $this->assertSame(5000, (int) EmployeeMovement::where('ref_operation_id', $wasteOp->id)->sum('amount'));
    }

    public function test_mahmoud_review_point5_multi_item_transfer_idempotency_and_receiver_branch_manager_only(): void
    {
        $order = PurchaseOrder::create([
            'order_number' => 'IT-MULTI-001',
            'order_type' => OrderType::INTERNAL_TRANSFER,
            'branch_id' => $this->branchA->id,
            'from_branch_id' => $this->branchB->id,
            'sourceable_type' => Branch::class,
            'sourceable_id' => $this->branchB->id,
            'requested_by' => $this->managerA->id,
            'status' => OrderStatus::PENDING,
            'total_amount' => 200,
        ]);
        $item1 = PurchaseOrderItem::create([
            'purchase_order_id' => $order->id,
            'item_id' => $this->flour->id,
            'item_name' => 'دقيق',
            'unit_of_measurement' => 'kg',
            'quantity_ordered' => 10,
            'unit_price' => 10,
            'total_price' => 100,
            'status' => 'pending',
        ]);
        $item2 = PurchaseOrderItem::create([
            'purchase_order_id' => $order->id,
            'item_id' => $this->cup->id,
            'item_name' => 'أكواب',
            'unit_of_measurement' => 'piece',
            'quantity_ordered' => 10,
            'unit_price' => 10,
            'total_price' => 100,
            'status' => 'pending',
        ]);

        $this->actingAs($this->managerB, 'sanctum')
            ->postJson("/api/v1/purchase/pending/{$order->id}/approve-transfer", [
                'action' => 'approve_all',
                'ready_time' => '1_hour',
            ])->assertOk();

        $this->actingAs($this->managerA, 'sanctum')
            ->postJson("/api/v1/purchase/receiving/orders/{$order->id}/receive-internal-transfer", [
                'items' => [
                    ['purchase_order_item_id' => $item1->id, 'quantity_received' => 8],
                    ['purchase_order_item_id' => $item2->id, 'quantity_received' => 7],
                ],
            ])->assertCreated();

        // Accountant (not BranchManager) is rejected 403
        $this->actingAs($this->accountant, 'sanctum')
            ->postJson("/api/v1/purchase/receiving/orders/{$order->id}/close-transfer-variance", [
                'resolution' => 'late_receipt',
                'idempotency_key' => 'multi-idem-1',
            ])->assertStatus(403);

        // Receiver BranchManager closes multi-item variance with idempotency_key -> succeeds & replays cleanly
        $this->actingAs($this->managerA, 'sanctum')
            ->postJson("/api/v1/purchase/receiving/orders/{$order->id}/close-transfer-variance", [
                'resolution' => 'late_receipt',
                'reason' => 'وصول متأخر للكميات المتبقية',
                'idempotency_key' => 'multi-idem-1',
            ])->assertOk()
            ->assertJsonCount(2, 'data.closed_variance_ids');

        $replay = $this->actingAs($this->managerA, 'sanctum')
            ->postJson("/api/v1/purchase/receiving/orders/{$order->id}/close-transfer-variance", [
                'resolution' => 'late_receipt',
                'idempotency_key' => 'multi-idem-1',
            ])->assertOk();
        $this->assertTrue((bool) $replay->json('data.idempotent_replay'));
        $this->assertSame(4, InventoryStockMovement::where('movement_type', 'transfer_in')->count());
    }
}
