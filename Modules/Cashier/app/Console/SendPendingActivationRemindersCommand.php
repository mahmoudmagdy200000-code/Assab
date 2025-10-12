<?php

namespace Modules\Cashier\Console;

use Illuminate\Console\Command;
use Modules\Cashier\Models\Cashier;
use Modules\Cashier\Services\CashierActivationService;
use Carbon\Carbon;

class SendPendingActivationRemindersCommand extends Command
{
    protected $signature = 'cashiers:send-activation-reminders';
    protected $description = 'Send activation reminders to cashiers who haven\'t activated their accounts';

    public function __construct(
        private CashierActivationService $activationService
    ) {
        parent::__construct();
    }

    public function handle(): int
    {
        $this->info('Sending activation reminders...');

        // Get cashiers pending activation for more than 24 hours
        $cashiers = Cashier::where('status', 'pending')
            ->where('created_at', '<=', Carbon::now()->subHours(24))
            ->where('created_at', '>=', Carbon::now()->subDays(7)) // Don't send to very old accounts
            ->get();

        if ($cashiers->isEmpty()) {
            $this->info('No pending cashiers found.');
            return Command::SUCCESS;
        }

        $count = 0;
        foreach ($cashiers as $cashier) {
            try {
                // Generate new password
                $newPassword = \Str::random(12);
                $cashier->update([
                    'password' => \Hash::make($newPassword),
                ]);

                // Resend activation link
                $this->activationService->sendActivationLink($cashier, $newPassword);

                $this->info("Reminder sent to: {$cashier->name} ({$cashier->email})");
                $count++;
            } catch (\Exception $e) {
                $this->error("Failed to send reminder to {$cashier->email}: {$e->getMessage()}");
            }
        }

        $this->info("Successfully sent {$count} activation reminders.");
        return Command::SUCCESS;
    }
}
