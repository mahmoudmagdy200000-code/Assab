<?php

namespace Tests\Unit;

use Illuminate\Http\Request;
use Illuminate\Config\Repository;
use Modules\Admin\Services\CanonicalRequestPayload;
use PHPUnit\Framework\TestCase;
use RuntimeException;

class CanonicalRequestPayloadTest extends TestCase
{
    public function test_json_object_member_order_does_not_change_the_payload_hash(): void
    {
        $hasher = $this->hasher();

        $this->assertSame(
            $hasher->hash($this->request('{"amount":1,"nested":{"a":true,"b":null}}')),
            $hasher->hash($this->request('{"nested":{"b":null,"a":true},"amount":1}')),
        );
    }

    public function test_numeric_lexemes_and_array_order_remain_semantic(): void
    {
        $hasher = $this->hasher();

        $this->assertNotSame($hasher->hash($this->request('{"amount":1}')), $hasher->hash($this->request('{"amount":1.0}')));
        $this->assertNotSame($hasher->hash($this->request('{"items":[1,2]}')), $hasher->hash($this->request('{"items":[2,1]}')));
    }

    public function test_duplicate_json_object_keys_are_rejected(): void
    {
        $this->expectException(RuntimeException::class);

        $this->hasher()->hash($this->request('{"amount":1,"amount":2}'));
    }

    public function test_empty_json_body_is_canonicalized_as_an_empty_object(): void
    {
        $hasher = $this->hasher();

        $this->assertSame($hasher->hash($this->request('{}')), $hasher->hash($this->request('')));
        $this->assertNotSame($hasher->hash($this->request('null')), $hasher->hash($this->request('')));
    }

    public function test_nonempty_malformed_json_is_still_rejected(): void
    {
        $this->expectException(RuntimeException::class);

        $this->hasher()->hash($this->request('{'));
    }

    private function request(string $body): Request
    {
        return Request::create('/test', 'POST', [], [], [], ['CONTENT_TYPE' => 'application/json'], $body);
    }

    public function test_password_payload_digest_is_keyed_and_stable_for_replay_protection(): void
    {
        $request = Request::create('/api/v1/admin/users', 'POST', [], [], [], [
            'CONTENT_TYPE' => 'application/json',
        ], '{"password":"$2y$12$hash-shaped-user-input"}');
        $config = new Repository(['app' => ['key' => 'test-key-a']]);
        $hasher = new CanonicalRequestPayload($config);
        $first = $hasher->hash($request);
        $config->set('app.key', 'test-key-b');
        $second = $hasher->hash($request);
        $config->set('app.key', 'test-key-a');
        $replay = $hasher->hash($request);

        $this->assertSame($first, $replay);
        $this->assertNotSame($first, $second);
        $this->assertNotSame(hash('sha256', $request->getContent()), $first);
    }

    public function test_payload_hashing_fails_closed_without_an_application_key(): void
    {
        $this->expectException(RuntimeException::class);

        (new CanonicalRequestPayload(new Repository(['app' => ['key' => '']])))->hash($this->request('{"password":"sample"}'));
    }

    private function hasher(): CanonicalRequestPayload
    {
        return new CanonicalRequestPayload(new Repository(['app' => ['key' => 'canonical-payload-test-key']]));
    }
}
