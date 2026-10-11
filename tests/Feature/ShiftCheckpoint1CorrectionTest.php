<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Modules\Admin\Models\CommandIdempotencyRecord;
use Modules\Branch\Models\Branch;
use Modules\BranchManagers\Models\BranchManager;
use Modules\Cashier\Models\Cashier;
use Modules\Shift\Models\BranchManagerShift;
use Modules\Shift\Models\CashierShift;
use Modules\Shift\Models\CashierShiftHandover;
use Modules\Shift\Models\Shift;
use Modules\Shift\Models\ShiftHandoverStatus;
use Modules\Shift\Models\ShiftReportCorrection;
use Modules\Shift\Models\ShiftReportRevision;
use Modules\Shift\Services\HandoverService;
use Modules\Shift\Services\ShiftCashCountService;
use Modules\Shift\Services\ShiftReportCorrectionService;
use Modules\Shift\Services\ShiftReportRevisionService;
use Modules\Shift\Transformers\BranchManagerShiftResource;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class ShiftCheckpoint1CorrectionTest extends TestCase
{
    use RefreshDatabase;

    public static function correctionRoutes(): array
    {
        return [['/api'], ['/api/v1']];
    }

    #[DataProvider('correctionRoutes')]
    public function test_same_recipient_command_requires_a_key_and_expected_revision(string $prefix): void
    {
        [$cashier, $source] = $this->fixture();
        $url = "{$prefix}/cashier/shifts/{$source->id}/handover/edit";
        $this->actingAs($cashier, 'sanctum')->getJson("{$prefix}/cashier/shifts/{$source->id}/handover/status")
            ->assertOk()->assertJsonPath('data.report_revision_number', 1);
        $this->actingAs($cashier, 'sanctum')->postJson($url, $this->payload())
            ->assertStatus(422)->assertJsonPath('code', 'IDEMPOTENCY_KEY_REQUIRED');
        $payload = $this->payload();
        unset($payload['expected_revision']);
        $this->withHeader('Idempotency-Key', (string) Str::uuid())->postJson($url, $payload)
            ->assertStatus(422)->assertJsonValidationErrors('expected_revision');
        $this->assertSame(1, ShiftReportRevision::count());
        $this->assertSame('100.00', (string) $source->fresh()->closing_balance);
    }

    #[DataProvider('correctionRoutes')]
    public function test_retry_replays_exact_response_with_one_revision_and_same_request(string $prefix): void
    {
        [$cashier, $source, $handover] = $this->fixture();
        $key = (string) Str::uuid();
        $url = "{$prefix}/cashier/shifts/{$source->id}/handover/edit";
        $first = $this->actingAs($cashier, 'sanctum')->withHeader('Idempotency-Key', $key)
            ->postJson($url, $this->payload())->assertOk();
        $first->assertJsonPath('data.edit_details.report_revision_number', 2);
        $afterFirst = $this->effectCounts($source->id);
        $this->assertSame(2, $afterFirst['shift_report_revisions']);
        $retry = $this->postJson($url, $this->payload())->assertOk();
        $this->assertSame($first->getContent(), $retry->getContent());
        $this->assertSame($afterFirst, $this->effectCounts($source->id));
        $this->assertSame($handover->id, $source->fresh()->handover->id);
        $this->assertSame(1, CashierShiftHandover::where('cashier_shift_id', $source->id)->count());
        $this->assertSame('pending', $handover->fresh()->status);
        $this->assertNull($handover->fresh()->current_transfer_attempt_id);
    }

    public function test_same_key_different_payload_is_a_payload_mismatch(): void
    {
        [$cashier, $source] = $this->fixture();
        $url = "/api/cashier/shifts/{$source->id}/handover/edit";
        $this->actingAs($cashier, 'sanctum')->withHeader('Idempotency-Key', (string) Str::uuid())
            ->postJson($url, $this->payload())->assertOk();
        $before = $this->effectCounts($source->id);
        $this->postJson($url, array_replace($this->payload(), ['handover_amount' => '85.00']))
            ->assertStatus(409)->assertJsonPath('code', 'IDEMPOTENCY_PAYLOAD_MISMATCH');
        $this->assertSame($before, $this->effectCounts($source->id));
    }

    public function test_new_key_with_stale_revision_is_rejected_before_not_correctable_state(): void
    {
        [$cashier, $source] = $this->fixture();
        $url = "/api/cashier/shifts/{$source->id}/handover/edit";
        $this->actingAs($cashier, 'sanctum')->withHeader('Idempotency-Key', (string) Str::uuid())
            ->postJson($url, $this->payload())->assertOk();
        $before = $this->effectCounts($source->id);
        $this->withHeader('Idempotency-Key', (string) Str::uuid())
            ->postJson($url, $this->payload())
            ->assertStatus(409)->assertJsonPath('code', 'STALE_REPORT_REVISION');
        $this->assertSame($before, $this->effectCounts($source->id));
    }

    public function test_lost_response_replays_durable_result_after_cache_loss_and_state_transition(): void
    {
        [$cashier, $source, $handover] = $this->fixture();
        $key = (string) Str::uuid();
        $url = "/api/cashier/shifts/{$source->id}/handover/edit";
        // Discard the successful HTTP response, as if the caller lost it.
        $this->actingAs($cashier, 'sanctum')->withHeader('Idempotency-Key', $key)
            ->postJson($url, $this->payload())->assertOk();
        $record = CommandIdempotencyRecord::where('idempotency_key', $key)->sole();
        $this->assertSame('completed', $record->status);
        $this->assertNotNull($record->completed_at);
        $stored = $record->response_body;
        $before = $this->effectCounts($source->id);
        $this->assertSame('pending', $handover->fresh()->status);
        $this->assertFalse($source->fresh()->handoverStatus->canCashierEdit());
        \Illuminate\Support\Facades\Cache::flush();
        $retry = $this->postJson($url, $this->payload())->assertOk();
        $this->assertSame($stored, $retry->getContent());
        $this->assertSame($before, $this->effectCounts($source->id));
    }

    public function test_stale_direct_correction_rolls_back_all_evidence(): void
    {
        [$cashier, $source] = $this->fixture();
        $this->actingAs($cashier, 'sanctum');
        $before = $this->effectCounts($source->id);
        try {
            app(HandoverService::class)->recordHandoverEdit($source, array_replace($this->payload(), ['expected_revision' => 0]));
            $this->fail('A stale direct correction must be rejected.');
        } catch (\Symfony\Component\HttpKernel\Exception\ConflictHttpException $error) {
            $this->assertSame('STALE_REPORT_REVISION', $error->getMessage());
        }
        $this->assertSame($before, $this->effectCounts($source->id));
        $this->assertSame('100.00', (string) $source->fresh()->closing_balance);
    }

    public function test_public_correction_cannot_silently_change_recipient(): void
    {
        [$cashier, $source, $handover] = $this->fixture();
        $before = $this->effectCounts($source->id);
        $this->actingAs($cashier, 'sanctum')->withHeader('Idempotency-Key', (string) Str::uuid())
            ->postJson("/api/cashier/shifts/{$source->id}/handover/edit", array_replace($this->payload(), [
                'handover_to_id' => (string) Str::uuid(),
            ]))->assertStatus(409)->assertJsonPath('code', 'HANDOVER_RECIPIENT_CHANGE_REQUIRES_REPLACEMENT');
        $this->assertSame($before, $this->effectCounts($source->id));
        $this->assertSame('rejected', $handover->fresh()->status);
    }

    public function test_forward_metadata_migration_preserves_every_evidence_value(): void
    {
        [$cashier, $source] = $this->fixture();
        $revision = app(ShiftReportCorrectionService::class)->correctCashierReport(
            $source, $cashier, ['card_payments' => '10.01'], 1, 'CP1 legacy unit label', (string) Str::uuid()
        );
        $id = ShiftReportCorrection::where('new_revision_id', $revision->id)->where('field_name', 'card_payments')->sole()->id;
        DB::table('shift_report_corrections')->where('id', $id)->update(['field_type' => 'monetary']);
        $before = (array) DB::table('shift_report_corrections')->where('id', $id)->sole();
        $migration = require base_path('Modules/Shift/database/migrations/2026_10_10_000010_normalize_shift_report_correction_monetary_type.php');
        $migration->up();
        $after = (array) DB::table('shift_report_corrections')->where('id', $id)->sole();
        $this->assertSame('monetary_halalas', $after['field_type']);
        $before['field_type'] = 'monetary_halalas';
        $this->assertSame($before, $after);
        $migration->up();
        $migration->down();
        $this->assertSame($after, (array) DB::table('shift_report_corrections')->where('id', $id)->sole());
    }

    public function test_submitted_workday_reports_reopen_disabled_and_endpoint_is_unchanged(): void
    {
        $company = \Modules\Admin\Models\AsabCompany::create(['name' => 'CP1 daily evidence company', 'plan' => 'Professional', 'status' => 'active']);
        $branch = Branch::factory()->create(['asab_company_id' => $company->id]);
        $manager = BranchManager::factory()->create(['branch_id' => $branch->id]);
        $day = BranchManagerShift::firstOrCreate([
            'branch_manager_id' => $manager->id, 'branch_id' => $branch->id, 'shift_date' => today()->toDateString(),
        ], ['status' => 'completed']);
        $day->update(['status' => 'completed']);
        app(\Modules\Shift\Services\ShiftReportRevisionService::class)->recordManagerRevision($day, 'branch_manager', $manager->id, 0);
        $response = $this->actingAs($manager, 'sanctum')
            ->withHeader('Idempotency-Key', (string) \Illuminate\Support\Str::uuid())
            ->postJson('/api/branch-manager/workday/daily-close/submit', ['final_notes' => 'CP1', 'expected_revision' => 1]);
        $response->assertOk()
            ->assertJsonPath('data.shift.daily_close.is_submitted', true)
            ->assertJsonPath('data.shift.daily_close.can_reopen', false)
            ->assertJsonPath('data.shift.available_actions.can_reopen', false);
        $this->assertFalse($day->fresh()->can_reopen);
        $before = $day->fresh()->getRawOriginal();
        $histories = DB::table('cashier_shift_history')->count();
        $this->postJson('/api/branch-manager/workday/daily-close/reopen', ['reopen_reason' => 'D-PR remains unresolved'])
            ->assertStatus(409)->assertJsonPath('code', 'REPORT_REOPEN_REQUIRED');
        $this->assertSame($before, $day->fresh()->getRawOriginal());
        $this->assertSame($histories, DB::table('cashier_shift_history')->count());
    }

    #[DataProvider('historicalDailyCloseStates')]
    public function test_historical_reopen_flag_is_not_advertised(bool $submitted, bool $timestamp, bool $approved): void
    {
        $manager = BranchManager::factory()->create();
        $day = BranchManagerShift::firstOrCreate([
            'branch_manager_id' => $manager->id, 'branch_id' => $manager->branch_id, 'shift_date' => today()->toDateString(),
        ], ['status' => 'completed']);
        $day->update([
            'daily_report_submitted' => $submitted,
            'daily_report_submitted_at' => $timestamp ? now() : null,
            'approved_at' => $approved ? now() : null,
            'can_reopen' => true,
        ]);
        $before = $day->fresh()->getRawOriginal();
        $resource = (new BranchManagerShiftResource($day->fresh()))->resolve();
        $this->assertFalse($resource['daily_close']['can_reopen']);
        $this->assertFalse($resource['available_actions']['can_reopen']);
        $this->assertSame($before, $day->fresh()->getRawOriginal(), 'Serialization must not rewrite historical flags.');
    }

    public static function historicalDailyCloseStates(): array
    {
        return [[true, true, false], [false, true, false], [false, false, true]];
    }

    public function test_money_evidence_uses_halalas_type_without_changing_values(): void
    {
        [$cashier, $source] = $this->fixture();
        $revision = app(ShiftReportCorrectionService::class)->correctCashierReport(
            $source, $cashier, ['card_payments' => '10.01'], 1, 'CP1 typed evidence', (string) Str::uuid()
        );
        $money = ShiftReportCorrection::where('new_revision_id', $revision->id)->whereNotNull('new_halalas')->get();
        $this->assertNotEmpty($money);
        foreach ($money as $row) {
            $this->assertSame('monetary_halalas', $row->field_type);
            $this->assertIsInt($row->old_halalas);
            $this->assertIsInt($row->new_halalas);
        }
        $card = $money->firstWhere('field_name', 'card_payments');
        $this->assertSame(0, $card->old_halalas);
        $this->assertSame(1001, $card->new_halalas);
    }

    private function payload(): array
    {
        return ['handover_amount' => '90.00', 'correction_reason' => 'input_error', 'expected_revision' => 1];
    }

    private function effectCounts(string $shiftId): array
    {
        $counts = [];
        foreach ([
            'shift_report_revisions', 'shift_report_revision_snapshots', 'shift_report_cash_counts',
            'shift_report_corrections', 'shift_transfer_rejection_evidence', 'shift_transfer_attempts',
            'cashier_shift_history', 'cashier_custody_transactions', 'personal_ledger_transactions',
            'cashier_shift_handover_receipts',
        ] as $table) {
            $counts[$table] = DB::table($table)->count();
        }

        return $counts;
    }

    private function fixture(): array
    {
        $branch = Branch::factory()->create();
        $manager = BranchManager::factory()->create(['branch_id' => $branch->id]);
        $cashier = Cashier::factory()->create(['branch_id' => $branch->id, 'created_by' => $manager->id]);
        $recipient = Cashier::factory()->create(['branch_id' => $branch->id, 'created_by' => $manager->id]);
        $template = Shift::factory()->create(['branch_id' => $branch->id]);
        $source = CashierShift::factory()->completed()->create([
            'cashier_id' => $cashier->id, 'shift_id' => $template->id, 'shift_date' => today(),
            'total_sales' => '100.00', 'cash_collected' => '100.00', 'card_payments' => '0.00',
            'net_sales' => '86.96', 'vat_amount' => '13.04', 'closing_balance' => '100.00',
        ]);
        $revision = app(ShiftReportRevisionService::class)->recordCashierRevision($source, 'cashier', $cashier->id, 0);
        app(ShiftCashCountService::class)->record($source, $revision, 10000, 0, 0, 10000);
        $handover = CashierShiftHandover::create([
            'cashier_shift_id' => $source->id, 'handover_to_type' => 'cashier', 'handover_to_id' => $recipient->id,
            'handover_amount' => '100.00', 'status' => 'rejected', 'report_revision_id' => $revision->id,
            'handover_date' => today(), 'handover_time' => now(), 'rejection_reason' => 'Wrong amount',
        ]);
        ShiftHandoverStatus::create([
            'cashier_shift_id' => $source->id, 'handover_id' => $handover->id,
            'status' => 'rejected', 'manager_approval_status' => 'rejected', 'rejection_count' => 1,
        ]);

        return [$cashier, $source, $handover];
    }
}
