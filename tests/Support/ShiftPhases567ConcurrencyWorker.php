<?php

// Separate PHP process/connection, used only by the opt-in disposable MySQL gate.
require dirname(__DIR__, 2).'/vendor/autoload.php';
$app = require dirname(__DIR__, 2).'/bootstrap/app.php';
$app->make(\Illuminate\Contracts\Console\Kernel::class)->bootstrap();

$db = \Illuminate\Support\Facades\DB::connection();
if ($db->getDriverName() !== 'mysql' || ! preg_match('/^s111_phase567_test_[a-z0-9_]+$/', $db->getDatabaseName())
    || ! in_array($db->getConfig('host'), ['127.0.0.1', 'localhost'], true)) {
    throw new LogicException('Concurrency worker requires an owned disposable local MySQL schema.');
}
$input = json_decode($argv[1], true, 512, JSON_THROW_ON_ERROR);
file_put_contents($input['signal'].'.started', 'started');
try {
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
        } elseif ($input['command'] === 'legacy_reconcile') {
            $shift = \Modules\Shift\Models\CashierShift::findOrFail($input['shift_id']);
            $manager = \Modules\BranchManagers\Models\BranchManager::findOrFail($input['manager_id']);
            $posting = app(\Modules\Shift\Services\ShortagePostingService::class)->reconcile(
                $shift, $manager, $input['expected_revision'], $input['expected_allocation_version'], $input['operation_id']
            );
            $result = ['status' => 'reconciled', 'total_deltas' => $posting['total_deltas']];
        } elseif ($input['command'] === 'allocate_liability') {
            $shift = \Modules\Shift\Models\CashierShift::findOrFail($input['shift_id']);
            $cashier = \Modules\Cashier\Models\Cashier::findOrFail($input['cashier_id']);
            $allocation = app(\Modules\Shift\Liability\ShiftLiabilityService::class)->allocate(
                $shift->id,
                $cashier,
                $input['shares'],
                $input['expected_version']
            );
            $result = ['status' => 'allocated', 'allocation_id' => $allocation->id, 'version' => $allocation->version];
        } elseif ($input['command'] === 'bulk_correct_public') {
            $actor = \Modules\BranchManagers\Models\BranchManager::findOrFail($input['manager_id']);
            $token = $actor->createToken('public bulk audit')->plainTextToken;
            $locks = [];
            \Illuminate\Support\Facades\DB::listen(function ($query) use (&$locks) {
                if (str_contains(strtolower($query->sql), 'for update')) {
                    $locks[] = $query->sql;
                }
            });
            $request = \Illuminate\Http\Request::create('/api/v1/branch-manager/workday/daily-close', 'PUT', [], [], [], [
                'HTTP_AUTHORIZATION' => 'Bearer '.$token, 'HTTP_ACCEPT' => 'application/json',
                'HTTP_IDEMPOTENCY_KEY' => (string) \Illuminate\Support\Str::uuid(), 'CONTENT_TYPE' => 'application/json',
            ], json_encode($input['payload'], JSON_THROW_ON_ERROR));
            $response = app(\Illuminate\Contracts\Http\Kernel::class)->handle($request);
            $owner = $day = null;
            foreach ($locks as $index => $query) {
                if ($owner === null && str_contains($query, 'from `cashiers`')) {
                    $owner = $index;
                }
                if ($day === null && str_contains($query, 'from `branch_manager_shifts`')) {
                    $day = $index;
                }
            }
            $result = ['http_status' => $response->getStatusCode(), 'body' => json_decode($response->getContent(), true),
                'owner_before_day' => $owner !== null && $day !== null && $owner < $day];
        } elseif ($input['command'] === 'bulk_correct') {
            $day = \Modules\Shift\Models\BranchManagerShift::findOrFail($input['day_id']);
            $actor = \Modules\BranchManagers\Models\BranchManager::findOrFail($input['manager_id']);
            \Illuminate\Support\Facades\Auth::guard()->setUser($actor);
            $service = app(\Modules\Shift\Services\BranchManagerShiftService::class);
            $service->bulkUpdateCashierShifts(
                $input['shifts'],
                $day,
                $actor,
                $input['operation_id'] ?? null
            );
            $result = ['status' => 'bulk_completed'];
        } elseif ($input['command'] === 'submit_boundary') {
            $day = \Modules\Shift\Models\BranchManagerShift::whereKey($input['day_id'])->firstOrFail();
            $manager = \Modules\BranchManagers\Models\BranchManager::find($day->branch_manager_id);
            if ($manager) {
                \Illuminate\Support\Facades\Auth::guard()->setUser($manager);
            }
            $expectedRevision = $input['expected_revision'] ?? 1;
            $request = \Illuminate\Http\Request::create('/api/branch-manager/workday/daily-close/submit', 'POST', [
                'final_notes' => 'Audited daily close submission',
                'branch_manager_shift_id' => $day->id,
                'expected_revision' => $expectedRevision,
            ]);
            $request->setUserResolver(fn () => $manager);
            $response = app(\Modules\Shift\Http\Controllers\BranchManagerShiftController::class)->submitDailyReport($request);
            if ($response->getStatusCode() !== 200) {
                $data = $response->getData(true);
                throw new \RuntimeException('Actual daily submit rejected: '.json_encode($data, JSON_THROW_ON_ERROR));
            }
            $result = ['status' => 'submitted', 'http_status' => $response->getStatusCode()];
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
} catch (\Symfony\Component\HttpKernel\Exception\HttpException $exception) {
    echo json_encode(['status' => 'http_error', 'status_code' => $exception->getStatusCode(), 'code' => $exception->getMessage()], JSON_THROW_ON_ERROR);
} catch (\Illuminate\Validation\ValidationException $exception) {
    echo json_encode(['status' => 'validation_error', 'errors' => $exception->errors()], JSON_THROW_ON_ERROR);
} catch (Throwable $exception) {
    fwrite(STDERR, get_class($exception).': '.$exception->getMessage());
    exit(1);
}
