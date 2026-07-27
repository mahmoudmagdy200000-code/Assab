<?php

namespace Modules\Notification\Services;

use Illuminate\Contracts\Bus\Dispatcher;
use Illuminate\Database\ConnectionInterface;
use Illuminate\Support\Collection;
use Modules\Notification\Enums\DeviceApp;
use Modules\Notification\Enums\DevicePlatform;
use Modules\Notification\Jobs\SyncDeviceTopicsJob;
use Modules\Notification\Models\DeviceToken;
use Modules\Notification\Repositories\DeviceTokenRepositoryInterface;

/**
 * Owns the device_tokens lifecycle: registration, re-binding, revocation and
 * pruning.
 *
 * An FCM registration token is a device credential — anyone holding it can push
 * to that handset. It therefore has exactly one owner at a time: registering a
 * token that another account already holds *moves* it rather than duplicating
 * it, which is what makes shared-handset logout/login safe.
 */
class DeviceTokenService
{
    public function __construct(
        private readonly DeviceTokenRepositoryInterface $repository,
        private readonly FcmTopicService $topicService,
        private readonly ConnectionInterface $db,
        private readonly Dispatcher $dispatcher,
    ) {}

    /**
     * @param  array<string, mixed>  $attributes  platform, app, locale, device_id, device_name, app_version
     */
    public function register(object $owner, string $token, array $attributes = []): DeviceToken
    {
        $attributes = $this->normalise($attributes);

        [$deviceToken, $detachFrom] = $this->db->transaction(function () use ($owner, $token, $attributes) {
            $existing = $this->repository->findByToken($token);
            $previousTopics = [];

            if ($existing !== null && ! $this->belongsTo($existing, $owner)) {
                // Handset changed hands. Strip the previous owner's topic
                // membership before rebinding, or the new user inherits their
                // branch/role broadcasts.
                $previousOwner = $existing->notifiable;
                $previousTopics = $previousOwner
                    ? $this->topicService->topicsFor($previousOwner, $existing->app ?? DeviceApp::MOBILE)
                    : [];

                $this->repository->forgetToken($token);
                $existing = null;
            }

            // Same physical device, rotated token: FCM re-issues on reinstall
            // and on some OS upgrades. Drop the superseded row so the device is
            // not addressed twice.
            if (! empty($attributes['device_id'])) {
                $this->forgetSupersededTokens($owner, $attributes['device_id'], $token);
            }

            $deviceToken = $existing !== null
                ? $this->repository->updateToken($existing, $attributes)
                : $this->repository->createForOwner($owner, $token, $attributes);

            return [$deviceToken, $previousTopics];
        });

        // Topic membership is remote work: it must not sit inside the
        // transaction, and it must not add ~5 sequential Instance-ID round-trips
        // to a request the mobile app makes on every launch.
        $topics = $this->topicService->topicsFor($owner, $deviceToken->app ?? DeviceApp::MOBILE);

        // Only detach topics the NEW owner does not also belong to. Both users
        // are in `all`, and the two jobs have no guaranteed ordering — detaching
        // a shared topic could land after the re-subscribe and leave the device
        // silently off it.
        $detachFrom = array_values(array_diff($detachFrom, $topics));

        if ($detachFrom !== []) {
            $this->dispatcher->dispatch(new SyncDeviceTopicsJob($token, $detachFrom, subscribe: false));
        }

        $this->dispatcher->dispatch(new SyncDeviceTopicsJob($token, $topics));

        return $deviceToken;
    }

    /**
     * Revoke one of the caller's own tokens. Returns false when the token is not
     * theirs — deliberately indistinguishable from "already gone" so the
     * endpoint cannot be used to probe which tokens exist.
     */
    public function unregister(object $owner, string $token): bool
    {
        // Read the row before deleting it: the topics to detach depend on which
        // app registered the device, which only the row knows.
        $existing = $this->repository->findByToken($token);
        $app = $existing?->app ?? DeviceApp::MOBILE;

        $deleted = $this->repository->forgetOwnedToken($owner, $token) > 0;

        if ($deleted) {
            $this->dispatcher->dispatch(new SyncDeviceTopicsJob(
                $token,
                $this->topicService->topicsFor($owner, $app),
                subscribe: false,
            ));
        }

        return $deleted;
    }

    /**
     * Revoke every device for an owner — used on logout-everywhere, suspension
     * and deactivation.
     */
    public function revokeAll(object $owner): int
    {
        $devices = $this->repository->forOwner($owner);

        if ($devices->isEmpty()) {
            return 0;
        }

        $this->repository->forgetTokens($devices->pluck('token')->all());

        foreach ($devices as $device) {
            $this->dispatcher->dispatch(new SyncDeviceTopicsJob(
                $device->token,
                $this->topicService->topicsFor($owner, $device->app ?? DeviceApp::MOBILE),
                subscribe: false,
            ));
        }

        return $devices->count();
    }

    /**
     * @return Collection<int, DeviceToken>
     */
    public function forOwner(object $owner, ?DeviceApp $app = null): Collection
    {
        return $this->repository->forOwner($owner, $app);
    }

    public function pruneStale(int $days): int
    {
        return $this->repository->pruneStale($days);
    }

    private function forgetSupersededTokens(object $owner, string $deviceId, string $currentToken): void
    {
        $superseded = DeviceToken::query()
            ->select(['id', 'token'])
            ->where('notifiable_type', $owner->getMorphClass())
            ->where('notifiable_id', $owner->getKey())
            ->where('device_id', $deviceId)
            ->where('token_hash', '!=', DeviceToken::hashFor($currentToken))
            ->pluck('token')
            ->all();

        if ($superseded !== []) {
            $this->repository->forgetTokens($superseded);
        }
    }

    private function belongsTo(DeviceToken $deviceToken, object $owner): bool
    {
        return $deviceToken->notifiable_type === $owner->getMorphClass()
            && (string) $deviceToken->notifiable_id === (string) $owner->getKey();
    }

    /**
     * @param  array<string, mixed>  $attributes
     * @return array<string, mixed>
     */
    private function normalise(array $attributes): array
    {
        return [
            'platform' => ($attributes['platform'] ?? null) instanceof DevicePlatform
                ? $attributes['platform']->value
                : (string) ($attributes['platform'] ?? DevicePlatform::ANDROID->value),
            'app' => ($attributes['app'] ?? null) instanceof DeviceApp
                ? $attributes['app']->value
                : (string) ($attributes['app'] ?? DeviceApp::MOBILE->value),
            'locale' => mb_substr((string) ($attributes['locale'] ?? config('app.locale', 'en')), 0, 8),
            'device_id' => $attributes['device_id'] ?? null,
            'device_name' => $attributes['device_name'] ?? null,
            'app_version' => $attributes['app_version'] ?? null,
        ];
    }
}
