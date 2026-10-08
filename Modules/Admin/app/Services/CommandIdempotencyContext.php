<?php

namespace Modules\Admin\Services;

use Illuminate\Support\Facades\DB;
use LogicException;
use Symfony\Component\HttpFoundation\Response;

/** Request-scoped reservation handle used by an authoritative writer transaction. */
class CommandIdempotencyContext
{
    public function __construct(private readonly string $identityHash) {}

    /** Serialize recovery with the authoritative writer before its effects begin. */
    public function lockForAuthoritativeWrite(): void
    {
        if (DB::transactionLevel() < 1) {
            throw new LogicException('The idempotency reservation must be locked inside the authoritative business transaction.');
        }

        $reservation = DB::table('asab_command_idempotency_keys')
            ->where('identity_hash', $this->identityHash)
            ->lockForUpdate()
            ->first();

        if ($reservation === null || $reservation->status !== 'processing') {
            throw new LogicException('The command reservation is no longer available to the authoritative writer.');
        }
    }

    public function completeWithinBusinessTransaction(Response $response): void
    {
        if (DB::transactionLevel() < 1) {
            throw new LogicException('Idempotency completion must share the authoritative business transaction.');
        }

        $completedAt = now();
        $updated = DB::table('asab_command_idempotency_keys')
            ->where('identity_hash', $this->identityHash)
            ->where('status', 'processing')
            ->update([
                'status' => 'completed',
                'response_status' => $response->getStatusCode(),
                'response_body' => $response->getContent(),
                'response_headers' => json_encode($this->replayableHeaders($response), JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR),
                'completed_at' => $completedAt,
                'response_expires_at' => $completedAt->copy()->addHours(24),
                'updated_at' => $completedAt,
            ]);

        if ($updated !== 1) {
            throw new LogicException('The idempotency reservation could not be completed by the business writer.');
        }
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
}
