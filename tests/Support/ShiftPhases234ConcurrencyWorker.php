<?php

// Separate PHP process/connection, used only by the opt-in disposable MySQL gate.
require dirname(__DIR__, 2).'/vendor/autoload.php';
$app = require dirname(__DIR__, 2).'/bootstrap/app.php';
$app->make(\Illuminate\Contracts\Console\Kernel::class)->bootstrap();
$db = \Illuminate\Support\Facades\DB::connection();
if ($db->getDriverName() !== 'mysql' || ! preg_match('/^s111_phase234_audit_[a-z0-9_]+$/', $db->getDatabaseName())
    || ! in_array($db->getConfig('host'), ['127.0.0.1', 'localhost'], true)) {
    throw new LogicException('Concurrency worker requires an owned disposable local MySQL schema.');
}
$input = json_decode($argv[1], true, 512, JSON_THROW_ON_ERROR);
file_put_contents($input['signal'].'.started', 'started');
try {
    $result = $db->transaction(function () use ($input) {
        $day = \Modules\Shift\Models\BranchManagerShift::findOrFail($input['day']);
        $manager = \Modules\BranchManagers\Models\BranchManager::findOrFail($day->branch_manager_id);
        if ($input['command'] === 'reserve') {
            $destination = \Modules\Shift\Models\CashierShift::findOrFail($input['destination']);
            $request = app(\Modules\Shift\Services\ShiftTransferReceiptService::class)->requestManagerCashTransfer(
                $day, \Modules\Cashier\Models\Cashier::findOrFail($destination->cashier_id), $destination, $input['amount'], $manager
            );
            $result = ['status' => 'created', 'id' => $request->id];
        } elseif ($input['command'] === 'replace') {
            $destination = \Modules\Shift\Models\CashierShift::findOrFail($input['destination']);
            $request = app(\Modules\Shift\Services\TransferRequestLifecycleService::class)->replaceRecipient(
                'manager_transfer', $input['request'], $manager,
                ['recipient_id' => $destination->cashier_id, 'destination_cashier_shift_id' => $destination->id],
                '60.00', 1, 'Concurrent replacement audit', \Illuminate\Support\Str::uuid()
            );
            $result = ['status' => 'created', 'id' => $request->id];
        } elseif ($input['command'] === 'confirm') {
            $recipient = \Modules\Cashier\Models\Cashier::findOrFail($input['cashier']);
            $receipt = app(\Modules\Shift\Services\ShiftTransferReceiptService::class)->confirmManagerCashTransfer($input['request'], $recipient, '60.00');
            $result = ['status' => 'created', 'id' => $receipt->id];
        } elseif ($input['command'] === 'submit_boundary') {
            $day = \Modules\Shift\Models\BranchManagerShift::whereKey($input['day'])->lockForUpdate()->firstOrFail();
            app(\Modules\Shift\Services\BranchManagerShiftService::class)->lockCashierFinancialInputs($day);
            $day->update(['daily_report_submitted' => true, 'daily_report_submitted_at' => now()]);
            $result = ['status' => 'submitted'];
        } elseif (in_array($input['command'], ['correct', 'reopen'], true)) {
            $source = \Modules\Shift\Models\CashierShift::findOrFail($input['source']);
            $actor = \Modules\Cashier\Models\Cashier::findOrFail($source->cashier_id);
            $revision = $input['command'] === 'correct'
                ? app(\Modules\Shift\Services\ShiftReportCorrectionService::class)->correctCashierReport(
                    $source, $actor, ['card_payments' => $input['cards'] ?? '1.00'], $input['revision'] ?? 1, 'Concurrent correction audit', \Illuminate\Support\Str::uuid()
                )
                : app(\Modules\Shift\Services\ShiftReportReopenService::class)->reopenCashierReport(
                    $source, $actor, $input['revision'] ?? 1, 'Concurrent reopen audit', \Illuminate\Support\Str::uuid()
                );
            $result = ['status' => 'created', 'id' => $revision->id];
        } else {
            throw new LogicException('Unknown audit worker command.');
        }
        if ($input['hold'] ?? false) {
            file_put_contents($input['signal'].'.held', 'held');
            $deadline = microtime(true) + 15;
            while (! is_file($input['release'])) {
                if (microtime(true) > $deadline) {
                    throw new RuntimeException('Audit barrier timeout.');
                }
                usleep(10000);
            }
        }

        return $result;
    });
    echo json_encode($result, JSON_THROW_ON_ERROR);
} catch (\Symfony\Component\HttpKernel\Exception\ConflictHttpException $exception) {
    echo json_encode(['status' => 'conflict', 'code' => $exception->getMessage()], JSON_THROW_ON_ERROR);
} catch (Throwable $exception) {
    fwrite(STDERR, get_class($exception).': '.$exception->getMessage());
    exit(1);
}
