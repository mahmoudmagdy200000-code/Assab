<?php

declare(strict_types=1);

/*
 * Read-only guard for the disposable MySQL 8.4 harness. It verifies the
 * resolved Laravel configuration and live connection before migrate:fresh is
 * allowed to run. Credentials and non-loopback host values are never printed.
 */

$root = dirname(__DIR__, 2);
chdir($root);

$databasePattern = '/\A(?:assab_harness_ci|s111_phase234_audit|s111_phase567_test|s111_phase8910_test|pkg7_test|pkg6_disposable|s12_at02|s12_fs12)_[0-9]+_[0-9]+\z/';
$failures = [];
$evidence = [];
$check = static function (string $label, bool $ok, string $detail = '') use (&$failures, &$evidence): void {
    $evidence[] = ($ok ? 'PASS' : 'FAIL').'  '.$label.($detail !== '' ? '  ['.$detail.']' : '');
    if (! $ok) {
        $failures[] = $label.($detail !== '' ? ' ['.$detail.']' : '');
    }
};

try {
    $expectedDatabase = (string) getenv('DB_DATABASE');
    $rawHost = (string) getenv('DB_HOST');
    $rawPort = (string) getenv('DB_PORT');
    $check('process APP_ENV is testing', getenv('APP_ENV') === 'testing');
    $check('process DB_CONNECTION is mysql', getenv('DB_CONNECTION') === 'mysql');
    $check('process host is exact TCP loopback', $rawHost === '127.0.0.1',
        'host='.($rawHost === '127.0.0.1' ? 'loopback' : '[redacted]'));
    $check('process port is the disposable service port', $rawPort === '3306',
        'port='.($rawPort === '3306' ? '3306' : '[redacted]'));
    $check('process database has an exact disposable name', preg_match($databasePattern, $expectedDatabase) === 1,
        'database='.(preg_match($databasePattern, $expectedDatabase) === 1 ? $expectedDatabase : '[redacted]'));
    $check('process DB_URL is empty', (string) getenv('DB_URL') === '');
    $check('process DATABASE_URL is empty', (string) getenv('DATABASE_URL') === '');
    $check('process DB_SOCKET is empty', (string) getenv('DB_SOCKET') === '');

    if ($failures !== []) {
        throw new RuntimeException('Static target checks failed; live connection was not attempted.');
    }

    require $root.'/vendor/autoload.php';
    $app = require $root.'/bootstrap/app.php';
    $app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

    $check('application environment is testing', $app->environment('testing'), 'env='.$app->environment());
    $default = (string) config('database.default');
    $mysql = (array) config('database.connections.mysql', []);
    $resolvedHost = (string) ($mysql['host'] ?? '');
    $resolvedPort = (string) ($mysql['port'] ?? '');
    $resolvedDatabase = (string) ($mysql['database'] ?? '');
    $check('resolved default connection is mysql', $default === 'mysql', 'default='.$default);
    $check('resolved MySQL URL is empty', empty($mysql['url'] ?? null));
    $check('resolved MySQL socket is empty', empty($mysql['unix_socket'] ?? null));
    $check('resolved MySQL host is exact TCP loopback', $resolvedHost === '127.0.0.1',
        'host='.($resolvedHost === '127.0.0.1' ? 'loopback' : '[redacted]'));
    $check('resolved MySQL port is the disposable service port', $resolvedPort === '3306',
        'port='.($resolvedPort === '3306' ? '3306' : '[redacted]'));
    $check('resolved database equals the requested disposable database',
        $resolvedDatabase === $expectedDatabase && preg_match($databasePattern, $resolvedDatabase) === 1,
        'database='.(preg_match($databasePattern, $resolvedDatabase) === 1 ? $resolvedDatabase : '[redacted]'));

    if ($failures === []) {
        $connection = Illuminate\Support\Facades\DB::connection();
        $check('live Laravel driver is mysql', $connection->getDriverName() === 'mysql', 'driver='.$connection->getDriverName());
        $check('live PDO driver is mysql', $connection->getPdo()->getAttribute(PDO::ATTR_DRIVER_NAME) === 'mysql');
        $actualDatabase = (string) ($connection->selectOne('SELECT DATABASE() AS database')->database ?? '');
        $check('live database is the exact disposable target', $actualDatabase === $expectedDatabase,
            'database='.($actualDatabase === $expectedDatabase ? $actualDatabase : '[redacted]'));
        $version = (string) ($connection->selectOne('SELECT VERSION() AS version')->version ?? '');
        $isMySql84 = preg_match('/\A8\.4\.[0-9]+/', $version) === 1 && stripos($version, 'MariaDB') === false;
        $check('live server is MySQL 8.4.x', $isMySql84,
            'version='.($isMySql84 ? $version : '[unexpected]'));
        $engine = (string) ($connection->selectOne('SELECT @@default_storage_engine AS engine')->engine ?? '');
        $check('default storage engine is InnoDB', strcasecmp($engine, 'InnoDB') === 0, 'engine='.$engine);
    } else {
        $evidence[] = 'SKIP  live connection checks (resolved configuration failed; no connection attempted)';
    }
} catch (Throwable $exception) {
    if ($failures === []) {
        $failures[] = 'guard crashed: '.get_class($exception).': '.preg_replace('/\s+/', ' ', $exception->getMessage());
    }
    $evidence[] = 'FAIL  guard stopped safely';
}

echo implode(PHP_EOL, $evidence), PHP_EOL;
if ($failures !== []) {
    echo '::error title=MYSQL HARNESS TARGET GUARD FAILED::'.implode(' | ', $failures), PHP_EOL;
    echo 'MYSQL_HARNESS_TARGET_GUARD=FAIL', PHP_EOL;
    exit(1);
}

echo 'MYSQL_HARNESS_TARGET_GUARD=PASS', PHP_EOL;
exit(0);
