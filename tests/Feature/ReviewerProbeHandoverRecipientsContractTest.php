<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Modules\Branch\Models\Branch;
use Modules\BranchManagers\Models\BranchManager;
use Modules\Cashier\Models\Cashier;
use Tests\TestCase;

/**
 * Reviewer probe (NOT for commit): BUG-01 handover recipients contract.
 * Calls GET /api/v1/branch-manager/cashiers/all as a CASHIER and dumps the
 * branch_managers payload to see (a) null String fields the Flutter model
 * casts non-nullably, (b) ineligible recipients (trashed / other branch / suspended).
 */
class ReviewerProbeHandoverRecipientsContractTest extends TestCase
{
    use RefreshDatabase;

    public function test_dump_recipients_payload_as_cashier(): void
    {
        $branchA = Branch::factory()->create(['name' => 'Branch A']);
        $branchB = Branch::factory()->create(['name' => 'Branch B']);

        // Active manager in A with NO phone (column is nullable in the migration).
        $mgrNoPhone = BranchManager::factory()->create(['branch_id' => $branchA->id, 'name' => 'Mgr A no-phone', 'phone' => null]);

        // Suspended / inactive manager in A.
        $mgrSuspended = BranchManager::factory()->create(['branch_id' => $branchA->id, 'name' => 'Mgr A inactive', 'status' => 'inactive', 'is_active' => false]);

        // Manager of ANOTHER branch who created a cashier in A, then soft-deleted.
        $mgrOtherDeleted = BranchManager::factory()->create(['branch_id' => $branchB->id, 'name' => 'Mgr B deleted creator']);

        $cashier = Cashier::factory()->create(['branch_id' => $branchA->id, 'created_by' => $mgrOtherDeleted->id]);
        $mgrOtherDeleted->delete();

        $res = $this->actingAs($cashier, 'sanctum')->getJson('/api/v1/branch-manager/cashiers/all');

        fwrite(STDERR, "\n[status] ".$res->status()."\n");
        foreach ((array) $res->json('data.branch_managers') as $m) {
            fwrite(STDERR, sprintf(
                "[manager] name=%s | phone=%s | email=%s | created_at=%s | is_active=%s | status=%s | branch=%s\n",
                $m['name'] ?? 'NULL',
                array_key_exists('phone', $m) ? var_export($m['phone'], true) : 'MISSING',
                array_key_exists('email', $m) ? var_export($m['email'], true) : 'MISSING',
                array_key_exists('created_at', $m) ? var_export($m['created_at'], true) : 'MISSING',
                var_export($m['is_active'] ?? null, true),
                json_encode($m['status'] ?? null),
                json_encode($m['branch'] ?? null),
            ));
        }
        fwrite(STDERR, '[message] '.var_export($res->json('message'), true)."\n");

        $this->assertTrue(true);
    }
}
