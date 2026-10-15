<?php

namespace Tests\Feature;

require_once __DIR__.'/Pkg6ApprovalStageGuardTest.php';

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Modules\Admin\Models\AsabBrand;
use Modules\Admin\Models\AsabCompany;
use Modules\Admin\Models\AsabUser;
use Modules\Admin\Models\AsabUserRole;
use Modules\Admin\Models\Operation;
use Modules\Admin\Services\OperationService;
use Modules\Admin\Support\OperationEnums;
use Modules\Branch\Models\Branch;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;
use Tests\TestCase;

/**
 * Final-acceptance gap closure: the PKG-6 review commands authorise the ACTOR (company, role, active, branch
 * scope) in ShiftReportReviewService::authorizeReview / ShiftReportCorrectionActorPolicy. These tests pin the
 * negative cases (cross-company, wrong role, inactive, branch-scoped to another branch, empty scope) so a
 * refused actor leaves the operation, its steps and the return-cycle outbox untouched.
 */
class Pkg6ReviewAuthorizationTest extends TestCase
{
    use Pkg6ReviewFixture, RefreshDatabase;

    private function foreignUser(string $role, string $email): AsabUser
    {
        $company = AsabCompany::create(['name' => 'Foreign', 'plan' => 'Professional', 'status' => 'active']);
        AsabBrand::create(['company_id' => $company->id, 'name' => 'Foreign', 'status' => 'active', 'sub_status' => 'active']);
        $user = AsabUser::create(['company_id' => $company->id, 'name' => 'Foreign '.$role, 'email' => $email, 'password' => 'test', 'status' => 'active']);
        AsabUserRole::create(['user_id' => $user->id, 'role_key' => $role, 'scope' => 'all']);

        return $user;
    }

    private function sameCompanyAccountant(string $email, array $role, string $status = 'active'): AsabUser
    {
        $user = AsabUser::create(['company_id' => $this->operation->company_id, 'name' => 'Scoped', 'email' => $email, 'password' => 'test', 'status' => $status]);
        AsabUserRole::create(['user_id' => $user->id, 'role_key' => 'accountant'] + $role);

        return $user;
    }

    private function assertDenied(callable $command, string $code): void
    {
        try {
            $command();
            $this->fail('The unauthorised actor was accepted.');
        } catch (AccessDeniedHttpException $error) {
            $this->assertSame($code, $error->getMessage());
        }
    }

    /** @return array<string,int|string> a fingerprint of everything a refused command must not touch */
    private function footprint(Operation $operation): array
    {
        $fresh = $operation->fresh();

        return ['status' => $fresh->status, 'payload' => json_encode($fresh->payload), 'steps' => $fresh->steps()->count(),
            'outbox' => DB::table('shift_report_correction_notifications')->count(),
            'notifications' => DB::table('notifications')->count()];
    }

    public function test_foreign_company_accountant_cannot_approve_and_nothing_changes(): void
    {
        $foreign = $this->foreignUser('accountant', 'f-acc@test.invalid');
        $before = $this->footprint($this->operation);
        $this->assertDenied(fn () => app(OperationService::class)->approve($this->operation, $foreign), 'REPORT_REVIEW_ACTOR_REQUIRED');
        $this->assertSame($before, $this->footprint($this->operation));
        // positive control: the same-company accountant is accepted on the identical operation
        $this->assertSame('approved', app(OperationService::class)->approve($this->operation, $this->accountant)->status);
    }

    public function test_wrong_role_cannot_take_the_other_stage(): void
    {
        $this->assertDenied(fn () => app(OperationService::class)->approve($this->operation, $this->head), 'REPORT_REVIEW_ACTOR_REQUIRED');
        $approved = app(OperationService::class)->approve($this->operation, $this->accountant);
        $before = $this->footprint($approved);
        $this->assertDenied(fn () => app(OperationService::class)->finalApprove($approved, $this->accountant), 'REPORT_REVIEW_ACTOR_REQUIRED');
        $this->assertSame($before, $this->footprint($approved));
    }

    public function test_foreign_company_head_cannot_final_approve_or_return_an_approved_report(): void
    {
        $foreignHead = $this->foreignUser('head', 'f-head@test.invalid');
        $approved = app(OperationService::class)->approve($this->operation, $this->accountant);
        $before = $this->footprint($approved);
        $this->assertDenied(fn () => app(OperationService::class)->finalApprove($approved, $foreignHead), 'REPORT_REVIEW_ACTOR_REQUIRED');
        $this->assertDenied(fn () => app(OperationService::class)->returnForReview($approved, $foreignHead, 'x'), 'REPORT_REVIEW_ACTOR_REQUIRED');
        $this->assertSame($before, $this->footprint($approved));
        $this->assertArrayNotHasKey('return_cycle', $approved->fresh()->payload['pkg6Review']);
    }

    public function test_foreign_company_accountant_cannot_return_a_report_to_the_manager(): void
    {
        $foreign = $this->foreignUser('accountant', 'f-acc2@test.invalid');
        $before = $this->footprint($this->operation);
        $reason = array_key_first(OperationEnums::rejectionReasons('shifts'));
        $this->assertDenied(fn () => app(OperationService::class)->reject($this->operation, $foreign, $reason, 'x'), 'REPORT_REVIEW_ACTOR_REQUIRED');
        $this->assertSame($before, $this->footprint($this->operation));
        $this->assertSame(0, DB::table('shift_report_correction_notifications')->count());
    }

    public function test_inactive_accountant_is_refused(): void
    {
        $inactive = $this->sameCompanyAccountant('inactive@test.invalid', ['scope' => 'all'], 'inactive');
        $before = $this->footprint($this->operation);
        $this->assertDenied(fn () => app(OperationService::class)->approve($this->operation, $inactive), 'REPORT_REVIEW_ACTOR_REQUIRED');
        $this->assertSame($before, $this->footprint($this->operation));
    }

    public function test_accountant_scoped_to_another_branch_or_to_nothing_is_refused(): void
    {
        $company = $this->operation->company_id;
        $otherBranch = Branch::factory()->create(['asab_company_id' => $company]);
        $elsewhere = $this->sameCompanyAccountant('elsewhere@test.invalid', ['scope' => 'branches', 'branch_ids' => [$otherBranch->id]]);
        $nothing = $this->sameCompanyAccountant('nothing@test.invalid', ['scope' => 'branches', 'branch_ids' => []]);
        $before = $this->footprint($this->operation);
        foreach ([$elsewhere, $nothing] as $actor) {
            $this->assertDenied(fn () => app(OperationService::class)->approve($this->operation, $actor), 'ACCOUNTANT_UNAUTHORIZED_FOR_BRANCH');
        }
        $this->assertSame($before, $this->footprint($this->operation));
        // positive control: scoped to THIS branch is accepted
        $mine = $this->sameCompanyAccountant('mine@test.invalid', ['scope' => 'branches', 'branch_ids' => [$this->operation->branch_id]]);
        $this->assertSame('approved', app(OperationService::class)->approve($this->operation, $mine)->status);
    }
}
