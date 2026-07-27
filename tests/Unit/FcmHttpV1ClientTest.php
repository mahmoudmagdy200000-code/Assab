<?php

namespace Tests\Unit;

use Illuminate\Http\Client\Factory as HttpFactory;
use Modules\Notification\DataTransferObjects\FcmMessage;
use Modules\Notification\Enums\NotificationPriority;
use Modules\Notification\Services\Fcm\FcmAccessTokenProvider;
use Modules\Notification\Services\Fcm\FcmCredentials;
use Modules\Notification\Services\Fcm\FcmHttpV1Client;
use PHPUnit\Framework\TestCase;

/**
 * FCM HTTP v1 wire behaviour: payload shape and — the part that matters for
 * data hygiene — how each Firebase error maps onto prune / retry / give up.
 *
 * Pure HTTP: no container, no database. The access-token exchange is stubbed
 * because signing a real service-account assertion is not what is under test.
 */
class FcmHttpV1ClientTest extends TestCase
{
    private HttpFactory $http;

    protected function setUp(): void
    {
        parent::setUp();
        $this->http = new HttpFactory;
    }

    private function client(): FcmHttpV1Client
    {
        $credentials = new class(null, 'assab-test') extends FcmCredentials
        {
            public function projectId(): string
            {
                return 'assab-test';
            }
        };

        $tokenProvider = new class extends FcmAccessTokenProvider
        {
            public array $forgotten = [];

            public function __construct() {}

            public function token(): string
            {
                return 'stub-access-token';
            }

            public function forget(): void
            {
                $this->forgotten[] = true;
            }
        };

        return new FcmHttpV1Client($credentials, $tokenProvider, $this->http, 5, 'assab_default');
    }

    private function message(NotificationPriority $priority = NotificationPriority::LOW): FcmMessage
    {
        return new FcmMessage(
            title: 'Shift starting soon',
            body: 'Your shift starts in 15 minutes.',
            data: ['shift_id' => 'shift-1', 'urgent' => false, 'skipped' => null],
            priority: $priority,
        );
    }

    // ── Success path ────────────────────────────────────────────────────────

    public function test_it_posts_to_the_v1_send_endpoint_with_a_bearer_token(): void
    {
        $this->http->fake([
            '*' => $this->http->response(['name' => 'projects/assab-test/messages/42']),
        ]);

        $result = $this->client()->sendToToken('device-token', $this->message());

        $this->assertTrue($result->success);
        $this->assertSame('projects/assab-test/messages/42', $result->messageId);

        $this->http->assertSent(function ($request) {
            return $request->url() === 'https://fcm.googleapis.com/v1/projects/assab-test/messages:send'
                && $request->hasHeader('Authorization', 'Bearer stub-access-token')
                && $request['message']['token'] === 'device-token';
        });
    }

    public function test_data_values_are_serialised_as_strings_and_nulls_dropped(): void
    {
        $this->http->fake(['*' => $this->http->response(['name' => 'ok'])]);

        $this->client()->sendToToken('device-token', $this->message());

        $this->http->assertSent(function ($request) {
            $data = $request['message']['data'];

            // FCM rejects non-string data values outright.
            return $data === ['shift_id' => 'shift-1', 'urgent' => '0']
                && ! array_key_exists('skipped', $data);
        });
    }

    public function test_high_priority_raises_the_android_and_apns_priorities(): void
    {
        $this->http->fake(['*' => $this->http->response(['name' => 'ok'])]);

        $this->client()->sendToToken('device-token', $this->message(NotificationPriority::CRITICAL));

        $this->http->assertSent(function ($request) {
            return $request['message']['android']['priority'] === 'HIGH'
                && $request['message']['apns']['headers']['apns-priority'] === '10';
        });
    }

    public function test_normal_priority_stays_low(): void
    {
        $this->http->fake(['*' => $this->http->response(['name' => 'ok'])]);

        $this->client()->sendToToken('device-token', $this->message());

        $this->http->assertSent(function ($request) {
            return $request['message']['android']['priority'] === 'NORMAL'
                && $request['message']['apns']['headers']['apns-priority'] === '5';
        });
    }

    public function test_it_targets_a_topic_without_the_topics_prefix(): void
    {
        $this->http->fake(['*' => $this->http->response(['name' => 'ok'])]);

        $this->client()->sendToTopic('/topics/branch_7', $this->message());

        $this->http->assertSent(fn ($request) => $request['message']['topic'] === 'branch_7');
    }

    public function test_it_sanitises_illegal_topic_characters(): void
    {
        $this->http->fake(['*' => $this->http->response(['name' => 'ok'])]);

        $this->client()->sendToTopic('branch/7 riyadh', $this->message());

        $this->http->assertSent(fn ($request) => $request['message']['topic'] === 'branch_7_riyadh');
    }

    // ── Error mapping ───────────────────────────────────────────────────────

    public function test_unregistered_marks_the_token_for_pruning(): void
    {
        $this->http->fake([
            '*' => $this->http->response([
                'error' => [
                    'status' => 'NOT_FOUND',
                    'message' => 'Requested entity was not found.',
                    'details' => [['errorCode' => 'UNREGISTERED']],
                ],
            ], 404),
        ]);

        $result = $this->client()->sendToToken('dead-token', $this->message());

        $this->assertFalse($result->success);
        $this->assertTrue($result->shouldPrune);
        $this->assertFalse($result->retryable);
    }

    public function test_sender_id_mismatch_marks_the_token_for_pruning(): void
    {
        $this->http->fake([
            '*' => $this->http->response([
                'error' => [
                    'status' => 'PERMISSION_DENIED',
                    'details' => [['errorCode' => 'SENDER_ID_MISMATCH']],
                ],
            ], 403),
        ]);

        $this->assertTrue($this->client()->sendToToken('foreign-token', $this->message())->shouldPrune);
    }

    public function test_invalid_argument_on_the_token_field_prunes(): void
    {
        $this->http->fake([
            '*' => $this->http->response([
                'error' => [
                    'status' => 'INVALID_ARGUMENT',
                    'details' => [[
                        'errorCode' => 'INVALID_ARGUMENT',
                        'fieldViolations' => [['field' => 'message.token', 'description' => 'Invalid registration token']],
                    ]],
                ],
            ], 400),
        ]);

        $this->assertTrue($this->client()->sendToToken('garbage', $this->message())->shouldPrune);
    }

    public function test_invalid_argument_on_the_payload_does_not_prune(): void
    {
        $this->http->fake([
            '*' => $this->http->response([
                'error' => [
                    'status' => 'INVALID_ARGUMENT',
                    'details' => [[
                        'errorCode' => 'INVALID_ARGUMENT',
                        'fieldViolations' => [['field' => 'message.android.ttl', 'description' => 'Invalid value']],
                    ]],
                ],
            ], 400),
        ]);

        $result = $this->client()->sendToToken('perfectly-good-token', $this->message());

        // Our payload is at fault, not the device. Deleting the token here would
        // silently unsubscribe a working handset over a server-side bug.
        $this->assertFalse($result->shouldPrune);
        $this->assertFalse($result->retryable);
    }

    public function test_quota_exceeded_is_retryable(): void
    {
        $this->http->fake([
            '*' => $this->http->response(['error' => ['status' => 'RESOURCE_EXHAUSTED']], 429),
        ]);

        $result = $this->client()->sendToToken('device-token', $this->message());

        $this->assertTrue($result->retryable);
        $this->assertFalse($result->shouldPrune);
    }

    public function test_server_errors_are_retryable(): void
    {
        $this->http->fake([
            '*' => $this->http->response(['error' => ['status' => 'UNAVAILABLE']], 503),
        ]);

        $this->assertTrue($this->client()->sendToToken('device-token', $this->message())->retryable);
    }

    public function test_a_401_is_retried_once_with_a_fresh_access_token(): void
    {
        $this->http->fakeSequence()
            ->push(['error' => ['status' => 'UNAUTHENTICATED']], 401)
            ->push(['name' => 'projects/assab-test/messages/7'], 200);

        $result = $this->client()->sendToToken('device-token', $this->message());

        // A rotated service-account key must self-heal rather than fail the send.
        $this->assertTrue($result->success);
        $this->http->assertSentCount(2);
    }

    // ── Topic membership ────────────────────────────────────────────────────

    public function test_topic_subscription_reports_tokens_firebase_rejected(): void
    {
        $this->http->fake([
            '*' => $this->http->response(['results' => [[], ['error' => 'NOT_FOUND'], []]]),
        ]);

        $invalid = $this->client()->subscribeToTopic(['good-1', 'dead', 'good-2'], 'branch_7');

        $this->assertSame(['dead'], $invalid);
    }

    public function test_topic_subscription_never_reports_tokens_on_a_failed_call(): void
    {
        $this->http->fake(['*' => $this->http->response([], 500)]);

        // A Firebase outage must not be read as "these tokens are dead".
        $this->assertSame([], $this->client()->subscribeToTopic(['good-1', 'good-2'], 'branch_7'));
    }
}
