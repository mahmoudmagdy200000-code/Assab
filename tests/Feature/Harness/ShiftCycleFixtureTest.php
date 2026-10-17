<?php

namespace Tests\Feature\Harness;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;
use Modules\Shift\Enums\ShiftStatus;
use Symfony\Component\HttpKernel\Exception\ConflictHttpException;
use Tests\Support\Harness\AssabHarnessFixtureBuilder;
use Tests\Support\Harness\ShiftCycleFixtureBuilder;
use Tests\TestCase;

final class ShiftCycleFixtureTest extends TestCase
{
    use RefreshDatabase;

    public function test_assignment_and_opening_use_the_real_shift_service_and_are_idempotency_safe(): void
    {
        Notification::fake();
        $fixture = (new AssabHarnessFixtureBuilder())->build();
        $builder = new ShiftCycleFixtureBuilder();
        $assignment = $builder->assigned($fixture);

        $this->assertSame(ShiftStatus::NOT_STARTED, $assignment->status);
        $this->assertSame('0.00', $assignment->opening_balance);
        $this->assertSame($fixture->branchManager()->id, $assignment->assigned_by);

        $opened = $builder->start($assignment);
        $this->assertSame(ShiftStatus::IN_PROGRESS, $opened->status);
        $this->assertNotNull($opened->actual_start_time);
        $this->assertNotNull($opened->operational_chain_id);
        $this->assertSame(1, $opened->history()->where('action', 'started')->count());

        $before = DB::table('cashier_shift_history')->orderBy('id')->get()->toJson();
        try {
            $builder->start($assignment);
            $this->fail('A second start must be rejected.');
        } catch (ConflictHttpException $exception) {
            $this->assertSame('SHIFT_NO_LONGER_PENDING', $exception->getMessage());
        }
        $this->assertSame($before, DB::table('cashier_shift_history')->orderBy('id')->get()->toJson());
    }
}
