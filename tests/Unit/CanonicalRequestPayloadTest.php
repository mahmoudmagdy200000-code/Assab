<?php

namespace Tests\Unit;

use Illuminate\Http\Request;
use Modules\Admin\Services\CanonicalRequestPayload;
use PHPUnit\Framework\TestCase;
use RuntimeException;

class CanonicalRequestPayloadTest extends TestCase
{
    public function test_json_object_member_order_does_not_change_the_payload_hash(): void
    {
        $hasher = new CanonicalRequestPayload;

        $this->assertSame(
            $hasher->hash($this->request('{"amount":1,"nested":{"a":true,"b":null}}')),
            $hasher->hash($this->request('{"nested":{"b":null,"a":true},"amount":1}')),
        );
    }

    public function test_numeric_lexemes_and_array_order_remain_semantic(): void
    {
        $hasher = new CanonicalRequestPayload;

        $this->assertNotSame($hasher->hash($this->request('{"amount":1}')), $hasher->hash($this->request('{"amount":1.0}')));
        $this->assertNotSame($hasher->hash($this->request('{"items":[1,2]}')), $hasher->hash($this->request('{"items":[2,1]}')));
    }

    public function test_duplicate_json_object_keys_are_rejected(): void
    {
        $this->expectException(RuntimeException::class);

        (new CanonicalRequestPayload)->hash($this->request('{"amount":1,"amount":2}'));
    }

    private function request(string $body): Request
    {
        return Request::create('/test', 'POST', [], [], [], ['CONTENT_TYPE' => 'application/json'], $body);
    }
}
