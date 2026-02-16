<?php

namespace Modules\Inventory\Console;

use Carbon\Carbon;
use Illuminate\Console\Command;
use Modules\Inventory\Enums\InventorySessionStatus;
use Modules\Inventory\Models\InventoryItem;
use Modules\Inventory\Models\InventorySession;
use Modules\Inventory\Repositories\DailyInventoryScheduleRepository;

class GenerateDailyInventorySessionsCommand extends Command
{
    protected $signature = 'inventory:generate-daily-sessions {--date= : Date (Y-m-d), default today}';

    protected $description = 'Generate daily inventory sessions from branch schedules (run daily via scheduler)';

    public function __construct(
        private readonly DailyInventoryScheduleRepository $scheduleRepository
    ) {
        parent::__construct();
    }

    public function handle(): int
    {
        $date = $this->option('date')
            ? Carbon::parse($this->option('date'))->startOfDay()
            : now()->startOfDay();

        $schedules = $this->scheduleRepository->getActiveSchedules();

        $created = 0;
        foreach ($schedules as $schedule) {
            if ($date->lt($schedule->start_date)) {
                continue;
            }

            $sessionExists = InventorySession::where('branch_id', $schedule->branch_id)
                ->whereDate('inventory_date', $date)
                ->exists();

            if ($sessionExists) {
                continue;
            }

            $startTime = $date->copy();
            if (is_string($schedule->start_time)) {
                $startTime->setTimeFromTimeString($schedule->start_time);
            }

            $session = InventorySession::create([
                'branch_id' => $schedule->branch_id,
                'created_by' => null,
                'assigned_to_type' => 'personal',
                'assigned_to_id' => null,
                'inventory_date' => $date,
                'start_time' => $startTime,
                'status' => InventorySessionStatus::PENDING,
            ]);

            foreach ($schedule->scheduleItems as $scheduleItem) {
                InventoryItem::create([
                    'inventory_session_id' => $session->id,
                    'purchase_order_item_id' => null,
                    'item_id' => $scheduleItem->item_id,
                    'item_name' => $scheduleItem->item?->name ?? '',
                    'quantity_inventory' => 0,
                    'notes' => null,
                    'branch_id' => $schedule->branch_id,
                ]);
            }

            $created++;
            $this->info("Created session {$session->session_number} for branch {$schedule->branch_id}");
        }

        $this->info("Generated {$created} daily inventory session(s).");
        return self::SUCCESS;
    }
}
