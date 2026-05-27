<?php

namespace AuthService\Helper\Sharing\Prep\Intents;

class PrepIntentRegistry
{
    /** @var array<string, PrepIntentHandler> */
    private array $handlers = [];

    public function register(string $slug, PrepIntentHandler $handler): void
    {
        $this->handlers[$slug] = $handler;
    }

    public function has(string $slug): bool
    {
        return isset($this->handlers[$slug]);
    }

    public function get(string $slug): PrepIntentHandler
    {
        if (!isset($this->handlers[$slug])) {
            throw new \OutOfBoundsException("No PrepIntentHandler registered for '{$slug}'");
        }
        return $this->handlers[$slug];
    }

    /** @return string[] */
    public function knownIntents(): array
    {
        return array_keys($this->handlers);
    }
}
