<?php

namespace Modules\Admin\Console\Commands;

use Illuminate\Console\Command;
use Modules\Admin\Models\AsabCompany;
use Modules\Admin\Services\ReminderService;

/**
 * §6.3.12 «قواعد التذكير التلقائي» — the consumer of `asab_auto_reminder_rules`.
 * A rule fires once its `trigger_hour` has passed and re-fires every
 * `repeat_hours` until the branch answers. Before this, the toggles on the
 * screen wrote rows nothing ever read.
 *
 * Scheduled hourly; each run only sends what is due, so a missed run catches up
 * on the next one rather than double-sending.
 */
class DispatchRemindersCommand extends Command
{
    protected $signature = 'asab:reminders-dispatch {--company= : one company id}';

    protected $description = 'Send due auto-reminders to branch managers (§6.3.12)';

    public function handle(ReminderService $reminders): int
    {
        $companies = AsabCompany::query()
            ->when($this->option('company'), fn ($q, $id) => $q->where('id', $id))
            ->pluck('id');

        $sent = 0;
        foreach ($companies as $companyId) {
            $sent += $reminders->dispatchDue($companyId);
        }

        $this->info("Dispatched {$sent} reminder(s).");

        return self::SUCCESS;
    }
}
