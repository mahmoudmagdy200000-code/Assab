<?php

declare(strict_types=1);

/*
 * ASSAB BRC v3.4 isolated regression CI -- Layer 2 isolation guard (Laravel).
 *
 * Boots the real application exactly as the test suite will and proves, from
 * the RESOLVED Laravel configuration (not just from environment variables),
 * that:
 *   - the application environment is "testing";
 *   - the default database connection is SQLite and its database is the
 *     in-memory database (no file, no URL);
 *   - an actual connection reports the sqlite driver and an empty file path
 *     (PRAGMA database_list), i.e. nothing on disk;
 *   - no configured connection points at a non-loopback host (so a Staging /
 *     Production hostname that leaked in through .env.testing or another file
 *     is detected even if it is not the default connection).
 *
 * It never prints credentials. Exit code 0 = PASS, 1 = FAIL.
 */

$root = dirname(__DIR__, 2);
chdir($root);

$failures = [];
$evidence = [];
$check = static function (string $label, bool $ok, string $detail = '') use (&$failures, &$evidence): void {
    $evidence[] = ($ok ? 'PASS' : 'FAIL').'  '.$label.($detail !== '' ? '  ['.$detail.']' : '');
    if (! $ok) {
        $failures[] = $label.($detail !== '' ? ' ['.$detail.']' : '');
    }
};

try {
    require $root.'/vendor/autoload.php';
    $app = require $root.'/bootstrap/app.php';
    $app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

    $check('application environment is "testing"', $app->environment('testing'), 'env='.$app->environment());

    $default = (string) config('database.default');
    $check('default connection is sqlite', $default === 'sqlite', 'default='.$default);

    $sqlite = (array) config('database.connections.sqlite', []);
    $check('sqlite database is :memory:', ($sqlite['database'] ?? null) === ':memory:', 'database='.($sqlite['database'] ?? 'unset'));
    $check('sqlite connection has no URL', empty($sqlite['url'] ?? null));

    $allowedHosts = ['', '127.0.0.1', 'localhost', '::1'];
    foreach ((array) config('database.connections', []) as $name => $cfg) {
        $host = (string) ($cfg['host'] ?? '');
        $url = (string) ($cfg['url'] ?? '');
        $dbName = (string) ($cfg['database'] ?? '');
        $hostOk = in_array($host, $allowedHosts, true);
        $check("connection '{$name}' host is empty or loopback", $hostOk, 'host='.($host === '' ? '(none)' : ($hostOk ? 'loopback' : '[non-loopback redacted]')));
        $check("connection '{$name}' has no URL", $url === '');
        $named = (bool) preg_match('/staging|prod|coolify/i', $dbName);
        $check("connection '{$name}' does not name a Staging/Production database", ! $named, 'database='.($named ? '[redacted]' : ($dbName === '' ? '(none)' : $dbName)));
    }

    // Only open a live connection once every static check above has passed, so a
    // misconfigured target is never contacted by the guard itself.
    if ($failures === []) {
        $connection = Illuminate\Support\Facades\DB::connection();
        $check('live connection driver is sqlite', $connection->getDriverName() === 'sqlite', 'driver='.$connection->getDriverName());

        $dbList = $connection->select('PRAGMA database_list');
        $files = array_map(static fn ($row): string => (string) ($row->file ?? ''), $dbList);
        $check('PRAGMA database_list reports no database file (memory only)', $files === [''], 'files='.json_encode($files));
    } else {
        $evidence[] = 'SKIP  live connection checks (static configuration already failed; no connection attempted)';
    }

    $cache = (string) config('cache.default');
    $check('cache store is array', $cache === 'array', 'cache='.$cache);
    $check('session driver is array', (string) config('session.driver') === 'array', 'session='.config('session.driver'));
    $check('queue connection is sync', (string) config('queue.default') === 'sync', 'queue='.config('queue.default'));
} catch (Throwable $e) {
    $failures[] = 'guard crashed: '.get_class($e).': '.preg_replace('/\s+/', ' ', $e->getMessage());
    $evidence[] = 'FAIL  guard crashed';
}

echo implode(PHP_EOL, $evidence), PHP_EOL;

if ($failures !== []) {
    echo '::error title=LARAVEL ISOLATION GUARD FAILED::'.implode(' | ', $failures), PHP_EOL;
    echo 'LARAVEL_ISOLATION_GUARD=FAIL', PHP_EOL;
    exit(1);
}

echo 'LARAVEL_ISOLATION_GUARD=PASS', PHP_EOL;
exit(0);
