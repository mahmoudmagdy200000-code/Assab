<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Modules\Admin\Models\Shift as AdminShift;
use Modules\Shift\Enums\ShiftStatus;
use Modules\Shift\Models\CashierShift;
use Modules\Shift\Services\CashierShiftStartService;
use Modules\Shift\Services\ReassignmentReportOwnershipService;
use Symfony\Component\HttpKernel\Exception\ConflictHttpException;
use Tests\TestCase;

class ShiftOperationalResponsibilityTest extends TestCase
{
    use RefreshDatabase;

    private function assign(): array
    {
        $source = CashierShift::factory()->create(['status' => ShiftStatus::IN_PROGRESS, 'actual_start_time' => now()->subHour()]);
        $recipient = \Modules\Cashier\Models\Cashier::factory()->create();
        $source->update(['original_cashier_id' => $source->cashier_id, 'cashier_id' => $recipient->id, 'status' => ShiftStatus::REASSIGNED]);
        $incoming = DB::transaction(fn () => app(ReassignmentReportOwnershipService::class)->separate($source));

        return [$source->fresh(), $incoming];
    }

    public function test_assignment_preserves_owner_until_actual_start(): void
    {
        [$source, $incoming] = $this->assign();
        $this->assertNotNull($source->operational_chain_id);
        $this->assertSame($source->operational_chain_id, $incoming->operational_chain_id);
        $this->assertNull($source->operational_ended_at);
        $this->assertNull($incoming->actual_start_time);
    }

    public function test_actual_start_ends_predecessor_at_the_same_instant_without_financial_close(): void
    {
        [$source, $incoming] = $this->assign();
        $before = $source->getAttributes();
        app(CashierShiftStartService::class)->startShift($incoming);
        $source->refresh();
        $incoming->refresh();
        $this->assertNotNull($source->operational_ended_at);
        $this->assertTrue($source->operational_ended_at->equalTo($incoming->actual_start_time));
        foreach (['status', 'actual_end_time', 'opening_balance', 'closing_balance', 'total_sales'] as $field) {
            $this->assertSame($before[$field] ?? null, $source->getAttributes()[$field] ?? null);
        }
        $this->assertSame(1, CashierShift::currentOperational()->where('operational_chain_id', $source->operational_chain_id)->count());
        $this->assertSame(0, DB::table('cashier_shift_handover_receipts')->count());
        $this->assertSame(0, DB::table('cashier_custody_transactions')->count());
        $this->assertSame('0.00', $incoming->opening_balance);
    }

    public function test_stale_start_does_not_repeat_transition_or_history(): void
    {
        [$source, $incoming] = $this->assign();
        $service = app(CashierShiftStartService::class);
        $service->startShift($incoming);
        $history = DB::table('cashier_shift_history')->count();
        try {
            $service->startShift($incoming);
            $this->fail('A stale start must conflict.');
        } catch (ConflictHttpException $e) {
            $this->assertSame('SHIFT_NO_LONGER_PENDING', $e->getMessage());
        }
        $this->assertSame($history, DB::table('cashier_shift_history')->count());
        $this->assertSame(2, CashierShift::count());
    }

    public function test_admin_live_scope_excludes_ended_owner_but_keeps_financial_state(): void
    {
        [$source, $incoming] = $this->assign();
        $attributes = ['company_id' => \Modules\Admin\Models\AsabCompany::create(['name' => 'Operational', 'plan' => 'Professional', 'status' => 'active'])->id,
            'branch_id' => $source->cashier->branch_id, 'cashier_name' => 'Cashier', 'started_at' => now(), 'status' => 'active'];
        $a = AdminShift::create($attributes + ['legacy_shift_id' => $source->id]);
        $b = AdminShift::create($attributes + ['legacy_shift_id' => $incoming->id]);
        app(CashierShiftStartService::class)->startShift($incoming);
        $this->assertSame([$b->id], AdminShift::withoutGlobalScopes()->currentlyOperational()->pluck('id')->all());
        $this->assertSame('active', $a->fresh()->status);
        $actor = \Modules\Admin\Models\AsabUser::create(['company_id' => $a->company_id, 'name' => 'Closer',
            'email' => 'closer@operational.test', 'password' => 'password', 'status' => 'active']);
        $closed = app(\Modules\Admin\Services\ShiftCloseService::class)->close($a->fresh(), ['cashActualHalalas' => 0], $actor);
        $this->assertSame('pending_review', $closed['shift']->status);
        $this->assertNotNull($closed['operation']->id);
    }

    public function test_counted_predecessor_report_and_shortage_are_unchanged_and_incoming_report_is_independent(): void
    {
        [$source, $incoming] = $this->assign();
        $source->shift->update(['branch_id' => $source->cashier->branch_id]);
        $company = \Modules\Admin\Models\AsabCompany::create(['name' => 'Counted', 'plan' => 'Professional', 'status' => 'active']);
        \Modules\Branch\Models\Branch::whereKey($source->cashier->branch_id)->update(['asab_company_id' => $company->id]);
        $source->update(['total_sales' => '100.00', 'cash_collected' => '100.00']);
        DB::transaction(function () use ($source) {
            $revision = app(\Modules\Shift\Services\ShiftReportRevisionService::class)->recordCashierRevision($source, 'cashier', $source->cashier_id);
            app(\Modules\Shift\Services\ShiftEndService::class)->recordReportCount($source, $revision, 9000, [
                'shortage_allocations' => [['responsible_type' => 'cashier', 'responsible_id' => $source->cashier_id, 'amount' => '10.00']],
            ], $source->cashier);
        });
        $tables = ['shift_report_aggregates', 'shift_report_revisions', 'shift_report_cash_counts', 'shift_liability_allocations', 'shift_liability_shares'];
        $before = [];
        foreach ($tables as $table) {
            $before[$table] = DB::table($table)->orderBy('id')->get()->toJson();
        }
        app(CashierShiftStartService::class)->startShift($incoming);
        foreach ($tables as $table) {
            $this->assertSame($before[$table], DB::table($table)->orderBy('id')->get()->toJson());
        }
        $sourceCount = app(\Modules\Shift\Services\ShiftCashCountService::class)->currentFor($source->id)->getAttributes();
        app(\Modules\Shift\Services\ShiftEndService::class)->endShiftOnly($incoming->fresh(), ['total_sales' => '50.00', 'counted_cash' => '50.00'], $incoming->cashier);
        $this->assertSame($sourceCount, app(\Modules\Shift\Services\ShiftCashCountService::class)->currentFor($source->id)->getAttributes());
        $this->assertSame(5000, app(\Modules\Shift\Services\ShiftCashCountService::class)->currentFor($incoming->id)->counted_halalas);
        $this->assertNull($source->fresh()->actual_end_time);
    }

    public function test_failed_history_rolls_back_both_operational_intervals(): void
    {
        [$source, $incoming] = $this->assign();
        \Modules\Shift\Models\CashierShiftHistory::creating(static function ($history) {
            if ($history->action === 'started') {
                throw new \RuntimeException('Injected start history failure');
            }
        });
        try {
            app(CashierShiftStartService::class)->startShift($incoming);
            $this->fail('The start must roll back.');
        } catch (\RuntimeException $e) {
            $this->assertSame('Injected start history failure', $e->getMessage());
        } finally {
            \Modules\Shift\Models\CashierShiftHistory::flushEventListeners();
        }
        $this->assertNull($source->fresh()->operational_ended_at);
        $this->assertNull($incoming->fresh()->actual_start_time);
        $this->assertSame(0, $source->history()->where('action', 'operational_responsibility_ended')->count());
    }

    public function test_projection_failure_is_observable_and_recoverable_after_operational_commit(): void
    {
        [$source, $incoming] = $this->assign();
        \Illuminate\Support\Facades\Log::spy();
        \Illuminate\Support\Facades\Event::listen(\Modules\Shift\Events\ShiftStartedEvent::class, static function () {
            throw new \RuntimeException('Injected projection failure');
        });
        app(CashierShiftStartService::class)->startShift($incoming);
        $this->assertNotNull($source->fresh()->operational_ended_at);
        $this->assertNotNull($incoming->fresh()->actual_start_time);
        $this->assertNull($source->fresh()->actual_end_time);
        $this->assertSame(0, DB::table('cashier_shift_handover_receipts')->count());
        \Illuminate\Support\Facades\Log::shouldHaveReceived('error')->with('Cashier shift start projection failed after commit', \Mockery::on(fn ($context) => $context['shift_id'] === $incoming->id))->once();
        $company = \Modules\Admin\Models\AsabCompany::create(['name' => 'Recovery', 'plan' => 'Professional', 'status' => 'active']);
        $employee = \Modules\Admin\Models\Employee::create(['company_id' => $company->id, 'branch_id' => $incoming->cashier->branch_id,
            'emp_number' => 'REC-1', 'name' => 'Recovered', 'role' => 'cashier', 'monthly_salary' => 0, 'status' => 'active']);
        $employee->forceFill(['legacy_cashier_id' => $incoming->cashier_id])->save();
        $mirror = app(\Modules\Admin\Services\LegacyShiftMirror::class)->open($incoming->fresh());
        $this->assertNotNull($mirror);
        $this->assertSame($mirror->id, app(\Modules\Admin\Services\LegacyShiftMirror::class)->open($incoming->fresh())->id);
    }

    public function test_second_pending_starter_cannot_steal_a_chain_after_predecessor_has_changed(): void
    {
        [$source, $incoming] = $this->assign();
        $other = CashierShift::factory()->create(['operational_chain_id' => $source->operational_chain_id,
            'shift_id' => $source->shift_id, 'shift_date' => $source->shift_date]);
        $other->recordHistory('reassignment_report_separated', null, ['source_cashier_shift_id' => $source->id]);
        app(CashierShiftStartService::class)->startShift($incoming);
        try {
            app(CashierShiftStartService::class)->startShift($other);
            $this->fail('The stale predecessor must conflict.');
        } catch (ConflictHttpException $e) {
            $this->assertSame('OPERATIONAL_PREDECESSOR_CHANGED', $e->getMessage());
        }
        $this->assertNull($other->fresh()->actual_start_time);
        $this->assertSame(1, CashierShift::currentOperational()->where('operational_chain_id', $source->operational_chain_id)->count());
    }

    public function test_legacy_shared_reassignment_start_separates_before_checking_incoming_start_time(): void
    {
        $source = CashierShift::factory()->create(['status' => ShiftStatus::IN_PROGRESS, 'actual_start_time' => now()->subHour()]);
        $recipient = \Modules\Cashier\Models\Cashier::factory()->create();
        $source->update(['original_cashier_id' => $source->cashier_id, 'cashier_id' => $recipient->id, 'status' => ShiftStatus::REASSIGNED]);
        $incoming = app(CashierShiftStartService::class)->startShift($source);
        $this->assertNotSame($source->id, $incoming->id);
        $this->assertSame($recipient->id, $incoming->cashier_id);
        $this->assertTrue($source->fresh()->operational_ended_at->equalTo($incoming->actual_start_time));
    }

    public function test_historically_separated_incoming_inherits_the_safe_linked_predecessor_chain(): void
    {
        [$source, $incoming] = $this->assign();
        $incoming->update(['operational_chain_id' => null]);
        $started = app(CashierShiftStartService::class)->startShift($incoming);
        $this->assertSame($source->operational_chain_id, $started->operational_chain_id);
        $this->assertSame(1, CashierShift::currentOperational()->where('operational_chain_id', $source->operational_chain_id)->count());
    }
}
