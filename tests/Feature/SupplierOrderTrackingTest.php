<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;
use Modules\Admin\Models\AsabCompany;
use Modules\Admin\Models\AsabUser;
use Modules\Branch\Models\Branch;
use Modules\Purchase\Enums\OrderStatus;
use Modules\Purchase\Enums\OrderType;
use Modules\Purchase\Models\PurchaseOrder;
use Modules\Supplier\Models\Supplier;
use Tests\TestCase;

class SupplierOrderTrackingTest extends TestCase
{
    use RefreshDatabase;

    public function test_supplier_can_fetch_tracking_for_own_order_and_is_isolated_from_others(): void
    {
        $company = AsabCompany::create([
            'id' => (string) Str::uuid(),
            'name' => 'Track Test Co',
        ]);

        $branch = Branch::create([
            'id' => (string) Str::uuid(),
            'company_id' => $company->id,
            'name' => 'Main Branch',
            'location' => 'Riyadh',
        ]);

        $supA = Supplier::create([
            'id' => (string) Str::uuid(),
            'name' => 'Supplier A',
            'email' => 'supa-track@example.test',
            'password' => bcrypt('Password123!'),
            'is_active' => true,
        ]);

        $supB = Supplier::create([
            'id' => (string) Str::uuid(),
            'name' => 'Supplier B',
            'email' => 'supb-track@example.test',
            'password' => bcrypt('Password123!'),
            'is_active' => true,
        ]);

        $nonSupplier = AsabUser::create([
            'id' => (string) Str::uuid(),
            'name' => 'BM User',
            'email' => 'bm-track@example.test',
            'password' => bcrypt('Password123!'),
        ]);

        $order = PurchaseOrder::create([
            'id' => (string) Str::uuid(),
            'order_number' => 'PO-TRK-001',
            'company_id' => $company->id,
            'branch_id' => $branch->id,
            'supplier_id' => $supA->id,
            'sourceable_type' => Supplier::class,
            'sourceable_id' => $supA->id,
            'requested_by' => $nonSupplier->id,
            'order_type' => OrderType::DIRECT_SUPPLIER,
            'status' => OrderStatus::PREPARING,
            'priority' => 'normal',
            'subtotal' => 100,
            'tax_amount' => 15,
            'total_amount' => 115,
        ]);

        // Unauthenticated -> 401
        $this->getJson("/api/v1/supplier/orders/{$order->id}/tracking")
            ->assertStatus(401);

        // Non-supplier (AsabUser) -> 403
        Sanctum::actingAs($nonSupplier);
        $this->getJson("/api/v1/supplier/orders/{$order->id}/tracking")
            ->assertStatus(403);

        // Other supplier -> 404 (isolated)
        Sanctum::actingAs($supB);
        $this->getJson("/api/v1/supplier/orders/{$order->id}/tracking")
            ->assertStatus(404);

        // Owning supplier -> 200 with OrderTrackingResource payload
        Sanctum::actingAs($supA);
        $this->getJson("/api/v1/supplier/orders/{$order->id}/tracking")
            ->assertStatus(200)
            ->assertJsonPath('success', true)
            ->assertJsonPath('data.order_id', $order->id);
    }

    public function test_supplier_orders_filter_by_status_and_date_preserves_supplier_isolation(): void
    {
        $company = AsabCompany::create([
            'id' => (string) Str::uuid(),
            'name' => 'Filter Test Co',
        ]);

        $branch = Branch::create([
            'id' => (string) Str::uuid(),
            'company_id' => $company->id,
            'name' => 'Main Branch',
            'location' => 'Riyadh',
        ]);

        $supA = Supplier::create([
            'id' => (string) Str::uuid(),
            'name' => 'Supplier Filter A',
            'email' => 'supa-filter@example.test',
            'password' => bcrypt('Password123!'),
            'is_active' => true,
        ]);

        $supB = Supplier::create([
            'id' => (string) Str::uuid(),
            'name' => 'Supplier Filter B',
            'email' => 'supb-filter@example.test',
            'password' => bcrypt('Password123!'),
            'is_active' => true,
        ]);

        $requester = AsabUser::create([
            'id' => (string) Str::uuid(),
            'name' => 'BM Filter User',
            'email' => 'bm-filter@example.test',
            'password' => bcrypt('Password123!'),
        ]);

        $ownPrep = PurchaseOrder::create([
            'id' => (string) Str::uuid(),
            'order_number' => 'PO-FLT-PREP',
            'company_id' => $company->id,
            'branch_id' => $branch->id,
            'supplier_id' => $supA->id,
            'sourceable_type' => Supplier::class,
            'sourceable_id' => $supA->id,
            'requested_by' => $requester->id,
            'order_type' => OrderType::DIRECT_SUPPLIER,
            'status' => OrderStatus::PREPARING,
            'priority' => 'normal',
            'subtotal' => 100,
            'tax_amount' => 15,
            'total_amount' => 115,
        ]);

        PurchaseOrder::create([
            'id' => (string) Str::uuid(),
            'order_number' => 'PO-FLT-PEND',
            'company_id' => $company->id,
            'branch_id' => $branch->id,
            'supplier_id' => $supA->id,
            'sourceable_type' => Supplier::class,
            'sourceable_id' => $supA->id,
            'requested_by' => $requester->id,
            'order_type' => OrderType::DIRECT_SUPPLIER,
            'status' => OrderStatus::PENDING,
            'priority' => 'normal',
            'subtotal' => 50,
            'tax_amount' => 7.5,
            'total_amount' => 57.5,
        ]);

        PurchaseOrder::create([
            'id' => (string) Str::uuid(),
            'order_number' => 'PO-FLT-OTHER-SUP',
            'company_id' => $company->id,
            'branch_id' => $branch->id,
            'supplier_id' => $supB->id,
            'sourceable_type' => Supplier::class,
            'sourceable_id' => $supB->id,
            'requested_by' => $requester->id,
            'order_type' => OrderType::DIRECT_SUPPLIER,
            'status' => OrderStatus::PREPARING,
            'priority' => 'normal',
            'subtotal' => 200,
            'tax_amount' => 30,
            'total_amount' => 230,
        ]);

        $today = now()->toDateString();
        $token = $supA->createToken('supplier-filter-test')->plainTextToken;
        $res = $this->withToken($token)
            ->getJson("/api/v1/supplier/orders?status=preparing&date_from={$today}&date_to={$today}");
        $res->assertStatus(200)
            ->assertJsonPath('meta.total', 1)
            ->assertJsonPath('data.0.id', $ownPrep->id)
            ->assertJsonPath('data.0.order_number', 'PO-FLT-PREP');
    }
}
