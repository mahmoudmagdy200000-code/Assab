<?php

// Separate PHP process/connection, used only by the opt-in disposable MySQL gate for Phases 8, 9, 10.
require dirname(__DIR__, 2).'/vendor/autoload.php';
$app = require dirname(__DIR__, 2).'/bootstrap/app.php';
$app->make(\Illuminate\Contracts\Console\Kernel::class)->bootstrap();

$db = \Illuminate\Support\Facades\DB::connection();
if ($db->getDriverName() !== 'mysql' || ! preg_match('/^s111_phase(567_phase)?8910_test_[a-z0-9_]+$/', $db->getDatabaseName())
    || ! in_array($db->getConfig('host'), ['127.0.0.1', 'localhost'], true)) {
    throw new LogicException('Concurrency worker requires an owned disposable local MySQL schema.');
}

$input = json_decode($argv[1], true, 512, JSON_THROW_ON_ERROR);
file_put_contents($input['signal'].'.started', 'started');

try {
    // Explicit positive-path simulation on the guarded disposable test schema only.
    config(['shift.settlement_authority_approved' => true]);
    $result = $db->transaction(function () use ($input) {
        if ($input['command'] === 'accountant_correct') {
            $shift = \Modules\Shift\Models\CashierShift::findOrFail($input['shift_id']);
            $actor = \Modules\Admin\Models\AsabUser::findOrFail($input['accountant_id']);
            \Illuminate\Support\Facades\Auth::guard()->setUser($actor);
            $service = app(\Modules\Shift\Services\AccountantReportCorrectionService::class);
            $revision = $service->correct(
                $shift,
                $actor,
                $input['payload'],
                $input['shares'] ?? null,
                $input['expected_revision'],
                $input['expected_allocation_version'] ?? null,
                $input['reason'] ?? 'Concurrent accountant audit',
                $input['operation_id'] ?? (string) \Illuminate\Support\Str::uuid()
            );
            $result = ['status' => 'created', 'revision_id' => $revision->id, 'revision_number' => $revision->revision_number];
        } elseif ($input['command'] === 'manager_approve') {
            $shift = \Modules\Shift\Models\CashierShift::findOrFail($input['shift_id']);
            $actor = \Modules\BranchManagers\Models\BranchManager::findOrFail($input['manager_id']);
            \Illuminate\Support\Facades\Auth::guard()->setUser($actor);
            $service = app(\Modules\Shift\Services\ShiftReportManagerReviewService::class);
            $review = $service->approveCurrent(
                $shift,
                $actor,
                $input['expected_revision'],
                $input['expected_allocation_version'] ?? null,
                $input['reason'] ?? $input['notes'] ?? 'Concurrent approval audit',
                $input['operation_id'] ?? (string) \Illuminate\Support\Str::uuid()
            );
            $result = ['status' => 'approved', 'review_id' => $review->id];
        } elseif ($input['command'] === 'submit_daily') {
            $day = \Modules\Shift\Models\BranchManagerShift::whereKey($input['day_id'])->firstOrFail();
            $manager = \Modules\BranchManagers\Models\BranchManager::findOrFail($day->branch_manager_id);
            $token = $manager->createToken('worker')->plainTextToken;

            $expectedRev = $input['expected_revision']
                ?? (app(\Modules\Shift\Services\ShiftReportRevisionService::class)->currentManagerRevision($day)?->revision_number ?? 1);

            $request = \Illuminate\Http\Request::create(
                '/api/v1/branch-manager/workday/daily-close/submit',
                'POST',
                [
                    'branch_manager_shift_id' => $day->id,
                    'final_notes' => $input['final_notes'] ?? 'Audited daily close submission',
                    'expected_revision' => $expectedRev,
                ],
                [],
                [],
                [
                    'HTTP_ACCEPT' => 'application/json',
                    'HTTP_AUTHORIZATION' => 'Bearer '.$token,
                ]
            );
            if (! empty($input['idempotency_key'])) {
                $request->headers->set('Idempotency-Key', $input['idempotency_key']);
            }

            $response = app(\Illuminate\Contracts\Http\Kernel::class)->handle($request);
            if ($response->getStatusCode() === 200) {
                $result = ['status' => 'submitted', 'http_status' => 200, 'body' => $response->getContent()];
            } else {
                $data = json_decode($response->getContent(), true) ?: [];
                $result = [
                    'status' => 'rejected',
                    'http_status' => $response->getStatusCode(),
                    'code' => $data['code'] ?? $data['message'] ?? 'UNKNOWN_ERROR',
                ];
            }
        } elseif ($input['command'] === 'settle_receipt') {
            $receipt = \Modules\Shift\Models\CashierShiftHandoverReceipt::whereKey($input['receipt_id'])->firstOrFail();
            $manager = \Modules\BranchManagers\Models\BranchManager::findOrFail($input['actor_id']);
            \Illuminate\Support\Facades\Auth::guard()->setUser($manager);

            $token = $manager->createToken('settlement-worker')->plainTextToken;
            $request = \Illuminate\Http\Request::create(
                '/api/v1/branch-manager/handover-receipts/'.$receipt->id.'/settlements', 'POST', [
                    'settlement_type' => $input['settlement_type'], 'amount_halalas' => $input['amount_halalas'],
                    'expected_settlement_version' => $input['expected_settlement_version'],
                    'reason' => $input['reason'] ?? 'Concurrent settlement audit',
                ], [], [], ['HTTP_ACCEPT' => 'application/json', 'HTTP_AUTHORIZATION' => 'Bearer '.$token,
                    'HTTP_IDEMPOTENCY_KEY' => $input['operation_id'] ?? (string) \Illuminate\Support\Str::uuid()]);
            $response = app(\Illuminate\Contracts\Http\Kernel::class)->handle($request);
            $data = json_decode($response->getContent(), true) ?: [];
            $result = ['status' => $response->isSuccessful() ? 'settled' : 'conflict',
                'http_status' => $response->getStatusCode(), 'body' => $response->getContent(),
                'code' => $data['code'] ?? $data['message'] ?? null];
        } elseif ($input['command'] === 'reserve_cashier') {
            $source = \Modules\Shift\Models\CashierShift::findOrFail($input['shift_id']);
            $cashier = \Modules\Cashier\Models\Cashier::findOrFail($source->cashier_id);
            app(\Modules\Shift\Services\HandoverService::class)->recordHandover($source, [
                'handover_to_type' => 'branch_manager', 'handover_to_id' => $input['manager_id'], 'handover_amount' => $input['amount'],
            ], $cashier);
            $result = ['status' => 'reserved'];
        } elseif ($input['command'] === 'reserve_manager') {
            $day = \Modules\Shift\Models\BranchManagerShift::findOrFail($input['day_id']);
            $manager = \Modules\BranchManagers\Models\BranchManager::findOrFail($day->branch_manager_id);
            $destination = \Modules\Shift\Models\CashierShift::findOrFail($input['destination_id']);
            $transfer = app(\Modules\Shift\Services\ShiftTransferReceiptService::class)->requestManagerCashTransfer(
                $day, \Modules\Cashier\Models\Cashier::findOrFail($destination->cashier_id), $destination, $input['amount'], $manager);
            $result = ['status' => 'reserved', 'id' => $transfer->id];
        } elseif ($input['command'] === 'create_report') {
            $source = \Modules\Shift\Models\CashierShift::findOrFail($input['shift_id']);
            if ($source->status === \Modules\Shift\Enums\ShiftStatus::NOT_STARTED) {
                $source = app(\Modules\Shift\Services\CashierShiftStartService::class)->startShift($source);
            }
            $cashier = \Modules\Cashier\Models\Cashier::findOrFail($source->cashier_id);
            app(\Modules\Shift\Services\ShiftEndService::class)->endShiftOnly($source, [
                'total_sales' => '10.00', 'cash_collected' => '10.00', 'card_payments' => '0.00', 'counted_cash' => '10.00',
            ], $cashier);
            $result = ['status' => 'created'];
        } elseif ($input['command'] === 'confirm_manager_receipt') {
            $manager = \Modules\BranchManagers\Models\BranchManager::findOrFail($input['manager_id']);
            $receipt = app(\Modules\Shift\Services\ShiftTransferReceiptService::class)
                ->confirmManagerHandover($input['request_id'], $manager, $input['amount']);
            $result = ['status' => 'received', 'id' => $receipt->id];
        } elseif ($input['command'] === 'approve_liability') {
            $source = \Modules\Shift\Models\CashierShift::findOrFail($input['shift_id']);
            $manager = \Modules\BranchManagers\Models\BranchManager::findOrFail($input['manager_id']);
            $token = $manager->createToken('approval-worker')->plainTextToken;
            $request = \Illuminate\Http\Request::create('/api/v1/branch-manager/shifts/'.$source->id.'/liability/approve', 'POST',
                ['version' => 1, 'expected_revision' => $input['expected_revision'] ?? 1], [], [], [
                    'HTTP_ACCEPT' => 'application/json', 'HTTP_AUTHORIZATION' => 'Bearer '.$token,
                    'HTTP_IDEMPOTENCY_KEY' => (string) \Illuminate\Support\Str::uuid(),
                ]);
            $response = app(\Illuminate\Contracts\Http\Kernel::class)->handle($request);
            $data = json_decode($response->getContent(), true) ?: [];
            $result = ['status' => $response->isSuccessful() ? 'approved' : 'rejected',
                'http_status' => $response->getStatusCode(), 'code' => $data['code'] ?? null];
        } else {
            throw new LogicException('Unknown audit worker command: '.$input['command']);
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
} catch (\Symfony\Component\HttpKernel\Exception\HttpException $exception) {
    echo json_encode(['status' => 'http_error', 'status_code' => $exception->getStatusCode(), 'code' => $exception->getMessage()], JSON_THROW_ON_ERROR);
} catch (\Illuminate\Validation\ValidationException $exception) {
    echo json_encode(['status' => 'validation_error', 'errors' => $exception->errors()], JSON_THROW_ON_ERROR);
} catch (\Throwable $exception) {
    fwrite(STDERR, get_class($exception).': '.$exception->getMessage());
    exit(1);
}
