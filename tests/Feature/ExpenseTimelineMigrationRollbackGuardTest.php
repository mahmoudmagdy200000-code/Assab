<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\Concerns\BuildsExpenseTenantFixtures;
use Tests\TestCase;

/**
 * FU-03 — the G-1 migration down() must abort BEFORE any schema change when
 * rows still hold the new 'brand_manager' value, instead of relying on DB
 * strict mode (a non-strict MySQL/MariaDB would silently coerce/truncate and
 * corrupt historical attribution). It never rewrites or deletes history.
 */
class ExpenseTimelineMigrationRollbackGuardTest extends TestCase
{
    use BuildsExpenseTenantFixtures;
    use RefreshDatabase;

    private function loadMigration(): object
    {
        // The migrator already require()d the real file (cached), so load a
        // unique copy to obtain a fresh instance whose down() we can call.
        $src = base_path('Modules/Expense/database/migrations/2026_10_12_120000_change_expense_timelines_performed_by_type_to_string.php');
        $tmp = tempnam(sys_get_temp_dir(), 'mig_').'.php';
        copy($src, $tmp);

        return require $tmp;
    }

    public function test_rollback_aborts_when_brand_manager_rows_exist(): void
    {
        $this->buildExpenseTenants();
        $expense = $this->pendingExpense($this->bmA1);

        // A legitimate brand-manager timeline row (the value the migration added).
        DB::table('expense_timelines')->insert([
            'id' => (string) Str::uuid(),
            'expense_id' => $expense->id,
            'action' => 'approve',
            'performed_by' => $this->managerA1->id,
            'performed_by_type' => 'brand_manager',
            'status' => 'approved',
            'created_at' => now(),
        ]);

        $migration = $this->loadMigration();

        try {
            $migration->down();
            $this->fail('down() should have aborted while brand_manager rows exist');
        } catch (\RuntimeException $e) {
            $this->assertStringContainsString('brand_manager', $e->getMessage());
        }

        // The row is untouched and the column still accepts the value (no
        // schema narrowing happened, nothing was coerced or deleted).
        $this->assertDatabaseHas('expense_timelines', [
            'expense_id' => $expense->id,
            'performed_by_type' => 'brand_manager',
        ]);
    }
}
