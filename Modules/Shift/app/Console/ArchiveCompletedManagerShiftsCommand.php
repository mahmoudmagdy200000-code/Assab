<?php

namespace Modules\Shift\Console;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;
use Modules\Shift\Models\BranchManagerShift;
use Carbon\Carbon;

/**
 * Command to auto-archive completed Branch Manager shifts
 * 
 * Business Rule: After day completes, system auto-archives/deletes 
 * completed Workday Management records
 * 
 * Usage: php artisan shift:archive-completed-manager-shifts
 * Schedule: Daily at midnight
 */
class ArchiveCompletedManagerShiftsCommand extends Command
{
    /**
     * The name and signature of the console command.
     */
    protected $signature = 'shift:archive-completed-manager-shifts 
                            {--days=1 : Number of days after completion to archive}
                            {--delete : Actually delete instead of soft archive}
                            {--dry-run : Show what would be archived without making changes}';

    /**
     * The console command description.
     */
    protected $description = 'Archive or delete completed Branch Manager shifts after the day ends';

    /**
     * Execute the console command.
     */
    public function handle(): int
    {
        $daysAfterCompletion = (int) $this->option('days');
        $shouldDelete = $this->option('delete');
        $isDryRun = $this->option('dry-run');

        $cutoffDate = Carbon::now()->subDays($daysAfterCompletion)->startOfDay();

        $this->info("Archiving Branch Manager shifts completed before: {$cutoffDate->format('Y-m-d')}");
        
        if ($isDryRun) {
            $this->warn('DRY RUN MODE - No changes will be made');
        }

        try {
            // Find shifts eligible for archiving
            $query = BranchManagerShift::where('status', 'completed')
                ->where('daily_report_submitted', true)
                ->whereNull('archived_at')
                ->whereDate('shift_date', '<', $cutoffDate);

            $shiftsToArchive = $query->get();
            $count = $shiftsToArchive->count();

            if ($count === 0) {
                $this->info('No shifts to archive.');
                return self::SUCCESS;
            }

            $this->info("Found {$count} shift(s) to archive.");

            if ($isDryRun) {
                $this->table(
                    ['ID', 'Manager', 'Branch', 'Shift Date', 'Completed At'],
                    $shiftsToArchive->map(fn($s) => [
                        $s->id,
                        $s->branchManager?->name ?? 'N/A',
                        $s->branch?->name ?? 'N/A',
                        $s->shift_date->format('Y-m-d'),
                        $s->daily_report_submitted_at?->format('Y-m-d H:i'),
                    ])
                );
                return self::SUCCESS;
            }

            $bar = $this->output->createProgressBar($count);
            $bar->start();

            $archived = 0;
            $errors = 0;

            foreach ($shiftsToArchive as $shift) {
                try {
                    if ($shouldDelete) {
                        // Hard delete
                        $shift->delete();
                    } else {
                        // Soft archive
                        $shift->update(['archived_at' => now()]);
                    }
                    $archived++;
                    
                    Log::info('Branch Manager shift archived', [
                        'shift_id' => $shift->id,
                        'manager_id' => $shift->branch_manager_id,
                        'shift_date' => $shift->shift_date,
                        'action' => $shouldDelete ? 'deleted' : 'archived',
                    ]);
                } catch (\Exception $e) {
                    $errors++;
                    Log::error('Failed to archive shift', [
                        'shift_id' => $shift->id,
                        'error' => $e->getMessage(),
                    ]);
                }

                $bar->advance();
            }

            $bar->finish();
            $this->newLine(2);

            $action = $shouldDelete ? 'deleted' : 'archived';
            $this->info("Successfully {$action}: {$archived} shift(s)");
            
            if ($errors > 0) {
                $this->warn("Errors encountered: {$errors}");
            }

            return self::SUCCESS;

        } catch (\Exception $e) {
            $this->error("Command failed: {$e->getMessage()}");
            Log::error('Archive command failed', [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);
            return self::FAILURE;
        }
    }
}

