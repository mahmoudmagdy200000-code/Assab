<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Schema;
use Modules\Admin\Models\AsabUser;
use Modules\Admin\Services\BrandManagerAssignmentService;
use Tests\Concerns\BuildsExpenseTenantFixtures;
use Tests\TestCase;

/**
 * R-04 — the two PKG-2 migrations must REFUSE a rollback that would destroy live
 * data: the immutable expense origin ownership (FU-02) and the append-only
 * brand-manager delegation audit (D-2). Both down() methods abort BEFORE any
 * schema change when real rows exist, and never silently drop the evidence.
 */
class Pkg2MigrationRollbackGuardTest extends TestCase
{
    use BuildsExpenseTenantFixtures;
    use RefreshDatabase;

    /** The migrator already require()d the file, so load a unique copy for a fresh instance. */
    private function loadMigration(string $relativePath): object
    {
        $tmp = tempnam(sys_get_temp_dir(), 'mig_').'.php';
        copy(base_path($relativePath), $tmp);

        return require $tmp;
    }

    public function test_origin_ownership_rollback_aborts_when_expenses_carry_origin(): void
    {
        $this->buildExpenseTenants();
        $this->pendingExpense($this->bmA1, ['status' => 'approved', 'total_amount' => 10, 'net_amount' => 10, 'vat_amount' => 0]);

        $migration = $this->loadMigration('Modules/Expense/database/migrations/2026_10_13_000001_add_origin_ownership_to_expenses.php');

        try {
            $migration->down();
            $this->fail('down() should have aborted while expenses carry immutable origin');
        } catch (\RuntimeException $e) {
            $this->assertStringContainsString('origin ownership', $e->getMessage());
        }

        // No schema change happened — the columns are still present.
        $this->assertTrue(Schema::hasColumn('expenses', 'origin_asab_brand_id'));
        $this->assertTrue(Schema::hasColumn('expenses', 'origin_branch_id'));
    }

    public function test_assignment_audit_rollback_aborts_when_history_exists(): void
    {
        $this->buildExpenseTenants();

        $manager = $this->makeBrandManager('audit.guard@tenant.test', 'Audit Guard Manager');
        $user = AsabUser::create([
            'company_id' => $this->companyA->id, 'name' => 'Dash AG', 'email' => 'audit.guard@tenant.test',
            'password' => 'x', 'status' => 'active',
        ]);
        app(BrandManagerAssignmentService::class)->grant($manager->id, $user, [$this->brandA1->id], 'actor');

        $migration = $this->loadMigration('Modules/Admin/database/migrations/2026_10_13_000002_create_asab_brand_manager_assignment_audit.php');

        try {
            $migration->down();
            $this->fail('down() should have aborted while audit history exists');
        } catch (\RuntimeException $e) {
            $this->assertStringContainsString('append-only', $e->getMessage());
        }

        // The audit table and its row are untouched.
        $this->assertTrue(Schema::hasTable('asab_brand_manager_assignment_audit'));
    }
}
