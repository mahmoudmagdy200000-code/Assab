<?php

require __DIR__.'/../../vendor/autoload.php';
$app = require __DIR__.'/../../bootstrap/app.php';
$app->make(\Illuminate\Contracts\Console\Kernel::class)->bootstrap();
$db = \Illuminate\Support\Facades\DB::connection();
if (getenv('PKG7_MYSQL_TESTS') !== '1' || $db->getDriverName() !== 'mysql'
    || ! preg_match('/^pkg7_test_[a-z0-9_]+$/', $db->getDatabaseName())
    || ! in_array($db->getConfig('host'), ['localhost', '127.0.0.1'], true)) {
    throw new \LogicException('Refusing worker on unapproved database.');
}
[$script, $shiftId, $managerId, $action, $barrier, $index, $replacementId] = $argv;
$shift = \Modules\Shift\Models\CashierShift::withoutEagerLoads()->findOrFail($shiftId);
$manager = \Modules\BranchManagers\Models\BranchManager::findOrFail($managerId);
file_put_contents($barrier.'.'.$index, 'ready');
$deadline = microtime(true) + 15;
while (! file_exists($barrier) && microtime(true) < $deadline) {
    usleep(10000);
}
if (! file_exists($barrier)) {
    throw new \RuntimeException('Barrier timeout');
}
try {
    if ($action === 'mark') {
        app(\Modules\Shift\Services\ShiftNotWorkedService::class)->mark($shift, $manager, 'Closed');
    } elseif ($action === 'start') {
        \Illuminate\Support\Facades\Auth::setUser($shift->cashier()->firstOrFail());
        $shift->startShift();
    } elseif ($action === 'reassign') {
        \Illuminate\Support\Facades\Auth::setUser($manager);
        $request = \Illuminate\Http\Request::create('/', 'POST', ['new_cashier_id' => $replacementId]);
        $response = app(\Modules\Shift\Http\Controllers\ReassignmentShiftController::class)->reassign($request, $shiftId);
        echo json_encode(['ok' => $response->getStatusCode() === 200, 'status' => $response->getStatusCode()]);
        exit(0);
    } else {
        throw new \LogicException('Unknown worker action');
    }
    echo json_encode(['ok' => true]);
} catch (\Symfony\Component\HttpKernel\Exception\ConflictHttpException $exception) {
    echo json_encode(['ok' => false, 'code' => $exception->getMessage()]);
}
