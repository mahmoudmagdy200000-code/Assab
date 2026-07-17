<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Modules\Admin\Models\AsabBrand;
use Modules\Admin\Models\AsabCompany;
use Modules\Admin\Models\AsabUser;
use Modules\Admin\Models\AsabUserRole;
use Modules\Admin\Models\Asset;
use Modules\Admin\Models\AssetDraft;
use Modules\Admin\Models\Operation;
use Modules\Admin\Services\OperationSequence;
use Modules\Branch\Models\Branch;
use Tests\TestCase;

/**
 * T05.4–T05.10 — convert-to-asset linkage and idempotency, draft lifecycle
 * guards, the register's list/edit contract and the per-company `FA-xxxx` id.
 */
class FixedAssetsRegisterTest extends TestCase
{
    use RefreshDatabase;

    private AsabCompany $company;

    private Branch $branchA;

    private Branch $branchB;

    private AsabUser $accountant;

    protected function setUp(): void
    {
        parent::setUp();

        $this->company = AsabCompany::create(['name' => 'FA Co', 'plan' => 'Professional', 'status' => 'active']);
        $brand = AsabBrand::create(['company_id' => $this->company->id, 'name' => 'براند', 'sub_status' => 'active', 'status' => 'active']);
        $this->branchA = Branch::factory()->create(['name' => 'فرع أ', 'asab_brand_id' => $brand->id, 'asab_company_id' => $this->company->id]);
        $this->branchB = Branch::factory()->create(['name' => 'فرع ب', 'asab_brand_id' => $brand->id, 'asab_company_id' => $this->company->id]);

        $this->accountant = AsabUser::create([
            'company_id' => $this->company->id, 'name' => 'محاسب', 'email' => 'acc@fa.test',
            'password' => 'secret-password', 'status' => 'active',
        ]);
        AsabUserRole::create(['user_id' => $this->accountant->id, 'role_key' => 'accountant', 'scope' => 'all']);
    }

    private function acc()
    {
        return $this->actingAs($this->accountant, 'sanctum');
    }

    /** A two-invoice expenses statement; invoice 0 is 11 500 halalas incl. VAT. */
    private function statement(?string $companyId = null, ?string $branchId = null): Operation
    {
        return Operation::create([
            'public_id' => OperationSequence::next('EXP'),
            'company_id' => $companyId ?? $this->company->id,
            'branch_id' => $branchId ?? $this->branchA->id,
            'module_key' => 'expenses',
            'payload' => ['invoices' => [
                ['invNum' => 'INV-1', 'vendor' => 'مورد', 'desc' => 'ثلاجة', 'date' => '2026-07-01', 'amountHalalas' => 11500],
                ['invNum' => 'INV-2', 'vendor' => 'مورد', 'desc' => 'فرن', 'date' => '2026-07-01', 'amountHalalas' => 5520000],
            ]],
            'amount' => 5531500, 'match' => 'exact', 'origin' => 'mobile',
            'status' => Operation::STATUS_PENDING, 'operation_date' => now(),
        ]);
    }

    private function wizard(array $overrides = []): array
    {
        return array_merge([
            'assetName' => 'ثلاجة عرض', 'category' => 'kitchen', 'usefulLifeMonths' => 48,
            'targetBranches' => [$this->branchA->id], 'custodian' => 'أحمد', 'qty' => 1,
        ], $overrides);
    }

    private function convert(Operation $op, array $overrides = [])
    {
        return $this->acc()->postJson(
            "/api/v1/company/me/expense-invoices/{$op->id}/convert-to-asset-draft", $this->wizard($overrides),
        );
    }

    // ── T05.4 conversion linkage ─────────────────────────────────────────────

    public function test_conversion_links_the_draft_to_its_invoice_and_capitalises_the_pre_tax_amount(): void
    {
        $op = $this->statement();

        $draft = $this->convert($op, ['invoiceIndex' => 0])->assertStatus(201)->json();

        $this->assertSame($op->id, $draft['expenseOpId']);
        $this->assertSame('INV-1', $draft['invNum']);
        $this->assertSame('ثلاجة', $draft['desc']);
        $this->assertSame('فرع أ', $draft['expenseBranch']);
        // VAT is reclaimable, never capitalised: 11 500 incl. → 10 000 pre-tax.
        $this->assertSame(10000, $draft['amountHalalas']);
        $this->assertSame('في انتظار التأكيد', $draft['statusLabelAr']);

        $invoice = $this->acc()->getJson("/api/v1/company/me/operations/{$op->id}")->assertOk()->json('expenses.invoices.0');
        $this->assertTrue($invoice['convertedToAsset']);
        $this->assertSame('محوّل', $invoice['convertedLabelAr']);
        $this->assertSame($draft['draftId'], $invoice['assetDraftId']);
    }

    public function test_an_invoice_converts_exactly_once(): void
    {
        $op = $this->statement();
        $this->convert($op, ['invoiceIndex' => 0])->assertStatus(201);

        $this->convert($op, ['invoiceIndex' => 0])
            ->assertStatus(409)
            ->assertJsonPath('error.code', 'INVOICE_ALREADY_CONVERTED')
            ->assertJsonPath('error.messageAr', 'الفاتورة محوّلة مسبقاً');

        $this->assertSame(1, AssetDraft::count());
    }

    public function test_sibling_invoices_of_the_same_statement_convert_independently(): void
    {
        $op = $this->statement();
        $this->convert($op, ['invoiceIndex' => 0])->assertStatus(201);
        $this->convert($op, ['invoiceIndex' => 1, 'assetName' => 'فرن'])->assertStatus(201);

        $this->assertSame(2, AssetDraft::count());
    }

    public function test_conversion_rejects_an_unsupported_useful_life(): void
    {
        $this->convert($this->statement(), ['usefulLifeMonths' => 30])->assertStatus(422);
        $this->assertSame(0, AssetDraft::count());
    }

    public function test_an_out_of_range_invoice_index_does_not_mint_a_draft(): void
    {
        $this->convert($this->statement(), ['invoiceIndex' => 9])
            ->assertStatus(422)->assertJsonPath('error.code', 'INVOICE_INDEX_OUT_OF_RANGE');
        $this->assertSame(0, AssetDraft::count());
    }

    public function test_a_statement_of_another_company_cannot_be_converted(): void
    {
        $other = AsabCompany::create(['name' => 'Other', 'plan' => 'Basic', 'status' => 'active']);
        $otherBranch = Branch::factory()->create(['name' => 'فرع غريب', 'asab_company_id' => $other->id]);

        $this->convert($this->statement($other->id, $otherBranch->id))->assertStatus(404);
        $this->assertSame(0, AssetDraft::count());
    }

    public function test_a_non_expenses_operation_cannot_be_converted(): void
    {
        $sales = Operation::create([
            'public_id' => OperationSequence::next('OPS'), 'company_id' => $this->company->id,
            'branch_id' => $this->branchA->id, 'module_key' => 'sales', 'amount' => 1000,
            'match' => 'exact', 'origin' => 'mobile', 'status' => Operation::STATUS_PENDING, 'operation_date' => now(),
        ]);

        $this->convert($sales)->assertStatus(404);
    }

    // ── T05.5 depreciation ───────────────────────────────────────────────────

    public function test_the_draft_returns_the_wizard_step_two_summary(): void
    {
        $op = $this->statement();

        // Invoice 1 is 5 520 000 incl. → 4 800 000 pre-tax; over 48 months that
        // is 1 200 000 halalas a year.
        $draft = $this->convert($op, ['invoiceIndex' => 1, 'usefulLifeMonths' => 48])->assertStatus(201)->json();

        $this->assertSame(4800000, $draft['amountHalalas']);
        $this->assertSame(1200000, $draft['annualDepreciationHalalas']);
        $this->assertSame(100000, $draft['monthlyDepreciationHalalas']);
        $this->assertSame(48, $draft['usefulLifeMonths']);
    }

    // ── T05.6 draft lifecycle ────────────────────────────────────────────────

    public function test_a_draft_confirms_once_and_mints_branches_times_qty_assets(): void
    {
        $op = $this->statement();
        $draft = $this->convert($op, ['targetBranches' => [$this->branchA->id, $this->branchB->id], 'qty' => 2])
            ->assertStatus(201)->json();

        $this->acc()->postJson("/api/v1/company/me/asset-drafts/{$draft['draftId']}/confirm")
            ->assertStatus(201)->assertJsonCount(4, 'createdAssets');
        $this->assertSame(4, Asset::count());

        $this->acc()->postJson("/api/v1/company/me/asset-drafts/{$draft['draftId']}/confirm")
            ->assertStatus(409)
            ->assertJsonPath('error.code', 'DRAFT_ALREADY_CONFIRMED');
        $this->assertSame(4, Asset::count());
    }

    public function test_a_confirmed_draft_cannot_be_discarded(): void
    {
        $draft = $this->convert($this->statement())->assertStatus(201)->json();
        $this->acc()->postJson("/api/v1/company/me/asset-drafts/{$draft['draftId']}/confirm")->assertStatus(201);

        $this->acc()->postJson("/api/v1/company/me/asset-drafts/{$draft['draftId']}/discard")
            ->assertStatus(409)->assertJsonPath('error.code', 'DRAFT_ALREADY_CONFIRMED');
    }

    public function test_a_discarded_draft_disappears_from_the_panel(): void
    {
        $draft = $this->convert($this->statement())->assertStatus(201)->json();

        $this->acc()->postJson("/api/v1/company/me/asset-drafts/{$draft['draftId']}/discard")->assertStatus(204);
        $this->acc()->getJson('/api/v1/accountant/asset-drafts')->assertOk()->assertJsonCount(0, 'data');

        $this->acc()->deleteJson("/api/v1/accountant/asset-drafts/{$draft['draftId']}")
            ->assertStatus(409)->assertJsonPath('error.code', 'DRAFT_ALREADY_DISCARDED');
    }

    public function test_confirming_a_draft_carries_the_invoice_snapshot_onto_the_assets(): void
    {
        $draft = $this->convert($this->statement(), ['invoiceIndex' => 0])->assertStatus(201)->json();

        $asset = $this->acc()->postJson("/api/v1/company/me/asset-drafts/{$draft['draftId']}/confirm")
            ->assertStatus(201)->json('createdAssets.0');

        $this->assertSame('INV-1', $asset['invNum']);
        $this->assertSame(10000, $asset['cost']);
        $this->assertSame('pending_branch', $asset['status']);
        $this->assertSame('معدات مطبخ', $asset['categoryLabelAr']);
    }

    // ── T05.7 register list + edit ───────────────────────────────────────────

    public function test_the_register_paginates_and_filters(): void
    {
        $this->acc()->postJson('/api/v1/company/me/assets', [
            'name' => 'ثلاجة كبيرة', 'category' => 'kitchen', 'branchId' => $this->branchA->id,
            'cost' => 100000, 'usefulLifeMonths' => 60, 'custodian' => 'سالم',
        ])->assertStatus(201);
        $this->acc()->postJson('/api/v1/company/me/assets', [
            'name' => 'لابتوب', 'category' => 'tech', 'branchId' => $this->branchB->id,
            'cost' => 500000, 'usefulLifeMonths' => 36,
        ])->assertStatus(201);

        $body = $this->acc()->getJson('/api/v1/company/me/assets?pageSize=1')->assertOk()->json();
        $this->assertCount(1, $body['data']);
        $this->assertSame(2, $body['meta']['total']);
        $this->assertSame(2, $body['meta']['summary']['pendingBranch']);
        $this->assertSame(600000, $body['meta']['summary']['bookValueTotal']);

        $this->acc()->getJson('/api/v1/company/me/assets?search=ثلاجة')->assertOk()->assertJsonCount(1, 'data');
        $this->acc()->getJson('/api/v1/company/me/assets?category=tech')->assertOk()->assertJsonCount(1, 'data');
        $this->acc()->getJson('/api/v1/company/me/assets?search=سالم')->assertOk()->assertJsonCount(1, 'data');
    }

    public function test_asset_create_enforces_the_useful_life_enum_and_returns_depreciation(): void
    {
        $this->acc()->postJson('/api/v1/company/me/assets', [
            'name' => 'فرن', 'category' => 'kitchen', 'branchId' => $this->branchA->id,
            'cost' => 120000, 'usefulLifeMonths' => 13,
        ])->assertStatus(422);

        $this->acc()->postJson('/api/v1/company/me/assets', [
            'name' => 'فرن', 'category' => 'kitchen', 'branchId' => $this->branchA->id,
            'cost' => 120000, 'usefulLifeMonths' => 24, 'serial' => 'SN-9', 'purchaseDate' => '2026-01-05',
        ])->assertStatus(201)
            ->assertJsonPath('serial', 'SN-9')
            ->assertJsonPath('annualDepreciationHalalas', 60000)
            ->assertJsonPath('monthlyDepreciationHalalas', 5000)
            ->assertJsonPath('publicId', 'FA-0001');
    }

    public function test_the_asset_status_enum_is_enforced_and_maintenance_round_trips(): void
    {
        $asset = Asset::create([
            'company_id' => $this->company->id, 'public_id' => 'FA-0001', 'name' => 'فرن',
            'category' => 'kitchen', 'branch_id' => $this->branchA->id, 'cost' => 1000,
            'book_value' => 1000, 'useful_life_months' => 60, 'status' => 'confirmed',
        ]);

        $this->acc()->patchJson("/api/v1/company/me/assets/{$asset->id}", ['status' => 'broken'])->assertStatus(422);

        $this->acc()->patchJson("/api/v1/company/me/assets/{$asset->id}", ['status' => 'maintenance', 'bookValue' => 900])
            ->assertOk()
            ->assertJsonPath('status', 'maintenance')
            ->assertJsonPath('statusLabelAr', 'صيانة')
            ->assertJsonPath('bookValue', 900);
    }

    public function test_a_custodian_can_be_cleared(): void
    {
        $asset = Asset::create([
            'company_id' => $this->company->id, 'public_id' => 'FA-0001', 'name' => 'فرن',
            'category' => 'kitchen', 'branch_id' => $this->branchA->id, 'cost' => 1000,
            'book_value' => 1000, 'useful_life_months' => 60, 'status' => 'active', 'custodian' => 'سالم',
        ]);

        $this->acc()->patchJson("/api/v1/company/me/assets/{$asset->id}", ['custodian' => null])
            ->assertOk()->assertJsonPath('custodian', null);
    }

    // ── T05.8 per-company public id ──────────────────────────────────────────

    public function test_two_companies_both_register_their_first_asset_as_fa_0001(): void
    {
        $this->acc()->postJson('/api/v1/company/me/assets', [
            'name' => 'أصل', 'category' => 'other', 'branchId' => $this->branchA->id,
            'cost' => 1000, 'usefulLifeMonths' => 60,
        ])->assertStatus(201)->assertJsonPath('publicId', 'FA-0001');

        $other = AsabCompany::create(['name' => 'Other', 'plan' => 'Basic', 'status' => 'active']);
        $otherBranch = Branch::factory()->create(['name' => 'فرع', 'asab_company_id' => $other->id]);
        $otherAcc = AsabUser::create([
            'company_id' => $other->id, 'name' => 'محاسب آخر', 'email' => 'acc@other.test',
            'password' => 'secret-password', 'status' => 'active',
        ]);
        AsabUserRole::create(['user_id' => $otherAcc->id, 'role_key' => 'accountant', 'scope' => 'all']);

        $this->actingAs($otherAcc, 'sanctum')->postJson('/api/v1/company/me/assets', [
            'name' => 'أصل', 'category' => 'other', 'branchId' => $otherBranch->id,
            'cost' => 1000, 'usefulLifeMonths' => 60,
        ])->assertStatus(201)->assertJsonPath('publicId', 'FA-0001');

        $this->assertSame(2, Asset::withoutGlobalScopes()->where('public_id', 'FA-0001')->count());
    }

    public function test_a_soft_deleted_asset_does_not_free_its_id(): void
    {
        $this->acc()->postJson('/api/v1/company/me/assets', [
            'name' => 'أصل', 'category' => 'other', 'branchId' => $this->branchA->id,
            'cost' => 1000, 'usefulLifeMonths' => 60,
        ])->assertStatus(201);
        Asset::withoutGlobalScopes()->first()->delete();

        $this->acc()->postJson('/api/v1/company/me/assets', [
            'name' => 'أصل ثانٍ', 'category' => 'other', 'branchId' => $this->branchA->id,
            'cost' => 1000, 'usefulLifeMonths' => 60,
        ])->assertStatus(201)->assertJsonPath('publicId', 'FA-0002');
    }

    // ── T05.10 categories ────────────────────────────────────────────────────

    public function test_the_category_lookup_matches_the_srs(): void
    {
        $rows = $this->acc()->getJson('/api/v1/company/me/lookups/asset-categories')->assertOk()->json('data');

        // electrical/smallwares/software carry the client fixed-assets
        // workbook's labels that had no canonical home; 'other' stays last.
        $this->assertSame(
            ['kitchen', 'tech', 'furniture', 'vehicles', 'construction', 'electrical', 'smallwares', 'software', 'other'],
            array_column($rows, 'id'),
        );
        $this->assertSame('تقنية وأجهزة', $rows[1]['name']);
        $this->assertSame('صيانة وإنشاءات', $rows[4]['name']);
    }
}
