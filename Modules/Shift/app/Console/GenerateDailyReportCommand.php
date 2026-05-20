<?php

namespace Modules\Shift\Console;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\Mail;
use Modules\Shift\Enums\ShiftStatus;
use Modules\Shift\Models\CashierShift;

class GenerateDailyReportCommand extends Command
{
    protected $signature = 'shifts:generate-daily-report {--date=}';

    protected $description = 'Generate daily shift report and send to branch managers';

    public function handle(): int
    {
        $date = $this->option('date') ?? today()->toDateString();
        $this->info("Generating daily report for: {$date}");

        $shifts = CashierShift::whereDate('shift_date', $date)
            ->with(['cashier', 'shift.branch', 'varianceDetails'])
            ->get();

        if ($shifts->isEmpty()) {
            $this->warn('No shifts found for this date.');

            return Command::SUCCESS;
        }

        $report = $this->generateReport($shifts);

        // Group by branch
        $branchReports = $shifts->groupBy('shift.branch_id');

        foreach ($branchReports as $branchId => $branchShifts) {
            $branch = $branchShifts->first()->shift->branch;
            $branchReport = $this->generateBranchReport($branchShifts);

            // Send email to branch manager
            try {
                Mail::to($branch->manager->email)->send(
                    new \Modules\Notification\Mail\DailyShiftReport($branch, $branchReport, $date)
                );

                $this->info("Report sent to {$branch->name} manager.");
            } catch (\Exception $e) {
                $this->error("Failed to send report to {$branch->name}: {$e->getMessage()}");
            }
        }

        $this->info('Daily reports generated successfully!');

        return Command::SUCCESS;
    }

    private function generateReport($shifts): array
    {
        return [
            'total_shifts' => $shifts->count(),
            'completed_shifts' => $shifts->where('status', ShiftStatus::COMPLETED)->count(),
            'total_sales' => $shifts->sum('total_sales'),
            'total_variance' => $shifts->sum('variance'),
            'shifts_with_variance' => $shifts->filter(fn ($s) => abs($s->variance) > 0)->count(),
        ];
    }

    private function generateBranchReport($shifts): array
    {
        return [
            'shifts' => $shifts->map(function ($shift) {
                return [
                    'cashier_name' => $shift->cashier->name,
                    'shift_name' => $shift->shift->name,
                    'status' => $shift->status->label(),
                    'total_sales' => $shift->total_sales,
                    'variance' => $shift->variance,
                    'start_time' => $shift->actual_start_time?->format('H:i'),
                    'end_time' => $shift->actual_end_time?->format('H:i'),
                ];
            }),
            'summary' => $this->generateReport($shifts),
        ];
    }
}
