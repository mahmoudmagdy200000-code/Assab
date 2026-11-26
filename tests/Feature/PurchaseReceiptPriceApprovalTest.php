<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Modules\Admin\Models\ApprovalStep;
use Modules\Admin\Models\AsabBrand;
use Modules\Admin\Models\AsabCompany;
use Modules\Admin\Models\AsabUser;
use Modules\Admin\Models\AsabUserRole;
use Modules\Admin\Models\CompanyUser;
use Modules\Admin\Models\Operation;
use Modules\Admin\Services\InventoryReconciliationService;
use Modules\Admin\Services\MonthlyWeightedCostService;
use Modules\Branch\Models\Branch;
use Modules\BranchManagers\Models\BranchManager;
use Modules\Inventory\Models\InventoryMonthlyCost;
use Modules\Inventory\Models\InventoryStockMovement;
use Modules\Purchase\Enums\OrderStatus;
use Modules\Purchase\Enums\OrderType;
use Modules\Purchase\Models\BranchInventory;
use Modules\Purchase\Models\BranchItem;
use Modules\Purchase\Models\GoodsReceipt;
use Modules\Purchase\Models\Item;
use Modules\Purchase\Models\PurchaseOrder;
use Modules\Purchase\Models\PurchaseOrderItem;
use Modules\Purchase\Models\PurchaseVariance;
use Modules\Supplier\Models\Supplier;
use Tests\TestCase;

class PurchaseReceiptPriceApprovalTest extends TestCase
{
    use RefreshDatabase;

    private AsabCompany $company;

    private Branch $branch;

    private BranchManager $manager;

    private AsabUser $accountant;

    private Supplier $supplier;

    private Item $itemA;

    private Item $itemB;

    private Item $itemUnlisted;

    protected function setUp(): void
    {
        parent::setUp();

        $this->company = AsabCompany::create([
            'name' => 'Stage 1 R1 Co',
            'plan' => 'Professional',
            'status' => 'active',
        ]);
        $brand = AsabBrand::create([
            'company_id' => $this->company->id,
            'name' => 'Brand R1',
            'abbr' => 'R1',
            'sub_status' => 'active',
            'status' => 'active',
        ]);
        $this->branch = Branch::factory()->create([
            'asab_company_id' => $this->company->id,
            'asab_brand_id' => $brand->id,
        ]);
        $this->manager = BranchManager::factory()->create(['branch_id' => $this->branch->id]);
        $this->accountant = AsabUser::create([
            'company_id' => $this->company->id,
            'name' => 'R1 Accountant',
            'email' => 'r1-acc@test.local',
            'password' => 'secret123',
            'status' => 'active',
        ]);
        AsabUserRole::create([
            'user_id' => $this->accountant->id,
            'role_key' => 'accountant',
            'scope' => 'all',
        ]);
        CompanyUser::create([
            'user_id' => $this->accountant->id,
            'company_id' => $this->company->id,
            'role_key' => 'accountant',
            'status' => 'active',
        ]);
        $this->supplier = Supplier::create([
            'name' => 'R1 Supplier',
            'email' => 'supplier-r1@test.local',
            'password' => 'secret123',
            'is_active' => true,
        ]);
        $this->itemA = Item::create(['name' => 'Flour A', 'unit' => 'kg', 'is_active' => true]);
        $this->itemB = Item::create(['name' => 'Sugar B', 'unit' => 'kg', 'is_active' => true]);
        $this->itemUnlisted = Item::create(['name' => 'Gift Mint', 'unit' => 'kg', 'is_active' => true]);
        foreach ([
            [$this->itemA, 30.00],
            [$this->itemB, 20.00],
            [$this->itemUnlisted, 10.00],
        ] as [$item, $price]) {
            BranchItem::create([
                'branch_id' => $this->branch->id,
                'item_id' => $item->id,
                'price' => $price,
                'quantity' => 0,
            ]);
        }
    }

    private function createOrderWithItems(array $lines, OrderType $orderType = OrderType::DIRECT_SUPPLIER): array
    {
        $subtotal = 0.0;
        foreach ($lines as $line) {
            $subtotal += $line['qty'] * $line['price'];
        }
        $order = PurchaseOrder::create([
            'order_type' => $orderType,
            'status' => OrderStatus::CONFIRMED,
            'branch_id' => $this->branch->id,
            'requested_by' => $this->manager->id,
            'sourceable_type' => Supplier::class,
            'sourceable_id' => $this->supplier->id,
            'supplier_id' => $this->supplier->id,
            'subtotal' => round($subtotal, 2),
            'tax_rate' => 15.00,
            'tax_amount' => round($subtotal * 0.15, 2),
            'total_amount' => round($subtotal * 1.15, 2),
        ]);

        $orderItems = [];
        foreach ($lines as $line) {
            $item = $line['item'];
            $orderItems[] = PurchaseOrderItem::create([
                'purchase_order_id' => $order->id,
                'item_id' => $item->id,
                'item_name' => $item->name,
                'quantity_ordered' => $line['qty'],
                'quantity_confirmed' => $line['qty'],
                'unit_price' => $line['price'],
                'total_price' => round($line['qty'] * $line['price'], 2),
                'unit_of_measurement' => 'kg',
                'status' => 'confirmed',
            ]);
        }

        return [$order, $orderItems];
    }

    private function procurement(string $scope = 'all', array $scopeIds = [], ?string $company = null): AsabUser
    {
        $actor = AsabUser::create(['company_id' => $company ?? $this->company->id, 'name' => 'Price reviewer',
            'email' => uniqid().'@price.test', 'password' => 'secret123', 'status' => 'active']);
        AsabUserRole::create(array_merge(['user_id' => $actor->id, 'role_key' => 'procurement', 'scope' => $scope], $scopeIds));
        CompanyUser::create(['company_id' => $actor->company_id, 'user_id' => $actor->id,
            'role_key' => 'procurement', 'status' => 'active']);
        return $actor;
    }

    private function receipt(float $received = 10, OrderType $type = OrderType::VIA_PURCHASING_OFFICER): GoodsReceipt
    {
        [$order, [$line]] = $this->createOrderWithItems([['item' => $this->itemA, 'qty' => 10, 'price' => 30]], $type);
        $this->actingAs($this->manager, 'sanctum')->postJson("/api/v1/purchase/receiving/orders/{$order->id}/start", [
            'items' => [['item_id' => $line->id, 'quantity_received' => $received, 'quality' => 'normal']],
            'document_type' => 'delivery_note',
        ])->assertStatus(201);
        $receipt = GoodsReceipt::where('purchase_order_id', $order->id)->firstOrFail();
        $this->invoice($receipt, $received * 35);
        return $receipt;
    }

    private function invoice(GoodsReceipt $receipt, float $amount): void
    {
        $this->actingAs($this->manager, 'sanctum')->postJson("/api/v1/purchase/receiving/{$receipt->id}/invoice", [
            'invoice_number' => 'PRICE-INV-'.$receipt->id, 'invoice_date' => today()->toDateString(),
            'amount_before_tax' => $amount, 'tax_rate' => 15,
        ])->assertStatus(201);
    }

    private function review(GoodsReceipt $receipt, AsabUser $actor): array
    {
        return $this->actingAs($actor, 'sanctum')->getJson("/api/v1/procurement/receiving/{$receipt->id}/price-review")
            ->assertOk()->json();
    }

    private function approve(GoodsReceipt $receipt, AsabUser $actor, ?array $review = null): Operation
    {
        $review ??= $this->review($receipt, $actor);
        $res = $this->actingAs($actor, 'sanctum')->postJson("/api/v1/procurement/receiving/{$receipt->id}/price-approvals", [
            'context_hash' => $review['context_hash'], 'receipt_item_ids' => array_column($review['lines'], 'receipt_item_id'),
            'reason' => 'Reviewed current invoice and available/reserved allocation',
        ])->assertOk();
        return Operation::withoutGlobalScopes()->findOrFail($res->json('id'));
    }

    private function assertValue(GoodsReceipt $receipt, int $value, int $pending): void
    {
        $movements = InventoryStockMovement::where('source_type', 'goods_receipt')->where('source_id', $receipt->id)->get();
        $this->assertSame($value, (int) $movements->sum('total_cost_halalas'));
        $this->assertEquals(10, $movements->sum('qty_delta'));
        $this->assertSame($pending, (int) $receipt->fresh()->pending_cost_delta_halalas);
    }

    public function test_price_production_approval_revoke_replay_and_supersession(): void
    {
        $receipt = $this->receipt();
        $actor = $this->procurement('branch', ['branch_ids' => [$this->branch->id]]);
        $this->assertValue($receipt, 30000, 5000);
        $op = $this->approve($receipt, $actor);
        $this->assertValue($receipt, 35000, 0);
        $step = ApprovalStep::where('operation_id', $op->id)->firstOrFail();
        $this->assertSame($actor->id, $step->actor_user_id);
        $this->assertSame('approve', $step->action);
        $this->assertNotEmpty($step->meta['seal']);
        $this->assertSame($op->id, $this->approve($receipt, $actor)->id);
        $this->assertSame(2, InventoryStockMovement::where('source_id', $receipt->id)->count());
        $this->invoice($receipt, 275);
        $this->assertValue($receipt, 35000, -7500); // new price remains pending; posted authorized value is retained
        $this->assertSame([], app(\Modules\Purchase\Services\ReceiptPriceApprovalService::class)
            ->authorizedLines($receipt->fresh('purchaseOrder'), $receipt->fresh('invoice')->invoice));
        $next = $this->approve($receipt, $actor);
        $this->assertValue($receipt, 27500, 0);
        $this->assertSame(Operation::STATUS_REJECTED, $op->fresh()->status);
        $this->assertSame('superseded', $op->fresh()->reject_reason);
        $this->actingAs($actor, 'sanctum')->postJson("/api/v1/procurement/receiving/{$receipt->id}/price-approvals/{$next->id}/revoke",
            ['reason' => 'Supplier document withdrawn'])->assertOk();
        $this->assertValue($receipt, 30000, -2500);
        $this->invoice($receipt, 275);
        $this->assertValue($receipt, 30000, -2500);
        $this->assertSame(1, ApprovalStep::where('operation_id', $next->id)->where('action', 'revoke')->count());
        // A generic financial endpoint must not give procurement its accountant/head authority.
        $this->actingAs($actor, 'sanctum')->postJson("/api/v1/operations/{$next->id}/approve", [])->assertForbidden();
    }

    public function test_price_rejects_stale_review_and_does_not_resurrect_approval_after_a_b_a(): void
    {
        $receipt = $this->receipt(); $actor = $this->procurement();
        $old = $this->review($receipt, $actor);
        $this->approve($receipt, $actor, $old);
        $this->invoice($receipt, 320);
        $this->invoice($receipt, 350);
        $this->actingAs($actor, 'sanctum')->postJson("/api/v1/procurement/receiving/{$receipt->id}/price-approvals", [
            'context_hash' => $old['context_hash'], 'receipt_item_ids' => array_column($old['lines'], 'receipt_item_id'),
            'reason' => 'Old review',
        ])->assertStatus(409)->assertJsonPath('error.code', 'STALE_PRICE_REVIEW');
        $this->assertSame([], app(\Modules\Purchase\Services\ReceiptPriceApprovalService::class)
            ->authorizedLines($receipt->fresh('purchaseOrder'), $receipt->fresh('invoice')->invoice));
        $this->assertSame(1, Operation::withoutGlobalScopes()->where('source_id', $receipt->id)->count());
    }

    public function test_price_deleted_revoked_forged_and_changed_provenance_cannot_authorize(): void
    {
        $receipt = $this->receipt(); $actor = $this->procurement();
        foreach (['deleted', 'revoked', 'payload', 'step', 'actor', 'scope', 'membership', 'tenant', 'branch', 'superseded'] as $case) {
            // Issue through HTTP first; then tamper persisted state, never seed positive approval evidence.
            $op = $this->approve($receipt, $actor);
            $role = $actor->roleAssignments()->firstOrFail();
            $membership = CompanyUser::where('user_id', $actor->id)->firstOrFail();
            $step = ApprovalStep::where('operation_id', $op->id)->where('action', 'approve')->firstOrFail();
            if ($case === 'deleted') $op->delete();
            if ($case === 'revoked') $op->update(['status' => 'rejected']);
            if ($case === 'payload') { $payload = $op->payload; $payload['reason'] = 'forged'; $op->update(['payload' => $payload]); }
            if ($case === 'step') $step->update(['meta' => ['seal' => str_repeat('0',64)]]);
            if ($case === 'actor') $actor->update(['status' => 'inactive']);
            if ($case === 'scope') $role->update(['scope' => 'branch', 'branch_ids' => []]);
            if ($case === 'membership') $membership->update(['status' => 'inactive']);
            if ($case === 'tenant') $op->update(['company_id' => AsabCompany::create(['name'=>'Other','plan'=>'Professional','status'=>'active'])->id]);
            if ($case === 'branch') $op->update(['branch_id' => Branch::factory()->create(['asab_company_id'=>$this->company->id])->id]);
            if ($case === 'superseded') $op->update(['reject_reason' => 'superseded']);
            $this->invoice($receipt, 350);
            $this->assertValue($receipt, 30000, 5000);
            $actor->update(['status' => 'active']);
            $role->update(['scope' => 'all', 'branch_ids' => []]);
            $membership->update(['status' => 'active']);
            // Invalid current decisions never fall back to any prior approved record.
        }
    }

    public function test_price_empty_restricted_wrong_hierarchy_tenant_and_role_are_denied(): void
    {
        $receipt = $this->receipt();
        $otherCompany = AsabCompany::create(['name'=>'Other tenant','plan'=>'Professional','status'=>'active']);
        $otherBranch = Branch::factory()->create(['asab_company_id'=>$this->company->id]);
        $wrongBrand = AsabBrand::create(['company_id'=>$this->company->id,'name'=>'Other brand','status'=>'active','sub_status'=>'active']);
        $wrongRestaurant = \Modules\Admin\Models\AsabRestaurant::create(['company_id'=>$this->company->id,
            'brand_id'=>$wrongBrand->id,'name'=>'Other restaurant','status'=>'active']);
        foreach ([['branch',[]], ['restaurant',[]], ['brand',[]],
            ['branch',['branch_ids'=>[$otherBranch->id]]], ['brand',['brand_ids'=>[$wrongBrand->id]]],
            ['restaurant',['restaurant_ids'=>[$wrongRestaurant->id]]]] as [$scope,$ids]) {
            $actor = $this->procurement($scope,$ids);
            $this->actingAs($actor,'sanctum')->getJson("/api/v1/procurement/receiving/{$receipt->id}/price-review")->assertForbidden();
        }
        $foreign = $this->procurement('all', [], $otherCompany->id);
        $this->actingAs($foreign,'sanctum')->getJson("/api/v1/procurement/receiving/{$receipt->id}/price-review")->assertForbidden();
        $this->actingAs($this->accountant,'sanctum')->getJson("/api/v1/procurement/receiving/{$receipt->id}/price-review")->assertForbidden();
        $this->assertValue($receipt,30000,5000);
        $this->assertSame(0, Operation::withoutGlobalScopes()->where('source_id',$receipt->id)->count());
    }

    public function test_price_hierarchical_brand_restaurant_and_platform_paths_and_wrong_line_denial(): void
    {
        $receipt=$this->receipt();
        $restaurant=\Modules\Admin\Models\AsabRestaurant::create(['company_id'=>$this->company->id,
            'brand_id'=>$this->branch->asab_brand_id,'name'=>'In scope','status'=>'active']);
        $this->branch->update(['asab_restaurant_id'=>$restaurant->id]);
        foreach ([['brand',['brand_ids'=>[$this->branch->asab_brand_id]]], ['restaurant',['restaurant_ids'=>[$restaurant->id]]]] as [$scope,$ids]) {
            $actor=$this->procurement($scope,$ids);
            $this->review($receipt,$actor);
        }
        $actor=$this->procurement(); $actor->update(['company_id'=>null]);
        $review=$this->review($receipt,$actor);
        $this->actingAs($actor,'sanctum')->postJson("/api/v1/procurement/receiving/{$receipt->id}/price-approvals",[
            'context_hash'=>$review['context_hash'],'receipt_item_ids'=>[(string) \Illuminate\Support\Str::uuid()],
            'reason'=>'Wrong line',
        ])->assertStatus(422);
        $this->approve($receipt,$actor,$review);
        $this->assertValue($receipt,35000,0);
    }

    public function test_price_reserved_excess_stays_reserved_and_direct_supplier_stays_closed(): void
    {
        $receipt=$this->receipt(13); $actor=$this->procurement();
        $this->assertValue($receipt,30000,15500);
        $this->approve($receipt,$actor);
        $this->assertValue($receipt,35000,10500);
        $this->assertSame('pending_excess_approval',$receipt->fresh()->cost_adjustment_status);
        $this->assertEquals(3,$receipt->items()->sum('quantity_excess_reserved'));
        $this->assertEquals(10,BranchInventory::where('branch_id',$this->branch->id)->where('item_id',$this->itemA->id)->value('available_quantity'));
        $direct=$this->receipt(10,OrderType::DIRECT_SUPPLIER);
        $this->actingAs($actor,'sanctum')->getJson("/api/v1/procurement/receiving/{$direct->id}/price-review")
            ->assertForbidden()->assertJsonPath('error.code','APPROVAL_ROLE_PENDING');
        $this->actingAs($actor,'sanctum')->postJson("/api/v1/procurement/receiving/{$direct->id}/price-approvals",[
            'context_hash'=>str_repeat('0',64),'receipt_item_ids'=>[$direct->items()->first()->id], 'reason'=>'Forbidden',
        ])->assertForbidden();
        $this->assertValue($direct,30000,5000);
    }

    public function test_price_cross_receipt_revoke_copied_provenance_and_restricted_platform_are_denied(): void
    {
        $receiptA = $this->receipt();
        $receiptB = $this->receipt();
        $actor = $this->procurement();

        // 1. Cross-receipt revoke must 404 and leave receiptA decision intact
        $opA = $this->approve($receiptA, $actor);
        $this->actingAs($actor, 'sanctum')
            ->postJson("/api/v1/procurement/receiving/{$receiptB->id}/price-approvals/{$opA->id}/revoke", [
                'reason' => 'Cross-receipt revoke attempt',
            ])
            ->assertNotFound();
        $this->assertSame(Operation::STATUS_APPROVED, $opA->fresh()->status);
        $this->assertValue($receiptA, 35000, 0);

        // 2. Copied provenance pointer (pointing receiptB meta to receiptA's valid Operation) must fail closed
        $metaB = $receiptB->fresh()->cost_adjustment_meta ?? [];
        $metaB['price_approval_id'] = $opA->id;
        $receiptB->update(['cost_adjustment_meta' => $metaB]);
        $this->invoice($receiptB, 350);
        $this->assertValue($receiptB, 30000, 5000);

        // 3. Restricted platform actor (company_id = null, branch scope pointing elsewhere) must be denied
        $otherBranch = Branch::factory()->create(['asab_company_id' => $this->company->id]);
        $restrictedPlatform = $this->procurement('branch', ['branch_ids' => [$otherBranch->id]]);
        $restrictedPlatform->update(['company_id' => null]);
        $this->actingAs($restrictedPlatform, 'sanctum')
            ->getJson("/api/v1/procurement/receiving/{$receiptB->id}/price-review")
            ->assertForbidden();
    }
}
