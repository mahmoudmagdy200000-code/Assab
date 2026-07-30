<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Modules\Admin\Models\AsabBrand;
use Modules\Admin\Models\AsabCompany;
use Modules\Admin\Models\Operation;
use Modules\Admin\Services\PurchaseFeedbackBridgeService;
use Modules\Branch\Models\Branch;
use Modules\BranchManagers\Models\BranchManager;
use Modules\Purchase\Enums\OrderStatus;
use Modules\Purchase\Enums\OrderType;
use Modules\Purchase\Models\Item;
use Modules\Purchase\Models\PurchaseOrder;
use Modules\Purchase\Services\PurchaseOrderService;
use Modules\Supplier\Models\Supplier;
use Tests\TestCase;

/**
 * Meeting 2026-07-30 «المشتريات ليست مرتبطة بالمحاسب والفرع»: a mobile purchase
 * order must mint a PUR- operation for the accountant, the head's rejection
 * must land back on the mobile order, and the branch must be able to resend.
 */
class PurchaseOrderBridgeTest extends TestCase
{
    use RefreshDatabase;

    private Branch $branch;

    private BranchManager $manager;

    private Supplier $supplier;

    private Item $pizza;

    protected function setUp(): void
    {
        parent::setUp();

        $companyId = AsabCompany::create(['name' => 'Co', 'plan' => 'Basic', 'status' => 'active'])->id;
        $brand = AsabBrand::create([
            'company_id' => $companyId, 'name' => 'برجر بيت', 'abbr' => 'BB',
            'sub_status' => 'active', 'status' => 'active',
        ]);
        $this->branch = Branch::factory()->create([
            'asab_company_id' => $companyId,
            'asab_brand_id' => $brand->id,
        ]);
        $this->manager = BranchManager::factory()->create(['branch_id' => $this->branch->id]);
        $this->supplier = Supplier::create([
            'name' => 'مورد عصب', 'email' => 'supplier@bridge.test',
            'password' => 'irrelevant-password', 'is_active' => true,
        ]);
        $this->pizza = Item::create(['name' => 'بيتزا', 'unit' => 'kg', 'is_active' => true]);
    }

    private function createOrder(): PurchaseOrder
    {
        $this->actingAs($this->manager, 'sanctum');

        return app(PurchaseOrderService::class)->createOrder([
            'order_type' => OrderType::DIRECT_SUPPLIER->value,
            'branch_id' => $this->branch->id,
            'requested_by' => $this->manager->id,
            'supplier_id' => $this->supplier->id,
            'items' => [[
                'item_id' => $this->pizza->id,
                'quantity' => 10,
                'unit' => 'kg',
                'unit_price' => 50,
            ]],
        ]);
    }

    private function opFor(PurchaseOrder $order): ?Operation
    {
        return Operation::withoutGlobalScopes()
            ->where('source_module', 'purchase')
            ->where('source_id', $order->id)
            ->latest('created_at')
            ->orderByDesc('id')
            ->first();
    }

    public function test_mobile_order_mints_a_pur_operation_for_the_branch(): void
    {
        $order = $this->createOrder();

        $op = $this->opFor($order);
        $this->assertNotNull($op, 'a submitted mobile order must reach the accountant inbox');
        $this->assertSame('purchases', $op->module_key);
        $this->assertSame($this->branch->id, $op->branch_id);
        $this->assertSame('mobile', $op->origin);
        $this->assertStringStartsWith('PUR-', $op->public_id);
        $this->assertSame('بيتزا', $op->payload['purchaseItems'][0]['item']);
        $this->assertSame(5000, $op->payload['purchaseItems'][0]['unitPriceHalalas']);
    }

    public function test_rejection_lands_back_on_the_mobile_order_and_resend_mints_a_new_op(): void
    {
        $order = $this->createOrder();
        $op = $this->opFor($order);

        // Head rejects on the dashboard side.
        $op->update(['status' => Operation::STATUS_REJECTED, 'reject_reason' => 'سعر غير مطابق']);
        app(PurchaseFeedbackBridgeService::class)->syncFromOperation($op->fresh());

        $order->refresh();
        $this->assertSame(OrderStatus::REJECTED, $order->status);
        $this->assertSame('سعر غير مطابق', $order->rejection_reason);

        // Branch edits then resends: rejected → pending is now a legal transition
        // and the bridge mints a NEW pending op superseding the rejected one.
        $this->assertTrue($order->transitionTo(OrderStatus::PENDING));

        $fresh = $this->opFor($order->fresh());
        $this->assertNotSame($op->id, $fresh->id, 'resend must mint a NEW operation — the rejected one is locked');
        $this->assertSame(Operation::STATUS_PENDING, $fresh->status);
        $this->assertSame($op->id, $fresh->payload['supersedesOperationId']);
    }

    public function test_internal_transfer_orders_stay_out_of_the_accountant_inbox(): void
    {
        $this->actingAs($this->manager, 'sanctum');
        $other = Branch::factory()->create(['asab_company_id' => $this->branch->asab_company_id]);

        $order = app(PurchaseOrderService::class)->createOrder([
            'order_type' => OrderType::INTERNAL_TRANSFER->value,
            'branch_id' => $this->branch->id,
            'requested_by' => $this->manager->id,
            'from_branch_id' => $other->id,
            'to_branch_id' => $this->branch->id,
            'items' => [[
                'item_id' => $this->pizza->id,
                'quantity' => 5,
                'unit' => 'kg',
                'unit_price' => 30,
            ]],
        ]);

        $this->assertNull($this->opFor($order));
    }

    public function test_unlinked_branch_is_skipped_loudly_and_backfill_catches_it(): void
    {
        $unlinked = Branch::factory()->create(['asab_company_id' => null, 'asab_brand_id' => null, 'asab_restaurant_id' => null]);
        $manager = BranchManager::factory()->create(['branch_id' => $unlinked->id]);
        $this->actingAs($manager, 'sanctum');

        $order = app(PurchaseOrderService::class)->createOrder([
            'order_type' => OrderType::DIRECT_SUPPLIER->value,
            'branch_id' => $unlinked->id,
            'requested_by' => $manager->id,
            'supplier_id' => $this->supplier->id,
            'items' => [[
                'item_id' => $this->pizza->id,
                'quantity' => 1,
                'unit' => 'kg',
                'unit_price' => 50,
            ]],
        ]);

        $this->assertNull($this->opFor($order), 'unlinked branch must not mint an op');

        // Link the branch, then the backfill sweeps the stranded order in.
        $unlinked->update(['asab_company_id' => $this->branch->asab_company_id]);
        $this->artisan('asab:bridge-backfill')->assertSuccessful();

        $this->assertNotNull($this->opFor($order->fresh()), 'backfill must bridge the stranded order');
    }
}
