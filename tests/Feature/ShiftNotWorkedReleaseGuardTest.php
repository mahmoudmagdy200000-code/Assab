<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Modules\Branch\Models\Branch;
use Modules\BranchManagers\Models\BranchManager;
use Modules\Cashier\Models\Cashier;
use Modules\Shift\Enums\ShiftStatus;
use Modules\Shift\Models\CashierShift;
use Modules\Shift\Models\Shift;
use Modules\Shift\Services\ShiftNotWorkedGuard;
use Symfony\Component\Process\Process;
use Tests\TestCase;

/**
 * P-01 release guard. The did-not-work route is a request-time, server-side, default-OFF feature
 * (config/features.php: shift_did_not_work). The feature gate and scheduled-period eligibility are
 * independent: enabling the feature does not allow future or still-open assignments.
 */
class ShiftNotWorkedReleaseGuardTest extends TestCase
{
    use RefreshDatabase;

    private BranchManager $manager;

    private CashierShift $shift;

    protected function setUp(): void
    {
        if (getenv('DB_CONNECTION') !== 'sqlite' || getenv('DB_DATABASE') !== ':memory:') {
            throw new \LogicException('P-01 guard tests require disposable SQLite :memory:.');
        }
        parent::setUp();
        $this->travelTo(today()->setTime(15, 0));
        $branch = Branch::factory()->create();
        $this->manager = BranchManager::factory()->create(['branch_id' => $branch->id]);
        $cashier = Cashier::factory()->create(['branch_id' => $branch->id, 'created_by' => $this->manager->id]);
        $slot = Shift::factory()->create(['branch_id' => $branch->id, 'start_time' => '08:00:00', 'end_time' => '14:00:00']);
        $this->shift = CashierShift::factory()->create(['cashier_id' => $cashier->id, 'shift_id' => $slot->id, 'shift_date' => today()]);
    }

    private function url(): string
    {
        return "/api/v1/branch-manager/shifts/{$this->shift->id}/mark-not-worked";
    }

    /** @return array<string,int> */
    private function footprint(): array
    {
        return [
            'history' => DB::table('cashier_shift_history')->count(),
            'idempotency' => DB::table('asab_command_idempotency_keys')->count() + DB::table('asab_command_idempotency_owners')->count(),
            'canceled' => DB::table('cashier_shifts')->where('status', ShiftStatus::CANCELED->value)->count(),
        ];
    }

    public function test_disabled_flag_answers_404_with_stable_code_and_changes_nothing(): void
    {
        config(['features.shift_did_not_work' => false]);
        $before = $this->footprint();

        $this->actingAs($this->manager, 'sanctum')->withHeader('Idempotency-Key', (string) Str::uuid())
            ->postJson($this->url(), ['reason' => 'Closed'])
            ->assertNotFound()
            ->assertJsonPath('code', 'SHIFT_NOT_WORKED_FEATURE_DISABLED')
            ->assertJsonPath('success', false);

        $this->assertSame($before, $this->footprint());
        $this->assertSame(ShiftStatus::NOT_STARTED, $this->shift->fresh()->status);
        $this->assertSame(0, $this->shift->notWorkedHistory()->count());
    }

    public function test_disabled_flag_also_covers_missing_key_and_unknown_shift_without_leaking_existence(): void
    {
        config(['features.shift_did_not_work' => false]);
        $this->actingAs($this->manager, 'sanctum');
        $this->postJson($this->url(), ['reason' => 'Closed'])->assertNotFound()->assertJsonPath('code', 'SHIFT_NOT_WORKED_FEATURE_DISABLED');
        $this->withHeader('Idempotency-Key', (string) Str::uuid())
            ->postJson('/api/v1/branch-manager/shifts/'.Str::uuid().'/mark-not-worked', ['reason' => 'Closed'])
            ->assertNotFound()->assertJsonPath('code', 'SHIFT_NOT_WORKED_FEATURE_DISABLED');
        $this->assertSame(0, $this->shift->notWorkedHistory()->count());
    }

    public function test_unauthenticated_request_is_still_rejected_before_the_gate(): void
    {
        config(['features.shift_did_not_work' => false]);
        $this->withHeader('Idempotency-Key', (string) Str::uuid())->postJson($this->url(), ['reason' => 'Closed'])->assertUnauthorized();
    }

    public function test_ui_hints_are_false_while_disabled_and_true_when_enabled(): void
    {
        $guard = app(ShiftNotWorkedGuard::class);
        config(['features.shift_did_not_work' => true]);
        $this->assertTrue($guard->canMark($this->shift->fresh(), $this->manager));
        config(['features.shift_did_not_work' => false]);
        $this->assertFalse($guard->canMark($this->shift->fresh(), $this->manager));
        $this->assertFalse(ShiftNotWorkedGuard::enabled());
    }

    public function test_enabled_flag_keeps_accepted_pkg7_behaviour_unchanged(): void
    {
        config(['features.shift_did_not_work' => true]);
        $key = (string) Str::uuid();
        $this->actingAs($this->manager, 'sanctum')->withHeader('Idempotency-Key', $key);
        $first = $this->postJson($this->url(), ['reason' => 'Closed']);
        $first->assertOk()->assertJsonPath('data.status', 'canceled');
        $this->assertSame($first->getContent(), $this->postJson($this->url(), ['reason' => 'Closed'])->getContent());
        $this->assertSame(1, $this->shift->notWorkedHistory()->count());
    }

    public function test_flag_can_be_reenabled_after_disabled_attempts_without_residue(): void
    {
        config(['features.shift_did_not_work' => false]);
        $this->actingAs($this->manager, 'sanctum')->withHeader('Idempotency-Key', $key = (string) Str::uuid());
        $this->postJson($this->url(), ['reason' => 'Closed'])->assertNotFound();
        config(['features.shift_did_not_work' => true]);
        $this->postJson($this->url(), ['reason' => 'Closed'])->assertOk();
        $this->assertSame(1, $this->shift->notWorkedHistory()->count());
    }

    /** The shipped default (no env override) is OFF. Evaluated in a clean process so phpunit.xml's test-only ON does not mask it. */
    public function test_production_default_is_off_when_the_environment_variable_is_absent(): void
    {
        $script = 'require $argv[1]."/vendor/autoload.php"; $f = require $argv[1]."/config/features.php"; echo json_encode($f["shift_did_not_work"]);';
        foreach ([[false, 'false'], ['true', 'true'], ['false', 'false']] as [$value, $expected]) {
            $env = ['FEATURE_SHIFT_DID_NOT_WORK' => $value, 'APP_ENV' => 'testing']; // false => variable removed from the child
            $process = new Process([PHP_BINARY, '-r', $script, base_path()], base_path(), $env);
            $process->mustRun();
            $this->assertSame($expected, trim($process->getOutput()), 'FEATURE_SHIFT_DID_NOT_WORK='.var_export($value, true));
        }
    }
}
