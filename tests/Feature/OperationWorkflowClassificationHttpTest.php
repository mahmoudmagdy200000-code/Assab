<?php

namespace Tests\Feature;

require_once __DIR__.'/Pkg6ApprovalStageGuardTest.php';

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Modules\Admin\Models\AsabCompany;
use Modules\Admin\Models\AsabUser;
use Modules\Admin\Models\AsabUserRole;
use Modules\Admin\Models\Operation;
use Modules\Branch\Models\Branch;
use Modules\Shift\Services\ShiftReportRevisionService;
use Tests\TestCase;

trait OperationWorkflowFixture
{
    protected function sal(array $overrides = []): Operation
    {
        app(ShiftReportRevisionService::class)->recordManagerRevision($this->day, 'branch_manager', $this->day->branch_manager_id);
        $payload = ['managerShiftId' => $this->day->id];
        foreach (['total_sales' => 'totalSalesHalalas', 'cash_collected' => 'cashHalalas', 'card_payments' => 'cardHalalas',
            'aggregator_payments' => 'appsHalalas', 'variance' => 'varianceHalalas'] as $field => $key) {
            $payload[$key] = \App\Support\ShiftFinancialCalculator::storedSarToHalalas($this->day->$field ?? '0.00');
        }
        return Operation::create(array_replace(['public_id' => 'TEST-'.Str::uuid(), 'company_id' => $this->head->company_id,
            'branch_id' => $this->day->branch_id, 'module_key' => 'sales', 'origin' => 'mobile',
            'status' => 'pending', 'payload' => $payload, 'operation_date' => now()], $overrides));
    }

    protected function nativeLegacy(string $surface = 'company', array $extra = []): Operation
    {
        $actor = AsabUser::create(['company_id' => $this->head->company_id, 'name' => 'Synthetic uploader',
            'email' => Str::uuid().'@test.invalid', 'password' => 'synthetic-only', 'status' => 'active']);
        AsabUserRole::create(['user_id' => $actor->id, 'role_key' => 'branch', 'scope' => 'branch', 'branch_ids' => [$this->day->branch_id]]);
        $url = $surface === 'platform' ? '/api/v1/branch/upload/sales' : '/api/v1/company/me/branch/upload';
        $body = $surface === 'platform' ? ['totalSales' => 10000] : ['reportType' => 'sales', 'salesHalalas' => 10000];
        $response = $this->actingAs($actor, 'sanctum')->withHeader('Idempotency-Key', (string) Str::uuid())
            ->postJson($url, array_merge($body, $extra))->assertCreated();
        return Operation::findOrFail($response->json('createdOperationId') ?? $response->json('id'));
    }

    protected function workflow(Operation $op): string
    {
        return $this->actingAs($this->head, 'sanctum')->getJson('/api/v1/operations/'.$op->id)
            ->assertOk()->json('workflowKind');
    }
}

class OperationWorkflowClassificationHttpTest extends TestCase
{
    use RefreshDatabase, Pkg6ReviewFixture, OperationWorkflowFixture;

    public function test_sal_with_null_review_is_classified_from_scoped_real_source_in_list_and_detail(): void
    {
        $op = $this->sal();
        $this->assertSame('sal_day', $this->workflow($op));
        $this->getJson('/api/v1/operations/'.$op->id)->assertJsonPath('reportReview', null);
        $row = collect($this->getJson('/api/v1/operations?moduleKey=sales')->assertOk()->json('data'))->firstWhere('id', $op->id);
        $this->assertSame('sal_day', $row['workflowKind']);
        $op->update(['origin' => 'system']);
        $this->assertSame('sal_day', $this->workflow($op), 'Origin is not type evidence');
    }

    public function test_native_legacy_provenance_is_server_written_for_both_real_upload_surfaces(): void
    {
        foreach (['company', 'platform'] as $surface) {
            $op = $this->nativeLegacy($surface, ['workflowKind' => 'sal_day', 'provenance' => ['kind' => 'sal_day']]);
            $this->assertSame('legacy_sales', $this->workflow($op));
            $meta = $op->steps()->where('stage_id', 'submit')->firstOrFail()->meta;
            $this->assertSame($op->id, $meta['workflowSource']['operationId']);
            $this->assertSame($op->company_id, $meta['workflowSource']['companyId']);
            $this->assertSame($op->branch_id, $meta['workflowSource']['branchId']);
        }
    }

    public function test_unproven_missing_forged_and_cross_scope_sources_fail_closed(): void
    {
        $unknown = $this->sal(['payload' => ['workflowKind' => 'legacy_sales'], 'source_module' => 'excel_upload']);
        $this->assertSame('unknown', $this->workflow($unknown));
        $missing = $this->sal(['payload' => ['managerShiftId' => (string) Str::uuid()]]);
        $this->assertSame('unknown', $this->workflow($missing));
        $otherCompany = AsabCompany::create(['name' => 'Foreign', 'plan' => 'Professional', 'status' => 'active']);
        $otherBranch = Branch::factory()->create(['asab_company_id' => $otherCompany->id]);
        $this->day->update(['branch_id' => $otherBranch->id]);
        $crossScope = $this->sal(['branch_id' => $this->operation->branch_id]);
        $this->assertSame('unknown', $this->workflow($crossScope));
        $this->actingAs($this->head, 'sanctum')->getJson('/api/v1/operations?moduleKey=sales')->assertOk();
    }

    public function test_legacy_proof_cannot_be_copied_to_a_different_operation_or_contradict_sal(): void
    {
        $legacy = $this->nativeLegacy();
        $proof = $legacy->steps()->where('stage_id', 'submit')->firstOrFail();
        $copy = $this->sal(['payload' => []]);
        $copied = $proof->replicate();
        $copied->operation_id = $copy->id;
        $copied->save();
        $this->assertSame('unknown', $this->workflow($copy));
        $legacy->update(['payload' => ['managerShiftId' => $this->day->id]]);
        $this->assertSame('unknown', $this->workflow($legacy), 'Conflicting provenance never resolves to Legacy');
    }

    public function test_legacy_evidence_is_created_atomically_with_the_operation(): void
    {
        $before = Operation::count();
        DB::beginTransaction();
        try {
            $op = $this->nativeLegacy();
            $this->assertNotNull($op->steps()->where('stage_id', 'submit')->first()->meta['workflowSource']);
        } finally {
            DB::rollBack();
        }
        $this->assertSame($before, Operation::count());
        $this->assertDatabaseMissing('asab_approval_steps', ['operation_id' => $op->id]);
    }
    public function test_head_pending_normal_and_grouped_match_list_and_detail_without_inventing_phase(): void
    {
        $sal = $this->sal(['status' => 'approved']);
        $legacy = $this->nativeLegacy();
        $legacy->update(['status' => 'approved']);
        $unknown = $this->sal(['status' => 'approved', 'payload' => []]);
        foreach ([null, ['phase' => 'head_review', 'dayId' => $this->day->id]] as $review) {
            $payload = $sal->payload;
            $payload['pkg6Review'] = $review;
            $sal->update(['payload' => $payload]);
            $this->actingAs($this->head, 'sanctum');
            $normal = collect($this->getJson('/api/v1/head/operations/pending')->assertOk()->json('data'));
            $grouped = collect($this->getJson('/api/v1/head/operations/pending?view=grouped&groupPreview=20')
                ->assertOk()->json('groups'))->flatMap(fn ($group) => $group['operations']);
            $list = collect($this->getJson('/api/v1/operations?moduleKey=sales')->assertOk()->json('data'));
            foreach ([$sal->id => 'sal_day', $legacy->id => 'legacy_sales', $unknown->id => 'unknown'] as $id => $kind) {
                $detail = $this->getJson('/api/v1/operations/'.$id)->assertOk()->json();
                foreach ([$normal, $grouped, $list] as $rows) {
                    $row = $rows->firstWhere('id', $id);
                    $this->assertNotNull($row);
                    $this->assertSame($kind, $row['workflowKind']);
                    $this->assertSame($detail['reportReview'], $row['reportReview']);
                }
            }
            $this->assertSame($review, $normal->firstWhere('id', $sal->id)['reportReview']);
        }
    }

    public function test_company_and_assigned_branch_scope_apply_to_all_projection_surfaces(): void
    {
        $own = $this->sal(['status' => 'approved']);
        $otherBranch = Branch::factory()->create(['asab_company_id' => $this->head->company_id]);
        $other = $this->sal(['status' => 'approved', 'branch_id' => $otherBranch->id]);
        $foreignCompany = AsabCompany::create(['name' => 'Foreign', 'plan' => 'Professional', 'status' => 'active']);
        $foreign = $this->sal(['status' => 'approved', 'company_id' => $foreignCompany->id]);
        $this->head->roleAssignments()->update(['scope' => 'branch', 'branch_ids' => json_encode([$own->branch_id])]);
        $this->head->unsetRelation('roleAssignments');
        $this->actingAs($this->head, 'sanctum');
        foreach (['/api/v1/operations', '/api/v1/head/operations/pending'] as $url) {
            $ids = collect($this->getJson($url)->assertOk()->json('data'))->pluck('id');
            $this->assertTrue($ids->contains($own->id));
            $this->assertFalse($ids->contains($other->id));
            $this->assertFalse($ids->contains($foreign->id));
        }
        $ids = collect($this->getJson('/api/v1/head/operations/pending?view=grouped')->assertOk()->json('groups'))
            ->flatMap(fn ($group) => $group['operations'])->pluck('id');
        $this->assertFalse($ids->contains($other->id));
        $this->assertFalse($ids->contains($foreign->id));
        foreach ([$other, $foreign] as $inaccessible) {
            $this->getJson('/api/v1/operations/'.$inaccessible->id)->assertNotFound();
        }
    }

}
