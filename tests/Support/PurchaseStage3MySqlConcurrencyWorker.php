<?php

// A separate process/connection for the opt-in Stage 3 MySQL locking & concurrency gate.
require dirname(__DIR__, 2).'/vendor/autoload.php';
$app = require dirname(__DIR__, 2).'/bootstrap/app.php';
$app->make(\Illuminate\Contracts\Console\Kernel::class)->bootstrap();

$db = \Illuminate\Support\Facades\DB::connection();
if (getenv('STAGE3_MYSQL_TESTS') !== '1' || $db->getDriverName() !== 'mysql'
    || ! preg_match('/^assab_stage3_mysql[a-z0-9_]*$/', $db->getDatabaseName())
    || ! in_array($db->getConfig('host'), ['127.0.0.1', 'localhost'], true)) {
    throw new LogicException('Stage 3 MySQL worker requires an explicitly enabled isolated local MySQL schema.');
}

$input = json_decode($argv[1], true, 512, JSON_THROW_ON_ERROR);
file_put_contents($input['signal'].'.started', 'started');

try {
    $result = $db->transaction(function () use ($input): array {
        $command = $input['command'];

        if ($command === 'approve_excess') {
            $actor = \Modules\Admin\Models\AsabUser::findOrFail($input['actor_id']);
            $receipt = app(\Modules\Purchase\Services\GoodsReceiptService::class)
                ->approveExcess($input['receipt_id'], $actor);
            $res = ['status' => 'ok', 'excess_status' => $receipt->items->first()?->excess_status];
        } elseif ($command === 'reject_excess') {
            $actor = \Modules\Admin\Models\AsabUser::findOrFail($input['actor_id']);
            $receipt = app(\Modules\Purchase\Services\GoodsReceiptService::class)
                ->rejectExcess($input['receipt_id'], $actor, $input['reason'] ?? 'Concurrent rejection');
            $res = ['status' => 'ok', 'excess_status' => $receipt->items->first()?->excess_status];
        } elseif ($command === 'create_return') {
            $order = \Modules\Purchase\Models\PurchaseOrder::findOrFail($input['order_id']);
            $return = app(\Modules\Purchase\Services\ReturnManagementService::class)->createReturn($order, [
                'created_by' => $input['actor_id'],
                'required_action' => \Modules\Purchase\Enums\ReturnRequiredAction::CASH_REFUND->value,
                'items' => [[
                    'purchase_order_item_id' => $input['order_item_id'],
                    'return_quantity' => (float) $input['qty'],
                    'quality_reason' => \Modules\Purchase\Enums\InspectionQuality::POOR->value,
                ]],
            ]);
            $res = ['status' => 'created', 'return_id' => $return->id];
        } elseif ($command === 'confirm_handover') {
            $return = app(\Modules\Purchase\Services\ReturnManagementService::class)
                ->confirmHandover($input['return_id'], $input['actor_id']);
            $res = ['status' => 'handed_over', 'handed_over_at' => $return->handed_over_at?->toISOString()];
        } else {
            throw new LogicException("Unknown Stage 3 concurrency command: {$command}");
        }

        if ($input['hold'] ?? false) {
            file_put_contents($input['signal'].'.held', 'held');
            $deadline = microtime(true) + 15;
            while (! is_file($input['release'])) {
                if (microtime(true) > $deadline) {
                    throw new RuntimeException('Stage 3 MySQL concurrency barrier timed out.');
                }
                usleep(10000);
            }
        }

        return $res;
    });

    echo json_encode($result, JSON_THROW_ON_ERROR);
} catch (\Modules\Admin\Exceptions\AsabException $e) {
    echo json_encode([
        'status' => 'rejected',
        'error_code' => $e->errorCode,
        'http_status' => $e->status,
        'message' => $e->getMessage(),
    ], JSON_THROW_ON_ERROR);
} catch (\Throwable $e) {
    fwrite(STDERR, $e->getMessage()."\n".$e->getTraceAsString());
    exit(1);
}
