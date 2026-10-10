<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Str;
use Modules\Branch\Models\Branch;
use Modules\BranchManagers\Models\BranchManager;
use Modules\Cashier\Models\Cashier;
use Modules\Shift\Enums\ShiftStatus;
use Modules\Shift\Models\CashierShift;
use Modules\Shift\Models\CashierShiftHandover;
use Modules\Shift\Models\Shift;
use Modules\Shift\Models\ShiftHandoverStatus;
use Modules\Shift\Models\ShiftReportCorrection;
use Modules\Shift\Models\ShiftReportRevision;
use Modules\Shift\Services\ShiftCashCountService;
use Modules\Shift\Services\ShiftReportCorrectionService;
use Modules\Shift\Services\ShiftReportRevisionService;
use Tests\TestCase;

class ShiftCorrectionCommandIdempotencyTest extends TestCase
{
    use RefreshDatabase;

    private function handoverFixture(string $amount): array
    {
        $branch = Branch::factory()->create();
        $manager = BranchManager::factory()->create(['branch_id' => $branch->id]);
        $cashier1 = Cashier::factory()->create(['branch_id' => $branch->id, 'created_by' => $manager->id]);
        $cashier2 = Cashier::factory()->create(['branch_id' => $branch->id, 'created_by' => $manager->id]);
        $template = Shift::factory()->create(['branch_id' => $branch->id]);

        $sourceShift = CashierShift::factory()->completed()->create([
            'cashier_id' => $cashier1->id,
            'shift_id' => $template->id,
            'shift_date' => today(),
        ]);
        $destShift = CashierShift::factory()->create([
            'cashier_id' => $cashier2->id,
            'shift_id' => $template->id,
            'shift_date' => today(),
            'status' => ShiftStatus::NOT_STARTED,
        ]);

        $revision = app(ShiftReportRevisionService::class)->recordCashierRevision($sourceShift, 'cashier', $cashier1->id, 0);
        $minor = (int) round((float) $amount * 100);
        app(ShiftCashCountService::class)->record($sourceShift, $revision, $minor, 0, 0, $minor);

        $handover = CashierShiftHandover::create([
            'cashier_shift_id' => $sourceShift->id,
            'handover_to_type' => 'cashier',
            'handover_to_id' => $cashier2->id,
            'handover_amount' => $amount,
            'status' => 'pending',
            'report_revision_id' => $revision->id,
            'handover_date' => today(),
            'handover_time' => now(),
        ]);

        ShiftHandoverStatus::create([
            'cashier_shift_id' => $sourceShift->id,
            'handover_id' => $handover->id,
            'status' => 'pending',
            'rejection_count' => 0,
        ]);

        return [$branch, $manager, $cashier1, $cashier2, $sourceShift, $destShift, $handover];
    }

    public function test_replace_recipient_command_replays_under_same_idempotency_key(): void
    {
        [$branch, $manager, $cashier1, $cashier2, $sourceShift, $destShift1, $handover] = $this->handoverFixture('50.00');
        $cashier3 = Cashier::factory()->create(['branch_id' => $branch->id, 'created_by' => $manager->id]);
        $destShift2 = CashierShift::factory()->create([
            'cashier_id' => $cashier3->id,
            'shift_id' => $destShift1->shift_id,
            'shift_date' => today(),
            'status' => ShiftStatus::NOT_STARTED,
        ]);

        $key = (string) Str::uuid();
        $payload = [
            'recipient_type' => 'cashier',
            'recipient_id' => $cashier3->id,
            'receiving_shift_id' => $destShift2->id,
            'handover_amount' => '50.00',
            'expected_revision' => 1,
            'reason' => 'Routing to available cashier',
        ];

        // 1. First execution
        $response1 = $this->withHeader('Idempotency-Key', $key)
            ->actingAs($cashier1, 'sanctum')
            ->postJson("/api/cashier/shifts/{$sourceShift->id}/handover/replace-recipient", $payload);

        $response1->assertOk()
            ->assertJsonPath('success', true);

        $firstData = $response1->json('data');
        $this->assertNotEmpty($firstData['handover_request_id']);

        // 2. Retry identical command
        $response2 = $this->withHeader('Idempotency-Key', $key)
            ->actingAs($cashier1, 'sanctum')
            ->postJson("/api/cashier/shifts/{$sourceShift->id}/handover/replace-recipient", $payload);

        $response2->assertOk();
        $this->assertSame($response1->getContent(), $response2->getContent());

        // Ensure only ONE replacement handover was created
        $this->assertEquals(
            1,
            CashierShiftHandover::where('supersedes_id', $handover->id)->count()
        );
    }

    public function test_replace_recipient_rejects_modified_payload_for_same_idempotency_key(): void
    {
        [$branch, $manager, $cashier1, $cashier2, $sourceShift, $destShift1, $handover] = $this->handoverFixture('50.00');
        $cashier3 = Cashier::factory()->create(['branch_id' => $branch->id, 'created_by' => $manager->id]);
        $destShift2 = CashierShift::factory()->create([
            'cashier_id' => $cashier3->id,
            'shift_id' => $destShift1->shift_id,
            'shift_date' => today(),
            'status' => ShiftStatus::NOT_STARTED,
        ]);

        $key = (string) Str::uuid();
        $payload1 = [
            'recipient_type' => 'cashier',
            'recipient_id' => $cashier3->id,
            'receiving_shift_id' => $destShift2->id,
            'handover_amount' => '50.00',
            'expected_revision' => 1,
            'reason' => 'Routing to available cashier',
        ];

        $response1 = $this->withHeader('Idempotency-Key', $key)
            ->actingAs($cashier1, 'sanctum')
            ->postJson("/api/cashier/shifts/{$sourceShift->id}/handover/replace-recipient", $payload1);

        $response1->assertOk();

        // Modified payload with same key
        $payload2 = array_merge($payload1, ['handover_amount' => '60.00']);
        $response2 = $this->withHeader('Idempotency-Key', $key)
            ->actingAs($cashier1, 'sanctum')
            ->postJson("/api/cashier/shifts/{$sourceShift->id}/handover/replace-recipient", $payload2);

        $response2->assertStatus(409);
    }

    public function test_phase3_internal_correction_service_under_idempotency_test_route(): void
    {
        $branch = Branch::factory()->create();
        $cashier = Cashier::factory()->create(['branch_id' => $branch->id]);
        $template = Shift::factory()->create(['branch_id' => $branch->id]);

        $senderShift = CashierShift::factory()->create([
            'shift_id' => $template->id,
            'cashier_id' => $cashier->id,
            'status' => 'in_progress',
            'total_sales' => '100.00',
            'net_sales' => '86.96',
            'vat_amount' => '13.04',
            'card_payments' => '0.00',
            'cash_collected' => '100.00',
        ]);

        // Register an internal test route for Phase 3 correction service with the idempotency middleware
        Route::middleware(['auth:sanctum', 'asab.idempotency:required,transaction,legacy'])
            ->post('/test-api/shifts/{shiftId}/correct-report', function (string $shiftId) {
                $shift = CashierShift::findOrFail($shiftId);
                $service = app(ShiftReportCorrectionService::class);
                $revision = $service->correctCashierReport(
                    $shift,
                    auth()->user(),
                    request()->input('changes', []),
                    (int) request()->input('expected_revision'),
                    (string) request()->input('reason'),
                    (string) request()->header('Idempotency-Key')
                );

                return response()->json([
                    'success' => true,
                    'revision_number' => $revision->revision_number,
                    'revision_id' => $revision->id,
                ]);
            });

        $rev1 = app(ShiftReportRevisionService::class)->recordCashierRevision($senderShift, 'cashier', $cashier->id);

        $key = (string) Str::uuid();
        $payload = [
            'expected_revision' => 1,
            'reason' => 'Card adjustments',
            'changes' => [
                'card_payments' => '10.00',
            ],
        ];

        // 1. Initial call
        $first = $this->withHeader('Idempotency-Key', $key)
            ->actingAs($cashier, 'sanctum')
            ->postJson("/test-api/shifts/{$senderShift->id}/correct-report", $payload);

        $first->assertOk()->assertJsonPath('success', true);
        $this->assertEquals(2, $first->json('revision_number'));

        // 2. Retry identical call
        $retry = $this->withHeader('Idempotency-Key', $key)
            ->actingAs($cashier, 'sanctum')
            ->postJson("/test-api/shifts/{$senderShift->id}/correct-report", $payload);

        $retry->assertOk();
        $this->assertSame($first->getContent(), $retry->getContent());

        // Ensure no duplicate revision was created
        $this->assertEquals(
            2,
            ShiftReportRevision::where('report_aggregate_id', $rev1->report_aggregate_id)->count()
        );
        $this->assertEquals(
            1,
            ShiftReportCorrection::where('report_aggregate_id', $rev1->report_aggregate_id)->count()
        );
    }
}
