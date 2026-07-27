<?php

namespace Modules\Notification\Repositories;

use Illuminate\Support\Collection;
use Modules\Notification\Enums\DeviceApp;
use Modules\Notification\Models\DeviceToken;

class DeviceTokenRepository implements DeviceTokenRepositoryInterface
{
    /**
     * Hard cap on how many devices a single fan-out will address. A runaway
     * owner (shared account, emulator farm) must not turn one domain event into
     * an unbounded queue flood.
     */
    private const MAX_TOKENS_PER_OWNER = 20;

    private const MAX_TOKENS_PER_FANOUT = 2000;

    public function findByToken(string $token): ?DeviceToken
    {
        return DeviceToken::query()->forToken($token)->first();
    }

    public function createForOwner(object $owner, string $token, array $attributes): DeviceToken
    {
        return DeviceToken::create($attributes + [
            'notifiable_type' => $owner->getMorphClass(),
            'notifiable_id' => $owner->getKey(),
            'token' => $token,
            'token_hash' => DeviceToken::hashFor($token),
            'last_used_at' => now(),
        ]);
    }

    public function updateToken(DeviceToken $deviceToken, array $attributes): DeviceToken
    {
        $deviceToken->fill($attributes + ['last_used_at' => now()]);
        $deviceToken->save();

        return $deviceToken;
    }

    public function forgetToken(string $token): int
    {
        return DeviceToken::query()->forToken($token)->delete();
    }

    public function forgetOwnedToken(object $owner, string $token): int
    {
        return DeviceToken::query()
            ->forToken($token)
            ->where('notifiable_type', $owner->getMorphClass())
            ->where('notifiable_id', $owner->getKey())
            ->delete();
    }

    public function forgetTokens(array $tokens): array
    {
        $tokens = array_values(array_filter(array_unique($tokens)));

        if ($tokens === []) {
            return [];
        }

        $hashes = array_map(fn (string $token) => DeviceToken::hashFor($token), $tokens);

        DeviceToken::query()->whereIn('token_hash', $hashes)->delete();

        return $tokens;
    }

    public function forOwner(object $owner, ?DeviceApp $app = null): Collection
    {
        return DeviceToken::query()
            ->select(['id', 'notifiable_type', 'notifiable_id', 'token', 'platform', 'app', 'locale', 'last_used_at'])
            ->where('notifiable_type', $owner->getMorphClass())
            ->where('notifiable_id', $owner->getKey())
            ->when($app !== null, fn ($query) => $query->where('app', $app->value))
            ->orderByDesc('last_used_at')
            ->limit(self::MAX_TOKENS_PER_OWNER)
            ->get();
    }

    public function forOwners(array $owners, ?DeviceApp $app = null): Collection
    {
        // Group ids by morph class so many owners cost one query, not one each.
        $byType = [];

        foreach ($owners as $owner) {
            if (! is_object($owner) || ! method_exists($owner, 'getMorphClass')) {
                continue;
            }

            $byType[$owner->getMorphClass()][] = $owner->getKey();
        }

        if ($byType === []) {
            return collect();
        }

        return DeviceToken::query()
            ->select(['id', 'notifiable_type', 'notifiable_id', 'token', 'platform', 'app', 'locale', 'last_used_at'])
            ->where(function ($query) use ($byType) {
                foreach ($byType as $type => $ids) {
                    $query->orWhere(function ($inner) use ($type, $ids) {
                        $inner->where('notifiable_type', $type)
                            ->whereIn('notifiable_id', array_unique($ids));
                    });
                }
            })
            ->when($app !== null, fn ($query) => $query->where('app', $app->value))
            ->orderByDesc('last_used_at')
            ->limit(self::MAX_TOKENS_PER_FANOUT)
            ->get();
    }

    public function markUsed(array $tokens): void
    {
        $tokens = array_values(array_filter(array_unique($tokens)));

        if ($tokens === []) {
            return;
        }

        $hashes = array_map(fn (string $token) => DeviceToken::hashFor($token), $tokens);

        DeviceToken::query()
            ->whereIn('token_hash', $hashes)
            ->update(['last_used_at' => now()]);
    }

    public function pruneStale(int $days): int
    {
        return DeviceToken::query()->stale($days)->delete();
    }
}
