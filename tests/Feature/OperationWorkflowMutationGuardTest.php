<?php

namespace Tests\Feature;

require_once __DIR__.'/OperationWorkflowClassificationHttpTest.php';

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Str;
use Modules\Admin\Events\OperationApproved;
use Modules\Admin\Models\CompanyUser;
use Modules\Admin\Models\Operation;
use Modules\Admin\Services\OperationService;
use Modules\Admin\Services\RealtimeBroadcaster;
use Tests\TestCase;

class OperationWorkflowMutationGuardTest extends TestCase
{
    use OperationWorkflowFixture, RefreshDatabase;
    use Pkg6ReviewFixture {
        Pkg6ReviewFixture::setUp as pkg6SetUp;
    }

    protected function setUp(): void
    {
        $this->pkg6SetUp();

        CompanyUser::create([
            'company_id' => $this->accountant->company_id,
            'user_id' => $this->accountant->id,
            'role_key' => 'accountant',
            'status' => 'active',
        ]);
        CompanyUser::create([
            'company_id' => $this->head->company_id,
            'user_id' => $this->head->id,
            'role_key' => 'head',
            'status' => 'active',
        ]);
    }

    private function nativeShiftOperation(): Operation
    {
        // The shared PKG-6 fixture uses a non-numeric SHF id. Keep that unrelated
        // fixture outside OperationSequence's numeric high-water calculation.
        $this->operation->update(['public_id' => 'PROTECTED-'.$this->operation->id]);
        $shift = \Modules\Admin\Models\Shift::create([
            'company_id' => $this->head->company_id, 'branch_id' => $this->day->branch_id,
            'role' => 'cashier', 'status' => 'active', 'started_at' => now(),
            'sales_amount' => 0, 'opening_float' => 0,
        ]);

        return app(\Modules\Admin\Services\ShiftCloseService::class)
            ->close($shift, ['cashActualHalalas' => 0], $this->accountant, 'system')['operation'];
    }

    public function test_verified_native_bulk_preserves_approve_return_final_and_idempotent_replay(): void
    {
        $op = $this->nativeShiftOperation();
        $this->actingAs($this->accountant, 'sanctum')->postJson('/api/v1/operations/bulk-approve',
            ['operationIds' => [$op->id]])->assertOk()->assertJsonPath('approved.0', $op->public_id);
        $this->actingAs($this->head, 'sanctum')->postJson('/api/v1/operations/bulk-return',
            ['operationIds' => [$op->id]])->assertOk()->assertJsonPath('returned.0', $op->public_id);
        $this->assertSame('pending', $op->fresh()->status);
        app(OperationService::class)->approve($op->fresh(), $this->accountant);
        $key = (string) Str::uuid();
        $this->withHeader('Idempotency-Key', $key)->postJson('/api/v1/operations/bulk-final-approve',
            ['operationIds' => [$op->id]])->assertOk()->assertJsonPath('finalApproved.0', $op->public_id);
        $this->assertSame('closed', \Modules\Admin\Models\Shift::findOrFail($op->payload['shiftId'])->status);
        $before = $this->state();
        $this->postJson('/api/v1/operations/bulk-final-approve', ['operationIds' => [$op->id]])->assertOk();
        $this->assertEquals($before, $this->state(), 'Same-key native final retry has no additional effect');
        $this->withHeader('Idempotency-Key', (string) Str::uuid())->postJson('/api/v1/operations/bulk-final-approve',
            ['operationIds' => [$op->id]])->assertOk()->assertJsonPath('failed.0.code', 'OP_NOT_APPROVED');
        $this->assertEquals($before, $this->state());
    }

    private function state(): array
    {
        $state = [];
        foreach (['asab_operations', 'asab_shifts', 'asab_approval_steps', 'asab_employee_movements', 'personal_ledger_transactions', 'cashier_custody_transactions', 'custody_transactions', 'asab_notifications'] as $table) {
            $state[$table] = DB::table($table)->orderBy('id')->get()->toArray();
        }

        return $state;
    }

    public function test_native_plus_protected_selection_and_inconsistent_relationships_have_zero_effect(): void
    {
        $valid = $this->nativeShiftOperation();
        $foreignCompany = \Modules\Admin\Models\AsabCompany::create(['name' => 'Foreign', 'plan' => 'Professional', 'status' => 'active']);
        $otherBranch = \Modules\Branch\Models\Branch::factory()->create(['asab_company_id' => $this->head->company_id]);
        $cases = [
            'missing shift' => fn ($op) => $op->update(['payload' => array_replace($op->payload, ['shiftId' => (string) Str::uuid()])]),
            'conflicting source' => fn ($op) => $op->update(['source_id' => (string) Str::uuid()]),
            'null protected marker' => fn ($op) => $op->update(['payload' => $op->payload + ['pkg6Review' => null]]),
            'financial mismatch' => fn ($op) => $op->update(['payload' => array_replace($op->payload, ['cashActualHalalas' => 1])]),
            'wrong branch' => fn ($op) => \Modules\Admin\Models\Shift::findOrFail($op->payload['shiftId'])->update(['branch_id' => $otherBranch->id]),
            'wrong company' => fn ($op) => \Modules\Admin\Models\Shift::findOrFail($op->payload['shiftId'])->update(['company_id' => $foreignCompany->id]),
            'manager projection' => fn ($op) => \Modules\Admin\Models\Shift::findOrFail($op->payload['shiftId'])->update(['role' => 'branch_manager']),
            'protected legacy link' => fn ($op) => \Modules\Admin\Models\Shift::findOrFail($op->payload['shiftId'])->update(['legacy_shift_id' => $this->source->id]),
            'unclosed source' => fn ($op) => \Modules\Admin\Models\Shift::findOrFail($op->payload['shiftId'])->update(['status' => 'active']),
            'missing submit evidence' => fn ($op) => $op->steps()->delete(),
            'duplicate association' => function ($op) {
                $copy = $op->replicate();
                $copy->public_id = 'DUP-'.Str::uuid();
                $copy->save();
            },
        ];
        $invalid = [];
        foreach ($cases as $name => $corrupt) {
            $op = $this->nativeShiftOperation();
            $corrupt($op);
            $invalid[$name] = $op->fresh();
        }
        $invalid['SAL'] = $this->sal();
        $invalid['SHF'] = $this->operation;
        $invalid['unknown sales'] = $this->sal(['payload' => []]);
        Event::fake([OperationApproved::class, \Modules\Admin\Events\OperationFinalApproved::class,
            \Modules\Admin\Events\OperationReturnedForReview::class]);
        $this->mock(RealtimeBroadcaster::class, function ($mock): void {
            $mock->shouldNotReceive('operationStatusChanged', 'approvalPending', 'notificationNew', 'shiftChanged');
        });
        foreach ($invalid as $name => $op) {
            foreach (['bulk-approve', 'bulk-final-approve', 'bulk-return'] as $route) {
                $before = $this->state();
                $this->actingAs($this->head, 'sanctum')->withHeader('Idempotency-Key', (string) Str::uuid())
                    ->postJson('/api/v1/operations/'.$route, ['operationIds' => [$valid->id, $op->id]])
                    ->assertStatus(409);
                $this->assertEquals($before, $this->state(), $name.' must reject before every mutation');
            }
        }
        Event::assertNothingDispatched();
    }

    public function test_public_upload_cannot_forge_a_native_shift_relationship(): void
    {
        $native = $this->nativeShiftOperation();
        $uploader = \Modules\Admin\Models\AsabUser::create(['company_id' => $this->head->company_id,
            'name' => 'Uploader', 'email' => Str::uuid().'@test.invalid', 'password' => 'synthetic-only', 'status' => 'active']);
        \Modules\Admin\Models\AsabUserRole::create(['user_id' => $uploader->id, 'role_key' => 'branch',
            'scope' => 'branch', 'branch_ids' => [$this->day->branch_id]]);
        CompanyUser::create([
            'company_id' => $this->head->company_id,
            'user_id' => $uploader->id,
            'role_key' => 'branch',
            'status' => 'active',
        ]);
        foreach (['/api/v1/branch/upload/shift-close', '/api/v1/company/me/branch/upload'] as $url) {
            $response = $this->actingAs($uploader, 'sanctum')->postJson($url, [
                'reportType' => 'shift-close', 'shiftId' => $native->payload['shiftId'],
                'legacyShiftId' => null, 'payload' => $native->payload, 'origin' => 'dashboard',
            ])->assertCreated();
            $forged = Operation::findOrFail($response->json('id') ?? $response->json('createdOperationId'));
            $this->assertArrayNotHasKey('shiftId', $forged->payload);
            $before = $this->state();
            $this->actingAs($this->head, 'sanctum')->postJson('/api/v1/operations/bulk-approve',
                ['operationIds' => [$native->id, $forged->id]])->assertStatus(409);
            $this->assertEquals($before, $this->state());
        }
    }

    public function test_native_bulk_preserves_role_and_scope_fail_closed_guards(): void
    {
        $native = $this->nativeShiftOperation();
        $before = $this->state();
        $this->actingAs($this->accountant, 'sanctum')->postJson('/api/v1/operations/bulk-final-approve',
            ['operationIds' => [$native->id]])->assertForbidden();
        foreach (['bulk-approve', 'bulk-final-approve', 'bulk-return'] as $route) {
            $this->actingAs($this->head, 'sanctum')->postJson('/api/v1/operations/'.$route,
                ['operationIds' => [$native->id, (string) Str::uuid()]])->assertForbidden();
            $this->assertEquals($before, $this->state());
        }
        $otherBranch = \Modules\Branch\Models\Branch::factory()->create(['asab_company_id' => $this->head->company_id]);
        $foreignShift = \Modules\Admin\Models\Shift::create(['company_id' => $this->head->company_id,
            'branch_id' => $otherBranch->id, 'status' => 'active', 'started_at' => now(), 'sales_amount' => 0, 'opening_float' => 0]);
        $foreign = app(\Modules\Admin\Services\ShiftCloseService::class)
            ->close($foreignShift, ['cashActualHalalas' => 0], $this->accountant, 'system')['operation'];
        $restricted = \Modules\Admin\Models\AsabUser::create(['company_id' => $this->head->company_id,
            'name' => 'Limited head', 'email' => Str::uuid().'@test.invalid', 'password' => 'test', 'status' => 'active']);
        \Modules\Admin\Models\AsabUserRole::create(['user_id' => $restricted->id, 'role_key' => 'head',
            'scope' => 'branch', 'branch_ids' => [$this->day->branch_id]]);
        CompanyUser::create([
            'company_id' => $this->head->company_id,
            'user_id' => $restricted->id,
            'role_key' => 'head',
            'status' => 'active',
        ]);
        $before = $this->state();
        foreach (['bulk-approve', 'bulk-final-approve', 'bulk-return'] as $route) {
            $this->actingAs($restricted, 'sanctum')->postJson('/api/v1/operations/'.$route,
                ['operationIds' => [$native->id, $foreign->id]])->assertForbidden();
            $this->assertEquals($before, $this->state(), 'A valid foreign Native id cannot be silently filtered out');
        }
    }

    public function test_native_source_is_re_read_during_bulk_preflight_before_any_transition(): void
    {
        $op = $this->nativeShiftOperation();
        $before = $this->state();
        $interleaved = false;
        DB::listen(function ($query) use ($op, &$interleaved): void {
            if (! $interleaved && str_starts_with(strtolower(ltrim($query->sql)), 'select')
                && str_contains($query->sql, 'asab_operations') && in_array($op->id, $query->bindings, true)) {
                $interleaved = true;
                DB::table('asab_shifts')->where('id', $op->payload['shiftId'])->update(['status' => 'active']);
            }
        });
        try {
            app(OperationService::class)->bulkApprove([$op->id], $this->accountant);
            $this->fail('A stale Native source must not be approved');
        } catch (\Modules\Admin\Exceptions\AsabException $error) {
            $this->assertSame('BULK_WORKFLOW_NOT_ALLOWED', $error->errorCode);
        }
        $this->assertTrue($interleaved);
        $this->assertEquals($before, $this->state(), 'The entire transaction, including the injected change, rolls back');
    }

    public function test_direct_sal_and_unknown_return_and_reject_routes_write_nothing(): void
    {
        foreach ([$this->sal(['status' => 'approved']), $this->sal(['status' => 'approved', 'payload' => []])] as $op) {
            foreach (['return-for-review' => ['note' => 'test'], 'reject' => ['reason' => 'incomplete_data']] as $route => $body) {
                $before = $this->state();
                $this->actingAs($this->head, 'sanctum')->withHeader('Idempotency-Key', (string) Str::uuid())
                    ->postJson('/api/v1/operations/'.$op->id.'/'.$route, $body)->assertStatus(409);
                $this->assertEquals($before, $this->state());
            }
        }
    }

    public function test_mixed_bulk_rejects_the_entire_set_before_legacy_mutation(): void
    {
        $legacy = $this->nativeLegacy();
        $legacy->update(['status' => 'approved']);
        foreach ([$this->sal(['status' => 'approved']), $this->sal(['status' => 'approved', 'payload' => []]), $this->operation] as $protected) {
            foreach (['bulk-final-approve', 'bulk-return', 'bulk-approve'] as $route) {
                $before = $this->state();
                $this->actingAs($this->head, 'sanctum')->withHeader('Idempotency-Key', (string) Str::uuid())
                    ->postJson('/api/v1/operations/'.$route, ['operationIds' => [$legacy->id, $protected->id]])->assertStatus(409);
                $this->assertEquals($before, $this->state());
            }
        }
    }

    public function test_verified_legacy_individual_and_bulk_preserve_existing_permissions(): void
    {
        $legacy = $this->nativeLegacy();
        app(OperationService::class)->approve($legacy, $this->accountant);
        $this->actingAs($this->head, 'sanctum')->withHeader('Idempotency-Key', (string) Str::uuid())
            ->postJson('/api/v1/operations/bulk-final-approve', ['operationIds' => [$legacy->id]])->assertOk();
        $this->assertSame('final-approved', $legacy->fresh()->status);
        $other = $this->nativeLegacy();
        app(OperationService::class)->approve($other, $this->accountant);
        $this->actingAs($this->head, 'sanctum')->withHeader('Idempotency-Key', (string) Str::uuid())
            ->postJson('/api/v1/operations/'.$other->id.'/return-for-review')->assertOk();
        $this->assertSame('pending', $other->fresh()->status);
    }

    public function test_sal_individual_final_keeps_member_snapshot_and_duplicate_guards(): void
    {
        $sal = $this->sal();
        $member = app(OperationService::class)->approve($this->operation, $this->accountant);
        $sal = app(OperationService::class)->approve($sal, $this->accountant);
        $this->actingAs($this->head, 'sanctum')->withHeader('Idempotency-Key', (string) Str::uuid())
            ->postJson('/api/v1/operations/'.$sal->id.'/final-approve')->assertStatus(409);
        $this->assertSame('approved', $sal->fresh()->status);
        app(OperationService::class)->finalApprove($member, $this->head);
        $key = (string) Str::uuid();
        $this->actingAs($this->head, 'sanctum')->withHeader('Idempotency-Key', $key)
            ->postJson('/api/v1/operations/'.$sal->id.'/final-approve')->assertOk();
        $before = $this->state();
        $this->withHeader('Idempotency-Key', $key)->postJson('/api/v1/operations/'.$sal->id.'/final-approve')->assertOk();
        $this->assertEquals($before, $this->state(), 'Same-key retry has zero additional effect');
        $this->withHeader('Idempotency-Key', (string) Str::uuid())->postJson('/api/v1/operations/'.$sal->id.'/final-approve')->assertStatus(409);
        $this->assertEquals($before, $this->state());
    }

    public function test_unknown_final_and_wrong_role_are_denied_without_writes(): void
    {
        $unknown = $this->sal(['status' => 'approved', 'payload' => []]);
        $before = $this->state();
        $this->actingAs($this->head, 'sanctum')->withHeader('Idempotency-Key', (string) Str::uuid())
            ->postJson('/api/v1/operations/'.$unknown->id.'/final-approve')->assertStatus(409);
        $this->actingAs($this->accountant, 'sanctum')->withHeader('Idempotency-Key', (string) Str::uuid())
            ->postJson('/api/v1/operations/'.$unknown->id.'/final-approve')->assertStatus(403);
        $this->assertEquals($before, $this->state());
    }

    public function test_historical_legacy_submit_without_server_provenance_is_unknown_and_all_mutations_fail_closed(): void
    {
        $historical = $this->nativeLegacy();
        $submit = $historical->steps()->where('stage_id', 'submit')->firstOrFail();
        $submit->update(['meta' => null]);
        $historical->update([
            'status' => 'approved',
            'approved_by_id' => $this->accountant->id,
            'approved_at' => now(),
            'origin' => 'mobile',
        ]);

        $this->assertNull($historical->fresh()->steps()->where('stage_id', 'submit')->firstOrFail()->meta);
        $this->assertArrayNotHasKey('managerShiftId', $historical->payload ?? []);
        $this->assertArrayNotHasKey('pkg6Review', $historical->payload ?? []);
        $this->assertSame('unknown', $this->workflow($historical), 'Historical submit alone is not Legacy proof');
        $listRow = collect($this->getJson('/api/v1/operations?moduleKey=sales')->assertOk()->json('data'))
            ->firstWhere('id', $historical->id);
        $this->assertSame('unknown', $listRow['workflowKind'] ?? null, 'List projection uses the same fail-closed classifier');
        $before = $this->state();
        $this->actingAs($this->head, 'sanctum')->withHeader('Idempotency-Key', (string) Str::uuid())
            ->postJson('/api/v1/operations/'.$historical->id.'/final-approve')->assertStatus(409);
        $this->withHeader('Idempotency-Key', (string) Str::uuid())
            ->postJson('/api/v1/operations/'.$historical->id.'/return-for-review', ['note' => 'review'])->assertStatus(409);
        $this->withHeader('Idempotency-Key', (string) Str::uuid())
            ->postJson('/api/v1/operations/'.$historical->id.'/reject', ['reason' => 'incomplete_data'])->assertStatus(409);

        foreach (['bulk-final-approve', 'bulk-return', 'bulk-approve'] as $route) {
            $this->withHeader('Idempotency-Key', (string) Str::uuid())
                ->postJson('/api/v1/operations/'.$route, ['operationIds' => [$historical->id]])->assertStatus(409);
        }

        $this->assertEquals($before, $this->state(), 'No operation, audit, financial, or notification write survives');
    }

    public function test_bulk_approval_failure_after_first_transition_rolls_back_and_emits_no_external_effects(): void
    {
        $first = $this->nativeLegacy();
        $second = $this->nativeLegacy();
        $realtime = new class extends RealtimeBroadcaster
        {
            public array $calls = [];

            public function operationStatusChanged(Operation $op, string $oldStatus, string $newStatus, \Modules\Admin\Models\AsabUser $actor): void
            {
                $this->calls[] = 'operationStatusChanged';
            }

            public function approvalPending(string $companyId, string $accountantId, int $count): void
            {
                $this->calls[] = 'approvalPending';
            }

            public function notificationNew(\Modules\Admin\Models\AsabNotification $notification): void
            {
                $this->calls[] = 'notificationNew';
            }
        };
        app()->instance(RealtimeBroadcaster::class, $realtime);
        $before = $this->state();

        $eventCalls = 0;
        Event::listen(OperationApproved::class, function () use (&$eventCalls): void {
            $eventCalls++;
        });

        $firstTransitionDone = false;
        $failureInjected = false;
        DB::listen(function ($query) use (&$firstTransitionDone, &$failureInjected, $second): void {
            $sql = strtolower($query->sql);
            if (str_contains($sql, 'insert') && str_contains($sql, 'asab_approval_steps')
                && in_array('approved', $query->bindings, true)) {
                $firstTransitionDone = true;
            }

            if ($firstTransitionDone && str_contains($sql, 'select') && str_contains($sql, 'asab_operations')
                && in_array($second->id, array_map('strval', $query->bindings), true)) {
                $failureInjected = true;
                throw new \RuntimeException('A02 injected between bulk transitions');
            }
        });

        try {
            app(OperationService::class)->bulkApprove([$first->id, $second->id], $this->accountant);
            $this->fail('The injected failure must abort the outer bulk transaction');
        } catch (\RuntimeException $exception) {
            $this->assertSame('A02 injected between bulk transitions', $exception->getMessage());
        }

        $this->assertTrue($failureInjected, 'Failure was injected after the first transition and before the next item');
        $this->assertEquals($before, $this->state(), 'Status, audit, financial, and notification rows all roll back');
        $this->assertSame(0, $eventCalls, 'Application events are not dispatched before the outer commit');
        $this->assertSame([], $realtime->calls, 'Realtime and notification broadcasts are not emitted before the outer commit');
    }

    public function test_bulk_does_not_silently_drop_missing_or_unassigned_ids(): void
    {
        $legacy = $this->nativeLegacy();
        $legacy->update(['status' => 'approved']);
        foreach (['bulk-final-approve', 'bulk-return', 'bulk-approve'] as $route) {
            $before = $this->state();
            $this->actingAs($this->head, 'sanctum')->withHeader('Idempotency-Key', (string) Str::uuid())
                ->postJson('/api/v1/operations/'.$route, ['operationIds' => [$legacy->id, (string) Str::uuid()]])->assertForbidden();
            $this->assertEquals($before, $this->state());
        }
    }

    public function test_changed_sal_snapshot_cannot_finalize_and_writes_nothing(): void
    {
        app(OperationService::class)->approve($this->operation, $this->accountant);
        $sal = app(OperationService::class)->approve($this->sal(), $this->accountant);
        app(OperationService::class)->finalApprove($this->operation->fresh(), $this->head);
        $payload = $sal->payload;
        $payload['pkg6Review']['approved_snapshot']['day_revision'] = (string) Str::uuid();
        $sal->update(['payload' => $payload]);
        $before = $this->state();
        $this->actingAs($this->head, 'sanctum')->withHeader('Idempotency-Key', (string) Str::uuid())
            ->postJson('/api/v1/operations/'.$sal->id.'/final-approve')->assertStatus(409);
        $this->assertEquals($before, $this->state());
    }

    public function test_accountant_reject_pending_sal_with_null_review_is_protected(): void
    {
        foreach ([$this->sal(), $this->sal(['payload' => []])] as $op) {
            $before = $this->state();
            $this->actingAs($this->accountant, 'sanctum')->postJson('/api/v1/operations/'.$op->id.'/reject',
                ['reason' => 'incomplete_data'])->assertStatus(409);
            $this->assertEquals($before, $this->state());
        }
    }

    public function test_sal_final_requires_actual_head_phase_even_with_a_current_snapshot(): void
    {
        app(OperationService::class)->approve($this->operation, $this->accountant);
        $sal = app(OperationService::class)->approve($this->sal(), $this->accountant);
        app(OperationService::class)->finalApprove($this->operation->fresh(), $this->head);
        foreach ([null, 'accountant_review', 'head_returned', 'manager_returned'] as $phase) {
            $payload = $sal->payload;
            $payload['pkg6Review']['phase'] = $phase;
            $sal->update(['payload' => $payload]);
            $before = $this->state();
            $this->actingAs($this->head, 'sanctum')->withHeader('Idempotency-Key', (string) Str::uuid())
                ->postJson('/api/v1/operations/'.$sal->id.'/final-approve')->assertStatus(409);
            $this->assertEquals($before, $this->state());
        }
    }
}
