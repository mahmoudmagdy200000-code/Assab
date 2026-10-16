<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Route;
use Modules\Aggregator\Models\Aggregator;
use Modules\Branch\Models\Branch;
use Modules\BranchManagers\Models\BranchManager;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * Staging returned HTTP 500 («Route [login] not defined.») for an unauthenticated
 * request to an `auth:sanctum` API route whenever the client did not ask for JSON
 * (curl's default wildcard Accept header, or no Accept header at all). Laravel only answers
 * 401 JSON when `$request->expectsJson()`; otherwise it redirects guests to
 * `route('login')`, which this API-only application does not define.
 *
 * `/api` and `/api/*` (which includes `/api/v1/*`) must always be handled as JSON API
 * requests. Everything else keeps Laravel's normal content negotiation.
 */
class ApiUnauthenticatedJsonResponseTest extends TestCase
{
    use RefreshDatabase;

    /** Real requests with explicit control over the Accept header (getJson always adds one). */
    private function request(string $uri, ?string $accept, array $server = []): \Illuminate\Testing\TestResponse
    {
        if ($accept !== null) {
            $server['HTTP_ACCEPT'] = $accept;
        }

        return $this->call('GET', $uri, [], [], [], $server);
    }

    /** @return array<string, array{0: ?string}> */
    public static function acceptHeaders(): array
    {
        return [
            'application/json' => ['application/json'],
            'wildcard (curl default)' => ['*/*'],
            'no Accept header' => [null],
            'html browser style' => ['text/html,application/xhtml+xml'],
        ];
    }

    #[DataProvider('acceptHeaders')]
    public function test_unauthenticated_protected_api_route_returns_json_401_for_any_accept_header(?string $accept): void
    {
        $response = $this->request('/api/aggregators', $accept);

        $response->assertStatus(401);
        $response->assertHeader('Content-Type', 'application/json');
        $response->assertExactJson(['message' => 'Unauthenticated.']);
    }

    #[DataProvider('acceptHeaders')]
    public function test_unauthenticated_protected_api_v1_route_returns_json_401_for_any_accept_header(?string $accept): void
    {
        // Module provider copy of the aggregator routes, served under /api/v1.
        $this->request('/api/v1/aggregators', $accept)
            ->assertStatus(401)
            ->assertExactJson(['message' => 'Unauthenticated.']);

        // Admin/ASAB surface (asab.access + auth:sanctum) under /api/v1.
        $this->request('/api/v1/auth/me', $accept)
            ->assertStatus(401)
            ->assertExactJson(['message' => 'Unauthenticated.']);
    }

    public function test_the_401_contract_is_identical_with_and_without_a_json_accept_header(): void
    {
        $json = $this->request('/api/v1/auth/me', 'application/json');
        $plain = $this->request('/api/v1/auth/me', null);

        $this->assertSame($json->getStatusCode(), $plain->getStatusCode());
        $this->assertSame($json->getContent(), $plain->getContent());
    }

    public function test_an_invalid_bearer_token_is_still_401_json_without_a_json_accept_header(): void
    {
        $this->request('/api/aggregators', '*/*', ['HTTP_AUTHORIZATION' => 'Bearer not-a-real-token'])
            ->assertStatus(401)
            ->assertExactJson(['message' => 'Unauthenticated.']);
    }

    public function test_authentication_failure_is_never_converted_into_a_redirect_or_a_success(): void
    {
        foreach ([null, '*/*', 'text/html'] as $accept) {
            $response = $this->request('/api/aggregators', $accept);

            $this->assertFalse($response->isRedirect(), 'A guest must not be redirected to a login page.');
            $this->assertNotSame(200, $response->getStatusCode());
        }
    }

    /**
     * Existing behavior, characterised rather than assumed: the route file declares
     * `aggregators/available` outside the `auth:sanctum` group, but
     * AggregatorController::__construct() applies `auth:sanctum` to every action, so
     * the list is NOT anonymously readable. This fix must neither open nor close it.
     */
    public function test_aggregator_available_list_still_requires_authentication(): void
    {
        Aggregator::create([
            'name' => 'جاهز', 'code' => 'JAHEZ', 'commission_rate' => 15,
            'payment_terms' => 'Monthly', 'integration_type' => 'manual', 'is_active' => true,
        ]);

        foreach (['application/json', '*/*', null] as $accept) {
            $this->request('/api/aggregators/available', $accept)
                ->assertStatus(401)
                ->assertExactJson(['message' => 'Unauthenticated.']);
        }
    }

    public function test_authenticated_access_to_a_protected_api_route_is_unchanged(): void
    {
        $manager = BranchManager::factory()->create(['branch_id' => Branch::factory()->create()->id]);

        foreach (['application/json', '*/*', null] as $accept) {
            $this->actingAs($manager, 'sanctum');

            $response = $this->request('/api/aggregators/available', $accept);

            $response->assertOk();
            $response->assertJsonPath('success', true);
        }
    }

    public function test_non_api_requests_keep_laravels_content_negotiation(): void
    {
        Route::middleware('web')->get('/__regression/web-forbidden', fn () => abort(403, 'private detail'));

        // Browser-style request: HTML error page, not JSON.
        $html = $this->request('/__regression/web-forbidden', 'text/html');
        $html->assertStatus(403);
        $this->assertStringContainsString('text/html', (string) $html->headers->get('Content-Type'));

        // A client that explicitly asks for JSON still gets JSON on a non-API route.
        $json = $this->request('/__regression/web-forbidden', 'application/json');
        $json->assertStatus(403);
        $this->assertStringContainsString('application/json', (string) $json->headers->get('Content-Type'));
    }

    public function test_unhandled_exception_on_a_plain_api_route_is_json_and_leaks_nothing(): void
    {
        config(['app.debug' => false]);
        Route::middleware('api')->get('/api/__regression/boom', function () {
            throw new \RuntimeException('secret-internal-detail SELECT * FROM users');
        });

        foreach ([null, '*/*', 'application/json'] as $accept) {
            $response = $this->request('/api/__regression/boom', $accept);

            $response->assertStatus(500);
            $this->assertStringContainsString('application/json', (string) $response->headers->get('Content-Type'));
            $this->assertStringNotContainsString('secret-internal-detail', $response->getContent());
            $this->assertStringNotContainsString('SELECT', $response->getContent());
            $this->assertArrayNotHasKey('trace', (array) $response->json());
            $this->assertArrayNotHasKey('exception', (array) $response->json());
        }
    }

    public function test_the_established_api_v1_server_error_envelope_is_preserved(): void
    {
        config(['app.debug' => false]);
        Route::middleware('api')->get('/api/v1/__regression/boom', function () {
            throw new \RuntimeException('secret-internal-detail');
        });

        foreach ([null, '*/*', 'application/json'] as $accept) {
            $response = $this->request('/api/v1/__regression/boom', $accept);

            $response->assertStatus(500);
            $response->assertJsonPath('error.code', 'SERVER_ERROR');
            $response->assertJsonPath('error.message', 'An unexpected server error occurred');
            $response->assertJsonPath('error.details', []);
            $this->assertStringStartsWith('req_', (string) $response->json('requestId'));
            $this->assertStringNotContainsString('secret-internal-detail', $response->getContent());
        }
    }
}
