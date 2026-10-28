<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Modules\Branch\Models\Branch;
use Modules\BranchManagers\Models\BranchManager;
use Modules\Cashier\Models\Cashier;
use Tests\TestCase;

/** Reviewer probe (NOT for commit): does Laravel itself route the Flutter "cashier//my-shifts" paths? */
class ReviewerProbeDoubleSlashRoutingTest extends TestCase
{
    use RefreshDatabase;

    public function test_double_slash_paths(): void
    {
        $branch = Branch::factory()->create();
        $mgr = BranchManager::factory()->create(['branch_id' => $branch->id]);
        $cashier = Cashier::factory()->create(['branch_id' => $branch->id, 'created_by' => $mgr->id]);

        foreach (['/api/v1/cashier/my-shifts/completed', '/api/v1/cashier//my-shifts/completed', '/api/v1/cashier//my-shifts/in-progress'] as $p) {
            $r = $this->actingAs($cashier, 'sanctum')->getJson($p);
            fwrite(STDERR, "\n[$p] HTTP ".$r->status());
        }
        fwrite(STDERR, "\n");
        $this->assertTrue(true);
    }
}
