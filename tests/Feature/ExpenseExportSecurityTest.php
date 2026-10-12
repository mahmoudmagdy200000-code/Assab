<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Modules\Admin\Models\AsabIdentityMap;
use Modules\Admin\Models\AsabUser;
use Modules\BrandOwner\Models\BrandOwnerReportExport;
use Modules\Custody\Models\CustodyTransaction;
use Tests\Concerns\BuildsExpenseTenantFixtures;
use Tests\TestCase;

/**
 * IR-PKG2-04 (real export content) + IR-PKG2-01 (delivery exposure) for the
 * Brand Owner reports exports.
 *
 * Two kinds of test live here, clearly separated:
 *  - "content is scoped": the ACTUAL generated CSV (not the exporter spy) is read
 *    back from the public disk and asserted to contain only authorised data.
 *  - "exposure (negative control)": assertions that DOCUMENT the un-remediated
 *    IR-01 gap — files are written to the public disk and delivered by an
 *    unauthenticated static URL that survives revocation. These pass today and
 *    must FAIL once the private-disk + authenticated-download design lands.
 *
 * Fixture money (approved quick_cash, current month):
 *   Brand A1 = 100 ; Brand A2 = 500 ; Brand B1 (other company) = 7,777
 */
class ExpenseExportSecurityTest extends TestCase
{
    use BuildsExpenseTenantFixtures;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('public');
        $this->buildExpenseTenants();

        $this->approvedQuickCash($this->bmA1, 100.00);
        $this->approvedQuickCash($this->bmA2, 500.00);
        $this->approvedQuickCash($this->bmB1, 7777.00);

        $this->custodyCashIn($this->bmA1, $this->branchA1->id, 1000.00);
        $this->custodyCashIn($this->bmB1, $this->branchB1->id, 9999.00);
    }

    private function approvedQuickCash(\Modules\BranchManagers\Models\BranchManager $m, float $amount): void
    {
        $this->pendingExpense($m, ['status' => 'approved', 'total_amount' => $amount, 'net_amount' => $amount, 'vat_amount' => 0.00]);
    }

    private function custodyCashIn(\Modules\BranchManagers\Models\BranchManager $m, string $branchId, float $amount): void
    {
        CustodyTransaction::create([
            'branch_manager_id' => $m->id, 'branch_id' => $branchId, 'type' => 'Cash Transfer',
            'handover_method' => 'Cash Handover', 'amount' => $amount, 'is_cash_in' => true, 'transaction_date' => now(),
        ]);
    }

    private function exportExpense($actor): BrandOwnerReportExport
    {
        $this->actingAs($actor, 'sanctum')->postJson('/api/brand-owner/reports/expense/export', [
            'expense_type' => 'quick_cash', 'year' => (int) now()->year,
            'month_number' => (int) now()->month, 'format_type' => 'Excel',
        ])->assertStatus(200);

        return BrandOwnerReportExport::where('report_kind', 'expenses')->latest('created_at')->firstOrFail();
    }

    private function exportCustody($actor): BrandOwnerReportExport
    {
        $this->actingAs($actor, 'sanctum')->postJson('/api/brand-owner/reports/custody/export', [
            'year' => (int) now()->year, 'month_number' => (int) now()->month, 'format_type' => 'Excel',
        ])->assertStatus(200);

        return BrandOwnerReportExport::where('report_kind', 'custody')->latest('created_at')->firstOrFail();
    }

    // ---- IR-PKG2-04: the generated file content is scoped -------------------

    public function test_expense_export_csv_contains_only_authorized_records(): void
    {
        $export = $this->exportExpense($this->ownerA1);
        $csv = Storage::disk('public')->get($export->file_path);

        $this->assertStringContainsString('100', $csv);
        $this->assertStringContainsString($this->branchA1->name, $csv);
        // No other tenant's amount or branch may appear in the file.
        $this->assertStringNotContainsString('7777', $csv);
        $this->assertStringNotContainsString($this->branchB1->name, $csv);
        $this->assertStringNotContainsString($this->branchA2->name, $csv); // not owned by ownerA1
    }

    public function test_custody_export_http_path_is_scoped(): void
    {
        $export = $this->exportCustody($this->ownerA1);
        $csv = Storage::disk('public')->get($export->file_path);

        $this->assertStringContainsString($this->branchA1->name, $csv);
        $this->assertStringNotContainsString($this->branchB1->name, $csv);
        $this->assertStringNotContainsString('9999', $csv); // B1 cash-in must not leak
    }

    public function test_unlinked_owner_expense_export_csv_has_no_tenant_rows(): void
    {
        $export = $this->exportExpense($this->ownerUnlinked);
        $csv = Storage::disk('public')->get($export->file_path);

        $this->assertStringNotContainsString('7777', $csv);
        $this->assertStringNotContainsString($this->branchA1->name, $csv);
        $this->assertStringNotContainsString($this->branchB1->name, $csv);
    }

    // ---- IR-PKG2-01: delivery exposure (negative controls) ------------------
    // These DOCUMENT the un-remediated gap; they must FAIL once the design fix
    // (private disk + authenticated, scope-checked download) is implemented.

    public function test_negctrl_export_is_written_to_public_disk_and_served_by_unauthenticated_url(): void
    {
        $export = $this->exportExpense($this->ownerA1);

        // File lands on the PUBLIC disk (statically served).
        $this->assertTrue(Storage::disk('public')->exists($export->file_path));

        // download_url is a plain public asset — not an /api authenticated route,
        // not a signed/temporary URL. No per-download authorization exists.
        $url = $export->download_url;
        $this->assertStringContainsString('/storage/', $url);
        $this->assertStringNotContainsString('/api/', $url);
        $this->assertStringNotContainsString('signature=', $url);
    }

    public function test_negctrl_export_history_download_url_survives_brand_unlink(): void
    {
        $export = $this->exportExpense($this->ownerA1);
        $this->assertNotNull($export->download_url);

        // Revoke ownerA1's brand authorization entirely (identity map + same-email user).
        AsabIdentityMap::where('legacy_id', $this->ownerA1->id)->delete();
        AsabUser::where('email', $this->ownerA1->email)->delete();

        // The owner now resolves no brand (fail-closed on reports)...
        $this->actingAs($this->ownerA1, 'sanctum')
            ->getJson('/api/brand-owner/reports/expense/expenses?type=quick_cash')
            ->assertStatus(200)
            ->assertJsonPath('data.summary.total_requests', 0);

        // ...yet the export HISTORY still hands back the download_url (gap: the
        // history is keyed by creator id, not re-authorised against current scope,
        // and the file itself is a permanent public URL).
        $history = $this->actingAs($this->ownerA1, 'sanctum')
            ->getJson('/api/brand-owner/reports-and-analytics')
            ->assertStatus(200)
            ->json('data.export_history');

        $this->assertNotEmpty($history);
        $this->assertNotNull($history[0]['download_url']);
    }
}
