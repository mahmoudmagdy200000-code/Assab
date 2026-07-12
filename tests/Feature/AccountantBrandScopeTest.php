<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Modules\Admin\Models\AsabBrand;
use Modules\Admin\Models\AsabCompany;
use Modules\Admin\Models\AsabUser;
use Modules\Admin\Models\AsabUserRole;
use Modules\Admin\Models\Asset;
use Modules\Admin\Models\AssetDraft;
use Modules\Admin\Models\CashCustody;
use Modules\Admin\Models\Employee;
use Modules\Admin\Models\InventoryCatalogItem;
use Modules\Admin\Models\Operation;
use Modules\Admin\Models\Shift;
use Modules\Branch\Models\Branch;
use Tests\TestCase;

/**
 * WS4 — brand-level isolation on the accountant module controllers.
 * An accountant assigned to brand A must not read or mutate shifts, waste,
 * custody, employees, or inventory of brand B's branches; head (scope=all)
 * stays unrestricted.
 */
class AccountantBrandScopeTest extends TestCase
{
    use RefreshDatabase;

    private AsabCompany $company;

    private AsabBrand $brandA;

    private AsabBrand $brandB;

    private Branch $branchA;

    private Branch $branchB;

    private AsabUser $accountant;

    private AsabUser $head;

    protected function setUp(): void
    {
        parent::setUp();
        $this->company = AsabCompany::create(['name' => 'Scope Co', 'plan' => 'Professional', 'status' => 'active']);
        $this->brandA = AsabBrand::create(['company_id' => $this->company->id, 'name' => 'براند أ', 'sub_status' => 'active', 'status' => 'active']);
        $this->brandB = AsabBrand::create(['company_id' => $this->company->id, 'name' => 'براند ب', 'sub_status' => 'active', 'status' => 'active']);
        $this->branchA = Branch::factory()->create(['name' => 'فرع أ', 'asab_brand_id' => $this->brandA->id, 'asab_company_id' => $this->company->id]);
        $this->branchB = Branch::factory()->create(['name' => 'فرع ب', 'asab_brand_id' => $this->brandB->id, 'asab_company_id' => $this->company->id]);

        $this->accountant = AsabUser::create([
            'company_id' => $this->company->id, 'name' => 'محاسب أ', 'email' => 'acc-a@asab.test',
            'password' => 'secret-password', 'status' => 'active',
        ]);
        AsabUserRole::create([
            'user_id' => $this->accountant->id, 'role_key' => 'accountant', 'scope' => 'brand', 'brand_ids' => [$this->brandA->id],
        ]);

        $this->head = AsabUser::create([
            'company_id' => $this->company->id, 'name' => 'رئيس الحسابات', 'email' => 'head@asab.test',
            'password' => 'secret-password', 'status' => 'active',
        ]);
        AsabUserRole::create(['user_id' => $this->head->id, 'role_key' => 'head', 'scope' => 'all']);
    }

    private function asAccountant()
    {
        return $this->actingAs($this->accountant, 'sanctum');
    }

    private function asHead()
    {
        return $this->actingAs($this->head, 'sanctum');
    }

    private function shift(Branch $branch, string $status = 'active'): Shift
    {
        return Shift::create([
            'company_id' => $this->company->id, 'branch_id' => $branch->id, 'supervisor_name' => 'مشرف',
            'started_at' => now()->subHours(3), 'status' => $status,
        ]);
    }

    private function wasteOp(Branch $branch): Operation
    {
        return Operation::create([
            'public_id' => strtoupper(Str::random(12)),
            'company_id' => $this->company->id,
            'branch_id' => $branch->id,
            'module_key' => 'waste',
            'amount' => 5000,
            'match' => 'exact',
            'status' => Operation::STATUS_PENDING,
            'operation_date' => now(),
            'submitted_at' => now(),
            'payload' => ['products' => [['name' => 'خبز', 'qty' => 3]]],
        ]);
    }

    private function custody(Branch $branch): CashCustody
    {
        return CashCustody::create([
            'company_id' => $this->company->id, 'branch_id' => $branch->id, 'custodian_name' => 'أمين صندوق',
            'amount' => 100000, 'used' => 0, 'days_since_settlement' => 1, 'status' => 'active',
        ]);
    }

    private function employee(Branch $branch): Employee
    {
        return Employee::create([
            'company_id' => $this->company->id, 'branch_id' => $branch->id,
            'emp_number' => 'E'.random_int(1000, 9999), 'name' => 'موظف', 'role' => 'كاشير',
            'monthly_salary' => 400000, 'status' => 'active',
        ]);
    }

    // ---- Shifts ----

    public function test_scoped_accountant_sees_only_own_brand_shifts(): void
    {
        $a = $this->shift($this->branchA);
        $this->shift($this->branchB);

        $live = $this->asAccountant()->getJson('/api/v1/accountant/shifts/live');
        $live->assertStatus(200);
        $ids = collect($live->json('active'))->pluck('id');
        $this->assertTrue($ids->contains($a->id));
        $this->assertCount(1, $ids);

        $this->shift($this->branchA, 'closed');
        $this->shift($this->branchB, 'closed');
        $history = $this->asAccountant()->getJson('/api/v1/accountant/shifts/history');
        $history->assertStatus(200);
        $this->assertSame([$this->branchA->id], collect($history->json('data'))->pluck('branchId')->unique()->values()->all());
    }

    public function test_scoped_accountant_cannot_close_other_brand_shift(): void
    {
        $shiftB = $this->shift($this->branchB);

        $this->asAccountant()
            ->postJson("/api/v1/accountant/shifts/{$shiftB->id}/close", ['cashInDrawer' => 1000, 'salesSystem' => 1000])
            ->assertStatus(404);
        $this->assertSame('active', $shiftB->fresh()->status);

        $shiftA = $this->shift($this->branchA);
        // T08: a close no longer finalizes — it moves the shift into review and
        // mints an SHF- pipeline operation (closed happens on head final-approval).
        $this->asAccountant()
            ->postJson("/api/v1/accountant/shifts/{$shiftA->id}/close", ['cashInDrawer' => 1000, 'salesSystem' => 900])
            ->assertStatus(200)
            ->assertJsonPath('status', 'pending_review');
    }

    // ---- Waste ----

    public function test_scoped_accountant_cannot_touch_other_brand_waste(): void
    {
        $opA = $this->wasteOp($this->branchA);
        $opB = $this->wasteOp($this->branchB);

        $index = $this->asAccountant()->getJson('/api/v1/accountant/waste');
        $index->assertStatus(200);
        $this->assertSame([$opA->id], collect($index->json('data'))->pluck('id')->all());

        $this->asAccountant()->postJson("/api/v1/accountant/waste/{$opB->id}/approve")->assertStatus(404);
        $this->assertSame(Operation::STATUS_PENDING, $opB->fresh()->status);

        $this->asAccountant()->postJson("/api/v1/accountant/waste/{$opA->id}/approve")->assertStatus(200);
        $this->assertSame(Operation::STATUS_APPROVED, $opA->fresh()->status);
    }

    public function test_bulk_approve_drops_out_of_scope_waste_entries(): void
    {
        $opA = $this->wasteOp($this->branchA);
        $opB = $this->wasteOp($this->branchB);

        $this->asAccountant()
            ->postJson('/api/v1/accountant/waste/bulk-approve', ['entryIds' => [$opA->id, $opB->id]])
            ->assertStatus(200);

        $this->assertSame(Operation::STATUS_APPROVED, $opA->fresh()->status);
        $this->assertSame(Operation::STATUS_PENDING, $opB->fresh()->status);
    }

    // ---- Cash custody ----

    public function test_scoped_accountant_cannot_transact_other_brand_custody(): void
    {
        $custodyA = $this->custody($this->branchA);
        $custodyB = $this->custody($this->branchB);

        $index = $this->asAccountant()->getJson('/api/v1/accountant/cash-custody');
        $index->assertStatus(200);
        $this->assertSame([$custodyA->id], collect($index->json('data'))->pluck('id')->all());

        $payload = ['txnType' => 'debit', 'amount' => 500, 'description' => 'مشتريات'];
        $this->asAccountant()->postJson("/api/v1/accountant/cash-custody/{$custodyB->id}/transactions", $payload)->assertStatus(404);
        $this->assertSame(0, $custodyB->fresh()->used);

        $this->asAccountant()->postJson("/api/v1/accountant/cash-custody/{$custodyA->id}/transactions", $payload)->assertStatus(201);
        $this->assertSame(500, $custodyA->fresh()->used);

        // company/me settle variant
        $this->asAccountant()->postJson("/api/v1/company/me/cash-custody/{$custodyB->id}/settle", [])->assertStatus(404);
        $this->asAccountant()->postJson("/api/v1/company/me/cash-custody/{$custodyA->id}/settle", [])->assertStatus(200);
    }

    // ---- Employees ----

    public function test_scoped_accountant_cannot_move_other_brand_employee(): void
    {
        $empA = $this->employee($this->branchA);
        $empB = $this->employee($this->branchB);

        $index = $this->asAccountant()->getJson('/api/v1/accountant/employees');
        $index->assertStatus(200);
        $this->assertSame([$empA->id], collect($index->json('data'))->pluck('id')->all());

        $this->asAccountant()->getJson("/api/v1/accountant/employees/{$empB->id}/statement")->assertStatus(404);

        $movement = ['movementType' => 'debit', 'amount' => 1000, 'category' => 'advance', 'description' => 'سلفة'];
        $this->asAccountant()->postJson("/api/v1/accountant/employees/{$empB->id}/movements", $movement)->assertStatus(404);
        $this->asAccountant()->postJson("/api/v1/accountant/employees/{$empA->id}/movements", $movement)->assertStatus(201);
    }

    // ---- Inventory branch param ----

    public function test_scoped_accountant_cannot_flag_other_brand_inventory(): void
    {
        Operation::create([
            'public_id' => strtoupper(Str::random(12)), 'company_id' => $this->company->id,
            'branch_id' => $this->branchB->id, 'module_key' => 'inventory', 'amount' => 0, 'match' => 'exact',
            'status' => Operation::STATUS_PENDING, 'operation_date' => now(), 'submitted_at' => now(),
            'payload' => ['items' => []],
        ]);

        $this->asAccountant()
            ->postJson("/api/v1/accountant/inventory/branches/{$this->branchB->id}/flag", ['flagged' => true])
            ->assertStatus(404);
        $this->asAccountant()
            ->getJson("/api/v1/accountant/inventory/branches/{$this->branchB->id}/daily-list")
            ->assertStatus(404);
    }

    // ---- Company dashboard ----

    public function test_company_accountant_dashboard_counts_only_assigned_branches(): void
    {
        $this->wasteOp($this->branchA);
        $this->wasteOp($this->branchB);

        $res = $this->asAccountant()->getJson('/api/v1/company/me/accountant/dashboard');
        $res->assertStatus(200)->assertJsonPath('counts.awaitingReview', 1);
    }

    private function draft(Branch $branch, ?string $companyId = null): AssetDraft
    {
        return AssetDraft::create([
            'draft_id' => 'DRAFT-'.strtoupper(Str::random(8)),
            'company_id' => $companyId ?? $this->company->id,
            'asset_name' => 'ثلاجة عرض', 'category' => 'أجهزة', 'amount' => 250000, 'qty' => 1,
            'useful_life_months' => 36, 'target_branches' => [$branch->id], 'status' => 'draft',
        ]);
    }

    private function asset(Branch $branch): Asset
    {
        return Asset::create([
            'company_id' => $this->company->id, 'public_id' => 'FA-'.strtoupper(Str::random(6)),
            'name' => 'أصل', 'category' => 'أجهزة', 'branch_id' => $branch->id,
            'cost' => 100000, 'book_value' => 100000, 'useful_life_months' => 24, 'status' => 'confirmed',
        ]);
    }

    // ---- Asset drafts ----

    public function test_scoped_accountant_cannot_touch_other_brand_asset_draft(): void
    {
        $draftA = $this->draft($this->branchA);
        $draftB = $this->draft($this->branchB);

        $list = $this->asAccountant()->getJson('/api/v1/accountant/asset-drafts');
        $list->assertStatus(200);
        $this->assertSame([$draftA->id], collect($list->json('data'))->pluck('id')->all());

        $this->asAccountant()->postJson("/api/v1/accountant/asset-drafts/{$draftB->draft_id}/confirm")->assertStatus(404);
        $this->assertSame('draft', $draftB->fresh()->status);
        $this->asAccountant()->deleteJson("/api/v1/accountant/asset-drafts/{$draftB->draft_id}")->assertStatus(404);

        $this->asAccountant()->postJson("/api/v1/accountant/asset-drafts/{$draftA->draft_id}/confirm")->assertStatus(201);
        $this->assertSame('confirmed', $draftA->fresh()->status);
    }

    public function test_asset_drafts_isolated_across_companies(): void
    {
        $other = AsabCompany::create(['name' => 'Other Co', 'plan' => 'Professional', 'status' => 'active']);
        $otherBrand = AsabBrand::create(['company_id' => $other->id, 'name' => 'براند غريب', 'sub_status' => 'active', 'status' => 'active']);
        $otherBranch = Branch::factory()->create(['name' => 'فرع غريب', 'asab_brand_id' => $otherBrand->id, 'asab_company_id' => $other->id]);
        $foreign = $this->draft($otherBranch, $other->id);

        $list = $this->asHead()->getJson('/api/v1/accountant/asset-drafts');
        $list->assertStatus(200);
        $this->assertFalse(collect($list->json('data'))->pluck('id')->contains($foreign->id));

        $this->asHead()->postJson("/api/v1/accountant/asset-drafts/{$foreign->draft_id}/confirm")->assertStatus(404);
        $this->assertSame('draft', $foreign->fresh()->status);
        $this->assertSame(0, Asset::withoutGlobalScopes()->where('company_id', $other->id)->count());
        $this->asHead()->deleteJson("/api/v1/accountant/asset-drafts/{$foreign->draft_id}")->assertStatus(404);
    }

    // ---- Sales variance assign ----

    public function test_scoped_accountant_cannot_assign_other_brand_sales_variance(): void
    {
        $opB = Operation::create([
            'public_id' => strtoupper(Str::random(12)), 'company_id' => $this->company->id,
            'branch_id' => $this->branchB->id, 'module_key' => 'sales', 'amount' => 10000, 'match' => 'diff',
            'status' => Operation::STATUS_PENDING, 'operation_date' => now(), 'submitted_at' => now(),
            'payload' => [],
        ]);

        $this->asAccountant()
            ->postJson("/api/v1/company/me/operations/{$opB->id}/sales-variance/assign", [
                'allocations' => [['employeeId' => 'emp-1', 'amountHalalas' => 500]],
            ])
            ->assertStatus(404);
    }

    // ---- Asset update (company/me) ----

    public function test_scoped_accountant_cannot_update_other_brand_asset(): void
    {
        $assetA = $this->asset($this->branchA);
        $assetB = $this->asset($this->branchB);

        $this->asAccountant()->patchJson("/api/v1/company/me/assets/{$assetB->id}", ['name' => 'مخترق'])->assertStatus(404);
        $this->assertSame('أصل', $assetB->fresh()->name);

        // Cannot move an in-scope asset into an out-of-scope branch.
        $this->asAccountant()->patchJson("/api/v1/company/me/assets/{$assetA->id}", ['branchId' => $this->branchB->id])->assertStatus(404);
        $this->assertSame($this->branchA->id, $assetA->fresh()->branch_id);

        $this->asAccountant()->patchJson("/api/v1/company/me/assets/{$assetA->id}", ['name' => 'أصل معدل'])
            ->assertStatus(200)->assertJsonPath('name', 'أصل معدل');
    }

    // ---- Inventory catalog (brand-keyed) ----

    public function test_catalog_scoped_to_assigned_brands(): void
    {
        $itemA = InventoryCatalogItem::create(['brand_id' => $this->brandA->id, 'type' => InventoryCatalogItem::TYPE_SALES_ITEM, 'name' => 'صنف أ', 'category' => 'مشروبات', 'unit' => 'حبة', 'status' => 'active']);
        InventoryCatalogItem::create(['brand_id' => $this->brandB->id, 'type' => InventoryCatalogItem::TYPE_SALES_ITEM, 'name' => 'صنف ب', 'category' => 'مشروبات', 'unit' => 'حبة', 'status' => 'active']);

        $list = $this->asAccountant()->getJson('/api/v1/accountant/inventory/catalog');
        $list->assertStatus(200);
        $this->assertSame([$itemA->id], collect($list->json('items'))->pluck('id')->all());

        $other = $this->asAccountant()->getJson('/api/v1/accountant/inventory/catalog?brandId='.$this->brandB->id);
        $other->assertStatus(200);
        $this->assertSame([], $other->json('items'));

        $payload = ['brandId' => $this->brandB->id, 'name' => 'جديد', 'category' => 'مشروبات', 'unit' => 'حبة'];
        $this->asAccountant()->postJson('/api/v1/accountant/inventory/catalog', $payload)->assertStatus(404);
        $this->asAccountant()->postJson('/api/v1/accountant/inventory/catalog', array_merge($payload, ['brandId' => $this->brandA->id]))->assertStatus(201);

        $headList = $this->asHead()->getJson('/api/v1/accountant/inventory/catalog');
        $headList->assertStatus(200);
        $this->assertCount(3, $headList->json('items'));
    }

    // ---- scope=all is company-wide, not platform-wide ----

    public function test_scope_all_user_cannot_reach_other_company_branch(): void
    {
        $other = AsabCompany::create(['name' => 'Other Co', 'plan' => 'Professional', 'status' => 'active']);
        $otherBrand = AsabBrand::create(['company_id' => $other->id, 'name' => 'براند غريب', 'sub_status' => 'active', 'status' => 'active']);
        $otherBranch = Branch::factory()->create(['name' => 'فرع غريب', 'asab_brand_id' => $otherBrand->id, 'asab_company_id' => $other->id]);

        $this->asHead()->getJson("/api/v1/accountant/inventory/branches/{$otherBranch->id}/daily-list")->assertStatus(404);
        $this->asHead()->putJson("/api/v1/accountant/inventory/branches/{$otherBranch->id}/daily-list", ['items' => ['x']])->assertStatus(404);

        // Own-company branches stay reachable for scope=all.
        $this->asHead()->getJson("/api/v1/accountant/inventory/branches/{$this->branchB->id}/daily-list")->assertStatus(200);
    }

    // ---- Head (scope=all) unaffected ----

    public function test_head_with_scope_all_sees_and_mutates_everything(): void
    {
        $shiftB = $this->shift($this->branchB);
        $this->wasteOp($this->branchA);
        $this->wasteOp($this->branchB);

        $live = $this->asHead()->getJson('/api/v1/accountant/shifts/live');
        $live->assertStatus(200);
        $this->assertCount(1, $live->json('active'));

        $this->asHead()
            ->postJson("/api/v1/accountant/shifts/{$shiftB->id}/close", ['cashInDrawer' => 1000, 'salesSystem' => 1000])
            ->assertStatus(200);

        $waste = $this->asHead()->getJson('/api/v1/accountant/waste');
        $waste->assertStatus(200);
        $this->assertCount(2, $waste->json('data'));
    }
}
