<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Modules\Admin\Models\AsabBrand;
use Modules\Admin\Models\AsabCompany;
use Modules\Admin\Models\AsabRestaurant;
use Modules\Admin\Models\AsabSupplier;
use Modules\Admin\Models\AsabUser;
use Modules\Admin\Models\AsabUserRole;
use Modules\Admin\Models\CompanyUser;
use Modules\Admin\Models\Operation;
use Modules\Branch\Models\Branch;
use Modules\Purchase\Models\BranchInventory;
use Modules\Purchase\Models\BranchItem;
use Modules\Purchase\Models\Item;
use Modules\Purchase\Services\PurchaseOrderService;
use Modules\Purchase\Transformers\BranchItemResource;
use Modules\Supplier\Models\Supplier;
use Modules\Supplier\Models\SupplierProduct;
use Tests\TestCase;

class PurchaseUatBlockerRegressionsTest extends TestCase
{
    use RefreshDatabase;

    private AsabCompany $company;

    private AsabUser $accountant;

    private Branch $branch;

    private function seedAccountant(): void
    {
        $this->company = AsabCompany::create([
            'id' => (string) Str::uuid(),
            'name' => 'UAT Regression Co',
            'status' => 'active',
        ]);

        $this->branch = Branch::create([
            'id' => (string) Str::uuid(),
            'name' => 'UAT Regression Branch',
            'code' => 'UAT-REG-1',
            'location' => 'Riyadh',
            'status' => 'active',
            'asab_company_id' => $this->company->id,
        ]);

        $this->accountant = AsabUser::create([
            'id' => (string) Str::uuid(),
            'name' => 'UAT Accountant',
            'email' => 'acc.reg@localuat.test',
            'password' => 'Secret#2026!',
            'company_id' => $this->company->id,
            'is_active' => true,
            'status' => 'active',
        ]);

        AsabUserRole::create([
            'user_id' => $this->accountant->id,
            'role_key' => 'accountant',
            'scope_type' => 'company',
            'scope_id' => $this->company->id,
        ]);

        CompanyUser::create([
            'id' => (string) Str::uuid(),
            'company_id' => $this->company->id,
            'user_id' => $this->accountant->id,
            'role_key' => 'accountant',
            'status' => 'active',
        ]);
    }

    private function makePurchaseOp(string $documentType): Operation
    {
        return Operation::create([
            'id' => (string) Str::uuid(),
            'public_id' => 'PUR-REG-'.Str::upper(Str::random(5)),
            'company_id' => $this->company->id,
            'branch_id' => $this->branch->id,
            'module_key' => 'purchases',
            'source_module' => 'purchase',
            'source_id' => (string) Str::uuid(),
            'payload' => [
                'documentType' => $documentType,
                'hasInvoice' => false,
                'purchaseItems' => [
                    [
                        'rowId' => (string) Str::uuid(),
                        'item' => 'Cups',
                        'unit' => 'ea',
                        'ordQty' => 2,
                        'rcvQty' => 2,
                        'unitPriceHalalas' => 2000,
                        'orderedUnitPriceHalalas' => 2000,
                    ],
                ],
            ],
            'amount' => 4600,
            'match' => 'exact',
            'origin' => 'mobile',
            'channel' => 'mobile_app',
            'status' => Operation::STATUS_PENDING,
            'submitted_at' => now(),
            'operation_date' => now(),
        ]);
    }

    public function test_generic_attachment_does_not_bypass_receipt_without_document_approval_guard(): void
    {
        Storage::fake('public');
        $this->seedAccountant();
        $op = $this->makePurchaseOp('receipt_without_document');

        $this->actingAs($this->accountant, 'sanctum')
            ->postJson('/api/v1/uploads/direct', [
                'file' => UploadedFile::fake()->image('note.png'),
                'ownerType' => 'operation',
                'ownerId' => $op->id,
                'label' => 'other',
            ])
            ->assertCreated();

        $op->refresh();
        $this->assertFalse((bool) ($op->payload['hasInvoice'] ?? false));
        $this->assertSame(1, (int) $op->attachment_count);

        $this->actingAs($this->accountant, 'sanctum')
            ->postJson("/api/v1/operations/{$op->id}/approve")
            ->assertStatus(422)
            ->assertJsonPath('error.code', 'EVIDENCE_REQUIRED');
    }

    public function test_generic_attachment_does_not_bypass_delivery_note_approval_guard(): void
    {
        Storage::fake('public');
        $this->seedAccountant();
        $op = $this->makePurchaseOp('delivery_note');

        $this->actingAs($this->accountant, 'sanctum')
            ->postJson('/api/v1/uploads/direct', [
                'file' => UploadedFile::fake()->image('dn.png'),
                'ownerType' => 'operation',
                'ownerId' => $op->id,
            ])
            ->assertCreated();

        $op->refresh();
        $this->assertFalse((bool) ($op->payload['hasInvoice'] ?? false));

        $this->actingAs($this->accountant, 'sanctum')
            ->postJson("/api/v1/operations/{$op->id}/approve")
            ->assertStatus(422)
            ->assertJsonPath('error.code', 'INVOICE_REQUIRED');
    }

    public function test_valid_invoice_attachment_unlocks_purchase_approval(): void
    {
        Storage::fake('public');
        $this->seedAccountant();
        $op = $this->makePurchaseOp('receipt_without_document');

        $this->actingAs($this->accountant, 'sanctum')
            ->postJson('/api/v1/uploads/direct', [
                'file' => UploadedFile::fake()->image('tax-invoice.png'),
                'ownerType' => 'operation',
                'ownerId' => $op->id,
                'label' => 'invoice',
            ])
            ->assertCreated();

        $op->refresh();
        $this->assertTrue((bool) ($op->payload['hasInvoice'] ?? false));

        $this->actingAs($this->accountant, 'sanctum')
            ->postJson("/api/v1/operations/{$op->id}/approve")
            ->assertOk();

        $op->refresh();
        $this->assertSame(Operation::STATUS_APPROVED, $op->status);
    }

    public function test_zero_price_seeded_branch_item_exposes_supplier_estimated_price_without_altering_inventory_valuation(): void
    {
        $this->seedAccountant();

        $brand = AsabBrand::create([
            'id' => (string) Str::uuid(),
            'company_id' => $this->company->id,
            'name' => 'UAT Brand',
        ]);

        $restaurant = AsabRestaurant::create([
            'id' => (string) Str::uuid(),
            'company_id' => $this->company->id,
            'brand_id' => $brand->id,
            'name' => 'UAT Restaurant',
        ]);

        $this->branch->update([
            'asab_brand_id' => $brand->id,
            'asab_restaurant_id' => $restaurant->id,
        ]);

        $legacySupplier = Supplier::create([
            'id' => (string) Str::uuid(),
            'name' => 'Local Supplier 1',
            'email' => 'sup1.reg@localuat.test',
            'password' => 'Secret#2026!',
            'status' => 'online',
            'is_active' => true,
        ]);

        AsabSupplier::create([
            'id' => (string) Str::uuid(),
            'company_id' => $this->company->id,
            'brand_id' => $brand->id,
            'name' => 'Local Supplier 1',
            'status' => 'active',
            'legacy_supplier_id' => $legacySupplier->id,
        ]);

        $item = Item::create([
            'id' => (string) Str::uuid(),
            'name' => 'Seeded Cups',
            'unit' => 'ea',
            'is_active' => true,
        ]);

        $branchItem = BranchItem::create([
            'id' => (string) Str::uuid(),
            'branch_id' => $this->branch->id,
            'item_id' => $item->id,
            'price' => 0,
            'quantity' => 5,
        ]);

        $inventory = BranchInventory::create([
            'id' => (string) Str::uuid(),
            'branch_id' => $this->branch->id,
            'item_id' => $item->id,
            'quantity' => 5,
            'average_cost' => 9.50,
            'last_purchase_price' => 9.50,
            'total_value' => 47.50,
        ]);

        SupplierProduct::create([
            'id' => (string) Str::uuid(),
            'supplier_id' => $legacySupplier->id,
            'item_id' => $item->id,
            'name' => 'Seeded Cups',
            'unit' => 'ea',
            'unit_price' => 20.00,
            'is_available' => true,
        ]);

        $initialAvgCost = (float) $inventory->average_cost;
        $initialTotalValue = (float) $inventory->total_value;

        $page = app(PurchaseOrderService::class)->getBranchItems($this->branch->id, [], 15);
        $resource = BranchItemResource::collection($page->getCollection())->toArray(request());

        $this->assertCount(1, $resource);
        $this->assertSame(20.0, (float) $resource[0]['item_price']);
        $this->assertSame(20.0, (float) $resource[0]['rate']);

        $branchItem->refresh();
        $inventory->refresh();
        $this->assertSame(0.0, (float) $branchItem->price);
        $this->assertSame($initialAvgCost, (float) $inventory->average_cost);
        $this->assertSame($initialTotalValue, (float) $inventory->total_value);
    }
}
