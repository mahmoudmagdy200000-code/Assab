<?php

// A separate process/connection for the opt-in phase 5 MySQL locking gate.
require dirname(__DIR__, 2).'/vendor/autoload.php';
$app = require dirname(__DIR__, 2).'/bootstrap/app.php';
$app->make(\Illuminate\Contracts\Console\Kernel::class)->bootstrap();
$db = \Illuminate\Support\Facades\DB::connection();
if (getenv('EXPENSE_CUSTODY_MYSQL_TESTS') !== '1' || $db->getDriverName() !== 'mysql'
    || ! preg_match('/^assab_phase5_audit_[a-z0-9_]+$/', $db->getDatabaseName())
    || ! in_array($db->getConfig('host'), ['127.0.0.1', 'localhost'], true)) {
    throw new LogicException('Expense custody worker requires an explicitly enabled local disposable MySQL schema.');
}

$input = json_decode($argv[1], true, 512, JSON_THROW_ON_ERROR);
file_put_contents($input['signal'].'.started', 'started');
try {
    $result = $db->transaction(function () use ($input): array {
        if ($input['command'] === 'reserve') {
            $expense = \Modules\Expense\Models\Expense::query()->findOrFail($input['expense']);
            app(\Modules\Custody\Services\ExpenseCustodyService::class)->reserve($expense);
            $result = ['status' => 'reserved', 'expense' => $expense->id];
        } elseif ($input['command'] === 'approve' || $input['command'] === 'reject') {
            $expense = \Modules\Expense\Models\Expense::query()->findOrFail($input['expense']);
            $service = app(\Modules\Expense\Services\ExpenseApprovalService::class);
            if ($input['command'] === 'approve') {
                $service->approveExpense($expense, $input['owner'], 'Phase 5 MySQL owner',
                    \Modules\Expense\Enums\ExpenseDecisionRole::BRAND_OWNER, 1);
            } else {
                $service->rejectExpense($expense, $input['owner'], 'MySQL concurrent decision rejection', 'Phase 5 MySQL owner',
                    \Modules\Expense\Enums\ExpenseDecisionRole::BRAND_OWNER, 1);
            }
            $result = ['status' => $input['command']];
        } else {
            throw new LogicException('Unknown expense custody concurrency command.');
        }

        if ($input['hold'] ?? false) {
            file_put_contents($input['signal'].'.held', 'held');
            $deadline = microtime(true) + 15;
            while (! is_file($input['release'])) {
                if (microtime(true) > $deadline) {
                    throw new RuntimeException('Expense custody concurrency barrier timed out.');
                }
                usleep(10000);
            }
        }

        return $result;
    });

    echo json_encode($result, JSON_THROW_ON_ERROR);
} catch (\Modules\Custody\Exceptions\InsufficientExpenseCustodyException) {
    echo json_encode(['status' => 'insufficient'], JSON_THROW_ON_ERROR);
} catch (\Symfony\Component\HttpKernel\Exception\ConflictHttpException $exception) {
    echo json_encode(['status' => 'conflict', 'code' => $exception->getMessage()], JSON_THROW_ON_ERROR);
} catch (\Exception $exception) {
    if (in_array($input['command'], ['approve', 'reject'], true)) {
        echo json_encode(['status' => 'conflict', 'code' => $exception->getMessage()], JSON_THROW_ON_ERROR);
    } else {
        fwrite(STDERR, get_class($exception).': '.$exception->getMessage());
        exit(1);
    }
} catch (\Throwable $exception) {
    fwrite(STDERR, get_class($exception).': '.$exception->getMessage());
    exit(1);
}
