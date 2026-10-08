<?php

namespace Modules\Admin\Http\Middleware;

use Closure;
use Illuminate\Database\QueryException;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Modules\Admin\Models\IdempotencyKey as LegacyIdempotencyKey;
use Modules\Admin\Services\CanonicalRequestPayload;
use Modules\Admin\Services\CommandIdempotencyContext;
use Modules\Admin\Support\TenantContext;
use Symfony\Component\HttpFoundation\Response;
use Throwable;

/** Reserves scoped command identities without wrapping authoritative writers in a transaction. */
class IdempotencyKey
{
    private const RESPONSE_TTL_HOURS = 24;

    private const RESERVATION_TTL_MINUTES = 5;

    public function __construct(private readonly CanonicalRequestPayload $payloadHasher) {}

    public function handle(Request $request, Closure $next, ?string $requireKey = null): Response
    {
        if (in_array($request->method(), ['GET', 'HEAD', 'OPTIONS'], true)) {
            return $next($request);
        }

        $key = $request->header('Idempotency-Key');
        if ($key === null || $key === '') {
            return $requireKey === 'required'
                ? $this->error(422, 'IDEMPOTENCY_KEY_REQUIRED', 'Idempotency-Key is required.')
                : $next($request);
        }

        if (! is_string($key) || strlen($key) > 100 || trim($key) !== $key || preg_match('/[\x00-\x1F\x7F]/', $key)) {
            return $this->error(422, 'IDEMPOTENCY_KEY_INVALID', 'Idempotency-Key is invalid.');
        }

        if ($requireKey === 'required' && ! Str::isUuid($key)) {
            return $this->error(422, 'IDEMPOTENCY_KEY_INVALID', 'Idempotency-Key must be a UUID.');
        }

        $actor = $request->user();
        if ($actor === null || ! method_exists($actor, 'getAuthIdentifier')) {
            return $this->error(401, 'UNAUTHENTICATED', 'Authentication is required before replay lookup.');
        }

        try {
            $scope = $this->identityScope($request, $actor, $key);
            $payloadHash = $this->payloadHasher->hash($request);
        } catch (Throwable) {
            return $this->error(422, 'IDEMPOTENCY_PAYLOAD_INVALID', 'The request payload cannot be canonicalized.');
        }

        $legacy = LegacyIdempotencyKey::query()->where('key', $key)->first();
        if ($legacy !== null) {
            if ((string) $legacy->user_id !== (string) $actor->getAuthIdentifier()) {
                return $this->error(409, 'IDEMPOTENCY_KEY_REUSED', 'Idempotency-Key is already bound to another authenticated user.');
            }

            if ($legacy->method === strtoupper($request->method()) && $legacy->path === $request->path()) {
                return $this->error(409, 'IDEMPOTENCY_KEY_REUSED', 'This legacy key has no safe request-scope evidence. Use a new key.');
            }
        }

        $identityHash = hash('sha256', json_encode($scope, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR));
        $keyHash = hash('sha256', $key);

        try {
            $reservation = DB::transaction(function () use ($scope, $identityHash, $keyHash, $payloadHash) {
                $connection = DB::connection();
                $owners = $connection->table('asab_command_idempotency_owners');
                $owner = $owners->where('key_hash', $keyHash)->lockForUpdate()->first();

                if ($owner === null) {
                    $now = now();
                    $owners->insertOrIgnore([
                        'key_hash' => $keyHash,
                        'actor_type' => $scope['actor_type'],
                        'actor_id' => $scope['actor_id'],
                        'created_at' => $now,
                        'updated_at' => $now,
                    ]);
                    $owner = $owners->where('key_hash', $keyHash)->lockForUpdate()->first();
                }

                if ($owner === null || $owner->actor_type !== $scope['actor_type'] || (string) $owner->actor_id !== $scope['actor_id']) {
                    return ['response' => $this->error(409, 'IDEMPOTENCY_KEY_REUSED', 'Idempotency-Key is already bound to another authenticated user.')];
                }

                $table = $connection->table('asab_command_idempotency_keys');
                $record = $table->where('identity_hash', $identityHash)->lockForUpdate()->first();
                if ($record !== null) {
                    if (! hash_equals($record->payload_hash, $payloadHash)) {
                        return ['response' => $this->error(409, 'IDEMPOTENCY_KEY_REUSED', 'Idempotency-Key was reused with a different payload.')];
                    }

                    if ($record->status === 'completed') {
                        return ['response' => $this->replayOrExpired($record)];
                    }

                    // Only the cashier receipt command has atomic writer-boundary
                    // completion, so an expired reservation can be safely recovered.
                    // Its writer locks this row in the same transaction as the effects.
                    if ($this->supportsWriterCompletion($scope['command'])
                        && $record->reservation_expires_at !== null
                        && now()->greaterThanOrEqualTo($record->reservation_expires_at)) {
                        $now = now();
                        $table->where('identity_hash', $identityHash)->update([
                            'reservation_expires_at' => $now->copy()->addMinutes(self::RESERVATION_TTL_MINUTES),
                            'updated_at' => $now,
                        ]);

                        return ['identity_hash' => $identityHash];
                    }

                    return ['response' => $this->error(409, 'IDEMPOTENCY_IN_PROGRESS', 'The command is reserved or requires command-specific recovery. It was not repeated.')];
                }

                $now = now();
                $inserted = $table->insertOrIgnore([
                    'id' => (string) Str::uuid(),
                    'identity_hash' => $identityHash,
                    'idempotency_key' => $scope['idempotency_key'],
                    'payload_hash' => $payloadHash,
                    'actor_type' => $scope['actor_type'],
                    'actor_id' => $scope['actor_id'],
                    'company_scope' => json_encode($scope['company_scope'], JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR),
                    'branch_scope' => $scope['branch_scope'],
                    'http_method' => $scope['http_method'],
                    'command' => $scope['command'],
                    'resource_scope' => json_encode($scope['resource_scope'], JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR),
                    'status' => 'processing',
                    'reservation_expires_at' => $now->copy()->addMinutes(self::RESERVATION_TTL_MINUTES),
                    'created_at' => $now,
                    'updated_at' => $now,
                ]);

                if ($inserted !== 1) {
                    return ['response' => $this->error(409, 'IDEMPOTENCY_IN_PROGRESS', 'A matching command reservation already exists. The command was not repeated.')];
                }

                return ['identity_hash' => $identityHash];
            });
        } catch (QueryException $exception) {
            if ($this->isLockWaitTimeout($exception)) {
                return $this->error(409, 'IDEMPOTENCY_IN_PROGRESS', 'A matching command reservation is being created. Retry with the same key.');
            }

            throw $exception;
        }

        if (isset($reservation['response'])) {
            return $reservation['response'];
        }

        // The reservation transaction is committed before invoking the command.
        // S1-08 retains ownership of all business transaction boundaries and after-commit work.
        $context = new CommandIdempotencyContext($reservation['identity_hash']);
        app()->instance(CommandIdempotencyContext::class, $context);
        try {
            $response = $next($request);
        } finally {
            app()->forgetInstance(CommandIdempotencyContext::class);
        }

        $durable = DB::table('asab_command_idempotency_keys')->where('identity_hash', $reservation['identity_hash'])->first();
        if ($durable?->status === 'completed') {
            return $this->replayOrExpired($durable);
        }

        if ($response->getStatusCode() < 200 || $response->getStatusCode() >= 300 || ! is_string($response->getContent())) {
            if ($response->getStatusCode() < 500) {
                $this->releaseFailedReservation($reservation['identity_hash']);
            }

            return $response;
        }

        try {
            $completedAt = now();
            DB::table('asab_command_idempotency_keys')->where('identity_hash', $reservation['identity_hash'])
                ->where('status', 'processing')
                ->update([
                    'status' => 'completed',
                    'response_status' => $response->getStatusCode(),
                    'response_body' => $response->getContent(),
                    'response_headers' => json_encode($this->replayableHeaders($response), JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR),
                    'completed_at' => $completedAt,
                    'response_expires_at' => $completedAt->copy()->addHours(self::RESPONSE_TTL_HOURS),
                    'updated_at' => $completedAt,
                ]);
        } catch (Throwable $exception) {
            // The durable processing reservation makes retries fail closed. Do not
            // replace an already successful command response with a persistence error.
            Log::error('Idempotency response snapshot persistence failed after command completion', [
                'identity_hash' => $reservation['identity_hash'],
                'error' => $exception->getMessage(),
            ]);
        }

        return $response;
    }

    private function releaseFailedReservation(string $identityHash): void
    {
        try {
            DB::table('asab_command_idempotency_keys')->where('identity_hash', $identityHash)
                ->where('status', 'processing')->delete();
        } catch (Throwable $exception) {
            Log::warning('Failed idempotency attempt could not release its reservation', [
                'identity_hash' => $identityHash,
                'error' => $exception->getMessage(),
            ]);
        }
    }

    private function identityScope(Request $request, object $actor, string $key): array
    {
        $tenant = app(TenantContext::class);
        $companyIds = $tenant->resolved ? ($tenant->companyIds ?: array_filter([$tenant->companyId])) : array_filter([
            data_get($actor, 'company_id'),
        ]);
        $companyIds = array_values(array_unique(array_map('strval', $companyIds)));
        sort($companyIds, SORT_STRING);
        $authorizationScope = [];
        foreach (['brand_ids' => $tenant->brandIds, 'branch_ids' => $tenant->branchIds, 'module_keys' => $tenant->moduleKeys] as $name => $values) {
            $authorizationScope[$name] = array_values(array_unique(array_map('strval', $values)));
            sort($authorizationScope[$name], SORT_STRING);
        }

        $route = $request->route();
        $parameters = $route?->parameters() ?? [];
        $resources = [];
        foreach ($parameters as $name => $value) {
            if (is_object($value) && method_exists($value, 'getRouteKey')) {
                $value = $value->getRouteKey();
            }
            if (is_scalar($value) || $value === null) {
                $resources[(string) $name] = $value === null ? null : (string) $value;
            }
        }
        ksort($resources, SORT_STRING);

        $branchScope = data_get($actor, 'branch_id');
        if ($authorizationScope['branch_ids'] !== []) {
            $branchScope = count($authorizationScope['branch_ids']) === 1
                ? $authorizationScope['branch_ids'][0]
                : hash('sha256', json_encode($authorizationScope['branch_ids'], JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR));
        }
        if ($branchScope === null) {
            foreach (['branch', 'branchId', 'branch_id'] as $parameter) {
                if (isset($resources[$parameter])) {
                    $branchScope = $resources[$parameter];
                    break;
                }
            }
        }

        $command = $route?->getName()
            ?: (($route?->getActionName() ?? 'unknown').'@'.($route?->uri() ?? $request->path()));

        return [
            'company_scope' => $companyIds,
            'actor_type' => get_class($actor),
            'actor_id' => (string) $actor->getAuthIdentifier(),
            'branch_scope' => $branchScope === null ? null : (string) $branchScope,
            'authorization_scope' => [
                'role' => $tenant->roleKey,
                ...$authorizationScope,
            ],
            'http_method' => strtoupper($request->method()),
            'command' => $command,
            'resource_scope' => $resources,
            'idempotency_key' => $key,
        ];
    }

    private function replayOrExpired(object $record): Response
    {
        if ($record->response_body === null || $record->response_status === null || ($record->response_expires_at !== null && now()->greaterThanOrEqualTo($record->response_expires_at))) {
            return $this->error(409, 'IDEMPOTENCY_RESPONSE_EXPIRED', 'The command already completed, but its response snapshot has expired. The command was not repeated.');
        }

        $headers = $record->response_headers ?? [];
        if (is_string($headers)) {
            $headers = json_decode($headers, true) ?: [];
        }

        return response($record->response_body, (int) $record->response_status, $headers);
    }

    private function replayableHeaders(Response $response): array
    {
        $headers = [];
        foreach (['content-type', 'cache-control', 'content-disposition'] as $name) {
            if ($response->headers->has($name)) {
                $headers[$name] = $response->headers->all($name);
            }
        }

        return $headers;
    }

    private function error(int $status, string $code, string $message): Response
    {
        return response()->json([
            'error' => [
                'code' => $code,
                'message' => $message,
            ],
            'requestId' => 'req_'.Str::upper(Str::random(13)),
        ], $status);
    }

    private function isLockWaitTimeout(QueryException $exception): bool
    {
        $sqlState = $exception->errorInfo[0] ?? null;
        $driverCode = (int) ($exception->errorInfo[1] ?? 0);

        return $sqlState === 'HY000' && in_array($driverCode, [1205, 1213], true);
    }

    private function supportsWriterCompletion(string $command): bool
    {
        return $command === 'cashier.handover.accept';
    }
}
