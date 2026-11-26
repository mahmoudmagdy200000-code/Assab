<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Modules\Admin\Models\AsabBrand;
use Modules\Admin\Models\AsabCompany;
use Modules\Admin\Models\AsabSupplier;
use Modules\Admin\Models\AsabUser;
use Modules\Admin\Models\AsabUserRole;
use Modules\Admin\Models\Attachment;
use Modules\Admin\Models\CompanyUser;
use Modules\Admin\Models\Operation;
use Modules\Admin\Services\OperationSequence;
use Modules\Branch\Models\Branch;
use Tests\TestCase;

class PurchaseStage4FiltersExportAndAttachmentSecurityTest extends TestCase
{
    use RefreshDatabase;

    private AsabCompany $companyA;

    private AsabCompany $companyB;

    private AsabBrand $brandA1;

    private AsabBrand $brandA2;

    private Branch $branchA1;

    private Branch $branchA2;

    private Branch $branchB1;

    private AsabUser $accountantA;

    private AsabUser $branchScopedAccA;

    private AsabUser $accountantB;

    private AsabSupplier $supplierA1;

    private AsabSupplier $supplierA2;

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('public');

        $this->companyA = AsabCompany::create(['name' => 'Stage4 Co A', 'plan' => 'Professional', 'status' => 'active']);
        $this->companyB = AsabCompany::create(['name' => 'Stage4 Co B', 'plan' => 'Professional', 'status' => 'active']);

        $this->brandA1 = AsabBrand::create(['company_id' => $this->companyA->id, 'name' => 'Brand A1', 'sub_status' => 'active', 'status' => 'active']);
        $this->brandA2 = AsabBrand::create(['company_id' => $this->companyA->id, 'name' => 'Brand A2', 'sub_status' => 'active', 'status' => 'active']);
        $brandB1 = AsabBrand::create(['company_id' => $this->companyB->id, 'name' => 'Brand B1', 'sub_status' => 'active', 'status' => 'active']);

        $this->branchA1 = Branch::factory()->create(['name' => 'Branch A1', 'asab_brand_id' => $this->brandA1->id, 'asab_company_id' => $this->companyA->id]);
        $this->branchA2 = Branch::factory()->create(['name' => 'Branch A2', 'asab_brand_id' => $this->brandA2->id, 'asab_company_id' => $this->companyA->id]);
        $this->branchB1 = Branch::factory()->create(['name' => 'Branch B1', 'asab_brand_id' => $brandB1->id, 'asab_company_id' => $this->companyB->id]);

        $this->accountantA = $this->makeAccountant($this->companyA, 'acc-a@stage4.test', 'all', null);
        $this->branchScopedAccA = $this->makeAccountant($this->companyA, 'acc-a1@stage4.test', 'branch', $this->branchA1->id);
        $this->accountantB = $this->makeAccountant($this->companyB, 'acc-b@stage4.test', 'all', null);

        $this->supplierA1 = AsabSupplier::create(['company_id' => $this->companyA->id, 'name' => 'Fresh Farms', 'category' => 'produce', 'rating' => 45, 'status' => 'active']);
        $this->supplierA2 = AsabSupplier::create(['company_id' => $this->companyA->id, 'name' => 'Dairy Co', 'category' => 'dairy', 'rating' => 40, 'status' => 'active']);
    }

    private function makeAccountant(AsabCompany $company, string $email, string $scope, ?string $branchId): AsabUser
    {
        $user = AsabUser::create([
            'company_id' => $company->id,
            'name' => $email,
            'email' => $email,
            'password' => 'secret-password',
            'status' => 'active',
        ]);
        AsabUserRole::create([
            'user_id' => $user->id,
            'role_key' => 'accountant',
            'scope' => $scope,
            'branch_ids' => $branchId ? [$branchId] : [],
        ]);
        CompanyUser::create([
            'company_id' => $company->id,
            'user_id' => $user->id,
            'role_key' => 'accountant',
            'status' => 'active',
        ]);

        return $user;
    }

    private function createPurchaseOp(
        AsabCompany $company,
        Branch $branch,
        AsabSupplier $supplier,
        string $itemName,
        int $amountHalalas,
        string $status = Operation::STATUS_PENDING,
        string $match = 'exact',
        ?string $receiptId = null,
    ): Operation {
        return Operation::create([
            'public_id' => OperationSequence::next('PUR'),
            'company_id' => $company->id,
            'branch_id' => $branch->id,
            'module_key' => 'purchases',
            'amount' => $amountHalalas,
            'match' => $match,
            'origin' => 'procurement',
            'status' => $status,
            'submitted_at' => now(),
            'operation_date' => now(),
            'payload' => [
                'supplierId' => $supplier->id,
                'supplierName' => $supplier->name,
                'goodsReceiptId' => $receiptId,
                'invoiceNumber' => 'INV-'.$amountHalalas,
                'items' => [[
                    'itemId' => 'itm-'.$amountHalalas,
                    'item' => $itemName,
                    'unit' => 'kg',
                    'qty' => 1,
                    'unitPriceHalalas' => $amountHalalas,
                ]],
            ],
        ]);
    }

    public function test_upload_controller_enforces_tenant_and_branch_isolation_and_blocks_unsafe_files(): void
    {
        $opA2 = $this->createPurchaseOp($this->companyA, $this->branchA2, $this->supplierA1, 'Tomatoes', 1234);
        $opB1 = $this->createPurchaseOp($this->companyB, $this->branchB1, $this->supplierA1, 'Milk', 5000);

        $attB = Attachment::create([
            'owner_type' => 'operation',
            'owner_id' => $opB1->id,
            'filename' => 'b-invoice.pdf',
            'mime_type' => 'application/pdf',
            'size' => 100,
            'storage_key' => "{$this->companyB->id}/operation/b-invoice.pdf",
            'uploaded_by_id' => $this->accountantB->id,
            'uploaded_at' => now(),
        ]);

        // Cross-tenant read/download/verify/delete/confirm are blocked (404)
        $this->actingAs($this->accountantA, 'sanctum')->getJson("/api/v1/attachments/{$attB->id}")->assertNotFound();
        $this->actingAs($this->accountantA, 'sanctum')->getJson("/api/v1/attachments/{$attB->id}/download")->assertNotFound();
        $this->actingAs($this->accountantA, 'sanctum')->postJson("/api/v1/attachments/{$attB->id}/verify")->assertNotFound();
        $this->actingAs($this->accountantA, 'sanctum')->deleteJson("/api/v1/attachments/{$attB->id}")->assertNotFound();
        $this->actingAs($this->accountantA, 'sanctum')->postJson("/api/v1/uploads/{$attB->id}/confirm")->assertNotFound();

        // Cross-tenant upload to foreign operation is blocked
        $this->actingAs($this->accountantA, 'sanctum')->post('/api/v1/uploads/direct', [
            'file' => UploadedFile::fake()->create('invoice.pdf', 10, 'application/pdf'),
            'ownerType' => 'operation',
            'ownerId' => $opB1->id,
        ])->assertNotFound();

        // Branch-scoped accountant in Branch A1 cannot upload to or access Branch A2 operation attachments
        $attA2 = Attachment::create([
            'owner_type' => 'operation',
            'owner_id' => $opA2->id,
            'filename' => 'a2-invoice.pdf',
            'mime_type' => 'application/pdf',
            'size' => 100,
            'storage_key' => "{$this->companyA->id}/operation/a2-invoice.pdf",
            'uploaded_by_id' => $this->accountantA->id,
            'uploaded_at' => now(),
        ]);
        $this->actingAs($this->branchScopedAccA, 'sanctum')->getJson("/api/v1/attachments/{$attA2->id}")->assertNotFound();
        $this->actingAs($this->branchScopedAccA, 'sanctum')->postJson("/api/v1/attachments/{$attA2->id}/verify")->assertNotFound();

        // Dangerous file types (.php / .svg / .html) are rejected with 422
        $this->actingAs($this->accountantA, 'sanctum')->postJson('/api/v1/uploads/direct', [
            'file' => UploadedFile::fake()->create('shell.php', 10, 'application/x-php'),
            'ownerType' => 'operation',
            'ownerId' => $opA2->id,
        ])->assertStatus(422);

        $this->actingAs($this->accountantA, 'sanctum')->postJson('/api/v1/uploads/presigned-url', [
            'filename' => 'xss.svg',
            'mimeType' => 'image/svg+xml',
            'size' => 128,
            'ownerType' => 'operation',
            'ownerId' => $opA2->id,
        ])->assertStatus(422);

        // Authorized same-tenant accountant can upload, show, download, verify, and delete
        $uploaded = $this->actingAs($this->accountantA, 'sanctum')->post('/api/v1/uploads/direct', [
            'file' => UploadedFile::fake()->create('valid-receipt.pdf', 20, 'application/pdf'),
            'ownerType' => 'operation',
            'ownerId' => $opA2->id,
        ])->assertCreated()->json();

        $this->actingAs($this->accountantA, 'sanctum')->getJson("/api/v1/attachments/{$uploaded['id']}")->assertOk();
        $this->actingAs($this->accountantA, 'sanctum')->getJson("/api/v1/attachments/{$uploaded['id']}/download")->assertOk()->assertJsonStructure(['url', 'expiresIn']);
        $verified = $this->actingAs($this->accountantA, 'sanctum')->postJson("/api/v1/attachments/{$uploaded['id']}/verify")->assertOk()->json();
        $this->assertNotNull($verified['verifiedAt']);
        $this->actingAs($this->accountantA, 'sanctum')->deleteJson("/api/v1/attachments/{$uploaded['id']}")->assertNoContent();
    }

    public function test_purchase_board_filters_and_filtered_csv_xlsx_export_preserve_halalas_and_receipt_id(): void
    {
        $this->createPurchaseOp($this->companyA, $this->branchA1, $this->supplierA1, 'Organic Tomatoes', 1234, Operation::STATUS_PENDING, 'diff', 'GR-STAGE4-001');
        $this->createPurchaseOp($this->companyA, $this->branchA2, $this->supplierA2, 'Whole Milk', 5000, Operation::STATUS_APPROVED, 'exact', 'GR-STAGE4-002');
        $this->createPurchaseOp($this->companyB, $this->branchB1, $this->supplierA1, 'Foreign Item', 9999, Operation::STATUS_PENDING, 'diff', 'GR-FOREIGN-999');

        // Filter by supplierId + status + match + brandId
        $filtered = $this->actingAs($this->accountantA, 'sanctum')
            ->getJson("/api/v1/company/me/purchases?brandId={$this->brandA1->id}&supplierId={$this->supplierA1->id}&status=pending&match=diff")
            ->assertOk()
            ->json();

        $this->assertCount(1, $filtered['data']);
        $this->assertSame('Fresh Farms', $filtered['data'][0]['supplierName']);
        $this->assertSame(1234, $filtered['data'][0]['totalHalalas']);
        $this->assertSame('GR-STAGE4-001', $filtered['data'][0]['invoices'][0]['receiptId']);

        // Filtered CSV export includes supplier, receipt ID, exact halala amount (12.34), and excludes other records
        $csvResponse = $this->actingAs($this->accountantA, 'sanctum')
            ->get("/api/v1/company/me/purchases/export?format=csv&supplierId={$this->supplierA1->id}&match=diff")
            ->assertOk();

        $csvContent = file_get_contents($csvResponse->baseResponse->getFile()->getPathname());

        $this->assertStringContainsString('معرف الاستلام', $csvContent);
        $this->assertStringContainsString('Fresh Farms', $csvContent);
        $this->assertStringContainsString('GR-STAGE4-001', $csvContent);
        $this->assertStringContainsString('12.34', $csvContent);
        $this->assertStringNotContainsString('Whole Milk', $csvContent);
        $this->assertStringNotContainsString('GR-FOREIGN-999', $csvContent);

        // Filtered XLSX export succeeds
        $this->actingAs($this->accountantA, 'sanctum')
            ->get("/api/v1/company/me/purchases/export?format=xlsx&supplierId={$this->supplierA1->id}")
            ->assertOk()
            ->assertHeader('content-type', 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
    }
}
