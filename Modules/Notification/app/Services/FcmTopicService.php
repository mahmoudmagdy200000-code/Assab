<?php

namespace Modules\Notification\Services;

use Modules\Notification\Contracts\FcmClientInterface;
use Modules\Notification\Enums\DeviceApp;
use Modules\Notification\Repositories\DeviceTokenRepositoryInterface;
use Psr\Log\LoggerInterface;

/**
 * Derives and maintains FCM topic membership for a device.
 *
 * Topics are the only affordable way to reach "every cashier in branch X" —
 * per-token fan-out for a broadcast of that size would be thousands of HTTP
 * calls. Membership is re-asserted on every token registration, so a role or
 * branch change lands on the device the next time the app boots.
 */
class FcmTopicService
{
    public function __construct(
        private readonly FcmClientInterface $client,
        private readonly DeviceTokenRepositoryInterface $repository,
        private readonly LoggerInterface $logger,
    ) {}

    /**
     * Topics a given owner/app pair belongs to.
     *
     * @return array<int, string>
     */
    public function topicsFor(object $owner, DeviceApp $app): array
    {
        $topics = ['all', 'app_'.$app->value];

        foreach ($this->rolesFor($owner) as $role) {
            $topics[] = 'role_'.$role;
        }

        foreach (['branch_id' => 'branch', 'company_id' => 'company'] as $attribute => $prefix) {
            $value = $this->attribute($owner, $attribute);

            if ($value) {
                $topics[] = $prefix.'_'.$value;
            }
        }

        return array_values(array_unique($topics));
    }

    /**
     * Subscribe a token to topics. Failures are logged, never thrown: a device
     * that missed a topic still receives direct pushes, so a transient
     * Instance-ID outage must not fail anything upstream.
     *
     * @param  array<int, string>  $topics
     */
    public function attach(string $token, array $topics): void
    {
        $this->applyTopics($token, $topics, subscribe: true);
    }

    /**
     * Detach a token from an owner's topics. Called when a device is handed to
     * another user, otherwise the new owner keeps receiving the old owner's
     * branch and role broadcasts.
     *
     * @param  array<int, string>  $topics
     */
    public function detach(string $token, array $topics): void
    {
        $this->applyTopics($token, $topics, subscribe: false);
    }

    /**
     * @param  array<int, string>  $topics
     */
    private function applyTopics(string $token, array $topics, bool $subscribe): void
    {
        foreach ($topics as $topic) {
            try {
                $invalid = $subscribe
                    ? $this->client->subscribeToTopic([$token], $topic)
                    : $this->client->unsubscribeFromTopic([$token], $topic);

                if ($invalid !== []) {
                    $this->repository->forgetTokens($invalid);
                }
            } catch (\Throwable $e) {
                $this->logger->warning('FCM topic sync failed', [
                    'topic' => $topic,
                    'subscribe' => $subscribe,
                    'error' => $e->getMessage(),
                ]);
            }
        }
    }

    /**
     * @return array<int, string>
     */
    private function rolesFor(object $owner): array
    {
        // The unified ASAB user carries N role assignments; the legacy per-type
        // models are their own role.
        if (method_exists($owner, 'roleKeys')) {
            try {
                return array_map(
                    fn ($role) => $this->slug((string) $role),
                    $owner->roleKeys()
                );
            } catch (\Throwable) {
                return [];
            }
        }

        return [$this->slug(class_basename($owner))];
    }

    private function attribute(object $owner, string $key): ?string
    {
        if (! method_exists($owner, 'getAttribute')) {
            return null;
        }

        $value = $owner->getAttribute($key);

        return is_scalar($value) && $value !== '' ? (string) $value : null;
    }

    private function slug(string $value): string
    {
        return mb_strtolower(preg_replace('/(?<!^)[A-Z]/', '_$0', $value) ?? $value);
    }
}
