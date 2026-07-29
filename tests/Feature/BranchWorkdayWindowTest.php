<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Modules\Branch\Models\Branch;
use Modules\BranchManagers\Models\BranchManager;
use Modules\Shift\Models\Shift;
use Modules\Shift\Services\BranchWorkdayWindowService;
use Tests\TestCase;

/**
 * The branch manager's workday spans the branch's WHOLE day of shifts, so
 * "My Shift" reports the sum of the day's shift hours instead of a fixed 8h.
 */
class BranchWorkdayWindowTest extends TestCase
{
    use RefreshDatabase;

    private function window(Branch $branch): array
    {
        return app(BranchWorkdayWindowService::class)->forBranch($branch->id);
    }

    private function shift(Branch $branch, string $name, string $start, string $end): void
    {
        Shift::create([
            'name' => $name, 'branch_id' => $branch->id,
            'start_time' => $start, 'end_time' => $end, 'is_active' => true,
        ]);
    }

    public function test_three_eight_hour_shifts_make_a_24_hour_workday(): void
    {
        $branch = Branch::factory()->create();
        $this->shift($branch, 'الأول', '00:00', '08:00');
        $this->shift($branch, 'الثاني', '08:00', '16:00');
        $this->shift($branch, 'الثالث', '16:00', '00:00');

        $window = $this->window($branch);

        $this->assertSame(24.0, $window['totalHours']);
        $this->assertSame('00:00', $window['start']);
        $this->assertSame('00:00', $window['end']);
        $this->assertSame(3, $window['shiftCount']);
    }

    public function test_two_six_hour_shifts_make_a_12_hour_workday(): void
    {
        $branch = Branch::factory()->create();
        $this->shift($branch, 'الأول', '06:00', '12:00');
        $this->shift($branch, 'الثاني', '12:00', '18:00');

        $window = $this->window($branch);

        $this->assertSame(12.0, $window['totalHours']);
        $this->assertSame('06:00', $window['start']);
        $this->assertSame('18:00', $window['end']);
    }

    public function test_a_branch_without_shift_templates_falls_back_to_eight_hours(): void
    {
        $window = $this->window(Branch::factory()->create());

        $this->assertSame(8.0, $window['totalHours']);
        $this->assertSame('09:00', $window['start']);
        $this->assertSame(0, $window['shiftCount']);
    }

    public function test_workday_current_reports_the_summed_window(): void
    {
        $branch = Branch::factory()->create();
        $this->shift($branch, 'الأول', '00:00', '08:00');
        $this->shift($branch, 'الثاني', '08:00', '16:00');
        $this->shift($branch, 'الثالث', '16:00', '00:00');
        $manager = BranchManager::factory()->create(['branch_id' => $branch->id]);

        $res = $this->actingAs($manager, 'sanctum')->getJson('/api/branch-manager/workday/current');

        $res->assertOk()
            ->assertJsonPath('data.shift_progress.planned_hours', 24)
            ->assertJsonPath('data.shift_progress.shifts_count', 3)
            ->assertJsonPath('data.shift_progress.start_time', '00:00')
            ->assertJsonPath('data.shift_progress.end_time', '00:00');
    }
}
