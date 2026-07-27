<?php

namespace Modules\Notification\Services\Fcm;

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Factory as HttpFactory;
use Illuminate\Http\Client\Response;
use Modules\Notification\Contracts\FcmClientInterface;
use Modules\Notification\DataTransferObjects\FcmMessage;
use Modules\Notification\DataTransferObjects\FcmSendResult;

/**
 * Firebase Cloud Messaging HTTP v1 transport.
 *
 * Rejections are returned as FcmSendResult values, never thrown, so the caller
 * can prune dead tokens and distinguish a retryable outage from a permanent
 * rejection. Only configuration faults (raised by FcmCredentials /
 * FcmAccessTokenProvider) propagate as exceptions.
 */
class FcmHttpV1Client implements FcmClientInterface
{
    private const SEND_ENDPOINT = 'https://fcm.googleapis.com/v1/projects/%s/messages:send';

    private const IID_BATCH_ADD = 'https://iid.googleapis.com/iid/v1:batchAdd';

    private const IID_BATCH_REMOVE = 'https://iid.googleapis.com/iid/v1:batchRemove';

    /** IID rejects payloads above 1000 tokens per call. */
    private const TOPIC_BATCH_SIZE = 1000;

    /**
     * FCM error codes that mean "this registration token is dead". Pruning on
     * anything else would delete tokens over a transient Google outage.
     */
    private const PRUNABLE_ERROR_CODES = [
        'UNREGISTERED',
        'NOT_FOUND',
        'INVALID_ARGUMENT',
        'SENDER_ID_MISMATCH',
    ];

    public function __construct(
        private readonly FcmCredentials $credentials,
        private readonly FcmAccessTokenProvider $tokenProvider,
        private readonly HttpFactory $http,
        private readonly int $timeoutSeconds = 10,
        private readonly ?string $defaultAndroidChannelId = null,
    ) {}

    public function sendToToken(string $token, FcmMessage $message): FcmSendResult
    {
        return $this->dispatch(['token' => $token], $message);
    }

    public function sendToTopic(string $topic, FcmMessage $message): FcmSendResult
    {
        return $this->dispatch(['topic' => $this->normaliseTopic($topic)], $message);
    }

    public function subscribeToTopic(array $tokens, string $topic): array
    {
        return $this->manageTopic(self::IID_BATCH_ADD, $tokens, $topic);
    }

    public function unsubscribeFromTopic(array $tokens, string $topic): array
    {
        return $this->manageTopic(self::IID_BATCH_REMOVE, $tokens, $topic);
    }

    /**
     * @param  array<string, string>  $target  either ['token' => ...] or ['topic' => ...]
     */
    private function dispatch(array $target, FcmMessage $message): FcmSendResult
    {
        $endpoint = sprintf(self::SEND_ENDPOINT, $this->credentials->projectId());

        try {
            $response = $this->post($endpoint, ['message' => $target + $this->buildPayload($message)]);

            // A stale bearer token survives in cache until Google rejects it;
            // re-mint once before giving up so a rotated key self-heals.
            if ($response->status() === 401) {
                $this->tokenProvider->forget();
                $response = $this->post($endpoint, ['message' => $target + $this->buildPayload($message)]);
            }
        } catch (ConnectionException $e) {
            return FcmSendResult::transientFailure('CONNECTION_FAILED', $e->getMessage());
        }

        if ($response->successful()) {
            return FcmSendResult::success($response->json('name'));
        }

        return $this->mapError($response);
    }

    /**
     * @param  array<string, mixed>  $body
     */
    private function post(string $url, array $body): Response
    {
        return $this->http
            ->withToken($this->tokenProvider->token())
            ->timeout($this->timeoutSeconds)
            ->acceptJson()
            ->post($url, $body);
    }

    private function mapError(Response $response): FcmSendResult
    {
        $status = $response->status();
        $errorCode = $this->extractErrorCode($response);
        $errorMessage = $response->json('error.message', $response->body());

        if (in_array($errorCode, self::PRUNABLE_ERROR_CODES, true)) {
            // INVALID_ARGUMENT is overloaded: it covers both a malformed token
            // and a malformed payload. Only the former is the device's fault, so
            // prune exclusively when Google names the token field.
            if ($errorCode === 'INVALID_ARGUMENT' && ! $this->blamesToken($response)) {
                return FcmSendResult::permanentFailure($errorCode, $errorMessage);
            }

            return FcmSendResult::invalidToken($errorCode, $errorMessage);
        }

        if ($status === 429 || $status >= 500) {
            return FcmSendResult::transientFailure($errorCode ?: 'HTTP_'.$status, $errorMessage);
        }

        if ($status === 401 || $status === 403) {
            $this->tokenProvider->forget();

            return FcmSendResult::transientFailure($errorCode ?: 'UNAUTHENTICATED', $errorMessage);
        }

        return FcmSendResult::permanentFailure($errorCode ?: 'HTTP_'.$status, $errorMessage);
    }

    private function extractErrorCode(Response $response): ?string
    {
        foreach ((array) $response->json('error.details', []) as $detail) {
            if (isset($detail['errorCode'])) {
                return (string) $detail['errorCode'];
            }
        }

        $status = $response->json('error.status');

        return is_string($status) ? $status : null;
    }

    private function blamesToken(Response $response): bool
    {
        foreach ((array) $response->json('error.details', []) as $detail) {
            foreach ((array) ($detail['fieldViolations'] ?? []) as $violation) {
                if (str_contains((string) ($violation['field'] ?? ''), 'token')) {
                    return true;
                }
            }
        }

        return false;
    }

    /**
     * @return array<string, mixed>
     */
    private function buildPayload(FcmMessage $message): array
    {
        $highPriority = $message->isHighPriority();

        $payload = [
            'notification' => array_filter([
                'title' => $message->title,
                'body' => $message->body,
                'image' => $message->imageUrl,
            ], fn ($value) => $value !== null),
            'data' => $message->stringData(),
            'android' => [
                'priority' => $highPriority ? 'HIGH' : 'NORMAL',
                'notification' => array_filter([
                    'channel_id' => $message->androidChannelId ?: $this->defaultAndroidChannelId,
                    'sound' => 'default',
                    'click_action' => $message->clickAction,
                ], fn ($value) => $value !== null),
            ],
            'apns' => [
                'headers' => array_filter([
                    'apns-priority' => $highPriority ? '10' : '5',
                    'apns-collapse-id' => $message->collapseKey,
                ], fn ($value) => $value !== null),
                'payload' => [
                    'aps' => array_filter([
                        'sound' => 'default',
                        'badge' => $message->badge,
                        'content-available' => 1,
                    ], fn ($value) => $value !== null),
                ],
            ],
            'webpush' => [
                'headers' => array_filter([
                    'Urgency' => $highPriority ? 'high' : 'normal',
                    'TTL' => $message->ttlSeconds !== null ? (string) $message->ttlSeconds : null,
                ], fn ($value) => $value !== null),
            ],
        ];

        if ($message->collapseKey !== null) {
            $payload['android']['collapse_key'] = $message->collapseKey;
        }

        if ($message->ttlSeconds !== null) {
            $payload['android']['ttl'] = $message->ttlSeconds.'s';
        }

        if ($message->clickAction !== null) {
            $payload['webpush']['fcm_options'] = ['link' => $message->clickAction];
        }

        return $payload;
    }

    /**
     * @param  array<int, string>  $tokens
     * @return array<int, string>
     */
    private function manageTopic(string $endpoint, array $tokens, string $topic): array
    {
        $tokens = array_values(array_unique(array_filter($tokens)));

        if ($tokens === []) {
            return [];
        }

        $invalid = [];

        foreach (array_chunk($tokens, self::TOPIC_BATCH_SIZE) as $chunk) {
            try {
                $response = $this->http
                    ->withToken($this->tokenProvider->token())
                    ->withHeaders(['access_token_auth' => 'true'])
                    ->timeout($this->timeoutSeconds)
                    ->acceptJson()
                    ->post($endpoint, [
                        'to' => '/topics/'.$this->normaliseTopic($topic),
                        'registration_tokens' => $chunk,
                    ]);
            } catch (ConnectionException) {
                // Topic membership is idempotent and re-asserted on the next
                // registration; a network blip must not delete live tokens.
                continue;
            }

            if (! $response->successful()) {
                continue;
            }

            // IID returns one result object per token, positionally aligned.
            foreach ((array) $response->json('results', []) as $index => $result) {
                $error = $result['error'] ?? null;

                if (is_string($error) && in_array($error, ['NOT_FOUND', 'INVALID_ARGUMENT'], true)) {
                    $invalid[] = $chunk[$index];
                }
            }
        }

        return $invalid;
    }

    /**
     * FCM topic names accept [a-zA-Z0-9-_.~%]+ only.
     */
    private function normaliseTopic(string $topic): string
    {
        $topic = ltrim($topic, '/');
        $topic = str_starts_with($topic, 'topics/') ? substr($topic, 7) : $topic;

        return preg_replace('/[^a-zA-Z0-9\-_.~%]/', '_', $topic) ?? $topic;
    }
}
