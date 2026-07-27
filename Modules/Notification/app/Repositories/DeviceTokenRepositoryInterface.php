<?php

namespace Modules\Notification\Repositories;

use Illuminate\Support\Collection;
use Modules\Notification\Enums\DeviceApp;
use Modules\Notification\Models\DeviceToken;

interface DeviceTokenRepositoryInterface
{
    public function findByToken(string $token): ?DeviceToken;

    /**
     * @param  array<string, mixed>  $attributes
     */
    public function createForOwner(object $owner, string $token, array $attributes): DeviceToken;

    /**
     * @param  array<string, mixed>  $attributes
     */
    public function updateToken(DeviceToken $deviceToken, array $attributes): DeviceToken;

    /**
     * Remove any row holding this raw token, whichever owner it is bound to.
     */
    public function forgetToken(string $token): int;

    /**
     * Remove a token only if the given owner holds it (authorisation-safe delete).
     */
    public function forgetOwnedToken(object $owner, string $token): int;

    /**
     * @return array<int, string>
     */
    public function forgetTokens(array $tokens): array;

    /**
     * Registration tokens belonging to one owner.
     *
     * @return Collection<int, DeviceToken>
     */
    public function forOwner(object $owner, ?DeviceApp $app = null): Collection;

    /**
     * Registration tokens for many owners in one query (N+1 guard).
     *
     * @param  array<int, object>  $owners
     * @return Collection<int, DeviceToken>
     */
    public function forOwners(array $owners, ?DeviceApp $app = null): Collection;

    /**
     * @param  array<int, string>  $tokens
     */
    public function markUsed(array $tokens): void;

    public function pruneStale(int $days): int;
}
