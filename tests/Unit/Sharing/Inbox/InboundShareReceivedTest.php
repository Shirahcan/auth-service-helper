<?php

namespace Tests\Unit\Sharing\Inbox;

use AuthService\Helper\Sharing\Inbox\Events\InboundHandoffCompleted;
use AuthService\Helper\Sharing\Inbox\Events\InboundShareReceived;
use AuthService\Helper\Sharing\Intents\Contracts\SharePayload;
use Orchestra\Testbench\TestCase;

class InboundShareReceivedTest extends TestCase
{
    protected function getPackageProviders($app): array
    {
        return [\AuthService\Helper\AuthServiceHelperServiceProvider::class];
    }

    public function test_event_exposes_typed_payload_and_envelope_metadata(): void
    {
        $payload = new FakeInboundPayload('ord_x');

        $event = new InboundShareReceived(
            shareId: 'share_uuid',
            intent: 'test.intent',
            intentVersion: '1.0',
            userId: 'user_uuid',
            sourceServiceId: 'src_uuid',
            payload: $payload,
            messageId: 'msg_uuid',
            correlationId: 'share_uuid',
            metadata: ['received_at' => '2026-05-27T10:00:00Z'],
        );

        $this->assertEquals('share_uuid', $event->shareId);
        $this->assertEquals('test.intent', $event->intent);
        $this->assertEquals('1.0', $event->intentVersion);
        $this->assertEquals('user_uuid', $event->userId);
        $this->assertEquals('src_uuid', $event->sourceServiceId);
        $this->assertSame($payload, $event->payload);
        $this->assertEquals('msg_uuid', $event->messageId);
        $this->assertEquals('share_uuid', $event->correlationId);
        $this->assertEquals(['received_at' => '2026-05-27T10:00:00Z'], $event->metadata);
    }

    public function test_handoff_completed_event_exposes_fields(): void
    {
        $event = new InboundHandoffCompleted(
            shareId: 'share_uuid',
            userId: 'user_uuid',
            targetServiceId: 'tgt_uuid',
            nextPath: '/cases/123',
            consumedAt: '2026-05-27T10:00:01Z',
        );

        $this->assertEquals('share_uuid', $event->shareId);
        $this->assertEquals('user_uuid', $event->userId);
        $this->assertEquals('tgt_uuid', $event->targetServiceId);
        $this->assertEquals('/cases/123', $event->nextPath);
        $this->assertEquals('2026-05-27T10:00:01Z', $event->consumedAt);
    }

    public function test_share_received_uses_dispatchable_and_serializes_models(): void
    {
        $traits = class_uses(InboundShareReceived::class);
        $this->assertContains(\Illuminate\Foundation\Events\Dispatchable::class, $traits);
        $this->assertContains(\Illuminate\Queue\SerializesModels::class, $traits);
    }

    public function test_handoff_completed_uses_dispatchable_and_serializes_models(): void
    {
        $traits = class_uses(InboundHandoffCompleted::class);
        $this->assertContains(\Illuminate\Foundation\Events\Dispatchable::class, $traits);
        $this->assertContains(\Illuminate\Queue\SerializesModels::class, $traits);
    }
}

class FakeInboundPayload implements SharePayload
{
    public function __construct(public readonly string $orderId) {}
    public static function fromArray(array $raw): self { return new self($raw['order_id']); }
    public function toArray(): array { return ['order_id' => $this->orderId]; }
    public static function intentSlug(): string { return 'test.intent'; }
    public static function intentVersion(): string { return '1.0'; }
}
