<?php

declare(strict_types=1);

// Independent PHP worker process for Stage 0 D4 Oracle MySQL 8.4.11 / InnoDB concurrency tests.
// Connects via its own PDO connection and executes real HTTP requests through Laravel's Http\Kernel.

require dirname(__DIR__, 2).'/vendor/autoload.php';
$app = require dirname(__DIR__, 2).'/bootstrap/app.php';
$app->make(\Illuminate\Contracts\Console\Kernel::class)->bootstrap();

$db = \Illuminate\Support\Facades\DB::connection();
$dbName = (string) $db->getDatabaseName();
$host = (string) $db->getConfig('host');
$port = (string) $db->getConfig('port');

if (
    getenv('PURCHASE_STAGE0_MYSQL_TESTS') !== '1'
    || $db->getDriverName() !== 'mysql'
    || ! in_array($host, ['127.0.0.1', 'localhost'], true)
    || $port !== '3310'
    || preg_match('/^assab_stage0_d4_[a-z0-9_]+$/', $dbName) !== 1
) {
    fwrite(STDERR, "SAFETY GUARD BLOCKED: Worker refused target '{$host}:{$port}/{$dbName}'.\n");
    exit(70);
}

$pdo = $db->getPdo();
$meta = $pdo->query('SELECT CONNECTION_ID() AS cid, VERSION() AS version, @@default_storage_engine AS engine, @@transaction_isolation AS iso')->fetch(\PDO::FETCH_ASSOC);
if (
    ! is_array($meta)
    || preg_match('/^8\.4\.[0-9]+/', (string) ($meta['version'] ?? '')) !== 1
    || stripos((string) ($meta['version'] ?? ''), 'MariaDB') !== false
    || strcasecmp((string) ($meta['engine'] ?? ''), 'InnoDB') !== 0
    || strtoupper((string) ($meta['iso'] ?? '')) !== 'REPEATABLE-READ'
) {
    fwrite(STDERR, "SAFETY GUARD BLOCKED: Server metadata mismatch.\n");
    exit(71);
}

$input = json_decode($argv[1], true, 512, JSON_THROW_ON_ERROR);
$signal = (string) $input['signal'];
file_put_contents($signal.'.connected', json_encode([
    'connection_id' => (int) $meta['cid'],
    'version' => (string) $meta['version'],
    'engine' => (string) $meta['engine'],
    'isolation' => (string) $meta['iso'],
], JSON_THROW_ON_ERROR));
$holdHook = $input['hold_hook'] ?? null;
$holdTargetCount = (int) ($input['hold_n'] ?? 1);
$releaseFile = $input['release'] ?? null;
$failHook = $input['fail_hook'] ?? null;
$failTargetCount = (int) ($input['fail_n'] ?? 1);

$waitForRelease = static function () use ($signal, $releaseFile): void {
    file_put_contents($signal.'.held', 'held');
    if (! is_string($releaseFile) || $releaseFile === '') {
        return;
    }
    $deadline = microtime(true) + 15.0;
    while (! is_file($releaseFile)) {
        if (microtime(true) > $deadline) {
            throw new \RuntimeException('Worker barrier timed out waiting for release signal.');
        }
        usleep(10000);
    }
};

$holdCounter = 0;
if ($holdHook === 'inventory_updating') {
    \Modules\Purchase\Models\BranchInventory::updating(static function () use (&$holdCounter, $holdTargetCount, $waitForRelease): void {
        $holdCounter++;
        if ($holdCounter === $holdTargetCount) {
            $waitForRelease();
        }
    });
} elseif ($holdHook === 'variance_creating') {
    \Modules\Inventory\Models\InternalTransferVariance::creating(static function () use (&$holdCounter, $holdTargetCount, $waitForRelease): void {
        $holdCounter++;
        if ($holdCounter === $holdTargetCount) {
            $waitForRelease();
        }
    });
} elseif ($holdHook === 'order_updating') {
    \Modules\Purchase\Models\PurchaseOrder::updating(static function () use (&$holdCounter, $holdTargetCount, $waitForRelease): void {
        $holdCounter++;
        if ($holdCounter === $holdTargetCount) {
            $waitForRelease();
        }
    });
}

$failCounter = 0;
if ($failHook === 'variance_creating') {
    \Modules\Inventory\Models\InternalTransferVariance::creating(static function () use (&$failCounter, $failTargetCount): void {
        $failCounter++;
        if ($failCounter === $failTargetCount) {
            throw new \RuntimeException('Simulated partial dispatch failure on item #'.$failTargetCount);
        }
    });
}

file_put_contents($signal.'.started', 'started');

$startBarrier = $input['start_barrier'] ?? null;
if (is_string($startBarrier) && $startBarrier !== '') {
    $deadline = microtime(true) + 15.0;
    while (! is_file($startBarrier)) {
        if (microtime(true) > $deadline) {
            fwrite(STDERR, "Worker start barrier timed out.\n");
            exit(72);
        }
        usleep(5000);
    }
}

$server = [
    'HTTP_ACCEPT' => 'application/json',
    'CONTENT_TYPE' => 'application/json',
    'HTTP_AUTHORIZATION' => 'Bearer '.(string) $input['token'],
];
if (! empty($input['idempotency_key'])) {
    $server['HTTP_IDEMPOTENCY_KEY'] = (string) $input['idempotency_key'];
}

$t0 = microtime(true);
try {
    $request = \Illuminate\Http\Request::create(
        (string) $input['uri'],
        strtoupper((string) ($input['method'] ?? 'POST')),
        [],
        [],
        [],
        $server,
        json_encode($input['payload'] ?? [], JSON_THROW_ON_ERROR)
    );

    /** @var \Illuminate\Contracts\Http\Kernel $kernel */
    $kernel = app(\Illuminate\Contracts\Http\Kernel::class);
    $response = $kernel->handle($request);
    $kernel->terminate($request, $response);

    $rawBody = (string) $response->getContent();
    $decodedBody = json_decode($rawBody, true);
    $exceptionInfo = null;
    if (isset($response->exception) && $response->exception instanceof \Throwable) {
        $exceptionInfo = get_class($response->exception).': '.$response->exception->getMessage();
    }

    echo json_encode([
        'connection_id' => (int) $meta['cid'],
        'version' => (string) $meta['version'],
        'engine' => (string) $meta['engine'],
        'isolation' => (string) $meta['iso'],
        'http_status' => $response->getStatusCode(),
        'body' => $decodedBody ?? $rawBody,
        'exception' => $exceptionInfo,
        'elapsed_ms' => round((microtime(true) - $t0) * 1000, 2),
    ], JSON_THROW_ON_ERROR);
} catch (\Throwable $e) {
    fwrite(STDERR, get_class($e).': '.$e->getMessage()."\n");
    exit(1);
} finally {
    \Modules\Purchase\Models\BranchInventory::flushEventListeners();
    \Modules\Inventory\Models\InternalTransferVariance::flushEventListeners();
    \Modules\Purchase\Models\PurchaseOrder::flushEventListeners();
}
