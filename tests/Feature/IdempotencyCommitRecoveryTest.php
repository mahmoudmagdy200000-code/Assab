<?php

namespace Tests\Feature;

use Illuminate\Auth\GenericUser;
use Illuminate\Foundation\Testing\DatabaseTruncation;
use Illuminate\Http\Request;
use Illuminate\Routing\Route;
use Illuminate\Support\Facades\DB;
use Modules\Admin\Http\Middleware\IdempotencyKey;
use Tests\TestCase;

/** No enclosing test transaction: these cases exercise a real business commit. */
class IdempotencyCommitRecoveryTest extends TestCase
{
    use DatabaseTruncation;

    public function test_commit_then_throw_keeps_key_reserved_even_after_expiry(): void
    {
        $this->assertSame(0, DB::transactionLevel());
        $middleware = app(IdempotencyKey::class);
        $request = Request::create('/commit-probe/1', 'POST', [], [], [], [
            'CONTENT_TYPE' => 'application/json', 'HTTP_IDEMPOTENCY_KEY' => 'commit-throw-key',
        ], '{}');
        $route = new Route(['POST'], 'commit-probe/{id}', static fn () => null);
        $route->name('commit-probe');
        $route->bind($request);
        $request->setRouteResolver(static fn () => $route);
        $request->setUserResolver(static fn () => new GenericUser(['id' => 'actor', 'company_id' => 'company']));
        $executions = 0;
        try {
            $middleware->handle($request, function () use (&$executions) {
                $executions++;
                // Simulate the malformed writer the guard explicitly defends against.
                DB::commit();
                throw new \RuntimeException('exception after real commit');
            }, null, 'transaction');
            $this->fail('The writer exception must propagate.');
        } catch (\RuntimeException $exception) {
            $this->assertSame('exception after real commit', $exception->getMessage());
        }
        $record = DB::table('asab_command_idempotency_keys')->sole();
        $this->assertSame('processing', $record->status);
        $this->assertNull($record->reservation_expires_at);
        $this->travel(10)->minutes();
        $retry = $middleware->handle($request, function () use (&$executions) {
            $executions++;

            return response()->json(['unexpected' => true]);
        }, null, 'transaction');
        $this->assertSame(409, $retry->getStatusCode());
        $this->assertSame('IDEMPOTENCY_IN_PROGRESS', json_decode($retry->getContent(), true)['error']['code']);
        $this->assertSame(1, $executions);
        $this->assertSame(0, DB::transactionLevel());
    }
}
