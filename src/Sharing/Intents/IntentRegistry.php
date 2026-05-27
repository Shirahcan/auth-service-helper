<?php

namespace AuthService\Helper\Sharing\Intents;

use AuthService\Helper\Sharing\Intents\Contracts\SharePayload;
use AuthService\Helper\Sharing\Intents\Exceptions\UnknownIntentException;

class IntentRegistry
{
    /** @var array<string, array{class: class-string<SharePayload>, schema: ?string}> */
    private array $intents = [];

    public function register(string $slug, string $payloadClass, ?string $schemaPath = null): void
    {
        if (!is_subclass_of($payloadClass, SharePayload::class)) {
            throw new \InvalidArgumentException(
                "Payload class {$payloadClass} must implement SharePayload"
            );
        }
        $this->intents[$slug] = ['class' => $payloadClass, 'schema' => $schemaPath];
    }

    public function payloadClassFor(string $slug): string
    {
        if (!isset($this->intents[$slug])) {
            throw new UnknownIntentException("Unknown intent: {$slug}");
        }
        return $this->intents[$slug]['class'];
    }

    public function schemaPathFor(string $slug): ?string
    {
        if (!isset($this->intents[$slug])) {
            throw new UnknownIntentException("Unknown intent: {$slug}");
        }
        return $this->intents[$slug]['schema'];
    }

    public function hydrate(string $slug, array $rawPayload): SharePayload
    {
        $class = $this->payloadClassFor($slug);
        return $class::fromArray($rawPayload);
    }

    public function knownIntents(): array
    {
        return array_keys($this->intents);
    }
}
