<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Routing\Route;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Modules\Admin\Http\Middleware\IdempotencyKey;
use Modules\Branch\Models\Branch;
use Modules\BranchManagers\Models\BranchManager;
use Modules\Cashier\Models\Cashier;
use Modules\Custody\Models\CashierCustodyTransaction;
use Modules\Custody\Models\PersonalLedgerTransaction;
use Modules\Shift\Enums\ShiftStatus;
use Modules\Shift\Models\CashierShift;
use Modules\Shift\Models\CashierShiftHandover;
use Modules\Shift\Models\CashierShiftHandoverReceipt;
use Modules\Shift\Models\Shift;
use Symfony\Component\HttpFoundation\Response;
use Tests\TestCase;

/** S1-09: request replay through the real mobile shift routes (TX-02, TX-03, TX-04). */
class ShiftCommandIdempotencyHttpTest extends TestCase
{
    use RefreshDatabase;

    public function test_tx02_tx04_end_only_replays_original_response_without_second_report_effect(): void
    {
        [, , $template] = $this->branchWithManager();
        $cashier = $this->cashierFor($template);
        $shift = $this->liveShift($cashier, $template);
        $key = (string) Str::uuid();
        $payload = ['total_sales' => '80.00', 'cash_collected' => '80.00', 'counted_cash' => '80.00'];

        $first = $this->withKey($key)->actingAs($cashier, 'sanctum')->postJson("/api/v1/cashier/shifts/{$shift->id}/end", $payload);
        $retry = $this->withKey($key)->actingAs($cashier, 'sanctum')->postJson("/api/v1/cashier/shifts/{$shift->id}/end", $payload);
        $alias = $this->withKey($key)->actingAs($cashier, 'sanctum')->postJson("/api/cashier/shifts/{$shift->id}/end", $payload);

        $first->assertOk()->assertJsonPath('success', true);
        $this->assertSame(200, $retry->getStatusCode());
        $this->assertSame($first->getContent(), $retry->getContent());
        $this->assertSame($first->getContent(), $alias->getContent());
        $this->assertSame(ShiftStatus::COMPLETED, $shift->fresh()->status);
        $this->assertSame(1, CashierCustodyTransaction::where('related_shift_id', $shift->id)->where('transaction_type', 'Total Sales')->count());

        $record = DB::table('asab_command_idempotency_keys')->sole();
        $this->assertSame('completed', $record->status);
        $this->assertTrue(now()->addDays(89)->lessThan($record->response_expires_at));
    }

    public function test_tx02_end_with_handover_and_manager_receipt_routes_replay_exactly_once(): void
    {
        [$branch, $manager, $template] = $this->branchWithManager();
        $cashier = $this->cashierFor($template);
        $shift = $this->liveShift($cashier, $template);
        $endKey = (string) Str::uuid();
        $payload = ['total_sales' => '200.00', 'cash_collected' => '200.00', 'counted_cash' => '200.00', 'handover_to_type' => 'branch_manager', 'handover_amount' => '200.00'];

        $end = $this->withKey($endKey)->actingAs($cashier, 'sanctum')->postJson("/api/v1/cashier/shifts/{$shift->id}/end-with-handover", $payload);
        $endRetry = $this->withKey($endKey)->actingAs($cashier, 'sanctum')->postJson("/api/v1/cashier/shifts/{$shift->id}/end-with-handover", $payload);
        $end->assertOk();
        $this->assertSame($end->getContent(), $endRetry->getContent());
        $this->assertSame(1, CashierShiftHandover::where('cashier_shift_id', $shift->id)->count());

        $this->flushHeaders();
        $this->actingAs($manager, 'sanctum')->getJson('/api/v1/branch-manager/workday/current')->assertOk();

        $approveKey = (string) Str::uuid();
        $approve = $this->withKey($approveKey)->actingAs($manager, 'sanctum')
            ->postJson("/api/v1/branch-manager/shifts/{$shift->id}/handover/approve", ['confirmed_amount' => '200.00']);
        $approveRetry = $this->withKey($approveKey)->actingAs($manager, 'sanctum')
            ->postJson("/api/v1/branch-manager/shifts/{$shift->id}/handover/approve", ['confirmed_amount' => '200.00']);
        $approve->assertOk();
        $this->assertSame($approve->getContent(), $approveRetry->getContent());

        $receipt = CashierShiftHandoverReceipt::query()->sole();
        $this->assertSame('200.00', (string) $receipt->confirmed_amount);
        $this->assertSame(1, PersonalLedgerTransaction::where('receipt_id', $receipt->id)->count());

        // The workday route is protected too: a second request for the same
        // shift under its own key replays exactly once.
        $second = $this->cashierFor($template, $branch);
        $secondShift = $this->liveShift($second, Shift::factory()->create(['branch_id' => $branch->id, 'is_active' => true, 'start_time' => '16:00:00', 'end_time' => '23:00:00']));
        $this->flushHeaders();
        $this->actingAs($second, 'sanctum')->postJson("/api/v1/cashier/shifts/{$secondShift->id}/end-with-handover", array_merge($payload, ['total_sales' => '50.00', 'cash_collected' => '50.00', 'handover_amount' => '50.00']))->assertOk();
        $handover = CashierShiftHandover::where('cashier_shift_id', $secondShift->id)->sole();

        $workdayKey = (string) Str::uuid();
        $workday = $this->withKey($workdayKey)->actingAs($manager, 'sanctum')
            ->postJson('/api/v1/branch-manager/workday/handoffs/approve', ['handover_id' => $handover->id, 'confirmed_amount' => '50.00']);
        $workdayRetry = $this->withKey($workdayKey)->actingAs($manager, 'sanctum')
            ->postJson('/api/branch-manager/workday/handoffs/approve', ['handover_id' => $handover->id, 'confirmed_amount' => '50.00']);
        $workday->assertOk();
        $this->assertSame($workday->getContent(), $workdayRetry->getContent());
        $this->assertSame(2, CashierShiftHandoverReceipt::count());
        $this->assertSame(1, CashierShiftHandoverReceipt::where('cashier_shift_handover_id', $handover->id)->count());
    }

    public function test_tx03_changed_payload_is_rejected_in_the_legacy_envelope_without_effect(): void
    {
        [, , $template] = $this->branchWithManager();
        $cashier = $this->cashierFor($template);
        $shift = $this->liveShift($cashier, $template);
        $key = (string) Str::uuid();

        $this->withKey($key)->actingAs($cashier, 'sanctum')
            ->postJson("/api/v1/cashier/shifts/{$shift->id}/end", ['total_sales' => '100.00', 'cash_collected' => '100.00', 'counted_cash' => '100.00'])->assertOk();
        $conflict = $this->withKey($key)->actingAs($cashier, 'sanctum')
            ->postJson("/api/v1/cashier/shifts/{$shift->id}/end", ['total_sales' => '90.00', 'cash_collected' => '90.00', 'counted_cash' => '90.00']);

        $conflict->assertStatus(409)
            ->assertJsonPath('success', false)
            ->assertJsonPath('code', 'IDEMPOTENCY_KEY_REUSED')
            ->assertJsonPath('message', 'IDEMPOTENCY_KEY_REUSED');
        $this->assertSame('100.00', (string) $shift->fresh()->total_sales);
        $this->assertSame(1, CashierCustodyTransaction::where('related_shift_id', $shift->id)->count());
    }

    public function test_tx03_key_of_another_actor_is_rejected_without_disclosure_or_effect(): void
    {
        [, , $template] = $this->branchWithManager();
        $first = $this->cashierFor($template);
        $other = $this->cashierFor($template);
        $firstShift = $this->liveShift($first, $template);
        $otherShift = $this->liveShift($other, Shift::factory()->create(['branch_id' => $template->branch_id, 'is_active' => true, 'start_time' => '16:00:00', 'end_time' => '23:00:00']));
        $key = (string) Str::uuid();
        $payload = ['total_sales' => '40.00', 'cash_collected' => '40.00', 'counted_cash' => '40.00'];

        $this->withKey($key)->actingAs($first, 'sanctum')->postJson("/api/v1/cashier/shifts/{$firstShift->id}/end", $payload)->assertOk();
        $conflict = $this->withKey($key)->actingAs($other, 'sanctum')->postJson("/api/v1/cashier/shifts/{$otherShift->id}/end", $payload);

        $conflict->assertStatus(409)->assertJsonPath('code', 'IDEMPOTENCY_KEY_REUSED');
        $this->assertStringNotContainsString('Shift ended', $conflict->getContent());
        $this->assertSame(ShiftStatus::IN_PROGRESS, $otherShift->fresh()->status);
        $this->assertSame(0, CashierCustodyTransaction::where('related_shift_id', $otherShift->id)->count());
    }

    public function test_failed_transactional_command_rolls_back_and_releases_its_key(): void
    {
        $branch = Branch::factory()->create();
        $template = Shift::factory()->create(['branch_id' => $branch->id, 'is_active' => true]);
        $creator = BranchManager::factory()->create(['branch_id' => Branch::factory()->create()->id]);
        $cashier = Cashier::factory()->create(['branch_id' => $branch->id, 'created_by' => $creator->id]);
        $shift = $this->liveShift($cashier, $template);
        $key = (string) Str::uuid();
        $payload = ['total_sales' => '70.00', 'cash_collected' => '70.00', 'counted_cash' => '70.00', 'handover_to_type' => 'branch_manager', 'handover_amount' => '70.00'];

        $failed = $this->withKey($key)->actingAs($cashier, 'sanctum')->postJson("/api/v1/cashier/shifts/{$shift->id}/end-with-handover", $payload);

        $failed->assertStatus(409)->assertJsonPath('code', 'BRANCH_MANAGER_RECIPIENT_UNAVAILABLE');
        $this->assertSame(ShiftStatus::IN_PROGRESS, $shift->fresh()->status);
        $this->assertSame(0, CashierShiftHandover::count());
        $this->assertSame(0, CashierCustodyTransaction::where('related_shift_id', $shift->id)->count());
        $this->assertSame(0, DB::table('asab_command_idempotency_keys')->count());

        BranchManager::factory()->create(['branch_id' => $branch->id, 'is_active' => true, 'status' => 'active']);
        $retry = $this->withKey($key)->actingAs($cashier, 'sanctum')->postJson("/api/v1/cashier/shifts/{$shift->id}/end-with-handover", $payload);

        $retry->assertOk();
        $this->assertSame(ShiftStatus::COMPLETED, $shift->fresh()->status);
        $this->assertSame(1, CashierShiftHandover::count());
        $this->assertSame('completed', DB::table('asab_command_idempotency_keys')->sole()->status);
    }

    public function test_writer_mode_domain_error_releases_key_on_both_aliases_and_replays_across_them(): void
    {
        [$branch, , $template] = $this->branchWithManager();
        $sender = $this->cashierFor($template);
        $recipient = $this->cashierFor($template);
        $source = $this->liveShift($sender, $template);
        $destination = $this->liveShift($recipient, Shift::factory()->create(['branch_id' => $branch->id, 'is_active' => true, 'start_time' => '16:00:00', 'end_time' => '23:00:00']));
        $this->actingAs($sender, 'sanctum')->postJson("/api/v1/cashier/shifts/{$source->id}/end-with-handover", [
            'total_sales' => '100.00', 'cash_collected' => '100.00', 'counted_cash' => '100.00', 'next_cashier_id' => $recipient->id, 'handover_amount' => '100.00',
        ])->assertOk();

        $key = (string) Str::uuid();
        $mismatch = ['confirmed_amount' => '90.00', 'receiving_shift_id' => $destination->id];
        foreach (['/api', '/api/v1'] as $prefix) {
            foreach ([1, 2] as $attempt) {
                $this->withKey($key)->actingAs($recipient, 'sanctum')
                    ->postJson("{$prefix}/cashier/shifts/{$source->id}/handover/accept", $mismatch)
                    ->assertStatus(409)
                    ->assertJsonPath('code', 'HANDOVER_AMOUNT_MISMATCH_CORRECTION_REQUIRED');
            }
        }
        $this->assertSame(0, DB::table('asab_command_idempotency_keys')->count());
        $this->assertSame(0, CashierShiftHandoverReceipt::count());

        $exactKey = (string) Str::uuid();
        $exact = ['confirmed_amount' => '100.00', 'receiving_shift_id' => $destination->id];
        $accepted = $this->withKey($exactKey)->actingAs($recipient, 'sanctum')->postJson("/api/v1/cashier/shifts/{$source->id}/handover/accept", $exact);
        $aliasReplay = $this->withKey($exactKey)->actingAs($recipient, 'sanctum')->postJson("/api/cashier/shifts/{$source->id}/handover/accept", $exact);

        $accepted->assertOk();
        $this->assertSame($accepted->getContent(), $aliasReplay->getContent());
        $this->assertSame(1, CashierShiftHandoverReceipt::count());
        $this->assertSame(1, CashierCustodyTransaction::where('transaction_type', 'Handover Received')->count());
    }

    public function test_in_progress_duplicate_returns_retry_after_in_the_legacy_envelope(): void
    {
        [, , $template] = $this->branchWithManager();
        $cashier = $this->cashierFor($template);
        $middleware = app(IdempotencyKey::class);
        $nested = null;

        $outer = $middleware->handle($this->commandRequest($cashier, 'in-flight'), function () use ($middleware, $cashier, &$nested): Response {
            $nested = $middleware->handle($this->commandRequest($cashier, 'in-flight'), fn (): Response => response()->json(['ran' => true]), null, 'transaction', 'legacy');

            return response()->json(['success' => true]);
        }, null, 'transaction', 'legacy');

        $this->assertSame(200, $outer->getStatusCode());
        $this->assertSame(409, $nested->getStatusCode());
        $this->assertSame('2', $nested->headers->get('Retry-After'));
        $body = json_decode($nested->getContent(), true);
        $this->assertFalse($body['success']);
        $this->assertSame('IDEMPOTENCY_IN_PROGRESS', $body['code']);
        $this->assertSame('completed', DB::table('asab_command_idempotency_keys')->sole()->status);
    }

    public function test_snapshot_failure_rolls_back_the_command_in_the_same_commit(): void
    {
        [, , $template] = $this->branchWithManager();
        $cashier = $this->cashierFor($template);
        $shift = $this->liveShift($cashier, $template);
        $key = (string) Str::uuid();
        $payload = ['total_sales' => '60.00', 'cash_collected' => '60.00', 'counted_cash' => '60.00'];
        DB::statement("CREATE TRIGGER block_idempotency_completion BEFORE UPDATE ON asab_command_idempotency_keys WHEN NEW.status = 'completed' BEGIN SELECT RAISE(ABORT, 'forced completion failure'); END");

        $failed = $this->withKey($key)->actingAs($cashier, 'sanctum')->postJson("/api/v1/cashier/shifts/{$shift->id}/end", $payload);

        $failed->assertStatus(500);
        $this->assertSame(ShiftStatus::IN_PROGRESS, $shift->fresh()->status);
        $this->assertSame(0, CashierCustodyTransaction::where('related_shift_id', $shift->id)->count());
        $this->assertSame(0, DB::table('asab_command_idempotency_keys')->count());

        DB::statement('DROP TRIGGER block_idempotency_completion');
        $retry = $this->withKey($key)->actingAs($cashier, 'sanctum')->postJson("/api/v1/cashier/shifts/{$shift->id}/end", $payload);

        $retry->assertOk();
        $this->assertSame(ShiftStatus::COMPLETED, $shift->fresh()->status);
        $this->assertSame(1, CashierCustodyTransaction::where('related_shift_id', $shift->id)->count());
        $this->assertSame('completed', DB::table('asab_command_idempotency_keys')->sole()->status);
    }

    public function test_tx04_failure_after_commit_replays_the_committed_snapshot_once(): void
    {
        [, , $template] = $this->branchWithManager();
        $cashier = $this->cashierFor($template);
        $shift = $this->liveShift($cashier, $template);
        $key = (string) Str::uuid();
        $payload = ['total_sales' => '55.00', 'cash_collected' => '55.00', 'counted_cash' => '55.00'];
        $fired = false;
        CashierShift::updated(function () use (&$fired): void {
            if (! $fired) {
                $fired = true;
                DB::afterCommit(static function (): void {
                    throw new \RuntimeException('Simulated response loss after the business commit.');
                });
            }
        });

        $lost = $this->withKey($key)->actingAs($cashier, 'sanctum')->postJson("/api/v1/cashier/shifts/{$shift->id}/end", $payload);

        $lost->assertStatus(500);
        $this->assertSame(ShiftStatus::COMPLETED, $shift->fresh()->status);
        $record = DB::table('asab_command_idempotency_keys')->sole();
        $this->assertSame('completed', $record->status);

        $retry = $this->withKey($key)->actingAs($cashier, 'sanctum')->postJson("/api/v1/cashier/shifts/{$shift->id}/end", $payload);

        $retry->assertOk();
        $this->assertSame($record->response_body, $retry->getContent());
        $this->assertSame(1, CashierCustodyTransaction::where('related_shift_id', $shift->id)->count());
    }

    public function test_tx02_start_handover_and_record_handover_replay_exactly_once(): void
    {
        [$branch, , $template] = $this->branchWithManager();
        $sender = $this->cashierFor($template);
        $recipient = $this->cashierFor($template);
        $late = Shift::factory()->create(['branch_id' => $branch->id, 'is_active' => true, 'start_time' => '16:00:00', 'end_time' => '23:00:00']);

        foreach (['start-handover' => $template, 'handover' => $late] as $route => $shiftTemplate) {
            $shift = $this->liveShift($sender, $shiftTemplate);
            $this->flushHeaders();
            $this->actingAs($sender, 'sanctum')->postJson("/api/v1/cashier/shifts/{$shift->id}/end", ['total_sales' => '30.00', 'cash_collected' => '30.00', 'counted_cash' => '30.00'])->assertOk();

            $key = (string) Str::uuid();
            $body = ['next_cashier_id' => $recipient->id, 'handover_amount' => '30.00'];
            $first = $this->withKey($key)->actingAs($sender, 'sanctum')->postJson("/api/v1/cashier/shifts/{$shift->id}/{$route}", $body);
            $retry = $this->withKey($key)->actingAs($sender, 'sanctum')->postJson("/api/cashier/shifts/{$shift->id}/{$route}", $body);

            $first->assertOk();
            $this->assertSame($first->getContent(), $retry->getContent(), $route);
            $this->assertSame(1, CashierShiftHandover::where('cashier_shift_id', $shift->id)->count(), $route);
        }
    }

    private function withKey(string $key): static
    {
        return $this->withHeaders(['Idempotency-Key' => $key]);
    }

    private function commandRequest(Cashier $actor, string $key): Request
    {
        $request = Request::create('/api/v1/test/commands/c1', 'POST', [], [], [], [
            'CONTENT_TYPE' => 'application/json', 'HTTP_IDEMPOTENCY_KEY' => $key,
        ], '{"amount":"1.00"}');
        $route = new Route(['POST'], 'api/v1/test/commands/{command}', ['uses' => static fn () => null]);
        $route->name('test.command');
        $route->bind($request);
        $request->setRouteResolver(static fn () => $route);
        $request->setUserResolver(static fn () => $actor);

        return $request;
    }

    /** @return array{0: Branch, 1: BranchManager, 2: Shift} */
    private function branchWithManager(): array
    {
        $branch = Branch::factory()->create();
        $manager = BranchManager::factory()->create(['branch_id' => $branch->id, 'is_active' => true, 'status' => 'active']);
        $template = Shift::factory()->create(['branch_id' => $branch->id, 'is_active' => true]);

        return [$branch, $manager, $template];
    }

    private function cashierFor(Shift $template, ?Branch $branch = null): Cashier
    {
        $branchId = $branch?->id ?? $template->branch_id;
        $creator = BranchManager::query()->where('branch_id', $branchId)->value('id')
            ?? BranchManager::factory()->create(['branch_id' => Branch::factory()->create()->id])->id;

        return Cashier::factory()->create(['branch_id' => $branchId, 'created_by' => $creator]);
    }

    private function liveShift(Cashier $cashier, Shift $template): CashierShift
    {
        $shift = CashierShift::create([
            'cashier_id' => $cashier->id,
            'shift_id' => $template->id,
            'shift_date' => today()->toDateString(),
            'status' => ShiftStatus::NOT_STARTED->value,
        ]);
        $shift->update(['status' => ShiftStatus::IN_PROGRESS, 'actual_start_time' => now()]);

        return $shift->fresh();
    }
}
