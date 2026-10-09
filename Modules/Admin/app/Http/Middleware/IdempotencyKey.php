<?php

namespace Modules\Admin\Http\Middleware;

use Closure;
use Illuminate\Database\Events\TransactionCommitting;
use Illuminate\Database\QueryException;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Modules\Admin\Exceptions\IdempotencyRollbackResponse;
use Modules\Admin\Models\IdempotencyKey as LegacyIdempotencyKey;
use Modules\Admin\Services\CanonicalRequestPayload;
use Modules\Admin\Services\CommandIdempotencyContext;
use Modules\Admin\Support\TenantContext;
use Symfony\Component\HttpFoundation\Response;
use Throwable;

/**
 * Reserves scoped command identities before a command runs.
 *
 * Route parameters: `asab.idempotency:{requireKey},{completion},{envelope}`.
 *
 * - requireKey: `required` rejects a missing key; anything else leaves it optional.
 * - completion:
 *   - `after-commit` (default): the replay snapshot is written after the command
 *     returns. A failure between the business commit and the snapshot leaves the
 *     key reserved (fail-closed, never re-executed).
 *   - `writer`: the command's authoritative writer completes the reservation
 *     inside its own business transaction (CommandIdempotencyContext).
 *   - `transaction`: this middleware runs the command inside one transaction and
 *     writes the snapshot in that same commit.
 *   With `writer` and `transaction` a reservation still `processing` proves no
 *   business commit happened, so a failed attempt releases it and an expired one
 *   may be recovered.
 * - envelope: `legacy` returns the mobile `{success,message,code}` error family;
 *   anything else returns the Admin `{error:{code,message},requestId}` family.
 */
class IdempotencyKey
{
    private const RESERVATION_TTL_MINUTES = 5;

    private const RETRY_AFTER_SECONDS = 2;

    private const ATOMIC_COMPLETIONS = ['writer', 'transaction'];

    private const COMMIT_COUNTER = 'asab.idempotency.commit-counter';

    public function __construct(private readonly CanonicalRequestPayload $payloadHasher) {}

    public function handle(
        Request $request,
        Closure $next,
        ?string $requireKey = null,
        ?string $completion = null,
        ?string $envelope = null
    ): Response {
        if (in_array($request->method(), ['GET', 'HEAD', 'OPTIONS'], true)) {
            return $next($request);
        }

        $completion = in_array($completion, self::ATOMIC_COMPLETIONS, true) ? $completion : 'after-commit';
        $legacyEnvelope = $envelope === 'legacy';

        $key = $request->header('Idempotency-Key');
        if ($key === null || $key === '') {
            return $requireKey === 'required'
                ? $this->error(422, 'IDEMPOTENCY_KEY_REQUIRED', 'Idempotency-Key is required.', $legacyEnvelope)
                : $next($request);
        }

        if (! is_string($key) || strlen($key) > 100 || trim($key) !== $key || preg_match('/[\x00-\x1F\x7F]/', $key)) {
            return $this->error(422, 'IDEMPOTENCY_KEY_INVALID', 'Idempotency-Key is invalid.', $legacyEnvelope);
        }

        if ($requireKey === 'required' && ! Str::isUuid($key)) {
            return $this->error(422, 'IDEMPOTENCY_KEY_INVALID', 'Idempotency-Key must be a UUID.', $legacyEnvelope);
        }

        $actor = $request->user();
        if ($actor === null || ! method_exists($actor, 'getAuthIdentifier')) {
            return $this->error(401, 'UNAUTHENTICATED', 'Authentication is required before replay lookup.', $legacyEnvelope);
        }

        try {
            $scope = $this->identityScope($request, $actor, $key);
            $payloadHash = $this->payloadHasher->hash($request);
        } catch (Throwable) {
            return $this->error(422, 'IDEMPOTENCY_PAYLOAD_INVALID', 'The request payload cannot be canonicalized.', $legacyEnvelope);
        }

        $legacy = LegacyIdempotencyKey::query()->where('key', $key)->first();
        if ($legacy !== null) {
            if ((string) $legacy->user_id !== (string) $actor->getAuthIdentifier()) {
                return $this->error(409, 'IDEMPOTENCY_KEY_REUSED', 'Idempotency-Key is already bound to another authenticated user.', $legacyEnvelope);
            }

            if ($legacy->method === strtoupper($request->method()) && $legacy->path === $request->path()) {
                return $this->error(409, 'IDEMPOTENCY_KEY_REUSED', 'This legacy key has no safe request-scope evidence. Use a new key.', $legacyEnvelope);
            }
        }

        $identityHash = hash('sha256', json_encode($scope, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR));
        $keyHash = hash('sha256', $key);
        $atomic = $completion !== 'after-commit';

        try {
            $reservation = DB::transaction(function () use ($scope, $identityHash, $keyHash, $payloadHash, $atomic, $legacyEnvelope) {
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
                    return ['response' => $this->error(409, 'IDEMPOTENCY_KEY_REUSED', 'Idempotency-Key is already bound to another authenticated user.', $legacyEnvelope)];
                }

                $table = $connection->table('asab_command_idempotency_keys');
                $record = $table->where('identity_hash', $identityHash)->lockForUpdate()->first();
                if ($record !== null) {
                    if (! hash_equals($record->payload_hash, $payloadHash)) {
                        return ['response' => $this->error(409, 'IDEMPOTENCY_KEY_REUSED', 'Idempotency-Key was reused with a different payload.', $legacyEnvelope)];
                    }

                    if ($record->status === 'completed') {
                        return ['response' => $this->replayOrExpired($record, $legacyEnvelope)];
                    }

                    // With atomic completion a still-processing reservation proves
                    // the business commit did not happen, so an expired one is
                    // safely recoverable. The authoritative transaction re-locks
                    // this row before any effect.
                    if ($atomic
                        && $record->reservation_expires_at !== null
                        && now()->greaterThanOrEqualTo($record->reservation_expires_at)) {
                        $now = now();
                        $table->where('identity_hash', $identityHash)->update([
                            'reservation_expires_at' => $now->copy()->addMinutes(self::RESERVATION_TTL_MINUTES),
                            'updated_at' => $now,
                        ]);

                        return ['identity_hash' => $identityHash];
                    }

                    return ['response' => $this->inProgress('The command is reserved or requires command-specific recovery. It was not repeated.', $legacyEnvelope)];
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
                    return ['response' => $this->inProgress('A matching command reservation already exists. The command was not repeated.', $legacyEnvelope)];
                }

                return ['identity_hash' => $identityHash];
            });
        } catch (QueryException $exception) {
            if ($this->isLockWaitTimeout($exception)) {
                return $this->inProgress('A matching command reservation is being created. Retry with the same key.', $legacyEnvelope);
            }

            throw $exception;
        }

        if (isset($reservation['response'])) {
            return $reservation['response'];
        }

        $identityHash = $reservation['identity_hash'];

        if ($completion === 'transaction') {
            return $this->runInCommandTransaction($request, $next, $identityHash, $legacyEnvelope);
        }

        // The reservation transaction is committed before invoking the command.
        $context = new CommandIdempotencyContext($identityHash);
        app()->instance(CommandIdempotencyContext::class, $context);
        try {
            $response = $next($request);
        } catch (Throwable $exception) {
            if ($atomic) {
                // Writer completion is atomic: a reservation still processing
                // here means nothing was committed, so the key may be retried.
                $this->releaseFailedReservation($identityHash);
            }

            throw $exception;
        } finally {
            app()->forgetInstance(CommandIdempotencyContext::class);
        }

        $durable = DB::table('asab_command_idempotency_keys')->where('identity_hash', $identityHash)->first();
        if ($durable?->status === 'completed') {
            return $this->replayOrExpired($durable, $legacyEnvelope);
        }

        if (! $this->isReplayable($response)) {
            if ($atomic || $response->getStatusCode() < 500) {
                $this->releaseFailedReservation($identityHash);
            }

            return $response;
        }

        try {
            DB::table('asab_command_idempotency_keys')->where('identity_hash', $identityHash)
                ->where('status', 'processing')
                ->update($this->completionColumns($response));
        } catch (Throwable $exception) {
            // The durable processing reservation makes retries fail closed. Do not
            // replace an already successful command response with a persistence error.
            Log::error('Idempotency response snapshot persistence failed after command completion', [
                'identity_hash' => $identityHash,
                'error' => $exception->getMessage(),
            ]);
        }

        return $response;
    }

    /** Run the command and write its replay snapshot in one business commit. */
    private function runInCommandTransaction(Request $request, Closure $next, string $identityHash, bool $legacyEnvelope): Response
    {
        $committedOutsideSnapshot = false;

        try {
            return DB::transaction(function () use ($request, $next, $identityHash, $legacyEnvelope, &$committedOutsideSnapshot) {
                $reservation = DB::table('asab_command_idempotency_keys')
                    ->where('identity_hash', $identityHash)
                    ->lockForUpdate()
                    ->first();

                if ($reservation === null) {
                    return $this->inProgress('The command reservation is no longer available. It was not repeated.', $legacyEnvelope);
                }

                if ($reservation->status === 'completed') {
                    // A recovering duplicate waited behind the original commit.
                    return $this->replayOrExpired($reservation, $legacyEnvelope);
                }

                $level = DB::transactionLevel();
                $commits = $this->realCommitCounter();
                $commitsBefore = $commits['commits'];
                $response = $next($request);

                // A wrapped writer that leaves its manual transaction unbalanced
                // must never let a savepoint "commit" pass as the command commit.
                if (DB::transactionLevel() !== $level) {
                    if (DB::transactionLevel() > $level) {
                        // Undo the leaked inner level; the outer rollback follows.
                        DB::rollBack($level);
                    } elseif ($commits['commits'] > $commitsBefore) {
                        // An unbalanced commit really committed the command outside
                        // its snapshot: keep the key reserved so nothing re-runs it.
                        $committedOutsideSnapshot = true;
                    }

                    if (! $committedOutsideSnapshot && ! $this->isReplayable($response)) {
                        throw new IdempotencyRollbackResponse($response);
                    }

                    throw new \LogicException('An idempotent command left its database transaction unbalanced.');
                }

                if (! $this->isReplayable($response)) {
                    throw new IdempotencyRollbackResponse($response);
                }

                $updated = DB::table('asab_command_idempotency_keys')
                    ->where('identity_hash', $identityHash)
                    ->where('status', 'processing')
                    ->update($this->completionColumns($response));

                if ($updated !== 1) {
                    throw new \LogicException('The idempotency reservation could not be completed inside the command transaction.');
                }

                return $response;
            });
        } catch (IdempotencyRollbackResponse $rollback) {
            $this->releaseFailedReservation($identityHash);

            return $rollback->response;
        } catch (Throwable $exception) {
            if ($committedOutsideSnapshot) {
                $this->holdReservationWithoutRecovery($identityHash);
            } else {
                $this->releaseFailedReservation($identityHash);
            }

            throw $exception;
        }
    }

    /** Counts real PDO commits (Laravel fires `committing` only at level 1). */
    private function realCommitCounter(): \ArrayObject
    {
        if (! app()->bound(self::COMMIT_COUNTER)) {
            $counter = new \ArrayObject(['commits' => 0]);
            app()->instance(self::COMMIT_COUNTER, $counter);
            Event::listen(TransactionCommitting::class, static function () use ($counter): void {
                $counter['commits']++;
            });
        }

        return app(self::COMMIT_COUNTER);
    }

    /** Fail closed: the command may have committed, so the key must never be re-run. */
    private function holdReservationWithoutRecovery(string $identityHash): void
    {
        try {
            DB::table('asab_command_idempotency_keys')->where('identity_hash', $identityHash)
                ->where('status', 'processing')
                ->update(['reservation_expires_at' => null, 'updated_at' => now()]);
        } catch (Throwable $exception) {
            Log::error('Idempotency reservation could not be held after an unbalanced command commit', [
                'identity_hash' => $identityHash,
                'error' => $exception->getMessage(),
            ]);
        }
    }

    private function isReplayable(Response $response): bool
    {
        return $response->getStatusCode() >= 200
            && $response->getStatusCode() < 300
            && is_string($response->getContent());
    }

    private function completionColumns(Response $response): array
    {
        $completedAt = now();

        return [
            'status' => 'completed',
            'response_status' => $response->getStatusCode(),
            'response_body' => $response->getContent(),
            'response_headers' => json_encode(CommandIdempotencyContext::replayableHeaders($response), JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR),
            'completed_at' => $completedAt,
            'response_expires_at' => $completedAt->copy()->addDays(CommandIdempotencyContext::RESPONSE_TTL_DAYS),
            'updated_at' => $completedAt,
        ];
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
            'command' => $this->commandIdentity($request),
            'resource_scope' => $resources,
            'idempotency_key' => $key,
        ];
    }

    /**
     * The command is the handler, so `/api/...` and `/api/v1/...` aliases of one
     * command share a single identity. Closure routes fall back to name or URI.
     */
    private function commandIdentity(Request $request): string
    {
        $route = $request->route();
        $action = $route?->getActionName();
        if (is_string($action) && $action !== '' && $action !== 'Closure') {
            return $action;
        }

        return $route?->getName()
            ?: (($route?->uri() ?? $request->path()));
    }

    private function replayOrExpired(object $record, bool $legacyEnvelope): Response
    {
        if ($record->response_body === null || $record->response_status === null || ($record->response_expires_at !== null && now()->greaterThanOrEqualTo($record->response_expires_at))) {
            return $this->error(409, 'IDEMPOTENCY_RESPONSE_EXPIRED', 'The command already completed, but its response snapshot has expired. The command was not repeated.', $legacyEnvelope);
        }

        $headers = $record->response_headers ?? [];
        if (is_string($headers)) {
            $headers = json_decode($headers, true) ?: [];
        }

        return response($record->response_body, (int) $record->response_status, $headers);
    }

    private function inProgress(string $message, bool $legacyEnvelope): Response
    {
        $response = $this->error(409, 'IDEMPOTENCY_IN_PROGRESS', $message, $legacyEnvelope);
        $response->headers->set('Retry-After', (string) self::RETRY_AFTER_SECONDS);

        return $response;
    }

    private function error(int $status, string $code, string $message, bool $legacyEnvelope = false): Response
    {
        if ($legacyEnvelope) {
            return response()->json([
                'success' => false,
                'message' => $code,
                'code' => $code,
                'detail' => $message,
            ], $status);
        }

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

        // MySQL: 1205 lock wait timeout (HY000), 1213 deadlock (40001).
        return in_array($sqlState, ['HY000', '40001'], true) && in_array($driverCode, [1205, 1213], true);
    }
}
