<?php

namespace AuthService\Helper\Sharing\Intents\Builtin;

use AuthService\Helper\Sharing\Intents\Contracts\SharePayload;

final class ServicePurchasePayload implements SharePayload
{
    public function __construct(
        public readonly string $orderId,
        public readonly string $serviceSlug,
        public readonly int $amountCents,
        public readonly string $currency,
        public readonly string $purchasedAt,
        public readonly array $items,
    ) {}

    public static function fromArray(array $raw): self
    {
        return new self(
            orderId: $raw['order_id'],
            serviceSlug: $raw['service_slug'],
            amountCents: (int) $raw['amount_cents'],
            currency: $raw['currency'],
            purchasedAt: $raw['purchased_at'],
            items: $raw['items'] ?? [],
        );
    }

    public function toArray(): array
    {
        return [
            'order_id' => $this->orderId,
            'service_slug' => $this->serviceSlug,
            'amount_cents' => $this->amountCents,
            'currency' => $this->currency,
            'purchased_at' => $this->purchasedAt,
            'items' => $this->items,
        ];
    }

    public static function intentSlug(): string { return 'service_purchase'; }
    public static function intentVersion(): string { return '1.0'; }
}
