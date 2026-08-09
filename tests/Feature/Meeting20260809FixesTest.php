<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Modules\Admin\Models\AsabBrand;
use Modules\Admin\Models\AsabCompany;
use Modules\Admin\Models\AsabNotification;
use Modules\Admin\Models\AsabRestaurant;
use Modules\Admin\Models\AsabSupplier;
use Modules\Admin\Models\AsabUser;
use Modules\Admin\Models\AsabUserRole;
use Modules\Admin\Models\AutoReminderRule;
use Modules\Admin\Models\BranchShiftConfig;
use Modules\Admin\Models\CashCustody;
use Modules\Admin\Models\Employee;
use Modules\Admin\Models\Operation;
use Modules\Admin\Models\Reminder;
use Modules\Admin\Services\OperationSequence;
use Modules\Admin\Services\ReminderService;
use Modules\Branch\Models\Branch;
use Modules\Shift\Models\Shift as MobileShift;
use Tests\TestCase;

/**
 * Meeting 2026-08-09 fix batch:
 *  1. المشتريات — the grouped board (by supplier / by branch) with KPIs,
 *     «آخر سعر وصول» and per-line توثيق.
 *  2. إدارة الشفتات — free-text duration + per-shift override.
 *  3. كشف حساب الموظف — brand filter + PDF.
 *  5. العهد النقدية — brand + status filters.
 *  6. تعيين الشفتات — per-branch assignment.
 *  7. التذكيرات — generation, real sending, «إرسال للكل», auto-rules.
 */
class Meeting20260809FixesTest extends TestCase
{
    use RefreshDatabase;

    private AsabCompany $company;

    private AsabBrand $brand;

    private AsabBrand $otherBrand;

    private Branch $branchA;

    private Branch $branchB;

    /** Linked to the brand ONLY through its restaurant (the prod-common shape). */
    private Branch $branchViaRestaurant;

    private AsabUser $accountant;

    private AsabUser $manager;

    private AsabSupplier $supplier;

    protected function setUp(): void
    {
        parent::setUp();

        $this->company = AsabCompany::create(['name' => 'Fix Co', 'plan' => 'Professional', 'status' => 'active']);
        $this->brand = AsabBrand::create(['company_id' => $this->company->id, 'name' => 'جورمية كافية', 'sub_status' => 'active', 'status' => 'active']);
        $this->otherBrand = AsabBrand::create(['company_id' => $this->company->id, 'name' => 'براند ثانٍ', 'sub_status' => 'active', 'status' => 'active']);

        $restaurant = AsabRestaurant::create([
            'company_id' => $this->company->id, 'brand_id' => $this->brand->id, 'name' => 'مطعم الرياض', 'status' => 'active',
        ]);

        $this->branchA = Branch::factory()->create(['name' => 'فرع الرياض - العليا', 'asab_brand_id' => $this->brand->id, 'asab_company_id' => $this->company->id]);
        $this->branchB = Branch::factory()->create(['name' => 'فرع جدة - الحمراء', 'asab_brand_id' => $this->otherBrand->id, 'asab_company_id' => $this->company->id]);
        $this->branchViaRestaurant = Branch::factory()->create([
            'name' => 'فرع مكة - المعابدة', 'asab_brand_id' => null,
            'asab_restaurant_id' => $restaurant->id, 'asab_company_id' => $this->company->id,
        ]);

        $this->accountant = AsabUser::create([
            'company_id' => $this->company->id, 'name' => 'محاسب', 'email' => 'acc@fix.test',
            'password' => 'secret-password', 'status' => 'active',
        ]);
        AsabUserRole::create(['user_id' => $this->accountant->id, 'role_key' => 'accountant', 'scope' => 'all']);

        $this->manager = AsabUser::create([
            'company_id' => $this->company->id, 'name' => 'مدير فرع', 'email' => 'brm@fix.test',
            'password' => 'secret-password', 'status' => 'active',
        ]);
        AsabUserRole::create(['user_id' => $this->manager->id, 'role_key' => 'branch', 'scope' => 'branch', 'branch_ids' => [$this->branchA->id]]);

        $this->supplier = AsabSupplier::create([
            'company_id' => $this->company->id, 'name' => 'شركة الدواجن الوطنية', 'category' => 'دواجن',
            'rating' => 45, 'status' => 'active',
        ]);
    }

    private function acc()
    {
        return $this->actingAs($this->accountant, 'sanctum');
    }

    /** @param  array<int, array<string, mixed>>  $items */
    private function purchaseOp(array $items, array $attrs = []): Operation
    {
        $amount = array_sum(array_map(fn ($i) => (int) round(($i['ordQty'] ?? 0) * ($i['unitPriceHalalas'] ?? 0)), $items));

        return Operation::create(array_merge([
            'public_id' => OperationSequence::next('PUR'),
            'company_id' => $this->company->id,
            'branch_id' => $this->branchA->id,
            'module_key' => 'purchases',
            'amount' => $amount,
            'match' => 'review',
            'origin' => 'procurement',
            'status' => Operation::STATUS_PENDING,
            'submitted_at' => now(),
            'operation_date' => now(),
            'payload' => array_merge([
                'supplierId' => $this->supplier->id,
                'orderNumber' => 'INV-D001',
                'purchaseItems' => $items,
            ], $attrs['payload'] ?? []),
        ], array_diff_key($attrs, ['payload' => null])));
    }

    // ── 1. المشتريات — the grouped board ─────────────────────────────────────

    public function test_purchases_board_groups_by_supplier_with_kpis_and_lines(): void
    {
        $this->purchaseOp([
            ['rowId' => 'r1', 'itemId' => 'i1', 'item' => 'دجاج طازج', 'unit' => 'كجم', 'ordQty' => 48, 'unitPriceHalalas' => 3200],
            ['rowId' => 'r2', 'itemId' => 'i2', 'item' => 'صدر دجاج', 'unit' => 'كجم', 'ordQty' => 28, 'unitPriceHalalas' => 4500],
        ]);
        $this->purchaseOp(
            [['rowId' => 'r3', 'itemId' => 'i1', 'item' => 'دجاج طازج', 'unit' => 'كجم', 'ordQty' => 10, 'unitPriceHalalas' => 3000]],
            ['branch_id' => $this->branchB->id, 'match' => 'diff'],
        );

        $body = $this->acc()->getJson('/api/v1/company/me/purchases?groupBy=supplier')->assertOk()->json();

        // One supplier card holding both invoices, across two branches.
        $this->assertCount(1, $body['data']);
        $card = $body['data'][0];
        $this->assertSame('شركة الدواجن الوطنية', $card['supplierName']);
        $this->assertSame(2, $card['invoiceCount']);
        $this->assertSame(2, $card['counterpartCount']);
        $this->assertSame(48 * 3200 + 28 * 4500 + 10 * 3000, $card['totalHalalas']);
        $this->assertSame(1, $card['diffCount']);

        // KPI header: total / pending / diff / active suppliers.
        $this->assertSame(2, $body['meta']['kpis']['pendingInvoices']);
        $this->assertSame(1, $body['meta']['kpis']['diffInvoices']);
        $this->assertSame(1, $body['meta']['kpis']['activeSuppliers']);

        // Lines carry both money spellings + the توثيق counter.
        $twoLiner = collect($card['invoices'])->firstWhere('lineCount', 2);
        $line = $twoLiner['lines'][0];
        $this->assertArrayHasKey('unitPriceHalalas', $line);
        $this->assertArrayHasKey('unitPriceSar', $line);
        $this->assertSame('0/2 موثّق', $twoLiner['documentedCaption']);
    }

    public function test_purchases_board_groups_by_branch(): void
    {
        $this->purchaseOp([['rowId' => 'r1', 'item' => 'دجاج', 'ordQty' => 2, 'unitPriceHalalas' => 1000]]);
        $this->purchaseOp(
            [['rowId' => 'r2', 'item' => 'زيت', 'ordQty' => 1, 'unitPriceHalalas' => 5000]],
            ['branch_id' => $this->branchB->id],
        );

        $body = $this->acc()->getJson('/api/v1/company/me/purchases?groupBy=branch')->assertOk()->json();

        $this->assertSame('branch', $body['meta']['groupBy']);
        $this->assertCount(2, $body['data']);
        $names = array_column($body['data'], 'branchName');
        $this->assertContains('فرع الرياض - العليا', $names);
        $this->assertContains('فرع جدة - الحمراء', $names);
    }

    public function test_board_last_arrival_price_and_delta_come_from_the_previous_invoice(): void
    {
        // Last week the same item arrived at 30 ر.س; today it is 32.
        $this->purchaseOp(
            [['rowId' => 'old', 'itemId' => 'i1', 'item' => 'دجاج طازج', 'ordQty' => 10, 'unitPriceHalalas' => 3000]],
            ['operation_date' => now()->subWeek()],
        );
        $this->purchaseOp([['rowId' => 'new', 'itemId' => 'i1', 'item' => 'دجاج طازج', 'ordQty' => 48, 'unitPriceHalalas' => 3200]]);

        $body = $this->acc()->getJson('/api/v1/company/me/purchases')->assertOk()->json();
        $invoices = collect($body['data'][0]['invoices']);
        $today = $invoices->firstWhere('id', Operation::where('operation_date', '>=', now()->startOfDay())->value('id'));
        $line = collect($today['lines'])->firstWhere('rowId', 'new');

        $this->assertSame(3000, $line['lastArrivalPriceHalalas']);
        $this->assertSame(200, $line['priceDeltaHalalas']);
        $this->assertSame('up', $line['priceDirection']);

        // The first-ever arrival has nothing to compare against.
        $first = collect($invoices->firstWhere('id', '!=', $today['id'])['lines'])->first();
        $this->assertNull($first['lastArrivalPriceHalalas']);
        $this->assertSame('unknown', $first['priceDirection']);
    }

    public function test_last_arrival_price_is_the_closest_earlier_invoice_not_the_oldest(): void
    {
        foreach ([[10, 3000], [5, 3100], [7, 3400]] as $i => [$qty, $price]) {
            $this->purchaseOp(
                [['rowId' => 'r'.$i, 'itemId' => 'i1', 'item' => 'دجاج طازج', 'ordQty' => $qty, 'unitPriceHalalas' => $price]],
                ['operation_date' => now()->subDays(10 - $i * 4)],
            );
        }

        $lines = collect($this->acc()->getJson('/api/v1/company/me/purchases')->assertOk()->json('data.0.invoices'))
            ->flatMap(fn ($invoice) => $invoice['lines'])->keyBy('rowId');

        $this->assertNull($lines['r0']['lastArrivalPriceHalalas']);
        $this->assertSame(3000, $lines['r1']['lastArrivalPriceHalalas']);
        // The newest must compare against 3100 (the middle one), not 3000.
        $this->assertSame(3100, $lines['r2']['lastArrivalPriceHalalas']);
        $this->assertSame(300, $lines['r2']['priceDeltaHalalas']);
    }

    public function test_board_brand_filter_resolves_branches_through_the_restaurant(): void
    {
        // This branch is tied to the brand ONLY by its restaurant — the old
        // asab_brand_id-only filter returned nothing for it.
        $this->purchaseOp(
            [['rowId' => 'r1', 'item' => 'دجاج', 'ordQty' => 1, 'unitPriceHalalas' => 1000]],
            ['branch_id' => $this->branchViaRestaurant->id],
        );
        $this->purchaseOp(
            [['rowId' => 'r2', 'item' => 'زيت', 'ordQty' => 1, 'unitPriceHalalas' => 9000]],
            ['branch_id' => $this->branchB->id],
        );

        $body = $this->acc()->getJson("/api/v1/company/me/purchases?groupBy=branch&brandId={$this->brand->id}")
            ->assertOk()->json();

        $this->assertCount(1, $body['data']);
        $this->assertSame('فرع مكة - المعابدة', $body['data'][0]['branchName']);
    }

    public function test_a_line_can_be_documented_and_the_counter_follows(): void
    {
        $op = $this->purchaseOp([
            ['rowId' => 'r1', 'item' => 'دجاج طازج', 'ordQty' => 2, 'unitPriceHalalas' => 3200],
            ['rowId' => 'r2', 'item' => 'صدر دجاج', 'ordQty' => 1, 'unitPriceHalalas' => 4500],
        ]);

        $this->acc()->patchJson("/api/v1/company/me/operations/{$op->id}/purchase-lines/r1", ['documented' => true])
            ->assertOk()->assertJsonPath('documented', true);

        $body = $this->acc()->getJson('/api/v1/company/me/purchases')->assertOk()->json();
        $invoice = $body['data'][0]['invoices'][0];
        $this->assertSame(1, $invoice['documentedLineCount']);
        $this->assertSame('1/2 موثّق', $invoice['documentedCaption']);

        // Un-ticking clears it again.
        $this->acc()->patchJson("/api/v1/company/me/operations/{$op->id}/purchase-lines/r1", ['documented' => false])
            ->assertOk()->assertJsonPath('documented', false);
    }

    public function test_board_search_matches_item_and_branch_and_export_streams(): void
    {
        $this->purchaseOp([['rowId' => 'r1', 'item' => 'بطاطس مجمدة', 'ordQty' => 3, 'unitPriceHalalas' => 1500]]);

        $hit = $this->acc()->getJson('/api/v1/company/me/purchases?q=بطاطس')->assertOk()->json();
        $this->assertCount(1, $hit['data']);

        $miss = $this->acc()->getJson('/api/v1/company/me/purchases?q=لحم')->assertOk()->json();
        $this->assertCount(0, $miss['data']);

        // The «موثّق / غير موثّق» toggle sends true/false as a query string.
        $this->assertCount(0, $this->acc()->getJson('/api/v1/company/me/purchases?documented=true')->assertOk()->json('data'));
        $this->assertCount(1, $this->acc()->getJson('/api/v1/company/me/purchases?documented=false')->assertOk()->json('data'));

        $this->acc()->get('/api/v1/company/me/purchases/export?format=csv')
            ->assertOk()
            ->assertHeader('content-type', 'text/csv; charset=UTF-8');
    }

    // ── 2. + 6. الشفتات ─────────────────────────────────────────────────────

    public function test_shift_duration_accepts_a_hand_typed_fractional_value(): void
    {
        $body = $this->acc()->putJson("/api/v1/company/me/brands/{$this->brand->id}/shift-config", [
            'numShifts' => 3, 'durationHours' => 7.5, 'firstShiftStart' => '6:00',
        ])->assertOk()->json();

        $this->assertSame(450, $body['durationMinutes']);
        $this->assertSame('06:00', $body['firstShiftStart']);
        $this->assertSame('06:00-13:30', $body['shifts'][0]['window']);
        $this->assertSame('13:30-21:00', $body['shifts'][1]['window']);
    }

    public function test_one_shift_can_be_edited_on_its_own(): void
    {
        $this->acc()->putJson("/api/v1/company/me/brands/{$this->brand->id}/shift-config", [
            'numShifts' => 3, 'durationHours' => 8, 'firstShiftStart' => '06:00',
        ])->assertOk();

        // The ✏️ editor sends ONE shift; the rest of the schedule must survive.
        $body = $this->acc()->putJson("/api/v1/company/me/brands/{$this->brand->id}/shift-config", [
            'shiftOverrides' => [['no' => 2, 'start' => '15:00', 'durationHours' => 6]],
        ])->assertOk()->json();

        $this->assertSame(3, $body['numShifts']);
        $this->assertSame('06:00-14:00', $body['shifts'][0]['window']);
        $this->assertSame('15:00-21:00', $body['shifts'][1]['window']);
        $this->assertTrue($body['shifts'][1]['overridden']);
        // Shift 3 continues from where the overridden one ended.
        $this->assertSame('21:00-05:00', $body['shifts'][2]['window']);
    }

    public function test_an_impossible_clock_time_is_still_rejected(): void
    {
        $this->acc()->putJson("/api/v1/company/me/brands/{$this->brand->id}/shift-config", ['firstShiftStart' => '25:99'])
            ->assertStatus(422);
    }

    public function test_a_branch_can_be_assigned_its_own_schedule_which_wins_over_the_brand(): void
    {
        $this->acc()->putJson("/api/v1/company/me/brands/{$this->brand->id}/shift-config", [
            'numShifts' => 2, 'durationHours' => 8, 'firstShiftStart' => '06:00',
        ])->assertOk();
        $this->assertSame(2, MobileShift::where('branch_id', $this->branchA->id)->where('is_active', true)->count());

        $body = $this->acc()->putJson("/api/v1/company/me/branches/{$this->branchA->id}/shift-config", [
            'numShifts' => 3, 'durationHours' => 8, 'firstShiftStart' => '00:00',
        ])->assertOk()->json();

        $this->assertSame('branch', $body['scope']);
        $this->assertTrue($body['hasOwnConfig']);
        $this->assertSame(3, MobileShift::where('branch_id', $this->branchA->id)->where('is_active', true)->count());

        // Re-saving the BRAND config must not drag the branch back onto it.
        $this->acc()->putJson("/api/v1/company/me/brands/{$this->brand->id}/shift-config", [
            'numShifts' => 2, 'durationHours' => 8, 'firstShiftStart' => '06:00',
        ])->assertOk();
        $this->assertSame(3, MobileShift::where('branch_id', $this->branchA->id)->where('is_active', true)->count());

        // Dropping the override puts the branch back on the brand's schedule.
        $this->acc()->deleteJson("/api/v1/company/me/branches/{$this->branchA->id}/shift-config")
            ->assertOk()->assertJsonPath('hasOwnConfig', false);
        $this->assertSame(0, BranchShiftConfig::where('branch_id', $this->branchA->id)->count());
        $this->assertSame(2, MobileShift::where('branch_id', $this->branchA->id)->where('is_active', true)->count());
    }

    public function test_shift_config_list_can_return_branch_rows(): void
    {
        $this->acc()->putJson("/api/v1/company/me/brands/{$this->brand->id}/shift-config", [
            'numShifts' => 2, 'durationHours' => 8, 'firstShiftStart' => '06:00',
        ])->assertOk();

        $rows = $this->acc()->getJson('/api/v1/company/me/shifts/configs?scope=branch')->assertOk()->json('data');

        $branchRows = collect($rows)->where('scope', 'branch');
        $this->assertTrue($branchRows->isNotEmpty());
        $row = $branchRows->firstWhere('branchId', $this->branchA->id);
        // Inherits the brand's schedule until it gets one of its own.
        $this->assertFalse($row['hasOwnConfig']);
        $this->assertSame(2, $row['numShifts']);
    }

    public function test_branch_shift_config_is_branch_scoped(): void
    {
        // The branch-manager role may not write shift timings at all.
        $this->actingAs($this->manager, 'sanctum')
            ->putJson("/api/v1/company/me/branches/{$this->branchA->id}/shift-config", ['numShifts' => 1])
            ->assertStatus(403);

        // Another company's branch reads as absent, never as configurable.
        $otherCompany = AsabCompany::create(['name' => 'X Co', 'plan' => 'Basic', 'status' => 'active']);
        $foreign = Branch::factory()->create(['name' => 'فرع غريب', 'asab_company_id' => $otherCompany->id]);
        $this->acc()->putJson("/api/v1/company/me/branches/{$foreign->id}/shift-config", ['numShifts' => 1])
            ->assertStatus(404);
        $this->assertSame(0, BranchShiftConfig::where('branch_id', $foreign->id)->count());

        // …and a scoped accountant may not configure a branch outside their tree.
        $scoped = AsabUser::create([
            'company_id' => $this->company->id, 'name' => 'محاسب فرع', 'email' => 'scoped-shift@fix.test',
            'password' => 'secret-password', 'status' => 'active',
        ]);
        AsabUserRole::create(['user_id' => $scoped->id, 'role_key' => 'accountant', 'scope' => 'branch', 'branch_ids' => [$this->branchA->id]]);
        $this->actingAs($scoped, 'sanctum')
            ->putJson("/api/v1/company/me/branches/{$this->branchB->id}/shift-config", ['numShifts' => 1])
            ->assertStatus(404);
    }

    // ── 3. كشف حساب الموظف ───────────────────────────────────────────────────

    public function test_employee_list_filters_by_brand_and_statement_prints_pdf(): void
    {
        $inBrand = Employee::create([
            'company_id' => $this->company->id, 'branch_id' => $this->branchViaRestaurant->id,
            'emp_number' => '1001', 'name' => 'أحمد محمود', 'role' => 'طباخ', 'status' => 'active', 'monthly_salary' => 300000,
        ]);
        Employee::create([
            'company_id' => $this->company->id, 'branch_id' => $this->branchB->id,
            'emp_number' => '1002', 'name' => 'خالد سعيد', 'role' => 'كاشير', 'status' => 'active', 'monthly_salary' => 300000,
        ]);

        $rows = $this->acc()->getJson("/api/v1/company/me/employees?brandId={$this->brand->id}")->assertOk()->json('data');
        $this->assertCount(1, $rows);
        $this->assertSame('أحمد محمود', $rows[0]['name']);

        $pdf = $this->acc()->get("/api/v1/company/me/employees/{$inBrand->id}/statement/export?format=pdf")->assertOk();
        $this->assertStringContainsString('application/pdf', (string) $pdf->headers->get('content-type'));
    }

    // ── 5. العهد النقدية ─────────────────────────────────────────────────────

    public function test_custody_filters_by_brand_and_derived_status(): void
    {
        CashCustody::create([
            'company_id' => $this->company->id, 'branch_id' => $this->branchViaRestaurant->id,
            'custodian_name' => 'أمين مكة', 'amount' => 1000000, 'used' => 0, 'min_alert' => 500000, 'status' => 'active',
        ]);
        CashCustody::create([
            'company_id' => $this->company->id, 'branch_id' => $this->branchB->id,
            'custodian_name' => 'أمين جدة', 'amount' => 1000000, 'used' => 990000, 'min_alert' => 500000, 'status' => 'active',
        ]);

        $byBrand = $this->acc()->getJson("/api/v1/company/me/cash-custody?brandId={$this->brand->id}")->assertOk()->json('data');
        $this->assertCount(1, $byBrand);
        $this->assertSame('أمين مكة', $byBrand[0]['custodianName']);

        // «حرج» is derived from the live balance — the stored column still says
        // `active` on both rows.
        $critical = $this->acc()->getJson('/api/v1/company/me/cash-custody?status=critical')->assertOk()->json('data');
        $this->assertCount(1, $critical);
        $this->assertSame('أمين جدة', $critical[0]['custodianName']);

        $this->acc()->getJson('/api/v1/company/me/cash-custody?status=nope')->assertStatus(422);
    }

    // ── 7. التذكيرات ─────────────────────────────────────────────────────────

    public function test_scan_raises_a_reminder_per_missing_branch_module_and_closes_the_ones_that_arrived(): void
    {
        // Branch A uploaded its sales today; nothing else was uploaded anywhere.
        Operation::create([
            'public_id' => OperationSequence::next('SAL'), 'company_id' => $this->company->id,
            'branch_id' => $this->branchA->id, 'module_key' => 'sales', 'amount' => 100,
            'status' => Operation::STATUS_PENDING, 'operation_date' => now(), 'payload' => [],
        ]);

        $this->acc()->postJson('/api/v1/company/me/reminders/scan')->assertOk();

        // 3 branches × 4 required modules − the one that arrived.
        $this->assertSame(11, Reminder::count());
        $this->assertSame(
            0,
            Reminder::where('branch_id', $this->branchA->id)->where('module_key', 'sales')->count(),
        );

        $body = $this->acc()->getJson('/api/v1/company/me/reminders')->assertOk()->json();
        $this->assertSame(11, $body['meta']['summary']['notSent']);
        $this->assertSame(11, $body['meta']['summary']['totalMissing']);
        $this->assertSame('المبيعات', collect($body['data'])->firstWhere('moduleKey', 'sales')['moduleLabelAr']);

        // The data lands later → the reminder closes itself on the next pass.
        Operation::create([
            'public_id' => OperationSequence::next('WST'), 'company_id' => $this->company->id,
            'branch_id' => $this->branchA->id, 'module_key' => 'waste', 'amount' => 100,
            'status' => Operation::STATUS_PENDING, 'operation_date' => now(), 'payload' => [],
        ]);
        $this->acc()->postJson('/api/v1/company/me/reminders/scan')->assertOk()->assertJsonPath('resolved', 1);

        $after = $this->acc()->getJson('/api/v1/company/me/reminders')->assertOk()->json('meta.summary');
        $this->assertSame(10, $after['totalMissing']);
        $this->assertSame(1, $after['responded']);
    }

    public function test_scanning_twice_does_not_duplicate_reminders(): void
    {
        $this->acc()->postJson('/api/v1/company/me/reminders/scan')->assertOk();
        $first = Reminder::count();
        $this->acc()->postJson('/api/v1/company/me/reminders/scan')->assertOk()->assertJsonPath('created', 0);

        $this->assertSame($first, Reminder::count());
    }

    public function test_sending_a_reminder_actually_notifies_the_branch_manager(): void
    {
        $this->acc()->postJson('/api/v1/company/me/reminders/scan')->assertOk();
        $reminder = Reminder::where('branch_id', $this->branchA->id)->where('module_key', 'sales')->firstOrFail();

        $this->acc()->postJson("/api/v1/company/me/reminders/{$reminder->id}/send")
            ->assertOk()->assertJsonPath('reminderStatus', 'sent');

        $this->assertSame(1, AsabNotification::where('user_id', $this->manager->id)
            ->where('type', 'reminder.missing-data')->count());
        $this->assertNotNull($reminder->fresh()->sent_at);
    }

    public function test_send_all_pushes_every_outstanding_reminder(): void
    {
        $this->acc()->postJson('/api/v1/company/me/reminders/scan')->assertOk();
        $outstanding = Reminder::where('reminder_status', 'not_sent')->count();

        $this->acc()->postJson('/api/v1/company/me/reminders/send-all')
            ->assertOk()->assertJsonPath('sent', $outstanding);

        $this->assertSame(0, Reminder::where('reminder_status', 'not_sent')->count());
        // Branch A's manager got one notification per missing module of theirs.
        $this->assertSame(4, AsabNotification::where('user_id', $this->manager->id)->count());
    }

    public function test_send_all_can_be_narrowed_to_one_brand(): void
    {
        $this->acc()->postJson('/api/v1/company/me/reminders/scan')->assertOk();

        $this->acc()->postJson('/api/v1/company/me/reminders/send-all', ['brandId' => $this->otherBrand->id])
            ->assertOk()->assertJsonPath('sent', 4);

        $this->assertSame(4, Reminder::where('reminder_status', 'sent')->count());
        $this->assertSame(0, Reminder::where('branch_id', $this->branchA->id)->where('reminder_status', 'sent')->count());
    }

    public function test_the_rules_screen_is_seeded_and_toggleable(): void
    {
        $rules = $this->acc()->getJson('/api/v1/company/me/reminders/rules')->assertOk()->json('data');

        $this->assertCount(4, $rules);
        $sales = collect($rules)->firstWhere('module', 'sales');
        $this->assertSame('22:00', $sales['triggerHour']);
        $this->assertSame('المبيعات', $sales['moduleLabelAr']);
        $this->assertTrue($sales['active']);

        $this->acc()->postJson("/api/v1/company/me/reminders/rules/{$sales['id']}/toggle")
            ->assertOk()->assertJsonPath('active', false);
    }

    public function test_the_auto_rule_engine_sends_after_the_trigger_hour_and_respects_the_repeat_window(): void
    {
        $service = app(ReminderService::class);
        $service->generate($this->company->id, now()->toDateString());
        // Only the sales rule stays on, so the counting is unambiguous.
        AutoReminderRule::where('company_id', $this->company->id)->where('module', '!=', 'sales')
            ->update(['active' => false]);

        // 21:00 — before the 22:00 trigger.
        $this->assertSame(0, $service->dispatchDue($this->company->id, Carbon::parse(now()->toDateString().' 21:00')));

        // 22:30 — every not-yet-sent sales reminder goes out.
        $sent = $service->dispatchDue($this->company->id, Carbon::parse(now()->toDateString().' 22:30'));
        $this->assertSame(3, $sent);

        // 23:00 — inside the 2-hour repeat window, so nothing re-sends.
        $this->assertSame(0, $service->dispatchDue($this->company->id, Carbon::parse(now()->toDateString().' 23:00')));

        // 00:40 next day — the window elapsed, so they chase again.
        $this->assertSame(3, $service->dispatchDue($this->company->id, Carbon::parse(now()->addDay()->toDateString().' 00:40')));
    }

    public function test_reminders_are_branch_scoped_for_a_scoped_reader(): void
    {
        $this->acc()->postJson('/api/v1/company/me/reminders/scan')->assertOk();

        $scoped = AsabUser::create([
            'company_id' => $this->company->id, 'name' => 'محاسب فرع', 'email' => 'scoped@fix.test',
            'password' => 'secret-password', 'status' => 'active',
        ]);
        AsabUserRole::create(['user_id' => $scoped->id, 'role_key' => 'accountant', 'scope' => 'branch', 'branch_ids' => [$this->branchA->id]]);

        $rows = $this->actingAs($scoped, 'sanctum')->getJson('/api/v1/company/me/reminders')->assertOk()->json('data');

        $this->assertCount(4, $rows);
        $this->assertSame([$this->branchA->id], array_values(array_unique(array_column($rows, 'branchId'))));
    }
}
