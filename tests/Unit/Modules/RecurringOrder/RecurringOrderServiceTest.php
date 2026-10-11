<?php

namespace Tests\Unit\Modules\RecurringOrder;

use Carbon\Carbon;
use Modules\RecurringOrder\Enums\RecurringOrderStatus;
use Modules\RecurringOrder\Enums\RepeatFrequency;
use Modules\RecurringOrder\Models\RecurringOrder;
use Modules\RecurringOrder\Services\RecurringOrderService;
use Tests\TestCase;

class RecurringOrderServiceTest extends TestCase
{
    /**
     * computeNextRunAtFromModel returns a Carbon instance for weekly frequency with valid config.
     */
    public function test_compute_next_run_at_from_model_weekly_returns_future_date(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-10-07 11:00:00'));

        try {
            $service = app(RecurringOrderService::class);
            $model = RecurringOrder::make([
                'repeat_frequency' => RepeatFrequency::WEEKLY,
                'repeat_config' => ['repeat_days' => [1, 3]], // Monday, Wednesday
                'scheduling_time_am' => '10:00',
                'scheduling_time_pm' => null,
                'start_date' => Carbon::yesterday(),
                'end_date' => null,
                'next_run_at' => null,
                'status' => RecurringOrderStatus::PENDING,
            ]);
            $model->id = (string) \Illuminate\Support\Str::uuid();

            $next = $service->computeNextRunAtFromModel($model);

            $this->assertInstanceOf(Carbon::class, $next);
            $this->assertSame('2026-10-12 10:00:00', $next->format('Y-m-d H:i:s'));
            $this->assertTrue($next->isFuture());
            $this->assertContains((int) $next->format('w'), [1, 3]);
        } finally {
            Carbon::setTestNow();
        }
    }

    /**
     * computeNextRunAtFromModel returns null for based_on_inventory frequency.
     */
    public function test_compute_next_run_at_from_model_based_on_inventory_returns_null(): void
    {
        $service = app(RecurringOrderService::class);
        $model = RecurringOrder::make([
            'repeat_frequency' => RepeatFrequency::BASED_ON_INVENTORY,
            'repeat_config' => [],
            'scheduling_time_am' => null,
            'scheduling_time_pm' => null,
            'start_date' => Carbon::today(),
            'end_date' => null,
            'next_run_at' => null,
            'status' => RecurringOrderStatus::PENDING,
        ]);
        $model->id = (string) \Illuminate\Support\Str::uuid();

        $next = $service->computeNextRunAtFromModel($model);

        $this->assertNull($next);
    }
}
