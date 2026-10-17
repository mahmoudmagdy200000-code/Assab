<?php

namespace Tests\Feature;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Modules\Admin\Models\AsabUser;
use Symfony\Component\Process\Process;
use Tests\TestCase;

/** Real processes, independent MySQL/MariaDB connections, committed fixtures, explicit barriers. */
class AdminLegacyRefreshConcurrencyTest extends TestCase
{
    private string $barriers;
    private array $workers = [];
    private array $users = [];

    protected function setUp(): void
    {
        parent::setUp();
        if (! in_array(DB::connection()->getDriverName(), ['mysql', 'mariadb'], true)) {
            $this->markTestSkipped('MySQL/MariaDB InnoDB independent connections required');
        }
        $this->assertSame('testing', app()->environment());
        $this->assertStringStartsWith('s12_at02_', DB::connection()->getDatabaseName());
        $this->assertMatchesRegularExpression('/(^8\.4\.)|(MariaDB)/i', DB::selectOne('SELECT VERSION() AS version')->version);
        $this->assertSame(0, DB::transactionLevel(), 'Fixtures must be committed outside RefreshDatabase transactions');
        foreach (['asab_users', 'personal_access_tokens', 'admin_sessions'] as $table) {
            $engine = DB::selectOne('SELECT ENGINE AS engine FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ?', [$table]);
            $this->assertNotNull($engine, 'Run migrations in the disposable schema first');
            $this->assertSame('InnoDB', $engine->engine);
        }
        $this->barriers = sys_get_temp_dir().'/s12-at02-'.Str::uuid();
        mkdir($this->barriers, 0700);
    }

    protected function tearDown(): void
    {
        foreach ($this->workers as $worker) {
            if ($worker->isRunning()) {
                $worker->stop(1);
            }
        }
        foreach ($this->users as $user) {
            $user->tokens()->delete();
            $user->forceDelete();
        }
        if (isset($this->barriers)) {
            foreach (glob($this->barriers.'/*') as $file) {
                unlink($file);
            }
            rmdir($this->barriers);
        }
        parent::tearDown();
    }

    public function test_two_refreshes_with_same_resolved_candidate_create_one_successor_pair(): void
    {
        [$user, $token] = $this->fixture();
        $a = $this->worker('a', 'refresh', $user, $token->plainTextToken, false);
        $b = $this->worker('b', 'refresh', $user, $token->plainTextToken, false);
        $this->awaitFile('a.candidate');
        $this->awaitFile('b.candidate');
        // Both workers resolved the same token before either could rotate it.
        $this->release('a.candidate_go');
        $this->release('b.candidate_go');
        $first = $this->workerResult($a, 'a');
        $second = $this->workerResult($b, 'b');
        $this->record('refresh/refresh', [$first, $second]);
        $statuses = [$first['status'], $second['status']];
        sort($statuses);
        $this->assertSame(['INVALID_TOKEN', 'OK'], $statuses);
        $this->assertNotSame($first['connection_id'], $second['connection_id']);
        $this->assertSame(2, $user->tokens()->count());
        $this->assertSame(1, $user->tokens()->where('name', 'refresh')->count());
        $this->assertSame(1, $user->tokens()->where('name', 'access')->count());
        $this->assertSame(0, $user->tokens()->whereNotNull('session_id')->count());
        $this->assertDatabaseMissing('personal_access_tokens', ['id' => $token->accessToken->id]);
    }

    public function test_revocation_and_password_change_winning_prevent_stale_refresh_revival(): void
    {
        foreach (['revoke', 'credential'] as $action) {
            [$user, $token] = $this->fixture();
            $prefix = $action.'_first';
            $refresh = $this->worker($prefix.'_r', 'refresh', $user, $token->plainTextToken);
            $this->awaitFile($prefix.'_r.candidate');
            $writer = $this->worker($prefix.'_w', $action, $user, $token->plainTextToken);
            $this->awaitFile($prefix.'_w.held');
            $this->release($prefix.'_r.candidate_go');
            $this->awaitLockWait($prefix.'_r');
            $this->release($prefix.'_w.held_go');
            $written = $this->workerResult($writer, $prefix.'_w');
            $rejected = $this->workerResult($refresh, $prefix.'_r');
            $this->assertSame('OK', $written['status']);
            $this->assertSame('INVALID_TOKEN', $rejected['status']);
            $this->assertNotSame($written['connection_id'], $rejected['connection_id']);
            $this->assertSame(0, $user->tokens()->count());
            if ($action === 'credential') {
                $this->assertTrue(\Illuminate\Support\Facades\Hash::check('NewPassword456!', $user->fresh()->password));
            }
            $this->record('refresh/'.$action.' writer-first', [$written, $rejected]);
        }
    }

    public function test_refresh_winning_serializes_with_revocation_and_password_change(): void
    {
        foreach (['revoke', 'credential'] as $action) {
            [$user, $token] = $this->fixture();
            $prefix = $action.'_second';
            $refresh = $this->worker($prefix.'_r', 'refresh', $user, $token->plainTextToken);
            $this->awaitFile($prefix.'_r.candidate');
            $this->release($prefix.'_r.candidate_go');
            $this->awaitFile($prefix.'_r.held');
            $writer = $this->worker($prefix.'_w', $action, $user, $token->plainTextToken);
            $this->awaitLockWait($prefix.'_w');
            $this->release($prefix.'_r.held_go');
            $this->awaitFile($prefix.'_w.held');
            $this->release($prefix.'_w.held_go');
            $rotated = $this->workerResult($refresh, $prefix.'_r');
            $written = $this->workerResult($writer, $prefix.'_w');
            $this->assertSame('OK', $rotated['status']);
            $this->assertSame('OK', $written['status']);
            $this->assertNotSame($rotated['connection_id'], $written['connection_id']);
            // Specific-token revocation is a no-op after that old token was consumed;
            // credential mutation must remove the newly committed pair as well.
            $this->assertSame($action === 'credential' ? 0 : 2, $user->tokens()->count());
            if ($action === 'credential') {
                $this->assertTrue(\Illuminate\Support\Facades\Hash::check('NewPassword456!', $user->fresh()->password));
            }
            $this->record('refresh/'.$action.' refresh-first', [$rotated, $written]);
        }
    }

    public function test_token_only_logout_winning_prevents_stale_refresh_revival(): void
    {
        [$user, $token] = $this->fixture();
        $refresh = $this->worker('token_r', 'refresh', $user, $token->plainTextToken);
        $this->awaitFile('token_r.candidate');
        $logout = $this->worker('token_w', 'logout', $user, $token->plainTextToken);
        $this->awaitFile('token_w.held');
        $this->release('token_r.candidate_go');
        // Production uses deterministic User -> Token ordering, so the stale
        // refresh must first wait on the user-row lock held by logout.
        $this->awaitLockWait('token_r', 'asab_users');
        $this->release('token_w.held_go');
        $written = $this->workerResult($logout, 'token_w');
        $rejected = $this->workerResult($refresh, 'token_r');
        $this->record('refresh/logout token-only writer-first', [$written, $rejected]);
        $this->assertSame('OK', $written['status']);
        $this->assertSame('INVALID_TOKEN', $rejected['status']);
        $this->assertNotSame($written['connection_id'], $rejected['connection_id']);
        $this->assertSame(0, $user->tokens()->count());
    }

    private function fixture(): array
    {
        $user = AsabUser::create([
            'name' => 'Legacy concurrency', 'email' => Str::uuid().'@example.test',
            'password' => 'OldPassword123!', 'status' => 'active',
        ]);
        $this->users[] = $user;
        return [$user, $user->createToken('refresh')];
    }

    private function worker(string $name, string $action, AsabUser $user, string $plain, bool $holdRefresh = true): Process
    {
        $code = <<<'WORKER'
require getcwd().'/vendor/autoload.php';
$app = require getcwd().'/bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();
config(['admin.session_families_enabled' => false]);
$dir = getenv('S12_BARRIERS'); $name = getenv('S12_NAME'); $action = getenv('S12_ACTION');
$signal = function ($suffix, $value = 'ready') use ($dir, $name) { file_put_contents($dir.'/'.$name.'.'.$suffix, $value); };
$wait = function ($suffix) use ($dir, $name) {
    $deadline = microtime(true) + 20;
    while (! is_file($dir.'/'.$name.'.'.$suffix)) {
        if (microtime(true) > $deadline) { throw new RuntimeException('Barrier timeout '.$suffix); }
        usleep(10000);
    }
};
$db = Illuminate\Support\Facades\DB::connection();
$db->statement('SET SESSION innodb_lock_wait_timeout = 15');
$connection = (int) $db->selectOne('SELECT CONNECTION_ID() AS id')->id;
$isolation = $db->selectOne('SELECT @@tx_isolation AS level')->level;
$signal('connection', (string) $connection);
$auth = app(Modules\Admin\Services\AuthService::class);
$user = $action === 'refresh' ? null : Modules\Admin\Models\AsabUser::findOrFail(getenv('S12_USER'));
$barrierUsed = false;
Illuminate\Support\Facades\DB::listen(function ($query) use ($action, &$barrierUsed, $signal, $wait) {
    if ($barrierUsed) { return; }
    if ($action === 'logout' && str_contains($query->sql, 'delete') && str_contains($query->sql, 'personal_access_tokens')) {
        $barrierUsed = true; $signal('held'); $wait('held_go'); return;
    }
    if (! str_contains($query->sql, 'select') || ! str_contains($query->sql, 'asab_users')) { return; }
    if ($action === 'refresh' && ! str_contains($query->sql, 'for update')) {
        $barrierUsed = true; $signal('candidate'); $wait('candidate_go');
    } elseif ($action !== 'refresh' && str_contains($query->sql, 'for update')) {
        $barrierUsed = true; $signal('held'); $wait('held_go');
    }
});
try {
    if ($action === 'refresh') {
        $auth->refresh(getenv('S12_TOKEN'), function ($point) use ($signal, $wait) {
            if ($point === 'after_delete_old_tokens' && getenv('S12_HOLD') === '1') { $signal('held'); $wait('held_go'); }
        });
    } elseif ($action === 'revoke') {
        $auth->revokeSession($user, explode('|', getenv('S12_TOKEN'), 2)[0]);
    } elseif ($action === 'logout') {
        // Test-only transaction extends the real token DELETE lock for observation.
        $db->transaction(fn () => $auth->logoutWithRefreshToken(getenv('S12_TOKEN')));
    } else {
        $auth->changePassword($user, 'OldPassword123!', 'NewPassword456!');
    }
    $status = 'OK';
} catch (Modules\Admin\Exceptions\AsabException $e) { $status = $e->errorCode; }
echo json_encode(['status' => $status, 'connection_id' => $connection, 'isolation' => $isolation, 'action' => $action]);
WORKER;
        $worker = new Process([PHP_BINARY, '-r', $code], base_path(), [
            'S12_BARRIERS' => $this->barriers, 'S12_NAME' => $name, 'S12_ACTION' => $action,
            'S12_USER' => $user->id, 'S12_TOKEN' => $plain, 'S12_HOLD' => $holdRefresh ? '1' : '0',
        ]);
        $worker->setTimeout(30);
        $worker->start();
        $this->workers[] = $worker;
        return $worker;
    }

    private function awaitFile(string $suffix): void
    {
        $deadline = microtime(true) + 20;
        while (! is_file($this->barriers.'/'.$suffix)) {
            if (microtime(true) > $deadline) {
                $errors = implode('\n', array_map(fn ($p) => $p->getErrorOutput(), $this->workers));
                $this->fail('Barrier timeout '.$suffix.' '.$errors);
            }
            usleep(10000);
        }
    }

    private function release(string $suffix): void
    {
        file_put_contents($this->barriers.'/'.$suffix, 'go');
    }

    private function awaitLockWait(string $worker, string $expectedTable = 'asab_users'): void
    {
        $this->awaitFile($worker.'.connection');
        $id = (int) file_get_contents($this->barriers.'/'.$worker.'.connection');
        $deadline = microtime(true) + 10;
        do {
            $waiting = DB::selectOne('SELECT trx_query AS query FROM information_schema.INNODB_TRX WHERE trx_mysql_thread_id = ? AND trx_state = ?', [$id, 'LOCK WAIT']);
            if ($waiting) {
                $this->assertStringContainsString($expectedTable, $waiting->query);
                fwrite(STDOUT, "\nS12_LOCK_WAIT ".json_encode(['connection_id' => $id, 'table' => $expectedTable])."\n");
                $this->assertTrue(true, 'Observed independent connection waiting for row lock');
                return;
            }
            // InnoDB metadata is cached; allow more than 100ms between reads.
            usleep(200000);
        } while (microtime(true) < $deadline);
        $this->fail('Expected an actual InnoDB lock wait for '.$worker.' connection '.$id);
    }

    private function workerResult(Process $worker, string $name): array
    {
        $worker->wait();
        $this->assertTrue($worker->isSuccessful(), $name.': '.$worker->getErrorOutput());
        return json_decode($worker->getOutput(), true, flags: JSON_THROW_ON_ERROR);
    }

    private function record(string $scenario, array $results): void
    {
        $record = ['scenario' => $scenario, 'results' => $results,
            'version' => DB::selectOne('SELECT VERSION() AS version')->version,
            'engines' => DB::select("SELECT TABLE_NAME, ENGINE FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME IN ('asab_users', 'personal_access_tokens', 'admin_sessions')"),
            'token_count' => $this->users[count($this->users) - 1]->tokens()->count(),
            'observer_connection' => (int) DB::selectOne('SELECT CONNECTION_ID() AS id')->id,
            'barrier' => $scenario === 'refresh/refresh' ? 'both candidates resolved before release' : 'candidate-resolved + user/token-held; observed INNODB_TRX LOCK WAIT'];
        fwrite(STDOUT, "\nS12_CONCURRENCY ".json_encode($record)."\n");
    }
}
