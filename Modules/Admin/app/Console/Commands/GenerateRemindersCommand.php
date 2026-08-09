<?php

namespace Modules\Admin\Console\Commands;

use Illuminate\Console\Command;
use Modules\Admin\Models\AsabCompany;
use Modules\Admin\Services\ReminderService;

/**
 * §6.3.12 — raise «بيانات الفروع المفقودة» for a day and close the ones whose
 * data has since arrived. Nothing used to write `asab_reminders`, so the screen
 * was permanently empty; this is the producer.
 *
 * Idempotent: re-running for the same day updates the existing rows (age /
 * resolution) instead of duplicating them. Scheduled hourly so a branch that
 * uploads late clears its own reminder without anyone touching the dashboard.
 */
class GenerateRemindersCommand extends Command
{
    protected $signature = 'asab:reminders-generate {--date= : Y-m-d, defaults to today} {--company= : one company id}';

    protected $description = 'Raise/refresh missing-branch-data reminders (§6.3.12)';

    public function handle(ReminderService $reminders): int
    {
        $date = $this->option('date');
        $companies = AsabCompany::query()
            ->when($this->option('company'), fn ($q, $id) => $q->where('id', $id))
            ->pluck('id');

        $created = 0;
        $resolved = 0;
        foreach ($companies as $companyId) {
            $result = $reminders->generate($companyId, $date);
            $created += $result['created'];
            $resolved += $result['resolved'];
        }

        $this->info("Reminders: {$created} raised, {$resolved} resolved across {$companies->count()} company(ies).");

        return self::SUCCESS;
    }
}
