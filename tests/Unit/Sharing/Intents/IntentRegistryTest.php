<?php

namespace Tests\Unit\Sharing\Intents;

use AuthService\Helper\Sharing\Intents\Contracts\SharePayload;
use AuthService\Helper\Sharing\Intents\Exceptions\UnknownIntentException;
use AuthService\Helper\Sharing\Intents\IntentRegistry;
use PHPUnit\Framework\TestCase;

class IntentRegistryTest extends TestCase
{
    public function test_register_and_resolve_intent_class(): void
    {
        $reg = new IntentRegistry();
        $reg->register('test.intent', FakePayload::class);

        $this->assertEquals(FakePayload::class, $reg->payloadClassFor('test.intent'));
    }

    public function test_register_and_get_schema_path(): void
    {
        $reg = new IntentRegistry();
        $reg->register('test.intent', FakePayload::class, '/path/to/schema.json');

        $this->assertEquals('/path/to/schema.json', $reg->schemaPathFor('test.intent'));
    }

    public function test_unknown_intent_throws_from_payload_class_for(): void
    {
        $reg = new IntentRegistry();
        $this->expectException(UnknownIntentException::class);
        $reg->payloadClassFor('does.not.exist');
    }

    public function test_unknown_intent_throws_from_schema_path_for(): void
    {
        $reg = new IntentRegistry();
        $this->expectException(UnknownIntentException::class);
        $reg->schemaPathFor('does.not.exist');
    }

    public function test_known_intents_returns_all_registered(): void
    {
        $reg = new IntentRegistry();
        $reg->register('a', FakePayload::class);
        $reg->register('b', FakePayload::class);
        $this->assertEquals(['a', 'b'], $reg->knownIntents());
    }

    public function test_hydrate_payload_constructs_via_from_array(): void
    {
        $reg = new IntentRegistry();
        $reg->register('test.intent', FakePayload::class);

        $payload = $reg->hydrate('test.intent', ['order_id' => 'xyz']);
        $this->assertInstanceOf(FakePayload::class, $payload);
        $this->assertEquals('xyz', $payload->orderId);
    }
}

class FakePayload implements SharePayload
{
    public function __construct(public readonly string $orderId) {}
    public static function fromArray(array $raw): self { return new self($raw['order_id']); }
    public function toArray(): array { return ['order_id' => $this->orderId]; }
    public static function intentSlug(): string { return 'test.intent'; }
    public static function intentVersion(): string { return '1.0'; }
}
