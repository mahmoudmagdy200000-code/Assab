<?php

namespace Tests\Feature;

use Illuminate\Auth\GenericUser;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Routing\Route;
use Illuminate\Support\Facades\DB;
use Modules\Admin\Http\Middleware\IdempotencyKey;
use Modules\Admin\Support\TenantContext;
use Symfony\Component\HttpFoundation\Response;
use Tests\TestCase;

class IdempotencyKeyMiddlewareTest extends TestCase
{
    use RefreshDatabase;

    public function test_same_user_resource_key_replays_the_first_successful_response(): void
    {
        $executions = 0;
        $middleware = app(IdempotencyKey::class);
        $actor = $this->actor('user-a', 'company-a');
        $first = $middleware->handle($this->request('resource-1', 'key-1', '{"amount":1,"note":"x"}', $actor), function () use (&$executions): Response {
            $executions++;

            return response()->json(['effectId' => 'effect-1'], 201);
        });
        $replayed = $middleware->handle($this->request('resource-1', 'key-1', '{"note":"x","amount":1}', $actor), function () use (&$executions): Response {
            $executions++;

            return response()->json(['effectId' => 'effect-2'], 201);
        });

        $this->assertSame(201, $first->getStatusCode());
        $this->assertSame('{"effectId":"effect-1"}', $first->getContent());
        $this->assertSame(201, $replayed->getStatusCode());
        $this->assertSame($first->getContent(), $replayed->getContent());
        $this->assertSame(1, $executions);
        $this->assertSame(1, DB::table('asab_command_idempotency_keys')->where('status', 'completed')->count());
    }

    public function test_same_scoped_key_with_changed_payload_conflicts_without_a_second_effect(): void
    {
        $executions = 0;
        $middleware = app(IdempotencyKey::class);
        $actor = $this->actor('user-a', 'company-a');
        $middleware->handle($this->request('resource-1', 'key-2', '{"amount":1}', $actor), function () use (&$executions): Response {
            $executions++;

            return response()->json(['effectId' => 'effect-1'], 201);
        });
        $conflict = $middleware->handle($this->request('resource-1', 'key-2', '{"amount":2}', $actor), function () use (&$executions): Response {
            $executions++;

            return response()->json(['effectId' => 'effect-2'], 201);
        });

        $this->assertSame(409, $conflict->getStatusCode());
        $this->assertSame('IDEMPOTENCY_KEY_REUSED', json_decode($conflict->getContent(), true)['error']['code']);
        $this->assertStringNotContainsString('effect-1', $conflict->getContent());
        $this->assertSame(1, $executions);
        $this->assertSame(1, DB::table('asab_command_idempotency_keys')->count());
    }

    public function test_same_textual_key_from_a_different_user_is_rejected_without_response_disclosure(): void
    {
        $executions = 0;
        $middleware = app(IdempotencyKey::class);
        $first = $middleware->handle($this->request('resource-1', 'key-3', '{"amount":1}', $this->actor('user-a', 'company-a')), function () use (&$executions): Response {
            $executions++;

            return response()->json(['privateEffect' => 'user-a-result'], 201);
        });
        $second = $middleware->handle($this->request('resource-1', 'key-3', '{"amount":1}', $this->actor('user-b', 'company-a')), function () use (&$executions): Response {
            $executions++;

            return response()->json(['privateEffect' => 'user-b-result'], 201);
        });

        $this->assertSame(201, $first->getStatusCode());
        $this->assertSame(409, $second->getStatusCode());
        $this->assertSame('IDEMPOTENCY_KEY_REUSED', json_decode($second->getContent(), true)['error']['code']);
        $this->assertStringNotContainsString('user-a-result', $second->getContent());
        $this->assertSame(1, $executions);
    }

    public function test_same_user_can_use_same_textual_key_for_a_distinct_resource_scope(): void
    {
        $executions = 0;
        $middleware = app(IdempotencyKey::class);
        $actor = $this->actor('user-a', 'company-a');
        foreach (['resource-1', 'resource-2'] as $resource) {
            $response = $middleware->handle($this->request($resource, 'key-4', '{"amount":1}', $actor), function () use (&$executions, $resource): Response {
                $executions++;

                return response()->json(['resource' => $resource], 201);
            });
            $this->assertSame(201, $response->getStatusCode());
        }

        $this->assertSame(2, $executions);
        $this->assertSame(2, DB::table('asab_command_idempotency_keys')->count());
        $this->assertSame(1, DB::table('asab_command_idempotency_owners')->count());
    }

    public function test_empty_json_action_executes_once_and_replays(): void
    {
        $middleware = app(IdempotencyKey::class);
        $actor = $this->actor('user-a', 'company-a');
        $executions = 0;
        $handler = function () use (&$executions): Response {
            $executions++;

            return response()->json(['approved' => true]);
        };
        $first = $middleware->handle($this->request('resource-1', 'empty-key', '', $actor), $handler);
        $retry = $middleware->handle($this->request('resource-1', 'empty-key', '', $actor), $handler);

        $this->assertSame(200, $first->getStatusCode());
        $this->assertSame($first->getContent(), $retry->getContent());
        $this->assertSame(1, $executions);
    }

    public function test_scope_changes_cannot_create_another_command_or_disclose_the_original_reply(): void
    {
        $middleware = app(IdempotencyKey::class);
        $actor = $this->actor('user-a', 'company-a');
        $tenant = app(TenantContext::class);
        $tenant->resolved = true;
        $tenant->companyIds = ['company-a'];
        $tenant->branchIds = ['branch-a'];
        $tenant->brandIds = ['brand-a'];
        $tenant->moduleKeys = ['shifts'];
        $tenant->roleKey = 'accountant';
        $original = clone $tenant;
        $executions = 0;
        $handler = function () use (&$executions): Response {
            $executions++;

            return response()->json(['privateEffect' => 'original-result']);
        };
        $first = $middleware->handle($this->request('resource-1', 'scope-key', '{}', $actor), $handler);
        $identity = DB::table('asab_command_idempotency_keys')->value('identity_hash');

        foreach ([
            'companyIds' => ['company-a', 'company-b'],
            'branchIds' => ['branch-a', 'branch-b'],
            'brandIds' => ['brand-a', 'brand-b'],
            'moduleKeys' => ['shifts', 'cash'],
            'roleKey' => 'head',
        ] as $field => $changed) {
            $tenant->{$field} = $changed;
            $response = $middleware->handle($this->request('resource-1', 'scope-key', '{}', $actor), $handler);
            $this->assertSame(409, $response->getStatusCode());
            $this->assertSame('IDEMPOTENCY_SCOPE_CHANGED', json_decode($response->getContent(), true)['error']['code']);
            $this->assertStringNotContainsString('original-result', $response->getContent());
            $this->assertSame(1, DB::table('asab_command_idempotency_keys')->count());
            $this->assertSame($identity, DB::table('asab_command_idempotency_keys')->value('identity_hash'));
            $tenant->{$field} = $original->{$field};
        }

        $retry = $middleware->handle($this->request('resource-1', 'scope-key', '{}', $actor), $handler);
        $this->assertSame($first->getContent(), $retry->getContent());
        $this->assertSame(1, $executions);

        // An expired processing reservation must not be recovered under a new scope.
        DB::table('asab_command_idempotency_keys')->update([
            'status' => 'processing',
            'reservation_expires_at' => now()->subMinutes(10),
        ]);
        $tenant->branchIds = ['branch-b'];
        $blocked = $middleware->handle($this->request('resource-1', 'scope-key', '{}', $actor), $handler, null, 'transaction');
        $this->assertSame(409, $blocked->getStatusCode());
        $this->assertSame('IDEMPOTENCY_SCOPE_CHANGED', json_decode($blocked->getContent(), true)['error']['code']);
        $this->assertSame(1, $executions);
    }

    private function request(string $resource, string $key, string $body, GenericUser $actor): Request
    {
        $request = Request::create('/test/'.$resource, 'POST', [], [], [], [
            'CONTENT_TYPE' => 'application/json',
            'HTTP_IDEMPOTENCY_KEY' => $key,
        ], $body);
        $route = new Route(['POST'], 'test/{resource}', static fn () => null);
        $route->name('idempotency.test');
        $route->bind($request);
        $request->setRouteResolver(static fn () => $route);
        $request->setUserResolver(static fn () => $actor);

        return $request;
    }

    private function actor(string $id, string $companyId): GenericUser
    {
        return new GenericUser([
            'id' => $id,
            'company_id' => $companyId,
            'branch_id' => 'branch-a',
        ]);
    }
}
