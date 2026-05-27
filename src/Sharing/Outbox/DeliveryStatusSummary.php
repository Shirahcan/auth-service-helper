<?php

namespace AuthService\Helper\Sharing\Outbox;

use Illuminate\Support\Carbon;

final class DeliveryStatusSummary
{
    public function __construct(
        public readonly string $shareId,
        public readonly int $pending,
        public readonly int $delivered,
        public readonly int $failed,
        public readonly int $deadLettered,
        public readonly ?Carbon $lastDeliveredAt,
        public readonly ?Carbon $lastAttemptAt,
    ) {}

    public function toArray(): array
    {
        return [
            'share_id' => $this->shareId,
            'pending' => $this->pending,
            'delivered' => $this->delivered,
            'failed' => $this->failed,
            'dead_lettered' => $this->deadLettered,
            'last_delivered_at' => $this->lastDeliveredAt?->toIso8601String(),
            'last_attempt_at' => $this->lastAttemptAt?->toIso8601String(),
        ];
    }
}
